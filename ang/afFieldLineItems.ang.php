<?php
// Angular module declaration for the af-field-line-items cart directive.
// Auto-discovered by the ang-php civix mixin.
//
// Consumed by the afformOrder module's LineItemCart input-type template, and
// usable standalone by any consumer extension that requires this module.
return [
  'js' => [
    'ang/afFieldLineItems.js',
    'ang/afFieldLineItems/*.js',
  ],
  'css' => [
    'ang/afFieldLineItems/*.css',
  ],
  'partials' => [
    'ang/afFieldLineItems',
  ],
  'requires' => [
    'crmUi',
    'crmUtil',
    'api4',
  ],
  // Pre-load the override permission so the cart directive can call
  // CRM.checkPerm('override afform order line items') synchronously to gate
  // the per-line edit/revert affordances.
  'permissions' => [
    'override afform order line items',
  ],
];
