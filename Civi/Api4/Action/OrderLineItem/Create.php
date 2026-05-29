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

use Civi\Api4\Generic\DAOCreateAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Utils\CoreUtil;

/**
 * Create a new $ENTITY from supplied values.
 *
 * This action will create 1 new $ENTITY.
 * It cannot be used to update existing $ENTITIES; use the `Update` or `Replace` actions for that.
 */
class Create extends DAOCreateAction {

  protected function getBaoName() {
    return 'CRM_AfformOrder_BAO_OrderLineItem';
  }

  /**
   * @inheritDoc
   */
  public function _run(Result $result) {
    $this->formatWriteValues($this->values);
    $this->fillDefaults($this->values);
    $this->validateValues();

    $items = [$this->values];
    $result->exchangeArray($this->writeObjects($items));
  }

}
