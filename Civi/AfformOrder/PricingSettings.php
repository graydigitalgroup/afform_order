<?php

namespace Civi\AfformOrder;

use Civi\Api4\CustomField;
use Civi\Api4\PriceField;
use CRM_AfformOrder_ExtensionUtil as E;

/**
 * Pseudoconstant and form-validation callbacks for the companion pricing
 * settings.
 *
 * These settings drive the built-in, no-code companion rule (see
 * {@see CompanionLogic}): when a chosen "driver" PriceFieldValue maps to a
 * membership type, a companion line item is added whose unit_price comes from a
 * numeric custom field on that MembershipType.
 *
 * Settings governed by this class:
 *  - afform_order_pricing_driver_price_field_id
 *  - afform_order_pricing_companion_price_field_id
 *  - afform_order_pricing_companion_unit_price_field
 *
 * Defined in settings/afform_order_pricing.setting.php and surfaced on the
 * admin page at civicrm/admin/setting/afform_order_pricing.
 *
 * NOTE: the unit-price source is membership-shaped for now. Phase 2 generalises
 * companions into pluggable rule providers; this remains as the built-in rule.
 */
class PricingSettings {

  /**
   * Setting names governed by this class. Used by validateForm() to detect
   * whether a CRM_Admin_Form_Generic instance is the pricing page.
   */
  public const SETTING_DRIVER = 'afform_order_pricing_driver_price_field_id';

  public const SETTING_COMPANION = 'afform_order_pricing_companion_price_field_id';

  public const SETTING_UNIT_PRICE_FIELD = 'afform_order_pricing_companion_unit_price_field';

  /**
   * Numeric CustomField data types eligible to provide the companion's
   * unit_price.
   */
  private const NUMERIC_DATA_TYPES = ['Money', 'Float', 'Int'];

  /**
   * Pseudoconstant callback: list active PriceFields keyed by id.
   *
   * Label is formatted "PriceSet Title — PriceField Label" so the admin can
   * disambiguate identically-labelled fields across PriceSets.
   *
   * @return array<int, string>
   * @throws \CRM_Core_Exception
   */
  public static function getPriceFields(): array {
    $priceFields = PriceField::get(FALSE)
      ->addSelect('id', 'label', 'price_set_id.title')
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('price_set_id.is_active', '=', TRUE)
      ->addOrderBy('price_set_id.title', 'ASC')
      ->addOrderBy('weight', 'ASC')
      ->execute();

    $options = [];
    foreach ($priceFields as $priceField) {
      $options[(int) $priceField['id']] = sprintf(
        '%s — %s',
        $priceField['price_set_id.title'],
        $priceField['label']
      );
    }
    return $options;
  }

  /**
   * Pseudoconstant callback: list numeric custom fields on MembershipType,
   * keyed by API path (e.g. "TMPA_membership_types.POLDF").
   *
   * The API path matches the addSelect() syntax so the stored value can be fed
   * directly into MembershipType::get()->addSelect($value).
   *
   * @return array<string, string>
   * @throws \CRM_Core_Exception
   */
  public static function getMembershipTypeNumericCustomFields(): array {
    $customFields = CustomField::get(FALSE)
      ->addSelect('name', 'label', 'custom_group_id.name', 'custom_group_id.title')
      ->addWhere('custom_group_id.extends', '=', 'MembershipType')
      ->addWhere('custom_group_id.is_active', '=', TRUE)
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('data_type', 'IN', self::NUMERIC_DATA_TYPES)
      ->addOrderBy('custom_group_id.title', 'ASC')
      ->addOrderBy('weight', 'ASC')
      ->execute();

    $options = [];
    foreach ($customFields as $customField) {
      $apiPath = sprintf(
        '%s.%s',
        $customField['custom_group_id.name'],
        $customField['name']
      );
      $options[$apiPath] = sprintf(
        '%s — %s',
        $customField['custom_group_id.title'],
        $customField['label']
      );
    }
    return $options;
  }

  /**
   * Detect whether a given CRM_Admin_Form_Generic instance is the pricing
   * settings page.
   *
   * The generic admin form is shared across many settings pages, so we key
   * off the presence of our setting names in the submitted fields.
   *
   * @param array $fields
   * @return bool
   */
  public static function isPricingForm(array $fields): bool {
    return array_key_exists(self::SETTING_DRIVER, $fields)
      || array_key_exists(self::SETTING_COMPANION, $fields)
      || array_key_exists(self::SETTING_UNIT_PRICE_FIELD, $fields);
  }

  /**
   * hook_civicrm_validateForm handler for the pricing settings page.
   *
   * Cross-field validation lives here (per-setting "must be an active
   * PriceField" is already enforced by the pseudoconstant dropdown).
   *
   * Current rules:
   *  - Driver and companion must not be the same PriceField.
   *
   * @param array $fields
   *   Submitted form values.
   * @param array $errors
   *   Reference to the form's errors array.
   */
  public static function validateForm(array $fields, array &$errors): void {
    $driver = (int) ($fields[self::SETTING_DRIVER] ?? 0);
    $companion = (int) ($fields[self::SETTING_COMPANION] ?? 0);

    if ($driver && $companion && $driver === $companion) {
      $message = E::ts('The driver and companion PriceFields must be different.');
      $errors[self::SETTING_DRIVER] = $message;
      $errors[self::SETTING_COMPANION] = $message;
    }
  }

}
