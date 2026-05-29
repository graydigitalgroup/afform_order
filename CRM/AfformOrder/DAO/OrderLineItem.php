<?php

/**
 * Thin DAO wrapper that reuses core's LineItem entity provider (schema,
 * fields, primary key) under the OrderLineItem entity name. Paired with
 * CRM_AfformOrder_BAO_OrderLineItem to provide an afform_order-private
 * create surface while core PRs around Order/LineItem writes are in
 * review.
 */
class CRM_AfformOrder_DAO_OrderLineItem extends CRM_Core_DAO_Base {

  private static $_entityProviders = [];

  protected static function getEntityProvider(): \Civi\Schema\EntityProvider {
    $entityName = 'LineItem';
    self::$_entityProviders[$entityName] ??= Civi::entity($entityName);
    return self::$_entityProviders[$entityName];
  }

}
