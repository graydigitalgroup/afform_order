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
 * Exposes a single `modify` action that changes the line items of an existing
 * Pending (unpaid) Order, driving the work through the OrderLineItem
 * create / delete primitives and recomputing Contribution totals from the
 * stored line values.
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
      'meta' => ['access CiviContribute'],
      'default' => ['edit contributions'],
    ];
  }

}
