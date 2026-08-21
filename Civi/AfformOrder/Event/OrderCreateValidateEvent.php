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
 * Fired by {@see \Civi\AfformOrder\Submit} just BEFORE Order.create, so a
 * subscriber can inspect the order about to be created and veto it via
 * addError().
 *
 * This is the CREATE-path counterpart of {@see OrderModifyValidateEvent}: the
 * validate/veto seam (validate = STOP), as distinct from {@see OrderCreateEvent}
 * which is the pre-save MUTATE seam (shape, don't stop). The two validate
 * events together give a consumer one place to reject either an Order.create or
 * an OrderAO.modify that fails its own rules (e.g. a duplicate
 * price-field-value line).
 *
 * Fires AFTER the OrderCreateEvent alter seam, so it validates the FINAL,
 * companion-recomputed line set that will actually be written — a consumer can
 * never have its lines reshaped after the gate passes.
 *
 * A veto has NO metadata side-channel (unlike the modify seam, which needs one
 * to route refund-producing edits): there is no prior order state on create, so
 * a rejection is simply a hard error. When any error is set, Submit throws a
 * CRM_Core_Exception aggregating the messages and nothing is written.
 *
 * When core lands a create-time validate hook in the Order.create pipeline,
 * this explicit dispatch can be retired in the same way as OrderModifyValidateEvent.
 */
class OrderCreateValidateEvent extends Event {

  public const EVENT_NAME = 'civi.afform_order.create.validate';

  /**
   * The line items about to be created (final, post-alter, companion-recomputed).
   *
   * @var array
   */
  private array $lineItems;

  /**
   * The ContributionRecur values, or NULL for a non-recurring submission.
   *
   * @var array|null
   */
  private ?array $recurValues;

  /**
   * The contribution values about to be saved.
   *
   * @var array
   */
  private array $contribution;

  /**
   * @var array
   */
  private array $errors = [];

  public function __construct(
    array $lineItems,
    ?array $recurValues,
    array $contribution
  ) {
    $this->lineItems = $lineItems;
    $this->recurValues = $recurValues;
    $this->contribution = $contribution;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function getLineItems(): array {
    return $this->lineItems;
  }

  /**
   * @return array<string, mixed>|null
   *   NULL when the submission is not recurring.
   */
  public function getRecurValues(): ?array {
    return $this->recurValues;
  }

  public function isRecurring(): bool {
    return $this->recurValues !== NULL;
  }

  /**
   * @return array<string, mixed>
   */
  public function getContribution(): array {
    return $this->contribution;
  }

  /**
   * A subscriber calls this to VETO the create. Any error set here stops
   * Submit before Order.create runs; Submit throws a CRM_Core_Exception
   * aggregating the messages. No writes occur.
   *
   * @param string $errorMsg
   * @return void
   */
  public function addError(string $errorMsg): void {
    $this->errors[] = $errorMsg;
  }

  /**
   * @return array
   */
  public function getErrors(): array {
    return $this->errors;
  }

}
