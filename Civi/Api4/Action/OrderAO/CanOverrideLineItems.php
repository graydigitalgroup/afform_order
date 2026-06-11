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

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Report whether the current user holds the 'override afform order line items'
 * permission - the gate for the cart's per-line edit affordances (the edit
 * pencil / revert).
 *
 * Why this exists rather than the cart just calling CRM.checkPerm(): that reads
 * the clientside CRM.permissions object, which is populated only from the
 * modules loaded in a given page-load pass. When the cart is loaded
 * INCREMENTALLY - e.g. an afform opened in a SearchKit popup, injected into an
 * already-running Angular app - this module's declared permission never reaches
 * the global CRM.permissions, so CRM.checkPerm returns undefined even for a user
 * who holds it. The cart uses CRM.checkPerm as the fast path and falls back to
 * this action when the permission was not preloaded, so the affordance resolves
 * the same in every context (full page, popup, modal).
 *
 * Returns one row: { can_override: bool }.
 */
class CanOverrideLineItems extends AbstractAction {

  public function _run(Result $result): void {
    $result[] = [
      'can_override' => \CRM_Core_Permission::check('override afform order line items'),
    ];
  }

}
