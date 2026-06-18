<?php
use CRM_AfformOrder_ExtensionUtil as E;

/**
 * Default "create order" form shipped by afform_order.
 *
 * A generic, policy-free starting point: pick a contact, build a line-item
 * cart, set the core contribution header fields, optionally make it recurring,
 * and submit through the afform_order order-create path. Useful as-is on a
 * stock install and as a copy-and-modify template for consumer extensions.
 *
 * Route: civicrm/order/create  (rename if it collides with your own routes).
 */
return [
  'type' => 'form',
  'title' => E::ts('Create Order'),
  'icon' => 'fa-plus-circle',
  'server_route' => 'civicrm/order/create',
  'permission' => [
    'access CiviContribute',
    'edit contributions',
  ],
  'permission_operator' => 'AND',
  'requires' => [
    'afformOrder',
  ],
];
