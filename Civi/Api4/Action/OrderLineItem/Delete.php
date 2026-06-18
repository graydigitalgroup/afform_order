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

namespace Civi\Api4\Action\OrderLineItem;

use Civi\Api4\Generic\DAODeleteAction;
use Civi\Api4\Generic\Result;

/**
 * Delete one or more LineItems, tearing down their FinancialItems.
 *
 * Routes each matched record through CRM_AfformOrder_BAO_OrderLineItem::deleteRecord
 * so the FinancialItem teardown is owned by the BAO (consistent with how Create
 * routes through writeRecord). Only Pending (Unpaid) lines may be deleted; a line
 * with Paid / Partially paid FinancialItems will cause the BAO to throw, because
 * its financial history must be reversed (negative-unit_price line) rather than
 * destroyed.
 *
 * Gated by the 'edit contributions' permission (see OrderLineItem::permissions).
 */
class Delete extends DAODeleteAction {

  protected function getBaoName() {
    return 'CRM_AfformOrder_BAO_OrderLineItem';
  }

  /**
   * @inheritDoc
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    // Resolve the records to delete from the supplied where clause.
    $items = $this->getBatchRecords();

    if ($this->getCheckPermissions()) {
      // Defence in depth: the entity already gates 'delete' on
      // 'edit contributions', but enforce here too in case the action is
      // invoked directly.
      if (!\CRM_Core_Permission::check('edit contributions')) {
        throw new \Civi\API\Exception\UnauthorizedException('Deleting an OrderLineItem requires the "edit contributions" permission');
      }
    }

    $deleted = [];
    foreach ($items as $item) {
      \CRM_AfformOrder_BAO_OrderLineItem::deleteRecord(['id' => $item['id']]);
      $deleted[] = ['id' => $item['id']];
    }

    $result->exchangeArray($deleted);
  }

}
