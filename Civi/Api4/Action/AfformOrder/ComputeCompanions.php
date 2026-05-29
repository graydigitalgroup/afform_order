<?php

namespace Civi\Api4\Action\AfformOrder;

use Civi\AfformOrder\CompanionLogic;

/**
 * Recompute companion line items for a cart.
 *
 * Thin API4 wrapper over \Civi\AfformOrder\CompanionLogic::compute. Called by
 * the cart directive on every mutation: the directive sends its current
 * line_items array; the orchestrator partitions prior auto rows (preserving
 * manual overrides whose driver is still present), then dispatches
 * ComputeCompanionsEvent so any registered companion providers can append
 * their rows; the resulting cart is returned.
 *
 * Idempotent: calling repeatedly on the same input yields the same output
 * (provided every registered provider is itself idempotent).
 *
 * If no companion providers are registered, or none apply to the cart, the
 * input is returned with any pre-existing auto-companion rows stripped and
 * nothing added.
 *
 * Each row in the input/output is the cart-row shape consumed by the directive
 * and the submit subscriber:
 *   - price_field_id (int)
 *   - price_field_value_id (int)
 *   - qty (float)
 *   - unit_price (float)
 *   - line_total (float)
 *   - financial_type_id (int)
 *   - label (string)
 *   - entity_table ('civicrm_contribution' | 'civicrm_membership')
 *   - _cart_id (string)
 *   - _companion_for (string, set on auto rows only)
 *   - _afform_order_companion (bool, set on auto rows only)
 *   - _is_override (bool, set on rows staff manually edited)
 */
class ComputeCompanions extends \Civi\Api4\Generic\AbstractAction {

  /**
   * The cart to recompute. Each entry is an associative row object as
   * documented above.
   *
   * @var array
   * @required
   */
  protected array $lineItems = [];

  /**
   * Emit one result row per cart row, preserving order. Driver rows come
   * first (in their original order), followed by their generated companions.
   *
   * @param \Civi\Api4\Generic\Result $result
   * @throws \CRM_Core_Exception
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    $synced = CompanionLogic::compute($this->lineItems);
    foreach ($synced as $row) {
      $result[] = $row;
    }
  }

}
