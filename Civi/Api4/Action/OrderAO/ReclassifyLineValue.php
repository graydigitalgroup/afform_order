<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Api4\Action\OrderAO;

use Civi\Api4\Contribution;
use Civi\Api4\LineItem;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Reclassify value from one existing ("source") line item onto one or more NEW
 * line items, as a NET-ZERO movement of money already received - no change to
 * the contribution total, no payment, no refund.
 *
 * This is the generic engine behind a "suspense / clearing / placeholder" line:
 * a payment lands in a holding line and is later recognised against real income
 * lines, without altering what was paid. It is deliberately DIFFERENT from
 * OrderAO.modify:
 *   - modify models a paid change as reverse-and-re-add (a negative reversal
 *     LINE + a new line) plus a validate/veto pass and an AR balance
 *     adjustment. None of that fits here: there is no balance change to book,
 *     nothing to veto (money already in hand), and we do NOT want a reversal
 *     line for the source - just its value drawn down in place.
 *   - here the source line is reduced IN PLACE (line_total/unit_price updated)
 *     and the draw-down is recorded as a signed FinancialItem delta, NOT a new
 *     line.
 *
 * Accounting model (chosen by the consumer that drove this design): each
 * allocation books a reclassification FinancialTrxn (is_payment = 0) moving the
 * amount FROM the source line's financial account TO the new line's income
 * account, so the movement is itself an auditable record. BOTH sides are linked
 * to that trxn:
 *   - the new line's POSITIVE FinancialItem (created by OrderLineItem.create,
 *     which we point at the trxn via the financial_trxn_id passthrough), and
 *   - a NEGATIVE delta FinancialItem on the SOURCE line (the draw-down).
 *
 * This action owns all FinancialItem/Trxn plumbing (it lives in afform_order,
 * the canonical owner of line-item financial records) so a CONSUMER never has
 * to. The consumer expresses only intent: "move $X of this line into these new
 * lines". Any policy (which lines, who may do it, soft credits, audit activity)
 * stays in the consumer.
 *
 * Net-zero invariant: the sum of the allocation line totals must not exceed the
 * source line's current line_total (you cannot allocate more than is sitting in
 * the source). A partial allocation is allowed - the remainder stays on the
 * source line.
 *
 * Returns one row per created allocation line:
 *   { line_item_id, financial_trxn_id, amount, _ref }
 * where _ref echoes back the optional 'ref' the caller put on the allocation,
 * so it can correlate the created line with its own source record.
 *
 * @method $this setSourceLineItemID(int $id)
 * @method int getSourceLineItemID()
 * @method $this setAllocations(array $allocations)
 * @method array getAllocations()
 */
class ReclassifyLineValue extends AbstractAction {

  /**
   * The existing line item whose value is being drawn down (the
   * placeholder / suspense line).
   *
   * @var int
   * @required
   */
  protected ?int $sourceLineItemID = NULL;

  /**
   * The new lines to create from the source's value. Each entry is a line spec
   * (price_field_value_id, financial_type_id, qty, unit_price, label, and
   * optionally entity_table/entity_id for a connected entity such as a
   * membership). line_total is derived from qty * unit_price when not given;
   * the derived (or given) line_total is the amount drawn from the source.
   *
   * An optional 'ref' on an entry is echoed back on the matching result row as
   * '_ref' so the caller can map created lines to its own records.
   *
   * @var array
   * @required
   */
  protected array $allocations = [];

  /**
   * Tolerance for the net-zero / over-allocation comparison (currency rounding).
   */
  private const EPSILON = 0.005;

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    if (empty($this->sourceLineItemID)) {
      throw new \CRM_Core_Exception('reclassifyLineValue: sourceLineItemID is required');
    }
    if (empty($this->allocations)) {
      throw new \CRM_Core_Exception('reclassifyLineValue: at least one allocation is required');
    }

    // 1. Load the source line + its contribution.
    $source = LineItem::get(FALSE)
      ->addSelect('id', 'contribution_id', 'line_total', 'qty', 'financial_type_id')
      ->addWhere('id', '=', $this->sourceLineItemID)
      ->execute()
      ->first();
    if (empty($source)) {
      throw new \CRM_Core_Exception('reclassifyLineValue: source line ' . $this->sourceLineItemID . ' not found');
    }
    $contributionID = (int) $source['contribution_id'];
    $sourceTotal = (float) ($source['line_total'] ?? 0);
    // Stash qty so the in-transaction draw-down can recompute the source
    // unit_price (remaining / qty) without re-querying.
    $this->sourceQty = (float) ($source['qty'] ?? 0);

