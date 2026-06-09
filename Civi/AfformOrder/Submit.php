<?php

namespace Civi\AfformOrder;

use Civi\Afform\Event\AfformSubmitEvent;
use Civi\Afform\Event\AfformValidateEvent;
use Civi\AfformOrder\Event\OrderCreateEvent;
use Civi\AfformOrder\Event\OrderCreatedEvent;
use Civi\Core\Service\AutoSubscriber;
use CRM_AfformOrder_ExtensionUtil as E;

/**
 * Submit handler for cart-managed Afform forms.
 *
 * The civi_contribute extension ships a native Afform -> Order pipeline
 * (Civi\Contribute\Service\CreateContribution) that builds line items by
 * reading price-field values stored as fields on the form's entities. A
 * cart-managed form instead carries an editable cart with auto-computed
 * companion lines in an 'extra' field (rendered by the LineItemCart input type
 * / af-field-line-items directive, computed by Civi\AfformOrder\CompanionLogic),
 * which that native pipeline never reads — so it reports "No line items for
 * creating contribution".
 *
 * This subscriber implements "Option A": keep the cart, take over the
 * line-item/Order half for cart-managed forms only, and leave the native
 * checkout (Civi\Checkout\Afform) in place to process payment.
 *
 * A form is recognised as cart-managed by {@see CartForm} — it contains a field
 * whose input type is LineItemCart — rather than by a hardcoded form name, so
 * any form that drops in the cart field is handled automatically.
 *
 * Event wiring (Symfony dispatcher: higher priority runs earlier):
 *  - civi.afform.validate @ 2000  standDownNativeContribution()
 *      Disables CreateContribution for this request (it exposes setActive()).
 *      2000 beats CreateContribution's own validate listeners (1000 / 101),
 *      so neither its form-model check nor its "no line items" error fire.
 *      Services are per-request, so other native contribution forms in other
 *      requests are unaffected.
 *  - civi.afform.submit @ 0  saveContributionFromCart()
 *      Reads the cart, recomputes companions server-side (defence in depth),
 *      builds Order line items, creates the ContributionRecur when requested,
 *      runs Order.create, and records the new contribution id via
 *      setEntityId(0, ...).
 *  - Civi\Checkout\Afform::startCheckout @ -100 (native, untouched)
 *      Runs AFTER us and reads checkout_option / checkout_params off the saved
 *      contribution to process payment. Empty checkout_option short-circuits it
 *      (pay-later / manual invoice).
 *
 * Optional 'extra' fields read by convention (all optional; absent => skipped):
 *  - is_recur (bool) + recur_frequency_unit / recur_frequency_interval /
 *    recur_installments — create a linked ContributionRecur.
 *  - existing_membership_id — link membership lines to an existing membership
 *    instead of creating a new one.
 *  - membership_start_date / membership_end_date / membership_status_id /
 *    membership_is_override — overrides applied to membership lines.
 *
 * Membership advancement on completion is core behaviour
 * (Civi\Membership\OrderCompleteSubscriber): when the contribution completes,
 * each membership line is advanced by num_terms. Our job is only to create the
 * correctly-linked line.
 *
 * @service afform_order.submit
 */
class Submit extends AutoSubscriber {

  /**
   * Service name of the native contribution-creation handler we stand down.
   */
  private const NATIVE_SERVICE = 'civi.afform.create_contribution';

  public static function getSubscribedEvents(): array {
    return [
      'civi.afform.validate' => [
        ['standDownNativeContribution', 2000],
      ],
      'civi.afform.submit' => [
        ['saveContributionFromCart', 0],
      ],
    ];
  }

  /**
   * Disable the native CreateContribution pipeline for cart-managed forms +
   * this request, and apply our own "must have at least one line item" guard.
   */
  public function standDownNativeContribution(AfformValidateEvent $event): void {
    $cartField = CartForm::getCartFieldName($event->getFormDataModel());
    if ($cartField === NULL) {
      return;
    }

    // Per-request: only this submission is being processed, so disabling here
    // does not leak to other native contribution forms.
    if (\Civi::container()->has(self::NATIVE_SERVICE)) {
      \Civi::service(self::NATIVE_SERVICE)->setActive(FALSE);
    }

    // Mirror the native guard against our cart rather than its price fields.
    if (!$this->getCart($event, $cartField)) {
      $this->addValidationError($event, E::ts('Add at least one line item before submitting.'));
    }
  }

