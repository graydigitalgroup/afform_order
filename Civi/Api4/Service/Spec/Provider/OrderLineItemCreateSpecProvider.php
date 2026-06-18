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

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Service\Spec\RequestSpec;
use Civi\Core\Service\AutoService;

/**
 * @service
 * @internal
 */
class OrderLineItemCreateSpecProvider extends AutoService implements Generic\SpecProviderInterface {

  /**
   * @param \Civi\Api4\Service\Spec\RequestSpec $spec
   */
  public function modifySpec(RequestSpec $spec) {
    // line_total is derived from qty * unit_price in the BAO pre-hook.
    $spec->getFieldByName('line_total')->setRequired(FALSE);
    // entity_table / entity_id are defaulted from contribution_id in the BAO
    // pre-hook (a contribution line points at civicrm_contribution). Relax them
    // here so the create required-field gate accepts minimal input.
    $spec->getFieldByName('entity_table')->setRequired(FALSE);
    $spec->getFieldByName('entity_id')->setRequired(FALSE);
    // If a contribution is deleted the lineItem will still exist. But we should always have a contribution
    //   when we create a lineItem
    // @fixme: But apparently CiviCRM core disagrees :-(
    // $spec->getFieldByName('contribution_id')->setRequired(TRUE);
  }

  /**
   * @param string $entity
   * @param string $action
   *
   * @return bool
   */
  public function applies($entity, $action) {
    return $entity === 'OrderLineItem' && $action === 'create';
  }

}
