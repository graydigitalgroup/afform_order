<?php

namespace Civi\AfformOrder\Event;

use Civi\Core\Event\GenericHookEvent;

/**
 * Dispatched by {@see \Civi\AfformOrder\CompanionLogic::compute()} every time
 * a cart is recomputed (cart-directive mutation, server-side submit, or any
 * other caller).
 *
 * Event name: civi.afform_order.compute_companions  (see self::NAME)
 *
 * Companion-rule "providers" subscribe to this event and append rows to the
 * cart for any drivers in scope of their rule. afform_order ships no concrete
 * companion rule; consumer extensions register providers for any shape they
 * need — membership add-ons, flat-fee surcharges, conditional bundles, etc.
 *
 * Listeners may mutate:
 *  - the cart (getCart/setCart). Auto-generated rows MUST be stamped with
 *    {@see \Civi\AfformOrder\CompanionLogic::AUTO_MARKER} so the next compute
 *    cycle strips them before re-dispatching; otherwise they accumulate.
 *    Stamping with {@see \Civi\AfformOrder\CompanionLogic::PROVIDER_KEY} is
 *    optional but recommended for audit/debugging (it records which provider
 *    produced the row without affecting orchestration).
 *
 * Exposed read-only for context:
 *  - getContext()  arbitrary key/value bag the dispatcher passes through.
 *                  Currently empty when invoked from the JS directive (which
 *                  only sends the line items via Api4 AfformOrder.computeCompanions);
 *                  the server-side submit pipeline may populate it with extras
 *                  in a future change so providers can react to form fields
 *                  such as is_recur, recur_frequency_*, or any custom extras.
 *
 * Idempotency: every compute() call strips all AUTO_MARKER rows BEFORE
 * dispatching, so providers always see a clean cart with only the operator's
 * (or another non-companion) rows in it. The implication is the cart-shape
 * each provider builds must be stable for a given input cart, otherwise the
 * directive's debounced server sync will produce flicker.
 *
 * Ordering: Symfony event priorities apply. Later-running providers (negative
 * priority) can inspect rows earlier providers appended via getCart(), but
 * the simpler model is "providers are independent and do not coordinate". Two
 * providers appending a companion for the same driver row will both produce
 * a row.
 */
class ComputeCompanionsEvent extends GenericHookEvent {

  public const NAME = 'civi.afform_order.compute_companions';

  /**
   * @var array<int, array<string, mixed>>
   */
  private array $cart;

  /**
   * @var array<string, mixed>
   */
  private array $context;

  /**
   * @param array<int, array<string, mixed>> $cart
   *   The cart with all prior AUTO_MARKER rows already stripped by the
   *   orchestrator; providers append into this.
   * @param array<string, mixed> $context
   *   Optional context bag (form extras, etc.). Empty by default.
   */
  public function __construct(array $cart, array $context = []) {
    $this->cart = $cart;
    $this->context = $context;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function getCart(): array {
    return $this->cart;
  }

  /**
   * Replace the cart wholesale. Providers that mutate driver rows (to backfill
   * _cart_id, for instance) and append companion rows typically call this
   * with the modified array.
   *
   * @param array<int, array<string, mixed>> $cart
   */
  public function setCart(array $cart): self {
    $this->cart = $cart;
    return $this;
  }

  /**
   * @return array<string, mixed>
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * Convenience accessor: read a single context value with a default.
   *
   * @param string $key
   * @param mixed $default
   * @return mixed
   */
  public function getContextValue(string $key, $default = NULL) {
    return $this->context[$key] ?? $default;
  }

}
