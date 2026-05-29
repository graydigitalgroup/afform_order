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
 * SCOPE (phase 3a): no-payment path only. The Contribution must be Pending.
 * A paid Contribution throws - reversing paid line items (negative-unit_price
 * lines + an adjustment FinancialTrxn) is phase 3b and is not implemented here.
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

    // 1. Load the contribution and enforce the no-payment scope.
    $contribution = Contribution::get(FALSE)
      ->addSelect('id', 'contribution_status_id:name', 'currency')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first();
    if (empty($contribution)) {
      throw new \CRM_Core_Exception('Order modify: contribution ' . $contributionID . ' not found');
    }
    if ($contribution['contribution_status_id:name'] !== 'Pending') {
      throw new \CRM_Core_Exception(
        'Order modify: cannot modify contribution ' . $contributionID .
        ' because its status is "' . $contribution['contribution_status_id:name'] .
        '". Only Pending (unpaid) orders can be modified; reversing paid lines is not yet supported.'
      );
    }

    // 2. Everything inside one transaction so a later failure rolls back the
    //    whole modification rather than leaving a half-changed order.
    $transaction = new \CRM_Core_Transaction();
    try {
      // 3. Remove requested lines. OrderLineItem::delete tears down the line's
      //    Unpaid FinancialItems (and would throw on a Paid line - a second
      //    guard behind the Pending check above).
      foreach ($this->lineItemsToRemove as $lineItem) {
        if (empty($lineItem['id'])) {
          throw new \CRM_Core_Exception('Order modify: each lineItemsToRemove entry requires an id');
        }
        // CONNECTED-ENTITY SEAM (phase 3a): if this line points at a membership
        // or participant, we are NOT yet expiring/cancelling that entity here.
        // That decision needs UI confirmation and lands with the consumer
        // (refundrequest / cart edit) in a later phase.
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
      $totalAmount = 0.0;
      $taxAmount = 0.0;
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
    }
    catch (\Throwable $e) {
      $transaction->rollback();
      throw $e;
    }
    $transaction->commit();

    // 6. Return the updated contribution summary.
    $result[] = [
      'id' => $contributionID,
      'total_amount' => $totalAmount,
      'tax_amount' => $taxAmount,
    ];
  }

}
