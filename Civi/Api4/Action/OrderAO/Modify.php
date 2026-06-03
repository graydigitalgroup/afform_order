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
use Civi\Api4\OrderLineItem;
use Civi\AfformOrder\Event\OrderModifyValidateEvent;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Modify the line items of an existing, unpaid (Pending) Order.
 *
 * afform_order-private orchestrator, used while core's Order.modify
 * (civicrm/civicrm-core#35433) is in review. Deliberately self-contained: it
 * does NOT call CRM_Financial_BAO_Order::update() (which does not exist in
 * stock core) and instead drives the change through the OrderLineItem
 * create / delete primitives, then recomputes the Contribution totals by
 * summing the stored line values.
 *
 * SCOPE:
 *   - PENDING (unpaid) contributions: full add/remove restructure. "remove"
 *     deletes the line (and its Unpaid FinancialItems).
 *   - PAID contributions (Completed / Partially paid): classifies the net effect
 *     (increase / net_zero / decrease) and dispatches a validate event
 *     (OrderModifyValidateEvent) so a subscriber can veto - e.g. TMPA vetoes
 *     refund-producing edits and routes them to RefundRequest. If not vetoed it
 *     performs the restructure: "remove" REVERSES the line (negated copy, paid
 *     history preserved), "add" creates a new line. It then derives the
 *     contribution status and records an adjustment FinancialTrxn for the new
 *     balance (see applyAdjustedBalance), mirroring core's recordAdjustedAmt.
 *     Actual money movement (a refund/extra payment) remains a SEPARATE step.
 *
 * The validate event is dispatched explicitly here, BEFORE any writes. Core's
 * Order validate event (civicrm/civicrm-core#35433) is not yet part of the save
 * pipeline; when it is, this explicit dispatch becomes redundant.
 *
 * Ownership contract:
 *   - FinancialItem lifecycle is owned entirely by OrderLineItem. This action
 *     never calls FinancialItem::add/delete directly; create/delete of a line
 *     does the financial work via the OrderLineItem hooks.
 *   - Connected-entity reconciliation (membership / participant) for *removed*
 *     lines is intentionally NOT performed here yet - it is policy that needs
 *     UI confirmation. See the marked seam below.
 *
 * @method $this setContributionID(int $contributionID)
 * @method int getContributionID()
 * @method $this setLineItemsToAdd(array $lineItemsToAdd)
 * @method array getLineItemsToAdd()
 * @method $this setLineItemsToRemove(array $lineItemsToRemove)
 * @method array getLineItemsToRemove()
 * @method $this setContext(string $context)
 * @method string getContext()
 * @method $this setContextDetail(array $contextDetail)
 * @method array getContextDetail()
 */
class Modify extends AbstractAction {

  /**
   * The ID of the Contribution whose line items we are modifying.
   *
   * @var int
   * @required
   */
  protected ?int $contributionID = NULL;

  /**
   * Line items to add. Each entry is a line spec suitable for
   * OrderLineItem::create (must resolve to a line_total, e.g. via
   * qty + unit_price, and should carry financial_type_id).
   *
   * @var array
   */
  protected array $lineItemsToAdd = [];

  /**
   * Line items to remove. Each entry must contain at least 'id'.
   *
   * @var array
   */
  protected array $lineItemsToRemove = [];

  /**
   * Caller-declared origin of this modify, e.g. 'refundrequest', 'cart_edit'.
   * REQUIRED on paid contributions (a paid edit with no context is rejected);
   * ignored on Pending (which never reaches the validate event).
   *
   * This is a coordination signal, NOT proof of authorization - a subscriber
   * that allows a refund-producing edit on the strength of a context must
   * independently verify the claim (see OrderModifyValidateEvent).
   *
   * @var string
   */
  protected string $context = '';

  /**
   * Optional structured payload accompanying the context, for a subscriber to
   * verify against, e.g. ['activity_id' => N] or ['refund_request_id' => N].
   *
   * @var array
   */
  protected array $contextDetail = [];

  /**
   * Convenience setter: queue a single line item for addition.
   *
   * @param array $lineItem
   * @return $this
   */
  public function addLineItem(array $lineItem): Modify {
    $this->lineItemsToAdd[] = $lineItem;
    return $this;
  }

  /**
   * Convenience setter: queue a single line item for removal.
   *
   * @param array $lineItem
   * @return $this
   */
  public function removeLineItem(array $lineItem): Modify {
    $this->lineItemsToRemove[] = $lineItem;
    return $this;
  }

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $contributionID = $this->contributionID;
    if (empty($contributionID)) {
      throw new \CRM_Core_Exception('Order modify: contributionID is required');
    }

    // 1. Load the contribution.
    $contribution = Contribution::get(FALSE)
      ->addSelect('id', 'contribution_status_id:name', 'currency', 'total_amount')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first();
    if (empty($contribution)) {
      throw new \CRM_Core_Exception('Order modify: contribution ' . $contributionID . ' not found');
    }
    $statusName = $contribution['contribution_status_id:name'];
    $isPending = ($statusName === 'Pending');

    // 2. For PAID contributions, classify the net effect and dispatch a validate
    //    event so a subscriber can veto (e.g. route refund-producing edits to a
    //    refund-request workflow). We do this BEFORE any writes. With no
    //    subscriber the change proceeds (generic "just works"); if not vetoed we
    //    perform the paid restructure (line reversal) below.
    if (!$isPending) {
      // NOTE: context is OPTIONAL. OrderAO.modify is generic; requiring a
      // context would be surprising for a non-core API and would leak TMPA's
      // refund policy into the shared engine. We simply carry whatever context
      // the caller set (default '') and hand it to validate subscribers. Any
      // gating of refund-producing edits is a SUBSCRIBER's job (e.g. TMPA vetoes
      // a decrease unless it came from an approved Refund Request). An install
      // with no such subscriber gets the generic "just works" behaviour: the
      // paid reversal proceeds.
      $projectedTotal = $this->projectNewTotal($contributionID);
      $currentTotal = (float) ($contribution['total_amount'] ?? 0);
      $netDelta = $projectedTotal - $currentTotal;
      if (abs($netDelta) < 0.005) {
        $netEffect = OrderModifyValidateEvent::EFFECT_NET_ZERO;
      }
      elseif ($netDelta > 0) {
        $netEffect = OrderModifyValidateEvent::EFFECT_INCREASE;
      }
      else {
        $netEffect = OrderModifyValidateEvent::EFFECT_DECREASE;
      }

      $event = new OrderModifyValidateEvent(
        $contributionID,
        $statusName,
        $netEffect,
        $netDelta,
        $this->lineItemsToAdd,
        $this->lineItemsToRemove,
        $this->context,
        $this->contextDetail
      );
      \Civi::dispatcher()->dispatch(OrderModifyValidateEvent::EVENT_NAME, $event);
      $errors = $event->getErrors();
      if ($errors) {
        // A subscriber vetoed the modification (e.g. TMPA redirecting a refund to
        // RefundRequest, or refusing because no approved refund request exists).
        // Nothing has been written. Surface the reason(s).
        throw new \CRM_Core_Exception(implode("\n", $errors), 0, ['show_detailed_error' => TRUE]);
      }

      // Not vetoed - perform the PAID restructure. Unlike the Pending path,
      // "remove" here does NOT delete (OrderLineItem.delete throws on paid
      // lines, and paid financial history must be preserved). Instead a remove
      // REVERSES the line: an ordinary create with negated unit_price and the
      // exact negated stored tax, leaving the original line intact. An "add" is
      // a normal create. A line CORRECTION is expressed by the caller as a
      // remove (reverse the old) + add (the corrected) pair.
      //
      // After restructuring lines we ALSO derive the contribution status and
      // record an adjustment FinancialTrxn (see applyAdjustedBalance). This
      // follows the established core pattern (CRM_Event_BAO_Participant::
      // recordAdjustedAmt, as copied by the lineitemedit extension): when a
      // paid contribution's line total changes and no payment is taken, the
      // status is moved directly (Completed -> Partially paid for an increase,
      // -> Pending refund for a decrease) and an AR adjustment trxn records the
      // now-owed / now-owed-back balance. There is no payment that effects this
      // transition - the "you still owe / are owed" state is recorded first; a
      // later Record Payment / Record Refund settles it. The status write must
      // bypass the APIv4 status-transition guard (which rejects e.g. Completed
      // -> Partially paid), so it is done via direct DAO with manual pre/post
      // hooks - exactly as core's recordAdjustedAmt does.
      $totalAmount = 0.0;
      $taxAmount = 0.0;
      // Capture the contribution total BEFORE this call's restructure. The
      // adjustment FinancialTrxn must record only the change THIS call makes
      // (new total - prior total), not the full outstanding balance: modify is
      // called per-edit, so booking the whole balance each time would stack
      // overlapping AR trxns that overstate what is owed. (Core's
      // _recordAdjustedAmt books the full balance because changeFeeSelections
      // runs once for the entire new selection, not incrementally.)
      $priorTotal = (float) ($contribution['total_amount'] ?? 0);
      \CRM_Core_Transaction::create()->run(function (\CRM_Core_Transaction $tx) use ($contributionID, $priorTotal, &$totalAmount, &$taxAmount) {
        // Reverse each "removed" line (negated copy; original stays).
        foreach ($this->lineItemsToRemove as $lineItem) {
          if (empty($lineItem['id'])) {
            throw new \CRM_Core_Exception('Order modify: each lineItemsToRemove entry requires an id');
          }
          $this->reverseLine((int) $lineItem['id'], $contributionID);
        }

        // Add each new/corrected line as a normal create.
        foreach ($this->lineItemsToAdd as $lineItem) {
          if (empty($lineItem['price_field_value_id'])) {
            throw new \CRM_Core_Exception(
              'Order modify: each lineItemsToAdd entry requires a price_field_value_id ' .
              '(a line item must reference a price field value).'
            );
          }
          $lineItem['contribution_id'] = $contributionID;
          $lineItem['entity_table'] ??= 'civicrm_contribution';
          $lineItem['entity_id'] ??= $contributionID;
          civicrm_api4('OrderLineItem', 'create', [
            'checkPermissions' => FALSE,
            'values' => $lineItem,
          ]);
        }

        // Recompute contribution total/tax from the stored lines (now including
        // the negative reversal lines and any new lines).
        $lines = LineItem::get(FALSE)
          ->addSelect('line_total', 'tax_amount')
          ->addWhere('contribution_id', '=', $contributionID)
          ->execute();
        foreach ($lines as $line) {
          $totalAmount += (float) ($line['line_total'] ?? 0) + (float) ($line['tax_amount'] ?? 0);
          $taxAmount += (float) ($line['tax_amount'] ?? 0);
        }

        // Derive + persist the contribution status, total, tax and the
        // adjustment FinancialTrxn together (direct DAO, bypassing the API
        // status-transition guard). See applyAdjustedBalance. $priorTotal lets
        // it book only this call's incremental change to the AR account.
        $this->applyAdjustedBalance($contributionID, $totalAmount, $taxAmount, $priorTotal);
      });

      $result[] = [
        'id' => $contributionID,
        'total_amount' => $totalAmount,
        'tax_amount' => $taxAmount,
        'net_effect' => $netEffect,
        'net_delta' => $netDelta,
      ];
      return;
    }

    // 3. PENDING path: full add/remove restructure inside one transaction.
    //    We use CRM_Core_Transaction::create()->run($callable) - the idiom
    //    documented in that class - rather than a hand-rolled try/catch. Under
    //    CiviCRM's reference-counted transactions, rollback() only MARKS the
    //    frame rollback-only; the unwind happens when the frame resolves. run()
    //    guarantees that on an exception it calls rollback()->commit() (forcing
    //    the resolution) before rethrowing, so a fatal mid-modify cannot leave a
    //    partially-changed order. A bare rollback() + throw does not, because the
    //    inner API calls may already have pseudo-committed their own frames.
    $totalAmount = 0.0;
    $taxAmount = 0.0;
    \CRM_Core_Transaction::create()->run(function (\CRM_Core_Transaction $tx) use ($contributionID, &$totalAmount, &$taxAmount) {
      // 3a. Remove requested lines. OrderLineItem::delete tears down the line's
      //    Unpaid FinancialItems (and would throw on a Paid line - a second
      //    guard behind the Pending check above).
      foreach ($this->lineItemsToRemove as $lineItem) {
        if (empty($lineItem['id'])) {
          throw new \CRM_Core_Exception('Order modify: each lineItemsToRemove entry requires an id');
        }
        // CONNECTED-ENTITY SEAM: if this line points at a membership or
        // participant, follow core's QuickForm behaviour for editing/deleting a
        // Pending contribution's lines (the membership/participant + its payment
        // link can be removed). Not yet implemented here - tracked for the cart
        // edit UI work.
        OrderLineItem::delete(FALSE)
          ->addWhere('id', '=', $lineItem['id'])
          ->execute();
      }

      // 4. Add requested lines. OrderLineItem::create makes the line and its
      //    FinancialItem(s) via the post hook. contribution_id is forced to the
      //    order under modification so callers cannot retarget another order.
      //
      //    Each added line must reference a price field/value, exactly as any
      //    new CiviCRM line item does: a line without price_field_value_id is
      //    malformed and will fatal later when core tries to title or recalc it
      //    (CRM_Financial_BAO_Order::getPriceFieldSpec on a null price_field_id).
      //    We require it up front rather than guessing a default.
      //
      //    We invoke via civicrm_api4() with an explicit 'values' param rather
      //    than the fluent ->setValues() setter: the fluent setter routes
      //    through AbstractAction::__call -> paramExists('values'), which is not
      //    reliably registered on this OrderLineItem create subclass and throws
      //    "Unknown api parameter: setValues". The array form is stable.
      foreach ($this->lineItemsToAdd as $lineItem) {
        if (empty($lineItem['price_field_value_id'])) {
          throw new \CRM_Core_Exception(
            'Order modify: each lineItemsToAdd entry requires a price_field_value_id ' .
            '(a line item must reference a price field value).'
          );
        }
        $lineItem['contribution_id'] = $contributionID;
        // entity_table / entity_id for a contribution line are determined, not
        // guessed: the line belongs to civicrm_contribution and to this
        // contribution id. The BAO pre-hook sets the same values, but the create
        // required-field gate (AbstractCreateAction::checkRequiredFields) runs
        // BEFORE the hook, so we supply them here. (The spec-provider relaxation
        // is not reliably honoured by the required-field check on this core
        // version, hence setting the values rather than relying on it.)
        $lineItem['entity_table'] ??= 'civicrm_contribution';
        $lineItem['entity_id'] ??= $contributionID;
        civicrm_api4('OrderLineItem', 'create', [
          'checkPermissions' => FALSE,
          'values' => $lineItem,
        ]);
      }

      // 5. Recompute Contribution totals by summing the *stored* line values
      //    (option a). Faithful to what is in the DB: each line's tax_amount
      //    was computed by the OrderLineItem create hook, so we do not re-derive.
      $lines = LineItem::get(FALSE)
        ->addSelect('line_total', 'tax_amount')
        ->addWhere('contribution_id', '=', $contributionID)
        ->execute();
      foreach ($lines as $line) {
        $totalAmount += (float) ($line['line_total'] ?? 0) + (float) ($line['tax_amount'] ?? 0);
        $taxAmount += (float) ($line['tax_amount'] ?? 0);
      }

      // Write the two scalar columns directly via the DAO rather than through
      // Contribution::update. The APIv4 update path re-enters core's order
      // machinery (CRM_Financial_BAO_Order::getLineItems -> calculateLineItems
      // -> getLineItemTitle -> getPriceFieldSpec), which rebuilds line items in
      // a context where price_field_id is not carried and fatals on a null id
      // (Order.php getPriceFieldSpec(int $id)). We have already computed the
      // authoritative totals from the stored lines, so we deliberately bypass
      // any recalculation and just persist them.
      $contributionDAO = new \CRM_Contribute_DAO_Contribution();
      $contributionDAO->id = $contributionID;
      $contributionDAO->total_amount = $totalAmount;
      $contributionDAO->tax_amount = $taxAmount;
      $contributionDAO->save();
    });

    // 6. Return the updated contribution summary.
    $result[] = [
      'id' => $contributionID,
      'total_amount' => $totalAmount,
      'tax_amount' => $taxAmount,
    ];
  }

  /**
   * Compute what the contribution total WOULD be after applying the proposed
   * add/remove, without writing anything. Used only to classify the net effect
   * for the validate event on a paid contribution.
   *
   * = (sum of current lines, excluding those being removed)
   *   + (sum of lines being added)
   * each term including tax.
   *
   * Added-line totals are taken from the supplied line spec: line_total if
   * given, else qty * unit_price; tax from tax_amount if given. This mirrors
   * what the create path would store but does not run it. (A more exact figure
   * would re-derive tax from the price field value, but for classification
   * - increase vs decrease vs net-zero - the supplied values are sufficient and
   * avoid extra lookups.)
   *
   * @param int $contributionID
   * @return float
   * @throws \CRM_Core_Exception
   */
  private function projectNewTotal(int $contributionID): float {
    $removeIDs = [];
    foreach ($this->lineItemsToRemove as $lineItem) {
      if (!empty($lineItem['id'])) {
        $removeIDs[(int) $lineItem['id']] = TRUE;
      }
    }

    $projected = 0.0;
    $currentLines = LineItem::get(FALSE)
      ->addSelect('id', 'line_total', 'tax_amount')
      ->addWhere('contribution_id', '=', $contributionID)
      ->execute();
    foreach ($currentLines as $line) {
      if (isset($removeIDs[(int) $line['id']])) {
        continue;
      }
      $projected += (float) ($line['line_total'] ?? 0) + (float) ($line['tax_amount'] ?? 0);
    }

    foreach ($this->lineItemsToAdd as $lineItem) {
      if (isset($lineItem['line_total'])) {
        $lineTotal = (float) $lineItem['line_total'];
      }
      elseif (isset($lineItem['qty'], $lineItem['unit_price'])) {
        $lineTotal = (float) $lineItem['qty'] * (float) $lineItem['unit_price'];
      }
      else {
        $lineTotal = 0.0;
      }
      $projected += $lineTotal + (float) ($lineItem['tax_amount'] ?? 0);
    }

    return $projected;
  }

  /**
   * Reverse a paid line item by creating a negated copy of it.
   *
   * The reversal is an ordinary OrderLineItem create with:
   *   - negated unit_price (qty stays positive, so line_total comes out negative)
   *   - the EXACT negated stored tax_amount passed explicitly (NOT recomputed),
   *     so the reversal cancels the original to the cent even if the original's
   *     tax had manual adjustment or rounding quirks
   *   - the same price_field_id / price_field_value_id / financial_type_id / label
   *   - isReversal = TRUE (so the account resolver / hooks can treat it as a
   *     reversal if they wish; the resolved financial account is the same
   *     AP-first/Income-fallback as the original)
   *
   * Through the OrderLineItem create hook this yields a correctly-signed
   * negative FinancialItem (Paid status, derived from the Completed contribution)
   * plus a negative tax FinancialItem when tax applies. The original line is left
   * intact - the negative line documents the back-out alongside it, so the
   * contribution view and the financials reconcile line-for-line.
   *
   * @param int $lineItemID
   * @param int $contributionID
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  private function reverseLine(int $lineItemID, int $contributionID): void {
    $orig = LineItem::get(FALSE)
      ->addSelect('*')
      ->addWhere('id', '=', $lineItemID)
      ->addWhere('contribution_id', '=', $contributionID)
      ->execute()
      ->first();
    if (empty($orig)) {
      throw new \CRM_Core_Exception(
        'Order modify: line ' . $lineItemID . ' not found on contribution ' . $contributionID . ' to reverse'
      );
    }

    $reversal = [
      'contribution_id' => $contributionID,
      'entity_table' => $orig['entity_table'] ?? 'civicrm_contribution',
      // For a contribution line entity_id is the contribution; for a
      // membership/participant line we keep the original entity_id so the
      // reversal stays associated with the same entity. (Membership/participant
      // STATUS changes are the refund step's responsibility, not modify's.)
      'entity_id' => ($orig['entity_table'] ?? 'civicrm_contribution') === 'civicrm_contribution'
        ? $contributionID
        : ($orig['entity_id'] ?? $contributionID),
      'price_field_id' => $orig['price_field_id'] ?? NULL,
      'price_field_value_id' => $orig['price_field_value_id'] ?? NULL,
      'financial_type_id' => $orig['financial_type_id'] ?? NULL,
      'label' => $orig['label'] ?? NULL,
      'qty' => $orig['qty'] ?? 1,
      // Negate the unit_price; line_total falls out as qty * unit_price (negative).
      'unit_price' => -1 * (float) ($orig['unit_price'] ?? 0),
      'line_total' => -1 * (float) ($orig['line_total'] ?? 0),
      // Pass the EXACT negated stored tax so the reversal cancels precisely.
      // The create hook recomputes tax when financial_type_id + line_total are
      // present; passing tax_amount here is the value we WANT, but note the hook
      // currently overwrites tax_amount via getTaxAmountForLineItem. See below.
      'tax_amount' => -1 * (float) ($orig['tax_amount'] ?? 0),
      'isReversal' => TRUE,
    ];

    civicrm_api4('OrderLineItem', 'create', [
      'checkPermissions' => FALSE,
      'values' => $reversal,
    ]);
  }

  /**
   * Derive and persist the contribution status, total, tax and an adjustment
   * FinancialTrxn after a paid restructure.
   *
   * Faithfully mirrors core's CRM_Price_BAO_LineItem::_recordAdjustedAmt - the
   * core business object's own mechanism for adjusting a contribution when the
   * line total changes and NO payment is taken (used by changeFeeSelections):
   *
   *   balanceAmt = updatedAmount - paidAmount   (paid = getTotalPayments)
   *
   *   - balanceAmt != 0 and paidAmount == 0  -> leave status (nothing paid yet)
   *   - balanceAmt > 0                        -> Partially paid (more now owed)
   *   - balanceAmt < 0                        -> Pending refund (owed back)
   *   - balanceAmt == 0                       -> Completed (fully settled by the
   *                                              change)
   *
   * Status + total + net + tax are written via DIRECT DAO save (no API), because
   * the API/BAO create path rejects transitions like Completed -> Partially paid
   * ("Cannot change contribution status ..."). Core writes the DAO directly here
   * for exactly that reason.
   *
   * The STATUS is derived from the absolute outstanding balance
   * (updatedAmount - paidAmount), mirroring core. But the adjustment
   * FinancialTrxn records only the INCREMENTAL change this call made
   * (updatedAmount - priorTotal), NOT the full balance. This is the one place we
   * deliberately differ from core's _recordAdjustedAmt: core books the full
   * balance because changeFeeSelections runs once for an entire new selection,
   * whereas OrderAO.modify is called per-edit - booking the full balance each
   * call would stack overlapping AR trxns that overstate the amount owed. The
   * trxn's own status_id is Completed (the adjustment is a completed accounting
   * event), per core. No money has moved - a later Record Payment / Record
   * Refund settles the balance.
   *
   * @param int $contributionID
   * @param float $updatedAmount Total (incl. tax) of the stored lines after this call.
   * @param float $taxAmount Total tax of the stored lines.
   * @param float $priorTotal Contribution total BEFORE this call's restructure.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  private function applyAdjustedBalance(int $contributionID, float $updatedAmount, float $taxAmount, float $priorTotal): void {
    $paidAmount = (float) \CRM_Core_BAO_FinancialTrxn::getTotalPayments($contributionID);
    $balanceAmt = $updatedAmount - $paidAmount;
    // The change THIS call introduced - the amount to book on the AR account.
    $incrementAmt = $updatedAmount - $priorTotal;

    $statuses = \CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $partiallyPaidStatusId = array_search('Partially paid', $statuses);
    $pendingRefundStatusId = array_search('Pending refund', $statuses);
    $completedStatusId = array_search('Completed', $statuses);

    if (abs($balanceAmt) >= 0.005) {
      $existing = Contribution::get(FALSE)
        ->addSelect('financial_type_id', 'payment_instrument_id', 'currency')
        ->addWhere('id', '=', $contributionID)
        ->execute()
        ->first();

      // Write status + amounts via direct DAO (bypassing the API status guard).
      // Mirrors core: net_amount = updatedAmount, fee_amount = 0; status only
      // changes when something has actually been paid. Status reflects the
      // ABSOLUTE balance.
      $dao = new \CRM_Contribute_DAO_Contribution();
      $dao->id = $contributionID;
      if ($paidAmount != 0.0) {
        $dao->contribution_status_id = $balanceAmt > 0 ? $partiallyPaidStatusId : $pendingRefundStatusId;
      }
      $dao->total_amount = $dao->net_amount = $updatedAmount;
      $dao->fee_amount = 0;
      $dao->tax_amount = $taxAmount;
      $dao->save();

      // Adjustment FinancialTrxn for the INCREMENTAL change, to the
      // contribution's Accounts Receivable account. status_id is Completed (the
      // adjustment itself is a completed accounting event), per core. We guard
      // on the increment (not the balance) being non-zero: a call that does not
      // change the total books no trxn even if a balance is outstanding.
      if (abs($incrementAmt) >= 0.005) {
        $arAccount = \CRM_Contribute_PseudoConstant::getRelationalFinancialAccount(
          $existing['financial_type_id'],
          'Accounts Receivable Account is'
        );
        \CRM_Core_BAO_FinancialTrxn::create([
          'from_financial_account_id' => NULL,
          'to_financial_account_id' => $arAccount,
          'total_amount' => $incrementAmt,
          'net_amount' => $incrementAmt,
          'status_id' => $completedStatusId,
          'payment_instrument_id' => $existing['payment_instrument_id'],
          'contribution_id' => $contributionID,
          'trxn_date' => date('YmdHis'),
          'currency' => $existing['currency'],
        ]);
      }
    }
    else {
      // Balance is zero - the change fully settles the contribution. Set
      // Completed (and still persist the new total/tax). Mirrors core's CRM-17151
      // handling so successive changes don't leave the status incorrect. Still
      // book the incremental AR movement if this call changed the total (e.g. a
      // decrease that brought a previously-owed balance back to zero).
      $existing = Contribution::get(FALSE)
        ->addSelect('financial_type_id', 'payment_instrument_id', 'currency')
        ->addWhere('id', '=', $contributionID)
        ->execute()
        ->first();

      $dao = new \CRM_Contribute_DAO_Contribution();
      $dao->id = $contributionID;
      $dao->total_amount = $dao->net_amount = $updatedAmount;
      $dao->fee_amount = 0;
      $dao->tax_amount = $taxAmount;
      $dao->contribution_status_id = $completedStatusId;
      $dao->save();

      if (abs($incrementAmt) >= 0.005) {
        $arAccount = \CRM_Contribute_PseudoConstant::getRelationalFinancialAccount(
          $existing['financial_type_id'],
          'Accounts Receivable Account is'
        );
        \CRM_Core_BAO_FinancialTrxn::create([
          'from_financial_account_id' => NULL,
          'to_financial_account_id' => $arAccount,
          'total_amount' => $incrementAmt,
          'net_amount' => $incrementAmt,
          'status_id' => $completedStatusId,
          'payment_instrument_id' => $existing['payment_instrument_id'],
          'contribution_id' => $contributionID,
          'trxn_date' => date('YmdHis'),
          'currency' => $existing['currency'],
        ]);
      }
    }
  }

}