  /**
   * Add a validation error portably across core versions: newer cores expose
   * addError(); older cores only have setError() (which newer cores retain as
   * a deprecated alias). Probing the method also avoids a hard failure if the
   * running AfformValidateEvent bytecode predates addError().
   */
  private function addValidationError(AfformValidateEvent $event, string $message): void {
    if (method_exists($event, 'addError')) {
      $event->addError($message);
    }
    else {
      $event->setError($message);
    }
  }

  /**
   * Create the contribution (and any membership / recurring records) from the
   * cart, via Order.create.
   */
  public function saveContributionFromCart(AfformSubmitEvent $event): void {
    if ($event->getEntityType() !== 'Contribution') {
      return;
    }
    $cartField = CartForm::getCartFieldName($event->getFormDataModel());
    if ($cartField === NULL) {
      return;
    }

    $extra = $this->getExtraFields($event);
    $cart = $extra[$cartField] ?? [];
    if (!$cart) {
      // Nothing to do; the validate guard should already have surfaced this.
      return;
    }

    // Defence in depth: never trust the client's companion rows. Recompute
    // from the driver rows server-side. Idempotent and a no-op if the pricing
    // settings are unconfigured.
    $cart = CompanionLogic::compute($cart);

    // Mirror native saveNewContribution: pass the contribution fields through
    // as-is (Order.create tolerates the checkout_* fields the spec provider
    // adds, and startCheckout reads them off the event record afterwards).
    $contribution = $event->getRecords()[0]['fields'];
    if (\Civi::container()->has('civi.checkout') && \Civi::service('civi.checkout')->isTestMode()) {
      $contribution['is_test'] = TRUE;
    }

    $lineItems = $this->buildLineItems($cart, $extra);
    $recurValues = !empty($extra['is_recur'])
      ? $this->buildRecurValues($extra, $contribution)
      : NULL;

    // Extension point: let consumer extensions adjust the order built from the
    // cart (line items and/or recur values) before it is created — e.g. scaling
    // line item quantities to the recurring period — without reimplementing this
    // handler. Business rules belong in the listener, not here.
    $alter = new OrderCreateEvent($lineItems, $recurValues, $cart, $extra, $contribution, $event);
    \Civi::dispatcher()->dispatch(OrderCreateEvent::NAME, $alter);
    $lineItems = $alter->getLineItems();
    $recurValues = $alter->getRecurValues();

    // Snapshot the lineItems with private (underscore-prefixed) keys intact,
    // so OrderCreatedEvent subscribers (a generic post-create extension point
    // for consumer extensions) can see any private values consumers' own
    // OrderCreateEvent listeners may have stamped on the lines. The lineItems
    // passed to Order.create itself have those keys stripped just below.
    $lineItemsWithPrivateKeys = $lineItems;

    // Safety net: strip any private (underscore-prefixed) keys that the cart /
    // providers / subscribers use internally, so they never reach Order.create.
    // Membership entity overrides use dotted keys (entity_id.start_date) and
    // are unaffected.
    $lineItems = array_map(static function (array $line): array {
      return array_filter(
        $line,
        static fn($key) => strpos((string) $key, '_') !== 0,
        ARRAY_FILTER_USE_KEY
      );
    }, $lineItems);

    $order = \Civi\Api4\Order::create(FALSE)
      ->setContributionValues($contribution)
      ->setLineItems($lineItems);
    if ($recurValues !== NULL) {
      $order->setContributionRecurValues($recurValues);
    }

    $saved = $order->execute()->first();

    // Hand the new contribution id to the pipeline so the native checkout
    // (startCheckout @ -100) and the submission record can find it.
    $event->setEntityId(0, $saved['id']);

    // Post-create extension point: a generic seam for consumer extensions
    // that need to write to the records Order.create just produced, before
    // the native checkout engages a payment processor at -100. Built-in
    // per-line membership work (existing-membership link, explicit dates)
    // does not use this seam — it rides through Order.create itself via
    // entity_id + entity_id.<field> on the line items in buildLineItems.
    $created = new OrderCreatedEvent(
      (int) $saved['id'],
      $lineItemsWithPrivateKeys,
      $cart,
      $extra,
      $contribution,
      $event
    );
    \Civi::dispatcher()->dispatch(OrderCreatedEvent::NAME, $created);
  }

