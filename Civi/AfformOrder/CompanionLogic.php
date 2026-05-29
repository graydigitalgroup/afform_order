<?php

namespace Civi\AfformOrder;

use Civi\AfformOrder\Event\ComputeCompanionsEvent;

/**
 * Companion line-item orchestrator.
 *
 * afform_order treats companion generation as a pluggable concern: any number
 * of "providers" can register a rule that appends companion line items to the
 * cart for the drivers it cares about. This class is the dispatch surface in
 * front of those providers.
 *
 * Architecture:
 *
 *  CompanionLogic::compute($cart)
 *      |
 *      |  1. Partition the AUTO_MARKER rows:
 *      |       - an auto row flagged OVERRIDE_FLAG (staff manually edited it)
 *      |         is PRESERVED as long as its driver (the row whose _cart_id
 *      |         equals the companion's _companion_for) is still present, and
 *      |         DROPPED when that driver is gone (cascade). It is not
 *      |         regenerated, so the staff edit survives every recompute.
 *      |       - any other auto row is STRIPPED and left for the provider to
 *      |         rebuild. Non-auto rows are always kept.
 *      |
 *      |  2. Dispatch ComputeCompanionsEvent. Each subscribed provider
 *      |     inspects the cart, appends rows for any drivers in its scope,
 *      |     and tags those rows with AUTO_MARKER so the next compute()
 *      |     cycle strips them. Providers MUST skip drivers that already have
 *      |     a companion in the cart (a preserved override), so an override
 *      |     is never duplicated.
 *      |
 *      v
 *  Returns the resulting cart.
 *
 * Idempotency: calling compute() repeatedly on the same input cart yields
 * the same output, provided every provider is itself idempotent. The cart
 * directive relies on this — every cart mutation triggers a debounced
 * compute(), and the result must be stable when nothing relevant has
 * changed.
 *
 * afform_order ships no concrete companion rule. Concrete shapes (membership
 * add-ons, flat-fee surcharges, conditional bundles, etc.) live in consumer
 * extensions as their own ComputeCompanionsEvent subscribers — see README.md
 * "Extension points / Companion providers" for the registration pattern.
 *
 * Constants exported to providers and the JS directive:
 *  - {@see self::AUTO_MARKER}    boolean flag stamped on every auto-generated
 *                                row; the orchestrator strips on this marker
 *                                and the directive uses it to lock qty /
 *                                unit-price editing on those rows.
 *  - {@see self::PROVIDER_KEY}   optional string identifying which provider
 *                                produced the row, for audit/debugging only;
 *                                the orchestrator never reads it.
 */
class CompanionLogic {

  /**
   * Marker key stamped on auto-generated companion rows so the orchestrator
   * can identify and strip them at the start of each compute() pass.
   *
   * Providers MUST stamp this on every row they generate; otherwise their
   * rows will accumulate across compute() calls.
   *
   * Kept in sync with the JS directive's AUTO_MARKER constant (see
   * ang/afFieldLineItems/afFieldLineItems.component.js).
   */
  public const AUTO_MARKER = '_afform_order_companion';

  /**
   * Optional row key naming the provider that produced the auto row.
   * Provider-defined strings (typically `<extension>.<rule>`).
   *
   * The orchestrator never reads this; it exists for audit, debugging, and
   * to let advanced subscribers reason about cross-provider interactions.
   */
  public const PROVIDER_KEY = '_afform_order_companion_provider';

  /**
   * Boolean row flag meaning "staff manually edited this line." On a companion
   * (AUTO_MARKER) row it stops the orchestrator from regenerating the row, so
   * the edit survives recompute; the row is still cascade-removed when its
   * driver is gone. On a non-companion row it is informational only (drives
   * the "edited" highlight) — those rows are never regenerated anyway.
   *
   * Distinct from a membership entity's own `is_override` (the status freeze);
   * this is a cart-row marker and is never passed through to Order.create.
   *
   * Kept in sync with the JS directive's OVERRIDE_FLAG constant (see
   * ang/afFieldLineItems/afFieldLineItems.component.js).
   */
  public const OVERRIDE_FLAG = '_is_override';

  /**
   * Recompute companion line items for a cart.
   *
   * Strips every previously-auto-generated row, dispatches
   * {@see ComputeCompanionsEvent} so providers can append their rows, and
   * returns the resulting cart.
   *
   * Safe to call with no providers registered: returns the cart with all
   * AUTO_MARKER rows stripped and nothing added (equivalent to "companions
   * disabled").
   *
   * @param array<int, array<string, mixed>> $cart
   * @param array<string, mixed> $context
   *   Optional context bag forwarded to providers. The JS directive currently
   *   passes nothing; the submit pipeline may populate it with form extras in
   *   a future change.
   * @return array<int, array<string, mixed>>
   */
  public static function compute(array $cart, array $context = []): array {
    // Index the _cart_id of every non-companion row so we can tell whether a
    // preserved override's driver still exists.
    $presentCartIds = [];
    foreach ($cart as $row) {
      if (empty($row[self::AUTO_MARKER]) && !empty($row['_cart_id'])) {
        $presentCartIds[$row['_cart_id']] = TRUE;
      }
    }

    // Partition: keep non-auto rows and preserved overrides (whose driver is
    // still present); drop everything else auto-generated so the provider can
    // rebuild it. A preserved override keeps its AUTO_MARKER and _companion_for
    // so the provider's skip-guard recognises it and does not duplicate it.
    $kept = [];
    foreach ($cart as $row) {
      if (empty($row[self::AUTO_MARKER])) {
        $kept[] = $row;
        continue;
      }
      if (!empty($row[self::OVERRIDE_FLAG])) {
        $driverId = $row['_companion_for'] ?? NULL;
        if ($driverId !== NULL && isset($presentCartIds[$driverId])) {
          $kept[] = $row;
        }
        // else: orphaned override — driver removed — drop (cascade).
        continue;
      }
      // Non-overridden auto row: drop; the provider regenerates it.
    }

    $event = new ComputeCompanionsEvent(array_values($kept), $context);
    \Civi::dispatcher()->dispatch(ComputeCompanionsEvent::NAME, $event);

    return $event->getCart();
  }

}
