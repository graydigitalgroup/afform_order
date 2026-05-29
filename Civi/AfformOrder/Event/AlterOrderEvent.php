<?php

namespace Civi\AfformOrder\Event;

use Civi\Afform\Event\AfformSubmitEvent;
use Civi\Core\Event\GenericHookEvent;

/**
 * Dispatched by {@see \Civi\AfformOrder\Submit::saveContributionFromCart}
 * immediately before Order.create, so other extensions can adjust the order
 * built from a LineItemCart form without having to reimplement the submit
 * pipeline.
 *
 * Event name: civi.afform_order.alter_order  (see self::NAME)
 *
 * Listeners may mutate:
 *  - the line items (getLineItems/setLineItems), and
 *  - the ContributionRecur values (getRecurValues/setRecurValues), which are
 *    NULL for a non-recurring submission.
 *
 * Exposed read-only for context:
 *  - getCart()         the (companion-recomputed) cart rows
 *  - getExtra()        the form's non-entity 'extra' field values, including
 *                      recur inputs (is_recur, recur_frequency_unit,
 *                      recur_frequency_interval, recur_installments) and any
 *                      membership_* overrides
 *  - getContribution() the contribution values about to be saved
 *  - getSubmitEvent()  the originating AfformSubmitEvent (form, data model, …)
 *
 * This is intentionally generic: afform_order owns the order-building
 * mechanism, while site/consumer extensions own business rules. For example,
 * a consumer extension might listen for this event to scale a line item's qty
 * to the number of periods covered by the recurring frequency (quarterly => 3
 * months, annual => 12 months) — logic specific to how it prices memberships
 * and therefore not in this extension.
 */
class AlterOrderEvent extends GenericHookEvent {

  public const NAME = 'civi.afform_order.alter_order';

  private array $lineItems;

  private ?array $recurValues;

  private array $cart;

  private array $extra;

  private array $contribution;

  private AfformSubmitEvent $submitEvent;

  public function __construct(
    array $lineItems,
    ?array $recurValues,
    array $cart,
    array $extra,
    array $contribution,
    AfformSubmitEvent $submitEvent
  ) {
    $this->lineItems = $lineItems;
    $this->recurValues = $recurValues;
    $this->cart = $cart;
    $this->extra = $extra;
    $this->contribution = $contribution;
    $this->submitEvent = $submitEvent;
  }

  /**
   * @return array<int, array<string, mixed>>
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

  /**
   * @return array<string, mixed>|null
   *   NULL when the submission is not recurring.
   */
  public function getRecurValues(): ?array {
    return $this->recurValues;
  }

  /**
   * @param array<string, mixed>|null $recurValues
   */
  public function setRecurValues(?array $recurValues): self {
    $this->recurValues = $recurValues;
    return $this;
  }

  /**
   * Whether this submission creates a ContributionRecur.
   */
  public function isRecurring(): bool {
    return $this->recurValues !== NULL;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function getCart(): array {
    return $this->cart;
  }

  /**
   * @return array<string, mixed>
   */
  public function getExtra(): array {
    return $this->extra;
  }

  /**
   * @return array<string, mixed>
   */
  public function getContribution(): array {
    return $this->contribution;
  }

  public function getSubmitEvent(): AfformSubmitEvent {
    return $this->submitEvent;
  }

}