  /**
   * Map cart rows to Order line items, applying membership overrides and the
   * optional existing-membership link.
   *
   * @param array $cart
   *   Cart rows in the CompanionLogic shape.
   * @param array $extra
   *   The form's extra.fields values.
   * @return array
   */
  private function buildLineItems(array $cart, array $extra): array {
    $existingMembershipId = !empty($extra['existing_membership_id'])
      ? (int) $extra['existing_membership_id']
      : NULL;

    $lineItems = [];
    foreach ($cart as $row) {
      $qty = (float) ($row['qty'] ?? 1);
      $unitPrice = (float) ($row['unit_price'] ?? 0);
      $line = [
        'price_field_id' => $row['price_field_id'] ?? NULL,
        'price_field_value_id' => $row['price_field_value_id'] ?? NULL,
        'qty' => $qty,
        'unit_price' => $unitPrice,
        'line_total' => $row['line_total'] ?? ($qty * $unitPrice),
        'financial_type_id' => $row['financial_type_id'] ?? NULL,
        'label' => $row['label'] ?? NULL,
        'entity_table' => $row['entity_table'] ?? 'civicrm_contribution',
      ];

      // Membership rows: link to an existing membership when chosen, and fold
      // in any per-line overrides set via the modal. Order/BAO derive
      // membership_type_id etc. from the PriceFieldValue, and create a new
      // membership when entity_id is absent.
      if (($row['entity_table'] ?? NULL) === 'civicrm_membership') {
        // Per-line existing-membership link takes precedence over the form-
        // level extra so a single cart can mix new + renewal lines.
        $rowExisting = !empty($row['_existing_membership_id'])
          ? (int) $row['_existing_membership_id']
          : NULL;
        $effectiveExisting = $rowExisting ?? $existingMembershipId;
        if ($effectiveExisting) {
          $line['entity_id'] = $effectiveExisting;
        }
        // Carry the per-unit term count (cart default or staff override) as a
        // private key for the OrderCreateEvent subscriber to scale by qty. It is
        // stripped before Order.create (see saveContributionFromCart).
        if (isset($row['_num_terms_per_unit'])) {
          $line['_num_terms_per_unit'] = (int) $row['_num_terms_per_unit'];
        }
        // Per-line overrides from the cart row (dates, status, is_override)
        // become entity_id.<field> on the line for Order.create to unpack
        // onto the resulting membership.
        $this->applyMembershipOverrides($line, $row);

        // For new (non-renewal) memberships without an explicit status from
        // the modal, default status to Pending. An explicit status_id in
        // params bypasses the BAO's date-driven status recalc, so the
        // membership lands Pending and OrderCompleteSubscriber's
        // end_date-set short-circuit can fire on payment completion,
        // preserving any per-line dates the staff entered.
        if (!$effectiveExisting && !isset($line['entity_id.status_id'])) {
          $line['entity_id.status_id:name'] = 'Pending';
        }
      }

      // Drop NULLs so Order/BAO defaults take over; keep 0/0.0 (a $0 companion
      // line is intentional).
      $lineItems[] = array_filter($line, static fn($v) => $v !== NULL);
    }

    return $lineItems;
  }

