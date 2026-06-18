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

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired by OrderAO.ensureRecurTemplate when it MATERIALIZES a new template
 * contribution for a recurring series (NOT when it merely resolves an existing
 * one). Core derives the template by copying the most recent installment's line
 * items, which may include lines a consumer does not want to recur (e.g. a
 * one-off suspense/placeholder line or its allocations). This seam hands a
 * consumer the new template id at the one moment it was just created, so it can
 * prune or adjust those lines.
 *
 * afform_order ships no listener and attaches no meaning; pruning policy lives
 * in the consumer. Informational (the template already exists by the time this
 * fires); a listener should adjust, not veto.
 *
 * Event name: civi.afform_order.recur_template_created (see self::EVENT_NAME)
 */
class OrderRecurTemplateCreatedEvent extends Event {

  public const EVENT_NAME = 'civi.afform_order.recur_template_created';

  /**
   * The ContributionRecur whose template was created.
   *
   * @var int
   */
  private int $contributionRecurID;

  /**
   * The newly created template contribution.
   *
   * @var int
   */
  private int $templateContributionID;

  /**
   * @param int $contributionRecurID
   * @param int $templateContributionID
   */
  public function __construct(int $contributionRecurID, int $templateContributionID) {
    $this->contributionRecurID = $contributionRecurID;
    $this->templateContributionID = $templateContributionID;
  }

  public function getContributionRecurID(): int {
    return $this->contributionRecurID;
  }

  public function getTemplateContributionID(): int {
    return $this->templateContributionID;
  }

}
