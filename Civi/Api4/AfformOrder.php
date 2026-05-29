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

/**
 * Afform Order — companion line item computation and (later) order editing.
 *
 * @searchable none
 * @package Civi\Api4
 */
class AfformOrder extends Generic\AbstractEntity {

  /**
   * Recompute companion line items for a cart.
   *
   * @param bool $checkPermissions
   * @return Action\AfformOrder\ComputeCompanions
   */
  public static function computeCompanions($checkPermissions = TRUE) {
    return (new Action\AfformOrder\ComputeCompanions(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new Generic\BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }

}
