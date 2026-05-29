<?php

namespace Civi\AfformOrder;

use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoService;
use CRM_AfformOrder_ExtensionUtil as E;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Registers the "Line Item Cart" Afform input type.
 *
 * This mirrors how civi_contribute registers its "CheckoutBlock" input type
 * (Civi\Checkout\Afform::onAfformInputTypes). Registering an input type does
 * two things:
 *
 *  1. It makes the cart selectable in FormBuilder as a field input type, so a
 *     form author can drop a "Line Item Cart" field onto any form.
 *  2. It marks the field in the form's data model with
 *     input_type = {@see CartForm::INPUT_TYPE}, which is the signal the
 *     submit/validate subscribers scan for (via {@see CartForm}) to decide a
 *     form is cart-managed — no hardcoded form names.
 *
 * The referenced templates live in the `afformOrder` Angular module
 * (ang/afformOrder/). The runtime template renders the cart; the admin
 * template is what FormBuilder shows in design mode.
 *
 * @service afform_order.input_types
 */
class AfformInputTypes extends AutoService implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      'civi.afform.input_types' => ['onAfformInputTypes'],
    ];
  }

  public function onAfformInputTypes(GenericHookEvent $e): void {
    $e->inputTypes[CartForm::INPUT_TYPE] = [
      'label' => E::ts('Line Item Cart'),
      'template' => '~/afformOrder/LineItemCart.html',
      'admin_template' => '~/afformOrder/LineItemCartAdmin.html',
      // REQUIRED: AfformMetadataInjector::fillExtraFieldMetadata() does
      //   setFieldMetadata($afField, $typeInfo['extra_defn'])
      // with no null-guard, so an 'extra' field using this input type fatals
      // on cores that lack the fillExtraFieldMetadata robustness fix unless we
      // ship an extra_defn. Mirrors the built-in Text/Email/etc. types.
      // data_type is otherwise irrelevant here: the cart value (an array of
      // rows) is written to the form data model directly by the directive,
      // bypassing afField's value getters/setters.
      'extra_defn' => [
        'data_type' => 'String',
      ],
    ];
  }

}
