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
 * Fired by the afform submit edit branch ({@see \Civi\AfformOrder\Submit}) when
 * OrderAO.editOrder DECLINED to apply an edit and relayed neutral metadata
 * instead of writing - i.e. a validate subscriber vetoed-with-metadata (e.g. a
 * consumer routing a refund-producing edit elsewhere).
 *
 * It is the consumer seam for a routed edit: afform_order names no metadata keys
 * and attaches no domain meaning. A consumer extension subscribes, interprets
 * its own metadata keys (e.g. acts on the routed change), and may set a
 * completion message and/or a redirect for afform_order to surface on the afform
 * submission response.
 *
 * Carries the change that was NOT applied (so a consumer can build whatever it
 * routes to from it) and the verbatim validate metadata bag.
 */
class OrderEditRoutedEvent extends Event {

  public const EVENT_NAME = 'civi.afform_order.order_edit_routed';

  private int $contributionID;
  private array $lineItemsToAdd;
  private array $lineItemsToRemove;
  private array $validateMetadata;

  /**
   * Optional completion message (may contain markup) for the submission
   * response; afform_order forwards it as response 'message'. Empty = none.
   *
   * @var string|null
   */
  private ?string $message = NULL;

  /**
   * Optional redirect URL for the submission response; afform_order forwards it
   * as response 'redirect'. Empty = none.
   *
   * @var string|null
   */
  private ?string $redirect = NULL;

  public function __construct(int $contributionID, array $lineItemsToAdd, array $lineItemsToRemove, array $validateMetadata) {
    $this->contributionID = $contributionID;
    $this->lineItemsToAdd = $lineItemsToAdd;
    $this->lineItemsToRemove = $lineItemsToRemove;
    $this->validateMetadata = $validateMetadata;
  }

  public function getContributionID(): int {
    return $this->contributionID;
  }

  public function getLineItemsToAdd(): array {
    return $this->lineItemsToAdd;
  }

  public function getLineItemsToRemove(): array {
    return $this->lineItemsToRemove;
  }

  /**
   * The verbatim metadata bag a validate subscriber attached (consumer-owned
   * keys; afform_order interprets none of it).
   */
  public function getValidateMetadata(): array {
    return $this->validateMetadata;
  }

  public function setMessage(?string $message): void {
    $this->message = $message;
  }

  public function getMessage(): ?string {
    return $this->message;
  }

  public function setRedirect(?string $redirect): void {
    $this->redirect = $redirect;
  }

  public function getRedirect(): ?string {
    return $this->redirect;
  }

}
