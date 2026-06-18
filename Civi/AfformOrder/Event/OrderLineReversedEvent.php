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
 * Fired by OrderAO.modify immediately AFTER a paid line has been reversed -
 * i.e. after the negative-unit_price reversal line (and its FinancialItem) has
 * been created beside the original. Carries the link between the original line
 * and the reversal line that backs it out.
 *
 * Why this seam exists: the reversal line records the financial back-out, but
 * nothing in the line/FinancialItem schema records WHICH original a given
 * reversal reverses (the reversal copies the original's price_field_value_id
 * and negates the amount, but holds no explicit back-reference). After several
 * edits over time the ledger is a sum of many legitimate reversals from which
 * "is this specific line already reversed?" cannot be reconstructed. See the
 * README known-limitation on reversal provenance.
 *
 * This event hands a listener the exact pairing at the one moment it is known
 * for certain - right after creation - so an install can persist that provenance
 * however it wishes (e.g. an Activity recording original + reversal line ids, a
 * custom field, or - eventually - an explicit reverses-line-item column). The
 * generic engine ships NO listener: afform_order itself records nothing extra.
 * Recording policy belongs to the consumer extension.
 *
 * This is informational, not vetoable: the reversal has already happened by the
 * time it fires. A listener that throws will abort the surrounding transaction
 * (OrderAO.modify wraps the whole restructure in CRM_Core_Transaction::run), so
 * listeners should record, not validate.
 */
class OrderLineReversedEvent extends Event {

  public const EVENT_NAME = 'civi.afform_order.line_reversed';

  /**
   * The contribution whose line was reversed.
   *
   * @var int
   */
  private int $contributionID;

  /**
   * The id of the ORIGINAL line item that was backed out.
   *
   * @var int
   */
  private int $originalLineItemID;

  /**
   * The id of the REVERSAL line item just created (negated copy of the
   * original).
   *
   * @var int
   */
  private int $reversalLineItemID;

  /**
   * The signed line_total of the reversal line (negative). The original's
   * line_total is the negation of this.
   *
   * @var float
   */
  private float $reversalLineTotal;

  /**
   * @param int $contributionID
   * @param int $originalLineItemID
   * @param int $reversalLineItemID
   * @param float $reversalLineTotal
   */
  public function __construct(
    int $contributionID,
    int $originalLineItemID,
    int $reversalLineItemID,
    float $reversalLineTotal
  ) {
    $this->contributionID = $contributionID;
    $this->originalLineItemID = $originalLineItemID;
    $this->reversalLineItemID = $reversalLineItemID;
    $this->reversalLineTotal = $reversalLineTotal;
  }

  public function getContributionID(): int {
    return $this->contributionID;
  }

  public function getOriginalLineItemID(): int {
    return $this->originalLineItemID;
  }

  public function getReversalLineItemID(): int {
    return $this->reversalLineItemID;
  }

  public function getReversalLineTotal(): float {
    return $this->reversalLineTotal;
  }

}
