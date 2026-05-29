# afform_order

A generic engine for **Afform-based order forms**: build a CiviCRM admin/staff form
that produces a full Order (Contribution + line items + optional membership +
optional ContributionRecur) from an editable, in-form line-item cart, while
keeping Afform's existing checkout/payment flow intact.

`afform_order` itself is processor-agnostic and contains no business rules. It
provides the cart UI, the submit pipeline, the companion-line-item
orchestrator, and three extension points (two server-side, one client-side)
that consumer extensions hook into to add their own pricing, membership, and
validation logic.

---

## Status & requirements

**Base:** CiviCRM **6.15** branch.

**Required core patches.** This extension targets unreleased CiviCRM behaviour
and depends on the following PRs against `civicrm-core`. Until they merge and
ship, the corresponding patches need to be applied to the running CiviCRM.

| PR | Purpose | Required? |
|----|---------|-----------|
| [civicrm/civicrm-core#35728](https://github.com/civicrm/civicrm-core/pull/35728) | _(TODO: short description of what this changes in core; needed for the cart submit flow to land correctly.)_ | **Required** |
| [civicrm/civicrm-core#35562](https://github.com/civicrm/civicrm-core/pull/35562) | Token options on Afform extra fields (calculated fields / references to other fields). | **Optional** — only needed if a form wants to use Afform's token mechanism in extra-field defns. The components shipped here (`<af-field-line-items>`, `<af-existing-membership-select>`, `<af-membership-status-select>`) sidestep this entirely. |

**Companion extensions.** `afform_order` is the engine only. To see it in
action, a consumer extension supplies:
- The actual `.aff.html` form layout.
- Business rules: companion-line-item config, membership term scaling, advisory
  checks, etc. — wired through the extension points described below.

A working CiviCRM payment processor + its CheckoutOption implementation is
needed for the live checkout half of the flow.

---

## What it does, end to end

1. A form author defines an `.aff.html` whose Contribution slot is a single
   "cart" extra field of input type `LineItemCart`.
2. The cart UI lets staff add, edit, and remove line items (with quantity,
   unit price, etc.). On every mutation it calls back to the server to
   regenerate any **companion line items** configured via PriceField pairings,
   and re-runs registered **advisory checks** (e.g. "qty doesn't match the
   recurring period").
3. On submit, `afform_order`:
   - Stands down Afform's native `CreateContribution` for the current request
     (so it doesn't try to create a contribution from a non-existent
     amount/price-set on the form).
   - Builds `LineItem`s from the cart, creates a `ContributionRecur` if the
     form supplied recurrence fields, and runs `Order.create` to produce a
     **Pending** contribution + line items + (for membership lines) memberships,
     all linked.
   - Records the new contribution id on the submit event so the redirect /
     subsequent steps can address it.
4. Afform's existing checkout layer (`Civi\Checkout\Afform::startCheckout`)
   then runs, and a configured CheckoutOption hands the saved order to its
   payment processor's `doPayment`.

Throughout, the cart is the source of truth on the client; the
`ContributionRecur` and `Membership` are the source of truth on the server
after submit; and core's `OrderCompleteSubscriber` does the right thing with
`membership_num_terms` when the contribution eventually completes.

---

## Concepts & moving parts

### The `LineItemCart` Afform input type

Registered in `Civi\AfformOrder\AfformInputTypes`. A form author drops:

```html
<af-field defn="{name: 'line_items', input_type: 'LineItemCart', label: 'Line Items'}" />
```

into the Contribution entity slot. Afform `ng-include`s the input type's
template (`ang/afformOrder/LineItemCart.html`), which renders the cart
directive.

A form is detected as cart-managed purely by the presence of a `LineItemCart`
input type — there are no hardcoded field names.

The shipped input type metadata sets `extra_defn.data_type` to `String` so the
form schema doesn't fatal on `fillExtraFieldMetadata` in core builds that
predate the relevant fix.

### The cart directive — `<af-field-line-items>`

Located at `ang/afFieldLineItems/`. The directive:
- Reads/writes its cart state at `afForm.getFieldData()[name]`.
- Loads `PriceFieldValue` options for its add-line-item picker.
- Recomputes companion rows on every mutation (debounced ~250ms) via
  `AfformOrder.computeCompanions` (idempotent: passing the cart in returns the
  cart with auto-companion rows reconciled).
- Re-runs all registered advisory checks.
- Publishes a small facade on `afForm.afOrderCart[fieldName]` for the
  surrounding form template to use:
  - `hasRows()` / `hasMembershipRow()` / `total()`
  - `warnings()` — current advisory messages
  - `confirmed` — staff acknowledgment of warnings (two-way; ng-model target)
  - `needsConfirmation()` — true while there are unacknowledged warnings

### Submit pipeline — `Civi\AfformOrder\Submit`

Two listeners on the Afform submit event:

- **`civi.afform.validate` @ priority 2000.** Stands down Afform's
  `CreateContribution` listener for the current request (`setActive(FALSE)`),
  and enforces that a cart-managed form has at least one line item. Uses
  `addValidationError()` to be portable across CiviCRM builds that have or
  haven't yet merged the `addError()` API.
- **`civi.afform.submit` @ priority 0.** Reads the cart out of the *raw*
  extras (`getApiRequest()->getValues()['extra']['fields']` — afForm submits
  extras as a single object, not the per-entity record list that
  `AbstractProcessor` synthesises), recomputes companions one final time,
  builds line items, builds recur values, dispatches the `AlterOrderEvent`,
  and runs `Order.create`. The new contribution id is set on the submit event
  so the redirect URL token `[Contribution1.0.id]` resolves correctly.

Afform's native checkout runs after, at its existing priority of −100, so
nothing here needs to touch payment.

### Companion line items — pluggable providers

Companion generation is a pluggable concern. `afform_order` ships the
orchestrator (`Civi\AfformOrder\CompanionLogic`) and the event
(`Civi\AfformOrder\Event\ComputeCompanionsEvent`) it dispatches; it ships
**no concrete companion rules**. Consumer extensions register one or more
`AutoSubscriber`s on the event, each appending rows for any drivers in scope
of their rule.

The orchestrator's job is small but specific:
1. Strip every row tagged with `CompanionLogic::AUTO_MARKER` so providers
   always see a clean cart.
2. Dispatch `ComputeCompanionsEvent` so subscribed providers can append.
3. Return the resulting cart.

This is the right place to add companion shapes — membership add-ons,
flat-fee surcharges, conditional bundles, etc. Each provider owns its own
configuration (e.g. settings pages in its own extension) and its own rule;
`afform_order` doesn't pre-commit to any pricing model. See "Server: companion
providers via `Civi\AfformOrder\Event\ComputeCompanionsEvent`" below for the
registration pattern.

`CompanionLogic::compute` is idempotent (assuming every registered provider
is). The cart directive calls it on every mutation via
`AfformOrder.computeCompanions`; the submit subscriber calls it one last
time before saving so what the server records matches what the cart showed.

### Membership pickers

Both ship at `ang/afFieldLineItems/`:

- **`<af-existing-membership-select>`** — lets staff link the new
  contribution to an existing membership instead of creating a new one. Reads
  the prefilled contact id from `afForm.getData(entityName)[0].fields.id`,
  loads that contact's memberships via API4, and writes the chosen id to the
  configured extras field (the submit subscriber uses it as the
  `entity_id` override on the corresponding membership line).
- **`<af-membership-status-select>`** — loads `MembershipStatus` options via
  API4 and binds the chosen id back to the form's extras (used in conjunction
  with `is_override` on the membership line). Needed because afform extra
  Select fields can't derive options from an entity that isn't on the form.

Both seed from the extras on `$onInit`, so they're ready to prefill for an
edit form.

---

## Extension points

These are how a consumer extension layers business rules onto the generic
engine without forking it.

### Server: `Civi\AfformOrder\Event\AlterOrderEvent`

Fires from `Submit` just before `Order.create`, with mutable line items + recur
values and read-only context (cart, extras, contribution, original submit
event). Use it for any per-deployment Order shaping — e.g. setting
`membership_num_terms = qty × base` so the membership term tracks quantity, or
applying business-specific overrides.

```php
use Civi\AfformOrder\Event\AlterOrderEvent;
use Civi\Core\Service\AutoSubscriber;

class MyOrderSubscriber extends AutoSubscriber {
  public static function getSubscribedEvents(): array {
    return [AlterOrderEvent::NAME => 'shapeOrder'];
  }
  public function shapeOrder(AlterOrderEvent $event): void {
    $lineItems = $event->getLineItems();
    // ...mutate...
    $event->setLineItems($lineItems);
  }
}
```

### Server: companion providers via `Civi\AfformOrder\Event\ComputeCompanionsEvent`

The orchestrator (`CompanionLogic::compute`) dispatches this event on every
cart recompute, after stripping all previously auto-generated rows. Each
subscribed provider appends rows for any drivers in its scope; the resulting
cart is what the directive renders and what the submit pipeline records.

When to use this vs. `AlterOrderEvent`:
- **`ComputeCompanionsEvent`** — *generation* of companion lines (a derived
  row that follows from another row). Fires repeatedly during cart editing.
  Must be idempotent.
- **`AlterOrderEvent`** — *final shaping* of the order at submit time
  (membership term scaling, contribution-level overrides, etc.). Fires once
  per submit.

Providers MUST stamp every row they generate with
`CompanionLogic::AUTO_MARKER => TRUE` so the next recompute strips it; without
the stamp, auto rows accumulate. Stamping `CompanionLogic::PROVIDER_KEY` with
an extension-defined string is optional but recommended for audit/debugging.

```php
use Civi\AfformOrder\CompanionLogic;
use Civi\AfformOrder\Event\ComputeCompanionsEvent;
use Civi\Core\Service\AutoSubscriber;

class MyCompanionProvider extends AutoSubscriber {

  public const PROVIDER_KEY = 'myext.event_addon';

  public static function getSubscribedEvents(): array {
    return [
      ComputeCompanionsEvent::NAME => 'compute',
    ];
  }

  public function compute(ComputeCompanionsEvent $event): void {
    // 1. Bail early if your rule isn't configured (or doesn't apply).
    if (!self::isConfiguredForCurrentContext()) {
      return;
    }

    $cart = $event->getCart();

    // 2. Walk the cart for rows your rule cares about.
    foreach ($cart as $driver) {
      if (!$this->isDriver($driver)) {
        continue;
      }
      // 3. Append your companion row(s). Always stamp AUTO_MARKER.
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

The provider is auto-registered by the `scan-classes` mixin (no manual
services.yaml or hook wiring needed) as long as it lives under
`Civi/{Namespace}/` and extends `AutoSubscriber`.

If your provider needs configuration, ship its settings page in your own
extension (the `setting-admin` mixin handles the boilerplate). `afform_order`
intentionally has no admin page — it has nothing to configure.

Multiple providers can be active simultaneously — each appends independently.
If two providers shouldn't both fire for the same driver, gate that in each
provider's own logic (e.g. check whether another provider's marker is already
on a companion for the same driver).

### Client: `afOrderCartChecks` registry

Angular service in the `afFieldLineItems` module. Register an advisory check
from a consumer module's `.run()` block; the cart directive runs every
registered check on every cart/form change and surfaces the returned messages
through the gate facade.

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

The consuming form decides how to *present* the warnings — typically as a
"soft gate" right above the submit button, with a "confirm intentional"
checkbox that re-enables submit. The cart facade exposes `warnings()`,
`confirmed`, and `needsConfirmation()` exactly for this.

---

## Minimal form skeleton

A bare-bones cart-managed form looks roughly like this. A real consumer
extension's form will flesh this out with recurrence, membership overrides,
the submit gate, etc.

```php
// myform.aff.php
return [
  'type'                  => 'form',
  'title'                 => 'Create Contribution',
  'permission'            => ['access CiviContribute'],
  'create_submission'     => TRUE,
  'requires'              => ['afformOrder'],
  'placement'             => [/* ... */],
  'redirect'              => 'civicrm/contact/view/contribution?reset=1&action=view&id=[Contribution1.0.id]&cid=[Contact1.0.id]',
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

That's it — the cart, companions, validation stand-down, Order.create, and
redirect-by-id are all handled by `afform_order`.

---

## Roadmap / known limitations

- **Updating an existing Contribution.** Read-modify-write isn't built yet.
  The cart directive already supports prefill, so the edit form's shape is
  mostly there; the open question is how much editing to permit once a
  payment has been recorded (the financial-accounting balancing on
  `civicrm_financial_item` / `civicrm_financial_trxn` /
  `civicrm_entity_financial_trxn` once the contribution leaves Pending).
- **Afform submission-viewer error.** Viewing a stored submission of a
  cart form currently throws an Angular error (`af-markup` connecting outside
  a bootstrapped `af-form`, plus a `.data` undefined in the runtime
  expressions). The form *saves* submissions fine; only the in-app viewer
  is affected. Workarounds: don't review through the viewer, or set
  `create_submission => FALSE` on the form.
- **Multi-processor recurring cadence handling.** Each CheckoutOption that
  supports recurring needs to translate the saved `ContributionRecur` into
  `doPayment`'s `is_recur` / `contributionRecurID` / frequency keys. A shared
  helper would DRY this up across processors.

---

## Source layout

```
afform_order/
├── Civi/
│   └── AfformOrder/
│       ├── AfformInputTypes.php                    # LineItemCart input type
│       ├── CartForm.php                            # name-free cart detection
│       ├── CompanionLogic.php                      # orchestrator (strip + dispatch)
│       ├── Event/
│       │   ├── AlterOrderEvent.php                 # final-shaping seam (submit)
│       │   └── ComputeCompanionsEvent.php          # companion-generation seam
│       └── Submit.php                              # validate + submit subscribers
├── Civi/Api4/
│   ├── AfformOrder.php
│   └── Action/AfformOrder/
│       └── ComputeCompanions.php
└── ang/
    ├── afformOrder.{ang.php,js}                    # module bootstrap
    ├── afformOrder/LineItemCart.html               # input-type template
    └── afFieldLineItems/
        ├── afFieldLineItems.{ang.php,component.js,html,css}
        ├── afOrderCartChecks.service.js            # client extension point
        ├── afExistingMembershipSelect.{component.js,html}
        └── afMembershipStatusSelect.{component.js,html}
```
