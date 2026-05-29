<?php
// Angular module: afformOrder
//
// Primary Angular module for the Afform Order extension. Carries the
// "Line Item Cart" input-type templates (see Civi\AfformOrder\AfformInputTypes)
// and depends on the afFieldLineItems module that provides the cart directive
// rendered by LineItemCart.html.
//
// A form that uses the LineItemCart input type should `requires: ['afformOrder']`
// so this module (and transitively afFieldLineItems) is loaded and the
// input-type template is in the template cache.
//
// Auto-discovered by the ang-php civix mixin.
return [
  'js' => [
    'ang/afformOrder.js',
    'ang/afformOrder/*.js',
  ],
  'css' => [
    'ang/afformOrder/*.css',
  ],
  'partials' => [
    'ang/afformOrder',
  ],
  'requires' => [
    'crmUi',
    'crmUtil',
    'afFieldLineItems',
  ],
];