  /**
   * Translate the cart row's per-line `_`-prefixed override keys into the
   * entity_id.<field> notation that CRM_Financial_BAO_Order::saveLineItemEntity
   * unpacks onto the membership Order.create creates.
   *
   * Keys read from the row (all optional):
   *  - _start_date              entity_id.start_date
   *  - _end_date                entity_id.end_date
   *  - _status_id               entity_id.status_id (+ entity_id.is_override = TRUE)
   *  - _membership_is_override  entity_id.is_override = TRUE
   *
   * Supplying a status implies overriding it: without is_override = TRUE the
   * BAO recalculates status from dates and discards the chosen value. Mirrors
   * how CRM_Member_Form_Membership handles admin-entered status overrides.
   */
  private function applyMembershipOverrides(array &$line, array $row): void {
    if (!empty($row['_start_date'])) {
      $line['entity_id.start_date'] = $row['_start_date'];
    }
    if (!empty($row['_end_date'])) {
      $line['entity_id.end_date'] = $row['_end_date'];
    }
    if (!empty($row['_status_id'])) {
      $line['entity_id.status_id'] = (int) $row['_status_id'];
      $line['entity_id.is_override'] = TRUE;
    }
    if (!empty($row['_membership_is_override'])) {
      $line['entity_id.is_override'] = TRUE;
    }
  }

  /**
   * Build ContributionRecur values from the recur extras. Order fills amount
   * (from the line total) and defaults contact_id / financial_type_id.
   */
  private function buildRecurValues(array $extra, array $contribution): array {
    [$unit, $interval] = $this->resolveRecurFrequency($extra);
    $values = [
      'frequency_unit' => $unit,
      'frequency_interval' => $interval,
    ];
    if (!empty($extra['recur_installments'])) {
      $values['installments'] = (int) $extra['recur_installments'];
    }
    if (!empty($contribution['contact_id'])) {
      $values['contact_id'] = $contribution['contact_id'];
    }
    if (!empty($contribution['financial_type_id'])) {
      $values['financial_type_id'] = $contribution['financial_type_id'];
    }
    return $values;
  }

  /**
   * Resolve the recurring frequency unit + interval from the extras.
   *
   * Accepts either a single combined 'recur_frequency' formatted
   * "<interval>-<unit>" (e.g. "3-month") — convenient when a form offers only
   * the cadences its payment processor supports as one select — or the
   * separate 'recur_frequency_unit' / 'recur_frequency_interval' extras.
   *
   * @return array{0:string,1:int} [unit, interval]
   */
  private function resolveRecurFrequency(array $extra): array {
    if (!empty($extra['recur_frequency']) && strpos((string) $extra['recur_frequency'], '-') !== FALSE) {
      [$interval, $unit] = explode('-', (string) $extra['recur_frequency'], 2);
      return [$unit ?: 'month', (int) $interval ?: 1];
    }
    $unit = $extra['recur_frequency_unit'] ?? 'month';
    $interval = (int) ($extra['recur_frequency_interval'] ?? 1) ?: 1;
    return [$unit, $interval];
  }

  /**
   * Read the form's non-entity ("extra") field values.
   *
   * afForm submits these as values.extra.fields.<name> — a single object, not
   * a per-entity list (see afForm.component.js: `data = { extra: {fields: {}} }`).
   * We therefore read the raw submitted values via the api request.
   * getSubmittedValues() can't be used directly here: its per-entity list
   * normalisation (AbstractProcessor::preprocessSubmittedValues) iterates the
   * single 'extra' object as though it were a record, which spreads the field
   * values to the top level of ['extra'][0] and blanks ['extra'][0]['fields'].
   *
   * @param \Civi\Afform\Event\AfformSubmitEvent|\Civi\Afform\Event\AfformValidateEvent $event
   */
  private function getExtraFields($event): array {
    $apiRequest = $event->getApiRequest();
    if (method_exists($apiRequest, 'getValues')) {
      return $apiRequest->getValues()['extra']['fields'] ?? [];
    }
    // Defensive fallback: recover the values that list-normalisation spread
    // onto the top level of the first 'extra' record.
    $extra = $event->getSubmittedValues()['extra'][0] ?? [];
    unset($extra['fields'], $extra['joins']);
    return $extra;
  }

  /**
   * @param \Civi\Afform\Event\AfformSubmitEvent|\Civi\Afform\Event\AfformValidateEvent $event
   */
  private function getCart($event, string $cartField): array {
    return $this->getExtraFields($event)[$cartField] ?? [];
  }

}
