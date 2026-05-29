<?php

namespace Civi\AfformOrder;

use Civi\Afform\FormDataModel;

/**
 * Identifies "cart-managed" Afform forms and locates their cart field.
 *
 * A form is cart-managed when it contains an af-field whose input type is
 * {@see self::INPUT_TYPE}. That field is the cart slot: a Hidden field in the
 * 'extra' pseudo-entity whose value is the array of line-item rows produced by
 * the cart directive. Detecting forms this way — rather than by a hardcoded
 * form name — is what lets any form opt in to Order submission simply by
 * dropping the cart field onto it (including via FormBuilder, once the input
 * type is registered; see {@see AfformInputTypes}).
 *
 * The submit/validate subscribers ask this class "is this one of ours, and if
 * so where is the cart?" instead of comparing form names.
 */
class CartForm {

  /**
   * The Afform input type that marks a field as a line-item cart.
   *
   * Registered with FormBuilder via {@see AfformInputTypes::onAfformInputTypes}
   * and used as the marker scanned for here.
   */
  public const INPUT_TYPE = 'LineItemCart';

  /**
   * Find the name of the cart field on a form, if any.
   *
   * Scans every entity (including the 'extra' pseudo-entity, where nameless
   * af-fields land — see FormDataModel::parseFields) for a field whose
   * definition declares our input type. Returns the first match's field name,
   * which is also the key under which the cart's row array is submitted.
   *
   * @param \Civi\Afform\FormDataModel $model
   * @return string|null
   *   The cart field name (e.g. 'line_items'), or NULL if the form has no cart.
   */
  public static function getCartFieldName(FormDataModel $model): ?string {
    foreach ($model->getEntities() as $entity) {
      foreach ($entity['fields'] ?? [] as $fieldName => $field) {
        if (($field['defn']['input_type'] ?? NULL) === self::INPUT_TYPE) {
          return $fieldName;
        }
      }
    }
    return NULL;
  }

  /**
   * Whether a form is cart-managed.
   *
   * @param \Civi\Afform\FormDataModel $model
   * @return bool
   */
  public static function isCartForm(FormDataModel $model): bool {
    return self::getCartFieldName($model) !== NULL;
  }

}