    // 2. Resolve the amount each allocation draws down, and guard the invariant:
    //    you cannot reclassify more than is currently sitting in the source.
    $totalAllocated = 0.0;
    foreach ($this->allocations as $i => $alloc) {
      $amount = $this->allocationAmount($alloc);
      if ($amount <= 0) {
        throw new \CRM_Core_Exception('reclassifyLineValue: allocation ' . $i . ' must be a positive amount');
      }
      if (empty($alloc['price_field_value_id'])) {
        throw new \CRM_Core_Exception('reclassifyLineValue: allocation ' . $i . ' requires a price_field_value_id');
      }
      if (empty($alloc['financial_type_id'])) {
        throw new \CRM_Core_Exception('reclassifyLineValue: allocation ' . $i . ' requires a financial_type_id');
      }
      $totalAllocated += $amount;
    }
    if ($totalAllocated > $sourceTotal + self::EPSILON) {
      throw new \CRM_Core_Exception(
        'reclassifyLineValue: cannot allocate ' . $totalAllocated .
        ' - only ' . $sourceTotal . ' is available on the source line'
      );
    }

    // 3. The source line's existing FinancialItem gives us BOTH the "from"
    //    account for the reclassification trxn AND the template for the negative
    //    delta FinancialItem we write against the source.
    $sourceFinancialItem = \CRM_Financial_BAO_FinancialItem::getPreviousFinancialItem($this->sourceLineItemID);
    if (empty($sourceFinancialItem) || empty($sourceFinancialItem['financial_account_id'])) {
      throw new \CRM_Core_Exception(
        'reclassifyLineValue: source line ' . $this->sourceLineItemID .
        ' has no FinancialItem to reclassify from'
      );
    }
    $fromAccountID = (int) $sourceFinancialItem['financial_account_id'];

    $contribution = Contribution::get(FALSE)
      ->addSelect('id', 'currency', 'payment_instrument_id')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first();
    $statuses = \CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $completedStatusID = array_search('Completed', $statuses);

