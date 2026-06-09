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

namespace Civi\AfformOrder\Event;

use Civi\Core\Event\GenericHookEvent;

/**
 * PRE-SAVE (modify) — mutate the lines being added to an existing order, here.
 *
 * Dispatched by {@see \Civi\Api4\Action\OrderAO\Modify} just before it writes
 * the queued line additions, so a consumer can shape them the same way an
 * OrderCreateEvent listener shapes a create (e.g. recompute a membership line's
 * term count from its quantity). The create-path counterpart is
 * {@see OrderCreateEvent}; they are deliberately DISTINCT events (not one
 * action-discriminated event) so a create subscriber never receives a modify
 * payload. A consumer that needs the same rule on both factors it into a
 * callable and binds it to both events. See HANDOFF-DECISIONS "Order lifecycle
 * event family".
 *
 * DELTA-SHAPED, by design: this is the modify path, so getLineItems() carries
 * only the lines being ADDED/changed in this call (the delta), not the order's
 * full resulting line set. This mirrors how {@see OrderModifyValidateEvent}
 * carries the add/remove sets rather than a whole-order snapshot — modify
 * reasons about the change, not the end state. Removals are NOT exposed here:
 * a paid-line removal is realised as a reversal line built internally with
 * exact negated amounts (reverseLine), which a consumer must not reshape; a
 * pending removal is a plain delete with nothing to mutate.
 *
 * SCOPE + ORDER: fires on EVERY modify path (Pending, template, paid) over the
 * lines being added. Where a validate (veto) seam exists — the paid path's
 * {@see OrderModifyValidateEvent} — this alter seam fires only AFTER the veto
 * passes, never before: a consumer must not be able to reshape lines that the
 * gate then rejects. The Pending/template path has no validate seam, so the
 * alter seam simply runs before the restructure. isTemplate() lets a subscriber
 * branch (e.g. apply a future-installment-only rule).
 *
 * Event name: civi.afform_order.modify  (see self::NAME)
 *
 * Listeners may mutate:
 *  - the lines being added (getLineItems/setLineItems).
 *
 * Read-only context:
 *  - getContributionID()  the order under modification.
 *  - isTemplate()         TRUE when that contribution is a recurring series'
 *                         template (is_template = 1).
 */
class OrderModifyEvent extends GenericHookEvent {

  public const NAME = 'civi.afform_order.modify';

  private int $contributionID;

  private array $lineItems;

  private bool $isTemplate;

  public function __construct(int $contributionID, array $lineItems, bool $isTemplate) {
    $this->contributionID = $contributionID;
    $this->lineItems = $lineItems;
    $this->isTemplate = $isTemplate;
  }

  public function getContributionID(): int {
    return $this->contributionID;
  }

  /**
   * @return array<int, array<string, mixed>>
   *   The lines being added in this modify call (the delta), each a line spec
   *   suitable for OrderLineItem::create.
   */
  public function getLineItems(): array {
    return $this->lineItems;
  }

  /**
   * @param array<int, array<string, mixed>> $lineItems
   */
  public function setLineItems(array $lineItems): self {
    $this->lineItems = $lineItems;
    return $this;
  }

  public function isTemplate(): bool {
    return $this->isTemplate;
  }

}
