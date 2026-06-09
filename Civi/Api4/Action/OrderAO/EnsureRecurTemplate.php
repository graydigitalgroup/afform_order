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

namespace Civi\Api4\Action\OrderAO;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Resolve - creating it if necessary - the template contribution for a
 * recurring contribution, returning its id.
 *
 * Thin APIv4 wrapper around core's
 * CRM_Contribute_BAO_ContributionRecur::ensureTemplateContributionExists(),
 * which is BAO-only (core exposes no API to merely ensure/resolve a template;
 * ContributionRecur.updateAmountOnRecur ensures one but is amount-driven - the
 * inverse of a line-driven edit). The template contribution (is_template = 1,
 * status 'Template') is the editable definition of the series' FUTURE
 * installments: point OrderAO.modify at the returned id to change them.
 *
 * Deliberately LAZY: the template is materialized at the moment a caller asks
 * for it (e.g. staff opting in to a "future installments" edit scope), never
 * speculatively at form load. For an older series with no template yet, core
 * derives one from the most recent installment - which is exactly the state
 * the edit should start from.
 *
 * @method $this setContributionRecurID(int $contributionRecurID)
 * @method int getContributionRecurID()
 */
class EnsureRecurTemplate extends AbstractAction {

  /**
   * The ID of the ContributionRecur whose template contribution to resolve.
   *
   * @var int
   * @required
   */
  protected ?int $contributionRecurID = NULL;

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    if (empty($this->contributionRecurID)) {
      throw new \CRM_Core_Exception('Order ensureRecurTemplate: contributionRecurID is required');
    }

    $recur = ContributionRecur::get(FALSE)
      ->addSelect('id')
      ->addWhere('id', '=', $this->contributionRecurID)
      ->execute()
      ->first();
    if (empty($recur)) {
      throw new \CRM_Core_Exception(
        'Order ensureRecurTemplate: recurring contribution ' . $this->contributionRecurID . ' not found'
      );
    }

    $templateContributionID = \CRM_Contribute_BAO_ContributionRecur::ensureTemplateContributionExists(
      $this->contributionRecurID
    );
    // NULL means the series has no contribution at all to derive a template
    // from - nothing meaningful to edit. Surface that rather than returning
    // an empty row a caller would have to special-case.
    if (!$templateContributionID) {
      throw new \CRM_Core_Exception(
        'Order ensureRecurTemplate: recurring contribution ' . $this->contributionRecurID .
        ' has no contributions to derive a template from'
      );
    }

    $result[] = [
      'contribution_recur_id' => $this->contributionRecurID,
      'template_contribution_id' => (int) $templateContributionID,
    ];
  }

}