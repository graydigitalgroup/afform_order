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
 * Fired by OrderAO.modify once the line-item restructure is complete (inside the
 * restructure transaction, so a listener's writes commit/roll back with it).
 *
 * Carries the SHAPE of the change so a consumer can react to the end result -
 * which lines were added, which were removed, and (for a line CORRECTION) the
 * old->new pairing. afform_order attaches no meaning to any of this and ships no
 * listener; it is provenance for consumers (e.g. re-establishing a soft credit
 * on the replacement line). The pre-save counterpart is {@see OrderModifyEvent}
 * (fires BEFORE the writes, lets a consumer snapshot or reshape); this is the
 * AFTER seam.
 *
 * The replacements map is populated from the `_replaces_line_item_id` provenance
 * the cart puts on a corrected (edited) line's add spec: the line being added
 * carries the id of the loaded line it replaces, so the engine can report
 * old->new even though it models a correction as a remove + add pair. A pure add
 * (no provenance) appears only in addedLineItemIDs; a pure removal only in
 * removedLineItemIDs.
 *
 * NOTE the pending-vs-paid asymmetry a consumer must account for: on the Pending
 * path a removed line is DELETED (it and its dependent rows are gone by the time
 * this fires - snapshot anything you need from it in OrderModifyEvent, which
 * fires first); on the paid path a removed line is REVERSED (the original
 * survives, readable here).
 *
 * Informational, not vetoable: the restructure has already happened. A listener
 * that throws aborts the surrounding transaction, so listeners should record /
 * sync, not validate.
 *
 * Event name: civi.afform_order.modified  (see self::EVENT_NAME)
 */
class OrderModifiedEvent extends Event {

  public const EVENT_NAME = 'civi.afform_order.modified';

  /**
   * The contribution whose lines were modified.
   *
   * @var int
   */
  private int $contributionID;

  /**
   * Ids of the line items created in this modify (corrected re-adds + pure adds).
   *
   * @var int[]
   */
  private array $addedLineItemIDs;

  /**
   * Ids of the line items the caller asked to remove (deleted on the Pending
   * path; the originals that were reversed on the paid path).
   *
   * @var int[]
   */
  private array $removedLineItemIDs;

  /**
   * Map of [removedLineItemID => addedLineItemID] for lines that were a
   * correction (removed-and-re-added as one logical edit). Derived from the
   * `_replaces_line_item_id` provenance on the add specs.
   *
   * @var array<int, int>
   */
  private array $replacements;

  /**
   * TRUE when the modified contribution is a recurring series' template.
   *
   * @var bool
   */
  private bool $isTemplate;

  /**
   * @param int $contributionID
   * @param int[] $addedLineItemIDs
   * @param int[] $removedLineItemIDs
   * @param array<int, int> $replacements
   * @param bool $isTemplate
   */
  public function __construct(
    int $contributionID,
    array $addedLineItemIDs,
    array $removedLineItemIDs,
    array $replacements,
    bool $isTemplate
  ) {
    $this->contributionID = $contributionID;
    $this->addedLineItemIDs = $addedLineItemIDs;
    $this->removedLineItemIDs = $removedLineItemIDs;
    $this->replacements = $replacements;
    $this->isTemplate = $isTemplate;
  }

  public function getContributionID(): int {
    return $this->contributionID;
  }

  /**
   * @return int[]
   */
  public function getAddedLineItemIDs(): array {
    return $this->addedLineItemIDs;
  }

  /**
   * @return int[]
   */
  public function getRemovedLineItemIDs(): array {
    return $this->removedLineItemIDs;
  }

  /**
   * @return array<int, int> [removedLineItemID => addedLineItemID]
   */
  public function getReplacements(): array {
    return $this->replacements;
  }

  public function isTemplate(): bool {
    return $this->isTemplate;
  }

}
