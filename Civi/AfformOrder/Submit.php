<?php

namespace Civi\AfformOrder;

use Civi\Afform\Event\AfformSubmitEvent;
use Civi\Afform\Event\AfformValidateEvent;
use Civi\AfformOrder\Event\OrderCreateEvent;
use Civi\AfformOrder\Event\OrderCreateValidateEvent;
use Civi\AfformOrder\Event\OrderCreatedEvent;
use Civi\AfformOrder\Event\OrderEditRoutedEvent;
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
   * Is this submission an ORDER EDIT handled by us? The edit form embeds the
   * cart via a wrapper component (NOT a LineItemCart input-type field), so it
   * isn't recognised by CartForm; instead the afform-submit cart stashes its
   * diff in the 'order_edit' extra, whose presence is the signal.
   */
  private function isOrderEditSubmission($event): bool {
    return array_key_exists('order_edit', $this->getExtraFields($event));
  }

  /**
   * Disable the native CreateContribution pipeline for cart-managed forms +
   * this request, and apply our own "must have at least one line item" guard.
   */
  public function standDownNativeContribution(AfformValidateEvent $event): void {
    $isEdit = $this->isOrderEditSubmission($event);
    $cartField = CartForm::getCartFieldName($event->getFormDataModel());
    // Neither a cart-create form nor an order-edit form -> not ours.
    if ($cartField === NULL && !$isEdit) {
      return;
    }

    // Per-request: only this submission is being processed, so disabling here
    // does not leak to other native contribution forms. (An order edit must
    // also stand the native create pipeline down - the Contribution already
    // exists; native must not try to create a new one.)
    if (\Civi::container()->has(self::NATIVE_SERVICE)) {
      \Civi::service(self::NATIVE_SERVICE)->setActive(FALSE);
    }

    // The "at least one line item" guard is a CREATE concern only; an edit may
    // legitimately change only header fields (no line changes).
    if (!$isEdit && !$this->getCart($event, $cartField)) {
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

    // EDIT vs CREATE. An order-edit form is recognised by the 'order_edit' extra
    // (the afform-submit cart stashed its diff there); it has NO LineItemCart
    // input-type field, so this MUST be checked before the create-only cartField
    // bail below. Route to the atomic editOrder path, never Order.create.
    if ($this->isOrderEditSubmission($event)) {
      $existingId = $event->getRecords()[0]['fields']['id'] ?? NULL;
      if (!empty($existingId)) {
        $this->editOrderFromCart($event, (int) $existingId);
      }
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

    // Validate (veto) seam: dispatched on the FINAL line set, after the alter
    // seam and immediately before the write, so a consumer can STOP the create
    // if it fails a rule (e.g. a duplicate price-field-value line). Unlike the
    // modify seam there is no prior order state and no metadata side-channel: a
    // veto is a hard error, so we throw and nothing is written.
    $createValidate = new OrderCreateValidateEvent($lineItems, $recurValues, $contribution);
    \Civi::dispatcher()->dispatch(OrderCreateValidateEvent::EVENT_NAME, $createValidate);
    $createErrors = $createValidate->getErrors();
    if ($createErrors) {
      // Keep show_detailed_error so the message reaches the user even without
      // 'view debug output' permission (see CRM_Api4_Page_AJAX).
      throw new \CRM_Core_Exception(implode("\n", $createErrors), 0, ['show_detailed_error' => TRUE]);
    }

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

    // Zero-dollar orders: model core back-office behaviour — a $0 order has no
    // balance to pay, so complete it now instead of leaving it pay-later.
    // Completion fires civi.order.complete, which activates any membership on the
    // order and sets its dates (Civi\Membership\OrderCompleteSubscriber). Without
    // it a $0 membership order sits Pending with nothing to pay against, and the
    // membership never leaves Pending. Non-zero orders are untouched — they flow
    // through the native checkout (startCheckout @ -100).
    $this->autoCompleteZeroDollarOrder((int) $saved['id']);

    // Optional receipt/confirmation (models core QF's "send receipt"). Sent AFTER
    // any $0 completion so a completed order's receipt reflects its final state.
    $this->sendReceiptIfRequested((int) $saved['id'], $extra);
  }

  /**
   * Complete a $0 order so the entity-completion pipeline runs.
   *
   * A zero-total order has no balance and no money to move, so there is no
   * Payment / FinancialTrxn to record — we need only the completion SIDE EFFECTS
   * (status → Completed, membership activation + dates). completeOrder() is
   * core's entry point for exactly that: it dispatches civi.order.complete
   * (→ Civi\Membership\OrderCompleteSubscriber) WITHOUT requiring any payment
   * input, and with empty input sends no receipt. Without this the order sits
   * pay-later/Pending with nothing to pay against and a linked membership never
   * leaves Pending.
   *
   * We call the core BAO directly rather than an api3 API action: it is the
   * minimal, money-free expression of "complete this order", and afform_order
   * already bridges to core BAOs elsewhere (e.g. ContributionRecur::
   * updateOnTemplateUpdated). When core ships a public Order.complete this single
   * call swaps to it. (completeOrder uses api3 INTERNALLY — core's implementation
   * detail, not ours to carry.)
   *
   * Only EXACTLY-$0 orders are auto-completed; any positive total is left to the
   * checkout/payment flow. Degrades gracefully: the order is already created, so
   * if completion fails it simply stays Pending for manual completion rather than
   * failing the whole submit.
   *
   * @param int $contributionID
   */
  private function autoCompleteZeroDollarOrder(int $contributionID): void {
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('total_amount', 'contribution_status_id:name')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first();
    // Only an exactly-$0 order, and only if a post-create listener hasn't already
    // completed it.
    if (empty($contribution)
      || (float) ($contribution['total_amount'] ?? -1) !== 0.0
      || ($contribution['contribution_status_id:name'] ?? '') === 'Completed'
    ) {
      return;
    }
    try {
      \CRM_Contribute_BAO_Contribution::completeOrder([], NULL, $contributionID);
    }
    catch (\Exception $e) {
      \Civi::log()->warning(
        'afform_order: could not auto-complete $0 order {id}: {msg}',
        ['id' => $contributionID, 'msg' => $e->getMessage()]
      );
    }
  }

  }

  /**
   * Apply an order EDIT from the form: the cart's line-item diff PLUS the
   * Contribution header fields, via OrderAO.editOrder - one atomic,
   * correctly-ordered (lines then header) operation. The create path's
   * Order.create is NOT used for edits.
   *
   * CLIENT CONTRACT (approach (a) - the cart keeps its tested diff logic and
   * hands the result over): the cart computes its diff (reusing toModifyAddSpec
   * + its add/remove/replace marker rules) and writes it to the form's 'extra'
   * bag under 'order_edit' (afForm.getFieldData().order_edit), shaped as:
   *   {
   *     lineItemsToAdd:       [ <OrderLineItem-create spec>, ... ],
   *     lineItemsToRemove:    [ { id, removal_reason? }, ... ],
   *     expectedLineItemIDs:  [ <loaded line ids> ],   // optimistic-concurrency
   *     context:              'cart_edit',
   *     contextDetail:        { ... }                  // optional
   *   }
   * It rides in the extra bag (NOT a declared <af-field>): afform.submit() posts
   * the whole data model (values: data, including data.extra.fields) wholesale,
   * and a declared Hidden field would validate/coerce the object value as a
   * scalar and fail form validation. We read it here via
   * getExtraFields()['order_edit'] ( = getValues()['extra']['fields']['order_edit'] ).
   *
   * The header fields are the Contribution record's own af-field values (incl.
   * custom fields, e.g. the consumer's date field); editOrder sanitizes them
   * (drops line-driven values, rejects payment/checkout fields, rejects a
   * ->Completed status transition).
   *
   * A vetoed/routed change (e.g. a refund-producing edit a consumer's validate
   * subscriber redirects) comes back from editOrder as a row with applied=FALSE
   * plus a $validate_metadata bag; we relay it on the submission response for
   * the client to act on.
   *
   * @param \Civi\Afform\Event\AfformSubmitEvent $event
   * @param int $contributionId
   */
  private function editOrderFromCart(AfformSubmitEvent $event, int $contributionId): void {
    $edit = $this->getExtraFields($event)['order_edit'] ?? [];

    $headerFields = $event->getRecords()[0]['fields'] ?? [];
    // The id identifies the target; it is not a value to write.
    unset($headerFields['id']);

    $result = \Civi\Api4\OrderAO::editOrder(FALSE)
      ->setContributionID($contributionId)
      ->setLineItemsToAdd($edit['lineItemsToAdd'] ?? [])
      ->setLineItemsToRemove($edit['lineItemsToRemove'] ?? [])
      ->setContributionFields($headerFields)
      ->setExpectedLineItemIDs($edit['expectedLineItemIDs'] ?? [])
      ->setContext($edit['context'] ?? 'cart_edit')
      ->setContextDetail($edit['contextDetail'] ?? [])
      ->execute();

    // Publish the (existing) contribution id so downstream submit subscribers
    // and the submission record can find it - mirrors create's setEntityId(0).
    $event->setEntityId(0, $contributionId);

    // A vetoed/routed change comes back as applied=FALSE + $validate_metadata
    // (nothing was written). Fire the neutral OrderEditRoutedEvent so a consumer
    // can interpret its own metadata, act on the routed change, and supply a
    // completion message / redirect to surface on the afform submission response.
    // afform_order names no metadata keys; it only forwards whatever message /
    // redirect the consumer sets back onto the response.
    if (($result->first()['applied'] ?? NULL) === FALSE) {
      $routed = new OrderEditRoutedEvent(
        $contributionId,
        $edit['lineItemsToAdd'] ?? [],
        $edit['lineItemsToRemove'] ?? [],
        $result->validate_metadata ?? []
      );
      \Civi::dispatcher()->dispatch(OrderEditRoutedEvent::EVENT_NAME, $routed);

      // The afform client renders submissionResponse[0].message as the
      // confirmation and follows submissionResponse[0].redirect.
      if ($routed->getMessage() !== NULL && method_exists($event->getApiRequest(), 'setResponseItem')) {
        $event->getApiRequest()->setResponseItem('message', $routed->getMessage());
      }
      if ($routed->getRedirect() !== NULL && method_exists($event->getApiRequest(), 'setResponseItem')) {
        $event->getApiRequest()->setResponseItem('redirect', $routed->getRedirect());
      }
    }
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
