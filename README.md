# afform_order

This extension does two things, and it's worth separating them because they may not
stay together forever.

**1. It backfills a missing core capability: modifying an existing Order.** CiviCRM can
*create* an Order (`Order.create`) but has no first-class way to *modify* one — to add,
remove, or correct line items on a contribution that already exists, and to do so
correctly when that contribution has already been paid (reversing lines and booking an
AR adjustment rather than destructively editing history). That gap is the reason this
extension was written. The fill lives in the **`OrderAO`** API4 entity — most centrally
`OrderAO.modify` — which is deliberately named and shaped to become core's
`Order.modify` (see [Relationship to CiviCRM core](#relationship-to-civicrm-core)).

**2. It adds an Afform-based add/edit UI for Contributions built on top of that.** An
editable, in-form line-item **cart** (an Afform input type + Angular components) plus a
submit pipeline lets staff build a new Order (`Order.create`) or edit an existing one
(`OrderAO.modify`) directly from an Afform, while keeping Afform's existing
checkout/payment flow intact.

`afform_order` is processor-agnostic and contains no business rules. Concretely it ships:

- the **modify engine** (`OrderAO` + `OrderLineItem` API4 entities) — the core fill,
- the **cart UI** (the `LineItemCart` input type + Angular components) for building and
  editing line items,
- the **submit pipeline** that turns a cart into a `Order.create` (new) or
  `OrderAO.editOrder` (edit),
- a **companion-line-item orchestrator**, and
- a family of **extension points** (server events + client registries) that consumer
  extensions hook into to add their own pricing, membership, and validation logic.

---

## Relationship to CiviCRM core

The split above is a deliberate bet on where this code belongs long-term.

The modify half is a **stand-in for core**. `OrderAO` is named `OrderAO` (not `Order`)
specifically to sit beside core's existing `Order` entity without colliding, and
`OrderLineItem` routes writes through its own BAO so its hooks fire under a distinct
name rather than shadowing core's `LineItem`. The intent is that `OrderAO.modify`
converges with — and is eventually replaced by — a core `Order.modify`. The core work
this tracks is [civicrm/civicrm-core#35433](https://github.com/civicrm/civicrm-core/pull/35433).

**If core gains `Order.modify`, what's left here is solely the add/edit Contribution
UI** — the cart, the Afform components, and the submit pipeline — re-pointed from
`OrderAO.modify` to `Order.modify`. Nothing in the UI layer depends on the fill being an
extension; it depends only on *a* modify action with this shape. The event seams below
(`OrderModifyValidateEvent` and friends) are designed with the same convergence in mind:
they are the generic modify-time primitives a consumer would expect from core, kept here
until core offers equivalents.

Until then, this extension carries the whole capability, and consumers depend on
`OrderAO`/`OrderLineItem` as the stable public surface.

Convergence is happening on the **create** side too. The submit pipeline here already
builds an Order and its recurrence in a single `Order.create` call (passing recur values
in via `setContributionRecurValues()` rather than creating the `ContributionRecur`
separately). Core is moving toward the same pattern for its own Formbuilder payments in
[civicrm/civicrm-core#35995](https://github.com/civicrm/civicrm-core/pull/35995) — so
that part of the pipeline is a candidate to fold into core as well, leaving even less
here than just the UI.

---

## Status & requirements

**Base: CiviCRM 6.16+.** The extension requires a minimum CiviCRM version of 6.16 to function properly.

The `LineItemCart` input type still declares `extra_defn.data_type = String` (see
below). That is now belt-and-suspenders rather than a hard requirement: it keeps the
form schema safe on any build, and matches the core fallback.

**Companion extensions.** `afform_order` is the engine. A consumer extension supplies:

- The actual `.aff.html` form layout (two ready-to-copy samples ship here — see
  [Sample forms](#sample-forms)).
- Business rules: companion-line-item config, membership term scaling, advisory
  checks, edit/refund policy — wired through the extension points below.

A working CiviCRM payment processor + its CheckoutOption implementation is needed for
the live checkout half of the create flow.

---

## What it does, end to end (create)

1. A form author defines an `.aff.html` whose Contribution slot includes a single
   "cart" extra field of input type `LineItemCart`.
2. The cart UI lets staff add, edit, and remove line items (quantity, unit price,
   etc.). On every mutation it calls back to the server to regenerate any **companion
   line items** configured by consumer providers, and re-runs registered **advisory
   checks** (e.g. "quantity doesn't match the recurring period").
3. On submit, `afform_order`:
   - **Stands down** Afform's native `CreateContribution` service for the current
     request (`setActive(FALSE)`), so it doesn't try to create a contribution from a
     non-existent amount/price-set on the form. This is per-request and doesn't leak
     to other forms.
   - Recomputes companions server-side (never trusting the client's companion rows),
     builds `LineItem`s from the cart, and builds `ContributionRecur` values if the
     form supplied recurrence fields.
   - Fires the **create seams** (`OrderCreateEvent` to shape the order, then
     `OrderCreateValidateEvent` to veto it) and runs `Order.create` to produce a
     **Pending** contribution + line items + (for membership lines) memberships, all
     linked.
   - Records the new contribution id on the submit event so the redirect token
     `[Contribution1.0.id]` resolves, then fires `OrderCreatedEvent` (post-save).
   - **Zero-dollar orders** are auto-completed (so a $0 membership activates
     immediately); **receipts** are sent when the form sets `send_receipt`.
4. Afform's existing checkout layer (`Civi\Checkout\Afform::startCheckout`, priority
   −100) then runs, and a configured CheckoutOption hands the saved order to its
   payment processor's `doPayment`.

Throughout, the cart is the source of truth on the client; the `ContributionRecur` and
`Membership` are the source of truth on the server after submit; and core's
`OrderCompleteSubscriber` does the right thing with `membership_num_terms` when the
contribution eventually completes.

---

## Editing an existing order

The same cart, in **edit mode**, drives changes to an *existing* contribution. This is
the part of the engine that handles the hard case: editing a contribution that has
already been paid.

The server keystone is the **`OrderAO`** API4 entity (named `OrderAO`, not `Order`, to
avoid colliding with core's `Order` entity and a future core `Order.modify`). Its two
top-level actions:

- **`OrderAO.editOrder`** — the atomic front door used by the edit form. It applies
  line changes **first** (via `modify`) and contribution header changes **after** (via
  `Contribution.update`), all inside one transaction, so the financial engine books
  over the final line set. It also runs an **optimistic-concurrency check**: the form
  passes the line-item id set it loaded (`expectedLineItemIDs`), and the edit aborts if
  that set changed in the meantime ("this contribution was changed since you opened it;
  reload and try again"). Payment fields are rejected — the edit form never takes
  money.
- **`OrderAO.modify`** — the line-restructure core. It classifies the net effect of a
  change (`increase` / `net_zero` / `decrease`), fires the **validate/veto seam**
  (`OrderModifyValidateEvent`) on every path *before any write*, then:
  - **Pending / template contributions:** removed lines are **deleted**, new lines are
    created, totals are recomputed and written; for a recurring **template** the recur
    amount is re-synced and the payment processor is best-effort notified of the amount
    change.
  - **Paid contributions (Completed / Partially paid):** removed lines are **reversed**
    (a negated copy line is created, the original is preserved with its history), a
    single **AR adjustment** financial transaction is booked for this edit's increment,
    the contribution status is moved (→ Partially paid on an increase, → Pending refund
    on a decrease, → Completed when settled), and new lines are created. **No money
    moves** — a later Record Payment / Record Refund settles the balance.

Two more `OrderAO` actions support the flow:

- **`OrderAO.ensureRecurTemplate`** — lazily materializes (or resolves) the *template*
  contribution for a recurring series, so you can edit *future* installments by
  pointing `modify` at the returned `template_contribution_id`.
- **`OrderAO.reclassifyLineValue`** — a distinct, net-zero operation: move value
  already received from a source/suspense line onto one or more new lines (no total
  change, no payment, no refund, no reversal). Used to allocate a placeholder line to
  its real financial types after the fact.

See [Server API surface](#server-api-surface) for full parameter lists and
[Extension points](#extension-points) for the events these fire.

### A note on reversal provenance

When a line on a **paid** contribution is changed or removed, the change is recorded as
a negative reversal line sitting beside the intact original. The modify result and
`OrderModifiedEvent` expose a `reversals` record (`original_line_item_id →
reversal_line_item_id`, amount, context) and a `replacements` map, so a caller *can*
learn which original a reversal backed out **for that call**. What is still not
persisted on the line item itself is a durable back-reference, so after several edits
over time the ledger is a sum of legitimate reversals from which "is *this* specific
line already reversed?" can't be reconstructed from the rows alone. The edit flow's
guard against acting on stale state is the optimistic-concurrency snapshot described
above; it is not per-line double-reversal protection. See
[Roadmap / known limitations](#roadmap--known-limitations).

---

## Concepts & moving parts

### The `LineItemCart` Afform input type

Registered in `Civi\AfformOrder\AfformInputTypes`. A form author drops:

```html
<af-field defn="{name: 'line_items', input_type: 'LineItemCart', label: 'Line Items'}" />
```

into the Contribution entity slot. Afform `ng-include`s the input type's runtime
template (`ang/afformOrder/LineItemCart.html`), which renders the `<af-field-line-items>`
directive; a separate admin template shows a placeholder in the form builder.

A create form is detected as cart-managed purely by the presence of a `LineItemCart`
input type (`Civi\AfformOrder\CartForm` scans field defns — no hardcoded field names).
An **edit** form is recognized instead by an `order_edit` key in the submitted extras.

The input type sets `extra_defn.data_type = String` so an *extra* field using it never
fatals in `fillExtraFieldMetadata` (redundant with the 6.16 core fallback, kept for
safety on any build).

### The cart directive — `<af-field-line-items>`

Located at `ang/afFieldLineItems/`. It runs in two modes.

**Create mode.** The directive:
- Reads/writes its cart state at `afForm.getFieldData()[name]`.
- Loads `PriceFieldValue` options for its add-line-item picker (filtered by
  `allowed-price-sets` / `excluded-price-fields`, and de-duplicated so a membership
  type already in the cart can't be added twice).
- Recomputes companion rows on every mutation (debounced ~250ms) via API4
  `AfformOrder.computeCompanions`, using a generation counter to discard stale replies
  (running it again reconciles rather than duplicates).
- Re-runs all registered advisory checks, plus a built-in "did you mean to renew?"
  check that matches new membership rows against the contact's existing memberships.
- Publishes a small facade on `afForm.afOrderCart[fieldName]` for the surrounding form
  template:
  - `hasRows()` / `hasMembershipRow()` / `total()`
  - `warnings()` — current advisory messages
  - `confirmed` — staff acknowledgment of warnings (two-way; ng-model target)
  - `needsConfirmation()` — true while there are unacknowledged warnings

**Edit mode** (`edit-mode="true"`, `edit-contribution-id="…"`). The directive loads the
existing contribution and its line items, reconstructs which loaded lines are
companions of which drivers, pins every loaded line so recomputes don't regenerate
settled rows, and tracks edits as a **diff**: removals are marked (struck through, with
an optional reason) rather than spliced; corrected lines become a remove + re-add pair
carrying `_replaces_line_item_id`. With `afform-submit="true"` it stashes that diff
(plus `context` / `context-detail`) into the `order_edit` extra and lets the host
`afform.submit()` post it (the server's edit branch forwards to `OrderAO.editOrder`);
without it, the cart drives `OrderAO.modify` directly.

Per-line editing (label, unit price, membership terms/dates/status overrides) is gated
on the `override afform order line items` permission (see [Permissions](#permissions)).
Rows can also be **locked** and picker options **filtered** by consumer-registered
predicates (below).

### `<af-order-edit-cart>`

A thin host component (`contribution-id` binding) whose template embeds
`<af-field-line-items edit-mode="true" afform-submit="true" …>`. It exists because the
cart's bindings need to live in a component template string rather than on a bare
custom element in Afform markup. Drop it into an edit form and the host's own submit
button does the rest.

### Companion line items — pluggable providers

Companion generation is a pluggable concern. `afform_order` ships the orchestrator
(`Civi\AfformOrder\CompanionLogic`) and the event
(`Civi\AfformOrder\Event\ComputeCompanionsEvent`) it dispatches; it ships **no concrete
companion rules**. Consumer extensions register `AutoSubscriber`s on the event, each
appending rows for drivers in scope of their rule.

The orchestrator's job is small but specific:
1. Strip every row previously tagged with `CompanionLogic::AUTO_MARKER` (preserving
   staff overrides whose driver is still present) so providers always see a clean cart.
2. Dispatch `ComputeCompanionsEvent` so subscribed providers can append.
3. Return the resulting cart.

`CompanionLogic::compute` is safe to run repeatedly (assuming every registered provider
is): the cart directive calls it on every mutation via `AfformOrder.computeCompanions`,
and the submit subscriber calls it one last time before saving so what the server
records matches what the cart showed. See
[the companion provider example](#server-companion-providers-via-computecompanionsevent).

### Membership pickers

Both ship at `ang/afFieldLineItems/`:

- **`<af-existing-membership-select>`** — lets staff link the new contribution to an
  existing membership instead of creating a new one. Watches the form's contact id,
  loads that contact's memberships via API4, and writes the chosen id to the configured
  extras field (the submit pipeline uses it as the `entity_id` on the membership line).
- **`<af-membership-status-select>`** — loads `MembershipStatus` options via API4 and
  binds the chosen id to the form's extras (applied as `entity_id.status_id` +
  `entity_id.is_override` on the membership line). Needed because Afform extra Select
  fields can't derive options from an entity that isn't on the form.

Both seed from the extras on init, so they're ready to prefill an edit form.

### Date-only datetime fields — `crmUiDatepicker` decorator

An opt-in decorator on core's `crmUiDatepicker` fills in a default time when a datetime
field is given a date-only value, so date-only entry doesn't leave the field
incomplete and block `afform.submit()`. Enable per field with
`input_attrs: {defaultTimeOnDate: true}` (→ `00:00:00`) or a time string like
`'12:00:00'`. It no-ops unless the flag is present.

---

## Server API surface

All actions are API4. Entity names are intentionally suffixed to avoid colliding with
core (`OrderAO` vs core `Order`; `OrderLineItem` vs core `LineItem`).

### `AfformOrder`

| Action | Params | Does |
|--------|--------|------|
| `computeCompanions` | `lineItems` (required) | Runs `CompanionLogic::compute` and returns the cart with auto-companion rows reconciled. The endpoint the cart directive calls on every mutation. |

### `OrderAO` (operates on an existing Contribution — no table of its own)

| Action | Key params | Does | Permission |
|--------|-----------|------|------------|
| `editOrder` | `contributionID`, `lineItemsToAdd`, `lineItemsToRemove`, `contributionFields`, `expectedLineItemIDs`, `context`, `contextDetail` | Atomic edit: concurrency check → `modify` (lines) → `Contribution.update` (header). Rejects payment fields; drops line-driven totals. Returns a `ModifyResult`. | `edit contributions` |
| `modify` | `contributionID`, `lineItemsToAdd`, `lineItemsToRemove`, `context`, `contextDetail` | Classifies net effect, fires the validate/veto seam, then restructures lines. Pending/template → delete+create; paid → reverse + AR adjustment + create. Returns a `ModifyResult`. | `edit contributions` |
| `ensureRecurTemplate` | `contributionRecurID` | Lazily materialize/resolve the recur's template contribution; fires `OrderRecurTemplateCreatedEvent` on first materialization. Returns `{contribution_recur_id, template_contribution_id}`. | `edit contributions` |
| `reclassifyLineValue` | `sourceLineItemID`, `allocations` | Net-zero move of already-received value from a source line onto new lines (no total change, no reversal, no payment). | `edit contributions` |
| `canOverrideLineItems` | — | `{can_override: bool}` = permission check for `override afform order line items`. Fallback for the cart when the client permission wasn't preloaded. | `access CiviContribute` |
| `getFromEmails` | — | One row per configured From address `{value, label, name, email}`, feeding receipt sending. | `access CiviContribute` |

`editOrder` / `modify` return a **`ModifyResult`** (an api4 `Result` subclass). On an
applied edit each result row carries `applied: TRUE`, the updated totals, and detail
keys (`line_items_added`, `line_items_reversed`, `line_items_removed`, `reversals`,
`replacements`). On a **veto that carried metadata** the row is `applied: FALSE` and the
result exposes `validate_metadata` (a declared public property, so it survives the AJAX
layer to reach the client) — a throwing exception would flatten and lose the bag.

### `OrderLineItem`

Shares core's LineItem schema but routes writes through its own BAO so hooks fire under
the `OrderLineItem` name, not core's `LineItem`. `save`/`update` are denied; only
`create`, `delete`, and reads are exposed. `create` derives `line_total` from
`qty × unit_price` and defaults `entity_table`/`entity_id` from the contribution when
omitted (via `OrderLineItemCreateSpecProvider`), and honors passthrough flags
(`isReversal`, `financial_trxn_id`, `skipFinancialItems`) used by `modify` /
`reclassifyLineValue`. `delete` only removes Pending/Unpaid lines — a paid line must be
reversed, not destroyed.

---

## Extension points

These are how a consumer extension layers business rules onto the generic engine
without forking it. Every subscriber below is auto-registered by the `scan-classes`
mixin as long as it lives under `Civi/{Namespace}/` and extends `AutoSubscriber` — no
`services.yaml` or hook wiring needed.

> **Event-name constant convention.** The events built on `GenericHookEvent` expose
> their name as `::NAME`; the plain-Symfony `Event` subclasses expose it as
> `::EVENT_NAME`. The tables note which is which; use the one shown or your subscriber
> won't bind.

### The event family at a glance

| Event | Name constant | When | Mutate? |
|-------|---------------|------|---------|
| `ComputeCompanionsEvent` | `NAME` = `civi.afform_order.compute_companions` | Every cart recompute (create + edit) | Yes — append companion rows |
| `OrderCreateEvent` | `NAME` = `civi.afform_order.create` | Create submit, before `Order.create` | Yes — shape line items / recur |
| `OrderCreateValidateEvent` | `EVENT_NAME` = `civi.afform_order.create.validate` | Create submit, after shaping, before save | Veto only |
| `OrderCreatedEvent` | `NAME` = `civi.afform_order.created` | Create submit, after `Order.create` | No (read-only) |
| `OrderModifyValidateEvent` | `EVENT_NAME` = `civi.afform_order.modify.validate` | Every `modify` path, before any write | Veto (+ metadata) |
| `OrderModifyEvent` | `NAME` = `civi.afform_order.modify` | `modify`, after validate passes, before writes | Yes — reshape added lines |
| `OrderModifiedEvent` | `EVENT_NAME` = `civi.afform_order.modified` | Inside the modify transaction, after restructure | No (informational) |
| `OrderEditRoutedEvent` | `EVENT_NAME` = `civi.afform_order.order_edit_routed` | Submit edit branch, when `editOrder` declined + relayed metadata | Set message/redirect |
| `OrderRecurTemplateCreatedEvent` | `EVENT_NAME` = `civi.afform_order.recur_template_created` | `ensureRecurTemplate`, only on first materialization | No (informational) |
| `OrderFinancialAccountResolveEvent` | `EVENT_NAME` = `civi.afform_order.resolve_financial_account` | While resolving a line's FinancialItem account | Set account id |

### Server: shaping a create — `OrderCreateEvent`

Fires from `Submit` just before `Order.create`, with mutable line items + recur values
and read-only context (cart, extras, contribution, original submit event). Use it for
per-deployment Order shaping — e.g. setting `membership_num_terms = qty × base` so the
membership term tracks quantity.

```php
use Civi\AfformOrder\Event\OrderCreateEvent;
use Civi\Core\Service\AutoSubscriber;

class MyOrderSubscriber extends AutoSubscriber {
  public static function getSubscribedEvents(): array {
    return [OrderCreateEvent::NAME => 'shapeOrder'];
  }
  public function shapeOrder(OrderCreateEvent $event): void {
    $lineItems = $event->getLineItems();
    // ...mutate...
    $event->setLineItems($lineItems);
  }
}
```

Its modify-path counterpart is `OrderModifyEvent` (same reshape-before-save role, over
the *added* lines of an edit). They are distinct events — a create subscriber never
receives a modify payload — so put shared logic in a callable both invoke.

### Server: vetoing a create — `OrderCreateValidateEvent`

Fires after shaping, on the final line set, right before `Order.create`. Add an error
to stop the create; any error aborts and nothing is written.

```php
use Civi\AfformOrder\Event\OrderCreateValidateEvent;
use Civi\Core\Service\AutoSubscriber;

class MyCreateGate extends AutoSubscriber {
  public static function getSubscribedEvents(): array {
    return [OrderCreateValidateEvent::EVENT_NAME => 'gate'];
  }
  public function gate(OrderCreateValidateEvent $event): void {
    // inspect $event->getLineItems() / getContribution() / isRecurring()
    // $event->addError('...') to block
  }
}
```

### Server: reacting after a create — `OrderCreatedEvent`

Read-only, fires immediately after `Order.create` (before native checkout at −100).
Carries the new `contributionId`, the line items (with the engine's private `_` keys
still intact), cart, extras, contribution, and submit event. Use it for post-create
side effects that don't belong in checkout.

### Server: policing an edit — `OrderModifyValidateEvent`

Fired by `OrderAO.modify` **before any line/financial writes**, on **every** path
(pending, template, paid), so a consumer can inspect a proposed change and veto it. This
is the seam that lets a deployment impose policy on edits — most importantly on edits to
a **paid** contribution — without putting any of that policy in the engine.
`afform_order` ships **no** listener: with none, a modify proceeds.

The event carries `lineItemsToAdd` / `lineItemsToRemove`, the contribution status, a
net-effect classification (`increase` / `net_zero` / `decrease`, via
`isRefundProducing()` and the `EFFECT_*` constants), the `netDelta`, and a
caller-declared `context` + `contextDetail`. **The context is a coordination signal, not
authorization** — a subscriber that cares must independently verify it (e.g. confirm an
approved refund request actually exists) rather than trust the string.

```php
use Civi\AfformOrder\Event\OrderModifyValidateEvent;
use Civi\Core\Service\AutoSubscriber;

class MyModifyGate extends AutoSubscriber {
  public static function getSubscribedEvents(): array {
    return [OrderModifyValidateEvent::EVENT_NAME => 'gate'];
  }
  public function gate(OrderModifyValidateEvent $event): void {
    if (!$event->isRefundProducing()) {
      return; // only police refunds; increases/net-zero pass
    }
    // ...verify context / records, then either allow (return) or veto...
    $event->addError('This refund must go through the approval flow.');
  }
}
```

**Conveying a structured outcome (neutral metadata bag).** Sometimes a veto isn't just
"no" — the subscriber knows what *should* happen instead (e.g. "this reduction should
become a refund request, and here is the intended change") and wants to tell the caller.
The event carries a metadata bag modelled on core's
`Civi\Order\Event\OrderCompleteEvent::$params`:

- `setMetadata($key, $value)` / `getMetadata($key, $default)` / `hasMetadata($key)` /
  `getAllMetadata()`. **`afform_order` defines no keys and interprets none.** A consumer
  and its own client agree on key names (namespace them, e.g. `refund_required`).
- A veto **with** metadata is not thrown as an error — `modify` returns a *successful*
  result with `applied: FALSE` and the whole bag under `ModifyResult::$validate_metadata`
  (the only transport that survives to the AJAX client). A veto **without** metadata is
  thrown as `CRM_Core_Exception`. Either way nothing is written.

```php
// Vetoing AND conveying intent:
$event->addError('This reduction must go through the refund flow.');
$event->setMetadata('refund_required', [
  'contribution_id'      => $event->getContributionID(),
  'net_delta'            => $event->getNetDelta(),
  'line_items_to_remove' => $event->getLineItemsToRemove(),
  'line_items_to_add'    => $event->getLineItemsToAdd(),
]);
```

The edit cart forwards any relayed `validate_metadata` up through the submit response,
and `OrderEditRoutedEvent` (below) is the server seam a consumer uses to act on it. What
a key means and what shape its value takes lives entirely in the consumer that set it.
This split is deliberate: if/when a core `OrderValidateEvent` lands with its own metadata
bag, the consumer's routing helper reads and writes *that* bag instead, and the engine's
generic validate primitive converges with core while the consumer-specific routing stays
in the consumer.

### Server: reshaping an edit's new lines — `OrderModifyEvent`

Mutable, fires after the validate seam passes and before the restructure writes. Exposes
only the **added** lines of an edit (`getLineItems()` / `setLineItems()`) plus the
contribution id and `isTemplate()`. Removals and reversal lines are not exposed here.

### Server: reacting after an edit — `OrderModifiedEvent`

Informational, fires **inside** the modify transaction after the restructure (so a
listener's writes commit or roll back with the modify; a throwing listener aborts the
whole edit). Carries added/removed line-item ids, a `replacements` map
(`removedID → addedID`), and `reversals` records
(`original_line_item_id → reversal_line_item_id`, amount, context — empty on a Pending
edit, since there a removed line is deleted rather than reversed), plus `isTemplate()`.

### Server: routing a declined edit — `OrderEditRoutedEvent`

Fires from the submit edit branch when `OrderAO.editOrder` declined to apply and relayed
validate metadata (the veto-with-metadata case). A consumer inspects the metadata and
may set an optional completion `message` and/or `redirect` that `afform_order` forwards
on the submission response — e.g. "a refund request was created, here's the link."

### Server: recur template materialized — `OrderRecurTemplateCreatedEvent`

Fires from `ensureRecurTemplate` **only** when it materializes a *new* template
contribution (not when resolving an existing one). Carries the recur id and the new
template contribution id — the seam to prune copied lines that shouldn't recur.

### Server: financial-account resolution — `OrderFinancialAccountResolveEvent`

Lets a listener override the engine's default AP-first / Income-fallback financial
account when a line's FinancialItem is written (normal creates and paid-line reversals).
Carries the financial type id, the account the engine resolved (`getFinancialAccountID()`,
possibly NULL), and `getIsReversal()`; call `setFinancialAccountID()` to override.

### Server: companion providers via `ComputeCompanionsEvent`

The orchestrator (`CompanionLogic::compute`) dispatches this on every cart recompute,
after stripping previously auto-generated rows. Each provider appends rows for drivers in
its scope. Providers **must** stamp every row with `CompanionLogic::AUTO_MARKER => TRUE`
so the next recompute strips it; stamping `CompanionLogic::PROVIDER_KEY` is optional but
recommended for audit/debugging.

```php
use Civi\AfformOrder\CompanionLogic;
use Civi\AfformOrder\Event\ComputeCompanionsEvent;
use Civi\Core\Service\AutoSubscriber;

class MyCompanionProvider extends AutoSubscriber {

  public const PROVIDER_KEY = 'myext.event_addon';

  public static function getSubscribedEvents(): array {
    return [ComputeCompanionsEvent::NAME => 'compute'];
  }

  public function compute(ComputeCompanionsEvent $event): void {
    if (!self::isConfiguredForCurrentContext()) {
      return; // bail early if your rule doesn't apply
    }
    $cart = $event->getCart();
    foreach ($cart as $driver) {
      if (!$this->isDriver($driver)) {
        continue;
      }
      $cart[] = [
        'price_field_id'       => $myPriceFieldId,
        'price_field_value_id' => $myPriceFieldValueId,
        'qty'                  => (float) ($driver['qty'] ?? 1),
        'unit_price'           => $myUnitPrice,
        'line_total'           => $myUnitPrice * (float) ($driver['qty'] ?? 1),
        'financial_type_id'    => $myFinancialTypeId,
        'label'                => $myLabel,
        'entity_table'         => 'civicrm_contribution',
        '_cart_id'             => uniqid('cart_', TRUE),
        '_companion_for'       => $driver['_cart_id'] ?? NULL,
        CompanionLogic::AUTO_MARKER  => TRUE,
        CompanionLogic::PROVIDER_KEY => self::PROVIDER_KEY,
      ];
    }
    $event->setCart($cart);
  }
}
```

If a provider needs configuration, ship its settings page in your own extension.
`afform_order` intentionally has no admin page — it has nothing to configure. Multiple
providers can be active at once; if two shouldn't both fire for the same driver, gate
that in each provider's own logic.

### Client: advisory checks — `afOrderCartChecks`

Angular service in the `afFieldLineItems` module (the client analogue of the server
create seam). Register an advisory check from a consumer module's `.run()` block; the
cart runs every registered check on every cart/form change and surfaces the returned
messages through the gate facade.

```js
angular.module('myConsumerModule', CRM.angRequires('myConsumerModule'))
  .run(['afOrderCartChecks', function(afOrderCartChecks) {
    afOrderCartChecks.register(function(cart, context) {
      // context.formData is afForm.getFieldData() (extra fields, e.g. is_recur).
      // Return a string, an array of strings, or [] for no warning.
      // Advisory only — never mutate the cart, never block submit here.
    });
  }]);
```

The consuming form decides how to present the warnings — typically a "soft gate" above
the submit button with a "confirm intentional" checkbox. The facade exposes `warnings()`,
`confirmed`, and `needsConfirmation()` for exactly this.

### Client: row locks and picker filters

Two more registries in the same module let a consumer constrain the edit cart:

- **`afOrderLineLocks`** — `register(fn)` a predicate; a row for which any predicate
  returns truthy is locked (qty/unit_price disabled, edit/remove hidden). The predicate
  gets `{contributionId, contributionStatus, isTemplate, isEdit}`. The engine ships the
  mechanism and locks nothing itself.
- **`afOrderPickerFilters`** — `register(fn)` a predicate to hide add-line-item options
  (only an explicit `false` hides one). Resolve async data, cache it, then
  `$rootScope.$broadcast('afOrderPickerRefresh')` to re-run the picker.

### Client: popup close + Search Kit reload — `afOrderPopup`

A shared helper (`closeOnSuccess(element)`) for components that save via API4 directly
(not `afform.submit()`) while launched inside a CRM popup. It finds the enclosing
dialog; if there is none it returns `false` (standalone page — caller falls back to an
in-place refresh); if there is, it buffers a success then closes the dialog so crm.ajax
re-fires the opener's success handler and Search Kit / livePage reloads. Provided for
consumers; nothing in this extension calls it.

---

## Permissions

`afform_order` declares one permission:

- **`override afform order line items`** ("Afform Order: override line items") — gates
  the per-line edit affordances on a cart (editing label/price/terms/status, reverting
  an override). The client checks it up front; `OrderAO.canOverrideLineItems` is the
  server fallback when the client couldn't preload it (e.g. a cart injected into a
  Search Kit popup).

The API4 actions carry their own permissions as noted in
[Server API surface](#server-api-surface) (`edit contributions` for the write actions,
`access CiviContribute` for the read helpers).

---

## Sample forms

Two ready-to-copy Afform layouts ship under `ang/` and can be enabled as-is or used as
a starting point:

- **`afformOrderCreate`** (route `civicrm/order/create`) — a create form demonstrating
  the `LineItemCart` field, contribution header fields, a recurring-schedule fieldset
  (`is_recur` + `recur_frequency` / `recur_installments`), an optional receipt fieldset
  (`send_receipt` / `receipt_text`), the advisory-gate block wired to the cart facade,
  and a submit button disabled via `hasRows()` / `needsConfirmation()`.
- **`afformOrderEdit`** (route `civicrm/order/edit#?Contribution1=<id>`) — an edit form
  demonstrating the afform-submit edit flow via `<af-order-edit-cart>`, header fields
  using the `defaultTimeOnDate` datepicker opt-in, and the diff stashed into the
  `order_edit` extra for `OrderAO.editOrder` to apply atomically.

### Minimal create skeleton

```php
// myform.aff.php
return [
  'type'              => 'form',
  'title'             => 'Create Contribution',
  'permission'        => ['access CiviContribute', 'edit contributions'],
  'create_submission' => TRUE,
  'requires'          => ['afformOrder'],
  'redirect'          => 'civicrm/contact/view/contribution?reset=1&action=view&id=[Contribution1.0.id]&cid=[Contact1.0.id]',
];
```

```html
<!-- myform.aff.html -->
<af-form ctrl="afform">
  <af-entity type="Contact" name="Contact1" actions="{create: false, update: true}" url-autofill="1" />
  <af-entity type="Contribution" name="Contribution1" data="{contact_id: 'Contact1'}" />

  <fieldset af-fieldset="Contact1">
    <af-field name="id" defn="{input_type: 'Hidden'}" />
    <af-field name="display_name" defn="{input_type: 'DisplayOnly'}" />
  </fieldset>

  <fieldset af-fieldset="Contribution1">
    <af-field name="financial_type_id" />
    <af-field name="payment_instrument_id" />
    <af-field name="receive_date" />
    <af-field name="checkout_option" />
    <af-field name="checkout_params" defn="{label: false}" />

    <af-field defn="{name: 'line_items', input_type: 'LineItemCart', label: 'Line Items'}" />
  </fieldset>

  <button class="af-button btn btn-primary"
          ng-click="afform.submit()"
          ng-if="afform.showSubmitButton"
          ng-disabled="!(afform.afOrderCart && afform.afOrderCart.line_items && afform.afOrderCart.line_items.hasRows())">
    Save Contribution
  </button>
</af-form>
```

### Minimal edit skeleton

```html
<!-- myedit.aff.html -->
<af-form ctrl="afform">
  <af-entity type="Contribution" name="Contribution1" actions="{update: true}" url-autofill="1" />

  <fieldset af-fieldset="Contribution1">
    <af-field name="receive_date" defn="{input_attrs: {defaultTimeOnDate: true}}" />
    <!-- other header fields -->
  </fieldset>

  <af-order-edit-cart contribution-id="routeParams.Contribution1"></af-order-edit-cart>

  <button class="af-button btn btn-primary" ng-click="afform.submit()" ng-if="afform.showSubmitButton">
    Save Changes
  </button>
</af-form>
```

The cart, companions, validation stand-down, `Order.create` / `OrderAO.editOrder`, and
redirect-by-id are all handled by `afform_order`.

---

## Roadmap / known limitations

- **Afform submission-viewer error.** Viewing a stored submission of a cart form
  currently throws an Angular error (`af-markup` connecting outside a bootstrapped
  `af-form`, plus a `.data` undefined in the runtime expressions). The form *saves*
  submissions fine; only the in-app viewer is affected. Workarounds: don't review
  through the viewer, or set `create_submission => FALSE` on the form.
- **Multi-processor recurring cadence handling.** Each CheckoutOption that supports
  recurring needs to translate the saved `ContributionRecur` into `doPayment`'s
  `is_recur` / `contributionRecurID` / frequency keys. A shared helper would DRY this up
  across processors.
- **No persisted per-line reversal back-reference.** A paid-line change is recorded as a
  negative reversal line beside the intact original. The modify result and
  `OrderModifiedEvent` expose the reversal pairing *for that call*, but nothing stores a
  durable "reverses line item N" link on the line itself, so after several edits over
  time the ledger can't be walked to answer "is *this* specific line already reversed?"
  from the rows alone. This is a pre-existing core gap (core's own Order/LineItem
  reversals carry no back-link) that this edit flow surfaces rather than introduces.
  **Current mitigation:** the edit flow's optimistic-concurrency snapshot aborts if the
  contribution's line-item set changed since the form opened — which prevents acting on
  stale state but is not per-line double-reversal protection. **Eventual fix:** persist an
  explicit reversal reference (ideally in core) so provenance is exact.
- **Connected-entity removal on edit.** The pending/template restructure has a marked
  seam for removing a membership/participant tied to a removed line, but that teardown
  is not yet implemented — a removed membership line deletes the line, not the connected
  membership.

---

## Source layout

```
afform_order/
├── Civi/
│   └── AfformOrder/
│       ├── AfformInputTypes.php                     # LineItemCart input type
│       ├── CartForm.php                             # name-free cart detection
│       ├── CompanionLogic.php                       # companion orchestrator (strip + dispatch)
│       ├── ModifyResult.php                         # api4 Result subclass carrying validate_metadata
│       ├── Submit.php                               # validate stand-down + create/edit submit subscribers
│       └── Event/
│           ├── ComputeCompanionsEvent.php           # companion-generation seam
│           ├── OrderCreateEvent.php                 # pre-save create shape seam
│           ├── OrderCreateValidateEvent.php         # create veto seam
│           ├── OrderCreatedEvent.php                # post-create read-only seam
│           ├── OrderModifyEvent.php                 # pre-write edit reshape seam (added lines)
│           ├── OrderModifyValidateEvent.php         # edit validate/veto seam (+ metadata bag)
│           ├── OrderModifiedEvent.php               # post-restructure informational seam
│           ├── OrderEditRoutedEvent.php             # declined-edit routing seam
│           ├── OrderRecurTemplateCreatedEvent.php   # recur template materialized seam
│           └── OrderFinancialAccountResolveEvent.php# financial-account resolution seam
├── Civi/Api4/
│   ├── AfformOrder.php                              # computeCompanions
│   ├── OrderAO.php                                  # editOrder / modify / ensureRecurTemplate / reclassifyLineValue / helpers
│   ├── OrderLineItem.php                            # LineItem writes under a non-colliding entity name
│   ├── Action/AfformOrder/ComputeCompanions.php
│   ├── Action/OrderAO/{EditOrder,Modify,EnsureRecurTemplate,ReclassifyLineValue,CanOverrideLineItems,GetFromEmails}.php
│   ├── Action/OrderLineItem/{Create,Delete}.php
│   └── Service/Spec/Provider/OrderLineItemCreateSpecProvider.php
├── CRM/AfformOrder/                                 # civix DAO/BAO for OrderLineItem
└── ang/
    ├── afformOrder.{ang.php,js}                     # input-type module bootstrap
    ├── afformOrder/{LineItemCart,LineItemCartAdmin}.html
    ├── afformOrderCreate.aff.{html,php}             # sample create form
    ├── afformOrderEdit.aff.{html,php}               # sample edit form
    └── afFieldLineItems/
        ├── afFieldLineItems.{ang.php,js,component.js,html,css}   # cart directive (create + edit)
        ├── afOrderEditCart.component.js             # edit-cart host
        ├── afExistingMembershipSelect.{component.js,html}
        ├── afMembershipStatusSelect.{component.js,html}
        ├── afoDatepickerDefaultTime.directive.js    # date-only datetime helper
        ├── afOrderCartChecks.service.js             # advisory-check registry
        ├── afOrderLineLocks.service.js              # row-lock registry
        ├── afOrderPickerFilters.service.js          # picker-filter registry
        └── afOrderPopup.service.js                  # popup close + Search Kit reload helper
```
