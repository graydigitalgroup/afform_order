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
 * Fired by OrderAO.modify BEFORE any line/financial writes, so a subscriber can
 * inspect the proposed change and veto it via addError().
 *
 * The event carries the proposed change (lines to add/remove), the current
 * contribution status, and a net-effect classification (increase/net_zero/
 * decrease). It also exposes a GENERIC metadata bag (setMetadata/getMetadata):
 * a subscriber may attach arbitrary structured data for the caller to read back
 * after a veto. afform_order defines no metadata keys and interprets none - the
 * bag is how a CONSUMER conveys an outcome (e.g. "this veto means create a
 * refund request, here is the intended change") without the engine knowing the
 * concept. OrderAO.modify relays the bag to the caller on a veto.
 *
 * Why our own event (not core's Civi\Order\Event\OrderValidateEvent):
 *   - Core's Order.Modify + OrderValidateEvent (civicrm/civicrm-core#35433) is
 *     still in review and its constructor/signature has already changed between
 *     revisions (setError vs addError/setErrors, an OrderEvent base appearing).
 *     We don't want to bind to an unstable shape.
 *   - Core's event is built around a CRM_Financial_BAO_Order object; our
 *     orchestrator works directly off the contribution + line primitives and
 *     does not construct that BAO.
 *   - We additionally carry the net-effect CLASSIFICATION (increase/net_zero/
 *     decrease), which core's event does not, and which consumers (e.g. an
 *     extension routing refund-producing edits to a refund-request workflow)
 *     actually need to decide.
 *
 * When core's event lands and stabilises, OrderAO.modify can dispatch that
 * instead (or in addition) and this class can be retired.
 *
 * Also note: core's validate event is not yet part of the save pipeline, so
 * OrderAO.modify dispatches THIS event explicitly before saving. Once core wires
 * validation into save/modify, the explicit dispatch here becomes redundant.
 */
class OrderModifyValidateEvent extends Event {

  public const EVENT_NAME = 'civi.afform_order.modify.validate';

  /**
   * Net effect classifications.
   */
  public const EFFECT_INCREASE = 'increase';
  public const EFFECT_NET_ZERO = 'net_zero';
  public const EFFECT_DECREASE = 'decrease';

  /**
   * @var int
   */
  private int $contributionID;

  /**
   * Current contribution status name (e.g. 'Completed', 'Partially paid', 'Pending').
   *
   * @var string
   */
  private string $contributionStatusName;

  /**
   * One of the EFFECT_* constants describing the net effect of the proposed
   * change on the contribution total.
   *
   * @var string
   */
  private string $netEffect;

  /**
   * Signed net change to the contribution total (new total - current total).
   * Negative = refund-producing.
   *
   * @var float
   */
  private float $netDelta;

  /**
   * The proposed line items to add (as passed to OrderAO.modify).
   *
   * @var array
   */
  private array $lineItemsToAdd;

  /**
   * The proposed line items to remove (as passed to OrderAO.modify).
   *
   * @var array
   */
  private array $lineItemsToRemove;

  /**
   * Who is invoking the modify, so a subscriber can decide whether to allow a
   * refund-producing edit. This is a COORDINATION signal, not proof of
   * authorization: a subscriber that cares must independently VERIFY the claim
   * - e.g. on context 'refundrequest', confirm an approved refund request
   * actually exists for this contribution (using contextDetail) before standing
   * down its veto. Never trust the string alone.
   *
   * Required on paid modifies (enforced by OrderAO.modify); empty is not
   * permitted there.
   *
   * @var string
   */
  private string $context;

  /**
   * Optional structured payload accompanying the context, so a subscriber can
   * verify the claim against specific records, e.g. ['activity_id' => N] or
   * ['refund_request_id' => N]. Carrying a specific id lets the subscriber
   * verify THAT request was approved rather than "any approved request exists".
   *
   * @var array
   */
  private array $contextDetail;

  /**
   * @var array
   */
  private array $errors = [];

  /**
   * Generic, engine-agnostic metadata bag. A subscriber may attach arbitrary
   * structured data here for the CALLER of OrderAO.modify (or another
   * subscriber) to read back; afform_order neither defines nor interprets any
   * keys. This is the seam by which a CONSUMER conveys structured outcomes
   * (e.g. "this veto means a refund request should be created, and here is the
   * intended change") without the engine knowing anything about that concept.
   *
   * Modelled on core's metadata-bag precedent (Civi\Order\Event\
   * OrderCompleteEvent::$params): a neutral, evolvable side channel for data
   * that isn't first-class to the event itself. Keys are namespaced by
   * convention to avoid collisions between consumers, e.g.
   * 'refund_required' => [...] set by a refund-gate subscriber.
   *
   * OrderAO.modify relays this bag to the caller on a veto (see its catch),
   * so a client can branch on a consumer's outcome without the engine
   * mediating the meaning.
   *
   * @var array
   */
  private array $metadata = [];

  public function __construct(
    int $contributionID,
    string $contributionStatusName,
    string $netEffect,
    float $netDelta,
    array $lineItemsToAdd,
    array $lineItemsToRemove,
    string $context = '',
    array $contextDetail = []
  ) {
    $this->contributionID = $contributionID;
    $this->contributionStatusName = $contributionStatusName;
    $this->netEffect = $netEffect;
    $this->netDelta = $netDelta;
    $this->lineItemsToAdd = $lineItemsToAdd;
    $this->lineItemsToRemove = $lineItemsToRemove;
    $this->context = $context;
    $this->contextDetail = $contextDetail;
  }

  public function getContributionID(): int {
    return $this->contributionID;
  }

  public function getContributionStatusName(): string {
    return $this->contributionStatusName;
  }

  public function getNetEffect(): string {
    return $this->netEffect;
  }

  public function getNetDelta(): float {
    return $this->netDelta;
  }

  public function isRefundProducing(): bool {
    return $this->netEffect === self::EFFECT_DECREASE;
  }

  public function getLineItemsToAdd(): array {
    return $this->lineItemsToAdd;
  }

  public function getLineItemsToRemove(): array {
    return $this->lineItemsToRemove;
  }

  /**
   * The caller-declared origin of this modify (e.g. 'refundrequest', 'cart_edit').
   * A COORDINATION signal only - verify before trusting (see property docblock).
   *
   * @return string
   */
  public function getContext(): string {
    return $this->context;
  }

  /**
   * Structured payload accompanying the context, for verification.
   *
   * @return array
   */
  public function getContextDetail(): array {
    return $this->contextDetail;
  }

  /**
   * A subscriber calls this to VETO the modification. Any error set here causes
   * OrderAO.modify to throw before performing any writes.
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

  /**
   * Attach a piece of generic metadata under $key. Engine-agnostic: afform_order
   * never reads or interprets these values; they are for consumers and the
   * caller of OrderAO.modify. Use a namespaced key to avoid collisions between
   * subscribers (e.g. a refund-gate subscriber uses 'refund_required').
   *
   * @param string $key
   * @param mixed $value
   * @return void
   */
  public function setMetadata(string $key, $value): void {
    $this->metadata[$key] = $value;
  }

  /**
   * Read a piece of metadata, or $default if the key was not set.
   *
   * @param string $key
   * @param mixed $default
   * @return mixed
   */
  public function getMetadata(string $key, $default = NULL) {
    return $this->metadata[$key] ?? $default;
  }

  /**
   * Whether any metadata was set under $key.
   *
   * @param string $key
   * @return bool
   */
  public function hasMetadata(string $key): bool {
    return array_key_exists($key, $this->metadata);
  }

  /**
   * The entire metadata bag. OrderAO.modify relays this to the caller on a veto.
   *
   * @return array
   */
  public function getAllMetadata(): array {
    return $this->metadata;
  }

}
