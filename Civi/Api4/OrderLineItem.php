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

use Civi\Api4\Generic\AutocompleteAction;
use Civi\Api4\Generic\BasicReplaceAction;
use Civi\Api4\Generic\DAOCreateAction;
use Civi\Api4\Generic\DAODeleteAction;
use Civi\Api4\Generic\DAOGetAction;
use Civi\Api4\Generic\DAOGetFieldsAction;
use Civi\Api4\Generic\DAOSaveAction;
use Civi\Api4\Generic\DAOUpdateAction;
use Civi\Api4\Action\OrderLineItem\Create;
use Civi\Api4\Action\OrderLineItem\Delete;
use Civi\Api4\Utils\CoreUtil;

/**
 * afform_order's private LineItem API surface.
 *
 * Temporary while the corresponding core PR (refactoring Order->save into
 * calculate / save / postSave with proper event seams) is in review. Once
 * core lands the equivalent functionality this entity and its supporting
 * BAO / DAO / Create action / spec provider can be retired.
 *
 * Shares core's LineItem schema (the DAO points at Civi::entity('LineItem'))
 * but routes writes through CRM_AfformOrder_BAO_OrderLineItem::writeRecord
 * so hooks fire under the entity name "OrderLineItem", avoiding collision
 * with core hooks on "LineItem".
 *
 * `save`, `update`, and `delete` are denied by permissions: only `create`
 * (and the read actions) are exposed.
 *
 * @searchable secondary
 *
 * @package Civi\Api4
 */
class OrderLineItem extends Generic\DAOEntity {

  /**
   * @return array
   */
  public static function permissions() {
    $permissions = parent::permissions();
    // save and update remain denied: this entity exposes create + delete only.
    $permissions['save'] = $permissions['update'] = \CRM_Core_Permission::ALWAYS_DENY_PERMISSION;
    // delete is allowed but gated: removing a line tears down its FinancialItems,
    // which is a contribution-level financial edit.
    $permissions['delete'] = ['edit contributions'];
    return $permissions;
  }

  protected static function getDaoName(): ?string {
    return 'CRM_AfformOrder_DAO_OrderLineItem';
  }

  /**
   * @param bool $checkPermissions
   * @return DAOGetAction
   */
  public static function get($checkPermissions = TRUE) {
    return (new DAOGetAction('LineItem', __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return DAOSaveAction
   */
  public static function save($checkPermissions = TRUE) {
    return (new DAOSaveAction(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return DAOGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new DAOGetFieldsAction('LineItem', __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return DAOCreateAction
   */
  public static function create($checkPermissions = TRUE) {
    return (new Create(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return DAOUpdateAction
   */
  public static function update($checkPermissions = TRUE) {
    return (new DAOUpdateAction(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Delete
   */
  public static function delete($checkPermissions = TRUE) {
    return (new Delete(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return BasicReplaceAction
   */
  public static function replace($checkPermissions = TRUE) {
    return (new BasicReplaceAction(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return AutocompleteAction
   */
  public static function autocomplete($checkPermissions = TRUE) {
    return (new AutocompleteAction(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

}