    // 4. All writes in one transaction. A failure rolls back the whole
    //    reclassification (no partially-allocated source, no orphan trxns).
    $rows = [];
    \CRM_Core_Transaction::create()->run(function () use (
      &$rows, $contributionID, $contribution, $fromAccountID,
      $sourceFinancialItem, $completedStatusID, $sourceTotal, $totalAllocated
    ) {
      foreach ($this->allocations as $alloc) {
        $amount = $this->allocationAmount($alloc);
        $financialTypeID = (int) $alloc['financial_type_id'];

        // The "to" account is the same one the new line's FinancialItem will be
        // booked against (OrderLineItem resolves it identically), so the trxn's
        // destination and the FinancialItem account agree.
        $toAccountID = \CRM_AfformOrder_BAO_OrderLineItem::resolveFinancialAccount($financialTypeID);

        // 4a. The reclassification trxn: money moves from the source's account
        //     to this allocation's income account. is_payment = 0 - this is a
        //     reclassification, not a new payment.
        $trxn = \CRM_Core_BAO_FinancialTrxn::create([
          'from_financial_account_id' => $fromAccountID,
          'to_financial_account_id' => $toAccountID,
          'total_amount' => $amount,
          'net_amount' => $amount,
          'status_id' => $completedStatusID,
          'payment_instrument_id' => $contribution['payment_instrument_id'] ?? NULL,
          'contribution_id' => $contributionID,
          'trxn_date' => date('YmdHis'),
          'currency' => $contribution['currency'] ?? NULL,
          'is_payment' => 0,
        ]);

        // 4b. The new allocation line. financial_trxn_id is a passthrough the
        //     OrderLineItem post-hook honours (like isReversal / skipFinancialItems):
        //     it allocates the new line's POSITIVE FinancialItem to THIS trxn
        //     rather than to the contribution's original payment.
        $values = [
          'contribution_id' => $contributionID,
          'price_field_id' => $alloc['price_field_id'] ?? NULL,
          'price_field_value_id' => $alloc['price_field_value_id'],
          'financial_type_id' => $financialTypeID,
          'label' => $alloc['label'] ?? NULL,
          'qty' => $alloc['qty'] ?? 1,
          'unit_price' => $alloc['unit_price'] ?? $amount,
          'line_total' => $amount,
          'entity_table' => $alloc['entity_table'] ?? 'civicrm_contribution',
          'entity_id' => $alloc['entity_id'] ?? $contributionID,
          'financial_trxn_id' => $trxn->id,
        ];
        $created = civicrm_api4('OrderLineItem', 'create', [
          'checkPermissions' => FALSE,
          'values' => $values,
        ])->first();

        // 4c. The source draw-down: a NEGATIVE delta FinancialItem (clone of the
        //     source's item, negated) on the placeholder's account. No new source
        //     LINE is created - only this signed financial record, which reduces
        //     the source line's recognised income so the per-line FinancialItem
        //     totals still sum to the (unchanged) contribution total.
        //
        //     Deliberately NOT linked to the reclassification trxn: CiviCRM's
        //     convention is that a FinancialTrxn's EntityFinancialTrxn rows sum
        //     to its total_amount (verified in core's
        //     updateFinancialAccountsOnPaymentInstrumentChange - reversals are
        //     separate negative-total trxns, never offsetting +/- EFT rows on one
        //     trxn). The trxn is balanced by the +amount link to the new line's
        //     FinancialItem above (the "to" side); the "from" side is the trxn's
        //     from_financial_account_id. Linking this negative item here too would
        //     make the trxn's EFT rows net to zero and fail financial validation.
        $negParams = $sourceFinancialItem;
        unset($negParams['id']);
        $negParams['amount'] = -1 * $amount;
        $negParams['transaction_date'] = date('YmdHis');
        $negParams['financial_account_id'] = $fromAccountID;
        \CRM_Financial_BAO_FinancialItem::create($negParams);

        $rows[] = [
          'line_item_id' => (int) ($created['id'] ?? 0),
          'financial_trxn_id' => (int) $trxn->id,
          'amount' => $amount,
          '_ref' => $alloc['ref'] ?? NULL,
        ];
      }

      // 5. Draw the source line down IN PLACE by what we allocated. LineItem
      //    update touches only the scalar columns (it does not re-book
      //    FinancialItems - the negative deltas above already did the financial
      //    work), so the source keeps its identity and history.
      $remaining = $sourceTotal - $totalAllocated;
      if ($remaining < 0) {
        $remaining = 0.0;
      }
      $sourceQty = (float) ($this->sourceQty ?? 0);
      LineItem::update(FALSE)
        ->addWhere('id', '=', $this->sourceLineItemID)
        ->addValue('line_total', $remaining)
        ->addValue('unit_price', $sourceQty > 0 ? $remaining / $sourceQty : $remaining)
        ->execute();

      // 6. Recompute + persist the contribution total/tax from the stored lines.
      //    For a pure (tax-free) reclassification this is a no-op (the source
      //    fell by exactly what the new lines added), but recomputing keeps the
      //    header consistent if any allocation carried tax. Status is NOT
      //    touched: nothing was paid or refunded.
      $this->persistRecomputedTotals($contributionID);
    });

    foreach ($rows as $row) {
      $result[] = $row;
    }
  }

  /**
   * Stashed source qty, so the in-transaction draw-down can recompute the
   * source unit_price without re-querying.
   *
   * @var float|null
   */
  private ?float $sourceQty = NULL;

  /**
   * The amount an allocation entry draws from the source: its explicit
   * line_total, else qty * unit_price.
   *
   * @param array $alloc
   * @return float
   */
  private function allocationAmount(array $alloc): float {
    if (isset($alloc['line_total'])) {
      return (float) $alloc['line_total'];
    }
    return (float) ($alloc['qty'] ?? 1) * (float) ($alloc['unit_price'] ?? 0);
  }

  /**
   * Sum the stored line values and persist total_amount/tax_amount on the
   * contribution (status untouched). Mirrors OrderAO.modify's totals step.
   *
   * @param int $contributionID
   * @throws \CRM_Core_Exception
   */
  private function persistRecomputedTotals(int $contributionID): void {
    $totalAmount = 0.0;
    $taxAmount = 0.0;
    $lines = LineItem::get(FALSE)
      ->addSelect('line_total', 'tax_amount')
      ->addWhere('contribution_id', '=', $contributionID)
      ->execute();
    foreach ($lines as $line) {
      $totalAmount += (float) ($line['line_total'] ?? 0) + (float) ($line['tax_amount'] ?? 0);
      $taxAmount += (float) ($line['tax_amount'] ?? 0);
    }
    $dao = new \CRM_Contribute_DAO_Contribution();
    $dao->id = $contributionID;
    $dao->total_amount = $totalAmount;
    $dao->tax_amount = $taxAmount;
    $dao->save();
  }

}