<?php

/**
 * @file
 * Afform Order — editable line-item cart for Afform, with companion line items
 * and Order submission.
 *
 * Most behaviour lives in auto-discovered services under Civi\AfformOrder\
 * (scan-classes mixin), not in hooks here. This file is the civix bootstrap
 * shell.
 */

require_once 'afform_order.civix.php';

use CRM_AfformOrder_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function afform_order_civicrm_config(&$config): void {
  _afform_order_civix_civicrm_config($config);

  if (isset(Civi::$statics[__FUNCTION__])) {
    return;
  }
  Civi::$statics[__FUNCTION__] = 1;
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function afform_order_civicrm_install(): void {
  _afform_order_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function afform_order_civicrm_enable(): void {
  _afform_order_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_permission().
 *
 * Declares the override permission gating per-line editing affordances (edit
 * pencil, revert) on cart-managed forms. The cart directive checks for it via
 * CRM.checkPerm() and hides the controls when absent. Most staff roles can
 * have it; restricting it provides oversight where warranted.
 */
function afform_order_civicrm_permission(array &$permissions): void {
  $permissions['override afform order line items'] = [
    'label' => E::ts('Afform Order: override line items'),
    'description' => E::ts('Edit per-line fields on cart-managed Afform order forms (label, membership terms, dates, etc.). Without this, staff can still add and remove line items but cannot modify derived values.'),
  ];
}
