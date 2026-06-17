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

namespace Civi\Api4;
use Civi\Api4\Action\OrderAO\EnsureRecurTemplate;
use Civi\Api4\Action\OrderAO\Modify;
use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * afform_order's private Order API surface.
 *
 * Named OrderAO (NOT "Order") on purpose: core already ships a Civi\Api4\Order
 * entity (@since 5.68) and, once civicrm/civicrm-core#35433 lands, its own
 * Order.Modify action. Declaring our own Civi\Api4\Order would fatal on the
 * duplicate class name and collide on the Action\Order namespace. We keep to
 * our own name, in the same spirit as OrderLineItem sidestepping core LineItem.
 *
 * Actions:
 *  - `modify` changes the line items of an existing Order (Pending, paid, or
 *    a recurring series' template contribution), driving the work through the
 *    OrderLineItem create / delete primitives and recomputing Contribution
 *    totals from the stored line values.
 *  - `ensureRecurTemplate` resolves (creating if necessary) the template
 *    contribution of a recurring series, so a caller can point `modify` at it
 *    to edit the series' future installments.
 *
 * This is NOT a DAO entity - it has no table of its own; it operates on an
 * existing Contribution. Retire in favour of core Order.modify when that lands.
 *
 * @searchable none
 * @package Civi\Api4
 */
class OrderAO extends AbstractEntity {

  /**
   * @param bool $checkPermissions
   * @return \Civi\Api4\Action\OrderAO\Modify
   */
  public static function modify($checkPermissions = TRUE): Modify {
    return (new Modify(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return \Civi\Api4\Action\OrderAO\EnsureRecurTemplate
   */
  public static function ensureRecurTemplate($checkPermissions = TRUE): EnsureRecurTemplate {
    return (new EnsureRecurTemplate(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return \Civi\Api4\Generic\BasicGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE): BasicGetFieldsAction {
    return (new BasicGetFieldsAction(static::getEntityName(), __FUNCTION__, function () {
      return [
        [
          'name' => 'contributionID',
          'title' => 'Contribution ID',
          'data_type' => 'Integer',
          'description' => 'The Contribution whose line items are being modified.',
        ],
        [
          'name' => 'lineItemsToAdd',
          'title' => 'Line items to add',
          'data_type' => 'Array',
        ],
        [
          'name' => 'lineItemsToRemove',
          'title' => 'Line items to remove',
          'data_type' => 'Array',
        ],
      ];
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * Modifying an order's line items is a contribution-level financial edit.
   *
   * @return array
   */
  public static function permissions(): array {
    return [
      'modify' => ['edit contributions'],
      // Resolving the template may CREATE a contribution (the template row),
      // so it carries the same edit-level gate as modify.
      'ensureRecurTemplate' => ['edit contributions'],
      // Reclassifying value off one line onto new lines is a contribution-level
      // financial edit (creates lines + financial trxns), so it carries the
      // same edit-level gate as modify.
      'reclassifyLineValue' => ['edit contributions'],
      // Reporting whether the caller holds the override permission is a
      // read-only self-check the cart makes to decide whether to show its
      // per-line edit affordances; gate it at view level so the call itself
      // succeeds for any contribute user (it returns the boolean either way).
      'canOverrideLineItems' => ['access CiviContribute'],
      // Listing the receipt "from" addresses is a read-only lookup the
      // contribution-details panel makes to populate its "Receipt From"
      // select; view-level so it succeeds for any contribute user.
      'getFromEmails' => ['access CiviContribute'],
      'meta' => ['access CiviContribute'],
      'default' => ['edit contributions'],
    ];
  }

}
