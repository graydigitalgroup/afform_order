<?php
use CRM_AfformOrder_ExtensionUtil as E;

/**
 * Default "edit order" form shipped by afform_order.
 *
 * A generic, policy-free starting point for editing an existing order: core
 * contribution header af-fields plus the <af-order-edit-cart> line-item cart
 * host, saved atomically through the afform submit chain (OrderAO.editOrder).
 * Useful as-is on a stock install and as a copy-and-modify template for consumer
 * extensions (which can add their own policy fields / subscribers).
 *
 * Route: civicrm/order/edit#?Contribution1=<id>  (the arg is the af-entity
 * name). Rename the route if it collides with your own.
 *
 * Per-line override affordances inside the cart are additionally gated on the
 * 'override afform order line items' permission (checked by the cart itself);
 * users without it can still view the order but not override individual lines.
 */
return [
  'type' => 'form',
  'title' => E::ts('Edit Order'),
  'icon' => 'fa-pencil-square-o',
  'server_route' => 'civicrm/order/edit',
  'permission' => [
    'access CiviContribute',
    'edit contributions',
  ],
  'permission_operator' => 'AND',
  'requires' => [
    'afformOrder',
  ],
];
