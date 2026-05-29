<?php

namespace Civi\AfformOrder\Event;

use Civi\Afform\Event\AfformSubmitEvent;
use Civi\Core\Event\GenericHookEvent;

/**
 * Dispatched by {@see \Civi\AfformOrder\Submit::saveContributionFromCart}
 * immediately after Order.create returns and BEFORE the native Afform checkout
 * (which runs at priority -100) engages a payment processor.
 *
 * Event name: civi.afform_order.order_created  (see self::NAME)
 *
 * Why this seam exists: a generic post-create extension point for consumer
 * extensions that need to write to records Order.create just produced, before
 * the native checkout engages a payment processor. afform_order itself does
 * not use this seam: per-line membership work (existing-membership links,
 * explicit dates) rides through Order.create via entity_id and
 * entity_id.<field> on the line items, with entity_id.is_override = TRUE to
 * freeze status until payment completion. The seam exists for site-specific
 * concerns that don't map cleanly onto Order.create line-item fields.
 *
 * Listeners see the lineItems with their private (underscore-prefixed) keys
 * intact - these carry cart-row data that doesn't belong on Order.create
 * itself, including any private flags consumers' own AlterOrderEvent listeners
 * may have stamped on the lines. Use `price_field_value_id` to correlate a
 * carried line back to the saved entity (LineItem rows for the saved
 * contribution carry the entity id in `entity_id`).
 *
 * Read-only context:
 *  - getContributionId()  the id Order.create returned for the contribution.
 *  - getLineItems()       lineItems as submitted, *before* the safety strip
 *                         removed private keys.
 *  - getCart()            cart rows in the directive's shape.
 *  - getExtra()           form's non-entity 'extra' values (form-level
 *                         existing_membership_id, membership_* overrides, etc.).
 *  - getContribution()    the contribution values used at Order.create time.
 *  - getSubmitEvent()     the originating AfformSubmitEvent.
 *
 * This is intentionally generic: afform_order owns the create pipeline, while
 * consumer extensions own any post-create writes they need.
 *
 * Timing: dispatched at priority 0 inside saveContributionFromCart. The native
 * checkout listener runs at -100 (later), so subscribers writing entity fields
 * are always done before any payment is attempted.
 */
class OrderCreatedEvent extends GenericHookEvent {

  public const NAME = 'civi.afform_order.order_created';

  private int $contributionId;

  /**
   * @var array<int, array<string, mixed>>
   */
  private array $lineItems;

  /**
   * @var array<int, array<string, mixed>>
   */
  private array $cart;

  /**
   * @var array<string, mixed>
   */
  private array $extra;

  /**
   * @var array<string, mixed>
   */
  private array $contribution;

  private AfformSubmitEvent $submitEvent;

  /**
   * @param int $contributionId
   * @param array<int, array<string, mixed>> $lineItems
   *   The line items as built (still carrying private underscore-prefixed
   *   keys from the cart).
   * @param array<int, array<string, mixed>> $cart
   * @param array<string, mixed> $extra
   * @param array<string, mixed> $contribution
   * @param AfformSubmitEvent $submitEvent
   */
  public function __construct(
    int $contributionId,
    array $lineItems,
    array $cart,
    array $extra,
    array $contribution,
    AfformSubmitEvent $submitEvent
  ) {
    $this->contributionId = $contributionId;
    $this->lineItems = $lineItems;
    $this->cart = $cart;
    $this->extra = $extra;
    $this->contribution = $contribution;
    $this->submitEvent = $submitEvent;
  }

  public function getContributionId(): int {
    return $this->contributionId;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function getLineItems(): array {
    return $this->lineItems;
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
