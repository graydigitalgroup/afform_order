(function(angular, $, _) {
  "use strict";

  // Escape dynamic text before injecting into select2 option markup.
  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /**
   * <af-field-line-items> — cart/line-item editor for Afform-based order forms.
   *
   * Primary usage is as the template for the `LineItemCart` Afform input type
   * (see Civi\AfformOrder\AfformInputTypes): a form author adds
   *   <af-field defn="{name: 'line_items', input_type: 'LineItemCart'}" />
   * and afField ng-includes ~/afformOrder/LineItemCart.html, which renders this
   * directive. In that mode the directive takes no attributes and discovers its
   * context from the surrounding afField / afForm controllers:
   *   - the cart field name comes from the parent afField's fieldName
   *     (= the extra field's defn.name);
   *   - the cart value is read/written at afForm.getFieldData()[name].
   *
   * It can also be used standalone with an explicit name binding (legacy), in
   * which case the bindings below apply.
   *
   * Bindings (optional; only used in standalone mode):
   *   name                  string  cart field name
   *   label                 string  display label
   *   contactId             expr    referenced contact (reserved for filters)
   *   allowedPriceSets      array   picker filter (PriceSet ids); empty = all
   *   excludedPriceFields   array   picker filter (PriceField ids to hide)
   *
   * Requires (all optional so the directive degrades gracefully):
   *   ^^afForm    the form controller; cart state is published to the form's
   *               extras slot via getFieldData(), and an afOrderCart[name]
   *               helper namespace is exposed for the form template.
   *   ^^afField   the wrapping field (input-type mode); supplies the field name.
   *
   * Cart row shape (kept in sync with Civi\AfformOrder\CompanionLogic):
   *   {
   *     price_field_id, price_field_value_id,
   *     qty, unit_price, line_total, financial_type_id,
   *     label, entity_table,
   *     _cart_id,
   *     _companion_for?, _afform_order_companion?,  // auto rows only
   *     _html_type, _is_enter_qty,                  // UI hints
   *     _is_override?,                              // staff-customized
   *     // membership rows only:
   *     _membership_type_id?, _base_num_terms?, _num_terms_per_unit?,
   *     _existing_membership_id?,                   // per-line renewal target
   *     _start_date?, _end_date?                    // per-line explicit dates
   *   }
   *
   * The picker is a select2 (crm-ui-select on a hidden input) acting as a
   * pick-list via on-crm-ui-select: selecting an item fires addPickerSelection
   * and the widget clears itself. formatPickerOption renders rich rows.
   *
   * Server sync: every cart mutation triggers a debounced (250ms) call to
   * AfformOrder.computeCompanions; a generation counter discards stale replies.
   */
  angular.module('afFieldLineItems').component('afFieldLineItems', {
    bindings: {
      name: '@',
      cartFieldName: '<',
      label: '@',
      contactId: '<',
      allowedPriceSets: '<',
      excludedPriceFields: '<',
      // ---- Edit-mode (opt-in) -------------------------------------------
      // When editMode is truthy the component edits the line items of an
      // EXISTING contribution rather than building a cart for Order.create.
      // It loads that contribution's lines (each stamped with its real
      // LineItem.id), lets staff add/remove/edit, and on submit drives the
      // changes (tracked per-row via _dirty / _pending_remove) through
      // OrderAO.modify (add / remove / reverse). Companion computation
      // is UNCHANGED and runs in both modes: adding a parent still spawns its
      // companion; removing a loaded parent reverses parent + companion as a
      // pair. Default off, so the create path is wholly unaffected.
      editMode: '<',
      editContributionId: '<',
      // Origin signal forwarded to OrderAO.modify as `context` (default
      // 'cart_edit'), plus optional structured detail. A modify-validate
      // subscriber (e.g. a refund-gate subscriber) uses these to decide whether
      // a refund-producing edit is allowed. A refund-request flow sets
      // context='refundrequest' + contextDetail={activity_id: N}.
      editContext: '<',
      editContextDetail: '<',
      // Optional callback invoked after a successful submitEdit(), so a host
      // form (or the refundrequest flow) can react (close dialog, refresh).
      onEditSaved: '&?',
      // Optional callback invoked when OrderAO.modify is vetoed and the
      // validate event carried metadata (a consumer's gate attached structured
      // outcome data via setMetadata). afform_order names no metadata keys and
      // has no refund concept; it forwards the whole bag. A consumer extension
      // binds this and interprets its own keys. Called with:
      //   { metadata: <validate_metadata bag from the server>,
      //     contributionId, toAdd, toRemove }
      // If unbound, the cart just surfaces the veto message as an error.
      onRefundRequired: '&?'
    },
    require: {
      afForm: '?^^afForm'
    },
    templateUrl: '~/afFieldLineItems/afFieldLineItems.html',
    controller: function($scope, $timeout, $q, crmApi4, afOrderCartChecks) {
      var ts = $scope.ts = CRM.ts('afform_order');
      var ctrl = this;

      // Resolved-promise helper for building sequential chains (edit-mode
      // companion reconstruction). $q.when() with no arg resolves immediately.
      function $q_when() { return $q.when(); }

      // Auto-companion marker (kept in sync with
      // Civi\AfformOrder\CompanionLogic::AUTO_MARKER).
      var AUTO_MARKER = '_afform_order_companion';

      // Manual-edit marker (kept in sync with
      // Civi\AfformOrder\CompanionLogic::OVERRIDE_FLAG). On a companion row it
      // makes the server preserve rather than regenerate the row; on other
      // rows it just drives the "edited" highlight.
      var OVERRIDE_FLAG = '_is_override';

      // ---- State ----------------------------------------------------------
      ctrl.cart = [];
      ctrl.loading = false;
      ctrl.pickerOptions = [];
      ctrl.pickerSelect2Data = [];
      ctrl.pickerLoaded = false;
      ctrl.warnings = [];

      // Edit-modal state.
      ctrl.editOpen = false;
      ctrl.editRow = null;
      ctrl.editModel = null;
      // 'new' or 'existing' — only meaningful for membership rows in the modal.
      ctrl.editMembershipMode = 'new';

      // Override permission. Synchronously available because the permission
      // is declared in the module's .ang.php so CRM preloads it for the page.
      // When false, the edit/revert buttons and modal save are hidden.
      ctrl.canOverride = !!CRM.checkPerm('override afform order line items');

      // ---- Edit-mode state ------------------------------------------------
      // editMode is the opt-in flag; when on, the component loads an existing
      // contribution's lines and submits changes through OrderAO.modify rather
      // than persisting a cart for Order.create.
      ctrl.isEdit = false;
      // Status name of the contribution under edit ('Pending', 'Completed',
      // 'Partially paid', ...). The UI does not branch on it — OrderAO.modify
      // picks delete-vs-reverse by status — but we keep it for affordances
      // (e.g. a "paid: changes book a reversal" note) and to show running totals.
      ctrl.editContributionStatus = null;
      ctrl.editSaving = false;
      // Rows scheduled for removal in edit-mode are not spliced out (so staff
      // can see what will be reversed/deleted and undo it); they stay in the
      // cart with _pending_remove = TRUE and render struck-through. Companion
      // rows are removed in lockstep with their parent (paired).

      // Discovered contact id (best-effort from afForm) and its memberships,
      // used by the per-line existing-membership picker in the modal.
      ctrl.contactId = null;
      ctrl.contactMemberships = [];
      // All active membership statuses, used by the modal's status select.
      ctrl.membershipStatuses = [];

      var recomputeGen = 0;
      var debouncePromise = null;
      // Exposed cart facade (set once afForm is available) + change tracking
      // for the advisory-gate acknowledgment.
      var cartApi = null;
      var lastWarningSig = null;

      // ---- Lifecycle ------------------------------------------------------
      ctrl.$onInit = function() {
        ctrl.isEdit = !!ctrl.editMode;

        // Resolve the cart field name. In input-type mode it is passed down
        // from the wrapping afField controller (cart-field-name="$ctrl.fieldName"
        // in LineItemCart.html); standalone callers may pass an explicit `name`
        // binding instead. In edit-mode there may be no wrapping afField, so we
        // fall back to a private slot name.
        if (!ctrl.name) {
          ctrl.name = ctrl.cartFieldName || (ctrl.isEdit ? '_afoEditCart' : ctrl.name);
        }

        // EDIT-MODE: load the existing contribution's line items into the cart,
        // each stamped with its real LineItem.id and a frozen snapshot of its
        // original values, then mark loaded rows as overrides so the companion
        // recompute preserves (never regenerates) them. The create-mode prefill
        // path below is skipped. See loadExistingOrder().
        if (ctrl.isEdit) {
          if (!ctrl.editContributionId) {
            CRM.alert(ts('Edit mode requires a contribution id'), ts('Error'), 'error');
          }
          else {
            ctrl.loadExistingOrder(ctrl.editContributionId);
          }
          // Still load picker options + membership statuses (shared with create).
          ctrl.loadPickerOptions();
          ctrl.loadMembershipStatuses();
          // Rebuild picker select2 data on cart changes (same as create).
          $scope.$watchCollection(function() { return ctrl.cart; }, function() {
            ctrl.buildPickerSelect2Data();
          });
          return;
        }

        // Pull any prefilled cart (create-mode edit/prefill) from the form's
        // extras slot.
        if (ctrl.afForm && ctrl.afForm.getFieldData) {
          var fieldData = ctrl.afForm.getFieldData();
          ctrl.cart = angular.copy(fieldData[ctrl.name]) || [];

          ctrl.afForm.afOrderCart = ctrl.afForm.afOrderCart || {};
          cartApi = {
            hasRows: function() { return ctrl.cart.length > 0; },
            hasMembershipRow: function() {
              return ctrl.cart.some(function(r) {
                return r.entity_table === 'civicrm_membership';
              });
            },
            total: function() { return ctrl.cartTotal(); },
            // Advisory gate, consumed by the form near its submit button:
            //   warnings()           current advisory messages
            //   confirmed            staff acknowledgment (two-way; ng-model)
            //   needsConfirmation()  true while unacknowledged warnings exist,
            //                        so the form can disable submit until staff
            //                        either fix the discrepancy or confirm it.
            warnings: function() { return ctrl.warnings; },
            confirmed: false,
            needsConfirmation: function() {
              return ctrl.warnings.length > 0 && !cartApi.confirmed;
            }
          };
          ctrl.afForm.afOrderCart[ctrl.name] = cartApi;
        }

        // Best-effort contact discovery for the per-line existing-membership
        // picker. Conventional 'Contact1' first; if the form uses a different
        // entity name, the picker simply shows no existing memberships.
        //
        // The contact id is prefilled asynchronously (URL autofill happens
        // after $onInit), so we $watch for it and load once it appears.
        // Same pattern as <af-existing-membership-select>.
        if (ctrl.afForm && ctrl.afForm.getData) {
          $scope.$watch(function() {
            var d = ctrl.afForm.getData('Contact1');
            if (d && d[0]) {
              return (d[0].fields && d[0].fields.id) || d[0].id || null;
            }
            return null;
          }, function(cid) {
            if (cid && cid !== ctrl.contactId) {
              ctrl.contactId = cid;
              ctrl.loadContactMemberships(cid);
            }
          });
        }

        // Rebuild the picker's select2 data whenever the cart changes, so
        // membership types already in the cart are filtered out (no two of
        // the same type in one cart — a deliberate UI constraint).
        $scope.$watchCollection(function() { return ctrl.cart; }, function() {
        ctrl.buildPickerSelect2Data();
        });

        // Load picker options up front so the select2 is populated by the
        // time crm-ui-select compiles it (it reads its data once at init).
        ctrl.loadPickerOptions();

        // Load membership statuses for the modal's status override select.
        ctrl.loadMembershipStatuses();

        // Run once on load to re-stamp companion markers / detect drift.
        if (ctrl.cart.length > 0) {
          ctrl.recompute();
        }

        // Advisory checks: surface non-blocking warnings (e.g. a qty that
        // doesn't line up with the chosen recurrence) and refresh them whenever
        // the cart or any form field changes. Checks never mutate the cart or
        // block submission — staff decide what to do.
        ctrl.runChecks();
        if (ctrl.afForm && ctrl.afForm.getFieldData) {
          $scope.$watch(function() {
            return ctrl.afForm.getFieldData();
          }, function() {
            ctrl.runChecks();
          }, true);
        }
      };

      // ---- Picker ---------------------------------------------------------
      ctrl.loadPickerOptions = function() {
        var where = [
          ['is_active', '=', true],
          ['price_field_id.is_active', '=', true],
          ['price_field_id.price_set_id.is_active', '=', true]
        ];
        if (ctrl.allowedPriceSets && ctrl.allowedPriceSets.length) {
          where.push(['price_field_id.price_set_id', 'IN', ctrl.allowedPriceSets]);
        }
        if (ctrl.excludedPriceFields && ctrl.excludedPriceFields.length) {
          where.push(['price_field_id', 'NOT IN', ctrl.excludedPriceFields]);
        }

        crmApi4('PriceFieldValue', 'get', {
          select: [
            'id', 'label', 'amount', 'financial_type_id', 'membership_type_id',
            'membership_num_terms',
            'price_field_id',
            'price_field_id.label',
            'price_field_id.html_type',
            'price_field_id.is_enter_qty',
            'price_field_id.price_set_id',
            'price_field_id.price_set_id.title'
          ],
          where: where,
          orderBy: {
            'price_field_id.price_set_id.title': 'ASC',
            'price_field_id.weight': 'ASC',
            'weight': 'ASC'
          }
        }).then(function(rows) {
          ctrl.pickerOptions = rows || [];
          ctrl.buildPickerSelect2Data();
          ctrl.pickerLoaded = true;
        }, function(err) {
          CRM.alert(
            (err && err.error_message) || ts('Failed to load price options'),
            ts('Error'), 'error'
          );
        });
      };

      // Build (or rebuild) the picker's select2 data from pickerOptions,
      // filtering out membership PFVs whose membership_type_id is already in
      // the cart — the simplest form of same-type duplicate prevention.
      ctrl.buildPickerSelect2Data = function() {
        var claimedTypes = {};
        ctrl.cart.forEach(function(r) {
          if (r && r.entity_table === 'civicrm_membership' && r._membership_type_id) {
            claimedTypes[r._membership_type_id] = true;
          }
        });
        ctrl.pickerSelect2Data = (ctrl.pickerOptions || []).filter(function(pfv) {
          if (pfv.membership_type_id && claimedTypes[pfv.membership_type_id]) {
            return false;
          }
          return true;
        }).map(function(pfv) {
          var amount = parseFloat(pfv.amount) || 0;
          var text = pfv['price_field_id.label'] + ': ' + pfv.label;
          if (amount > 0) {
            text = text + ' (' + amount.toFixed(2) + ')';
          }
          return {
            id: String(pfv.id),
            text: text,
            priceField: pfv['price_field_id.label'],
            valueLabel: pfv.label,
            priceSet: pfv['price_field_id.price_set_id.title'],
            amount: amount,
            isMembership: !!pfv.membership_type_id
          };
        });
      };

      // Load the discovered contact's memberships once for the per-line
      // existing-membership picker. Filtered to the row's membership type at
      // modal-open time via getEditAvailableMemberships(). Re-runs checks on
      // completion so the built-in "did you mean to renew?" advisory can fire
      // against newly-arrived data.
      ctrl.loadContactMemberships = function(contactId) {
        crmApi4('Membership', 'get', {
          select: [
            'id', 'membership_type_id', 'membership_type_id:label',
            'status_id:label', 'start_date', 'end_date'
          ],
          where: [['contact_id', '=', contactId]],
          orderBy: { 'end_date': 'DESC' }
        }).then(function(rows) {
          ctrl.contactMemberships = rows || [];
          ctrl.runChecks();
        }, function() {
          // Picker silently shows no options if loading fails.
          ctrl.contactMemberships = [];
          ctrl.runChecks();
        });
      };

      // Memberships available to choose for the current edit row (matching
      // the row's membership type). Used as ng-options data for the modal.
      ctrl.getEditAvailableMemberships = function() {
        if (!ctrl.editModel || !ctrl.editModel._membership_type_id) {
          return [];
        }
        var typeId = ctrl.editModel._membership_type_id;
        return ctrl.contactMemberships.filter(function(m) {
          return String(m.membership_type_id) === String(typeId);
        });
      };

      // Format an existing-membership row as a single label string. Kept on
      // the controller (not inlined in ng-options) because Angular's ng-options
      // expression parser doesn't reliably handle complex bracket-string keys
      // with colons combined with ternary / nested string concat.
      ctrl.formatMembershipOption = function(m) {
        if (!m) { return ''; }
        var parts = [m['membership_type_id:label'] || ts('Membership')];
        if (m['status_id:label']) { parts.push(m['status_id:label']); }
        if (m.end_date) { parts.push(m.end_date); }
        return parts.join(' · ');
      };

      // Load all active membership statuses for the modal's status override
      // select. Loaded once on init.
      ctrl.loadMembershipStatuses = function() {
        crmApi4('MembershipStatus', 'get', {
          select: ['id', 'label'],
          where: [['is_active', '=', true]],
          orderBy: { weight: 'ASC' }
        }).then(function(rows) {
          ctrl.membershipStatuses = rows || [];
        }, function() {
          ctrl.membershipStatuses = [];
        });
      };

      // select2 formatResult: rich dropdown row with membership badge + amount.
      ctrl.formatPickerOption = function(item) {
        if (!item || !item.id) {
          return item && item.text ? escapeHtml(item.text) : '';
        }
        var html = '<span class="afo-opt-field">' + escapeHtml(item.priceField) + '</span>: ' +
          '<span class="afo-opt-value">' + escapeHtml(item.valueLabel) + '</span>';
        if (item.isMembership) {
          html += ' <span class="afo-cart-badge afo-cart-badge-membership">' +
            escapeHtml(ts('membership')) + '</span>';
        }
        var meta = item.priceSet || '';
        if (item.amount > 0) {
          var sep = meta ? ' \u00b7 ' : '';
          meta += sep + '\u0024' + item.amount.toFixed(2);
        }
        if (meta) {
          html += '<div class="afo-opt-meta">' + escapeHtml(meta) + '</div>';
        }
        return html;
      };

      ctrl.addPickerSelection = function(selection) {
        // `selection` is the chosen PriceFieldValue id, delivered by the
        // on-crm-ui-select directive (which also closes/clears the widget).
        var pfv = null;
        for (var i = 0; i < ctrl.pickerOptions.length; i++) {
          if (String(ctrl.pickerOptions[i].id) === String(selection)) {
            pfv = ctrl.pickerOptions[i];
            break;
          }
        }
        if (pfv) {
          ctrl.addRow(pfv);
        }
      };

      // ---- Cart mutations -------------------------------------------------
      ctrl.addRow = function(pfv) {
        var amount = parseFloat(pfv.amount) || 0;
        var isMembership = !!pfv.membership_type_id;
        var row = {
          price_field_id: pfv.price_field_id,
          price_field_value_id: pfv.id,
          qty: 1,
          unit_price: amount,
          line_total: amount,
          financial_type_id: pfv.financial_type_id,
          label: pfv['price_field_id.label'] + ' \u2014 ' + pfv.label,
          entity_table: isMembership ? 'civicrm_membership' : 'civicrm_contribution',
          _cart_id: mintCartId(),
          _html_type: pfv['price_field_id.html_type'],
          _is_enter_qty: !!pfv['price_field_id.is_enter_qty']
        };
        if (isMembership) {
          // Per-unit term count: the PriceFieldValue default, overridable via
          // the edit modal. _base_num_terms is the immutable default (for the
          // modal's "default" hint and revert); _num_terms_per_unit is current.
          var baseTerms = parseInt(pfv.membership_num_terms, 10) || 1;
          row._base_num_terms = baseTerms;
          row._num_terms_per_unit = baseTerms;
          // Track membership type for same-type prevention and the per-line
          // existing-membership picker (filtered by type at modal-open time).
          row._membership_type_id = pfv.membership_type_id;
        }
        ctrl.cart.push(row);
        ctrl.recompute();
      };

      ctrl.removeRow = function(row) {
        if (row[AUTO_MARKER]) {
          // Auto-companion rows are managed by computeCompanions; removing
          // the driver row removes its companion on the next recompute. In
          // edit-mode the companion is paired explicitly (see below), so it
          // never has its own remove button either.
          return;
        }

        // EDIT-MODE: a row loaded from the existing contribution is NOT spliced
        // out — it is marked _pending_remove so staff can see (struck-through)
        // what will be reversed/deleted on save, and undo it. Its companion, if
        // any, is marked in lockstep (paired reversal). A row ADDED this session
        // (no _line_item_id) is just removed from the array as in create-mode.
        // We do NOT recompute here: recompute round-trips through
        // computeCompanions, which would try to regenerate the very companion
        // we just paired for removal. The companion link is already known from
        // the loaded data, so we pair locally instead.
        if (ctrl.isEdit) {
          if (row._line_item_id) {
            ctrl.markRemoved(row, true);
            return;
          }
          // Session-added row: drop it and its session companion (if the
          // companion was generated this session it has no _line_item_id and
          // should also just be dropped on the next recompute).
          var sidx = ctrl.cart.indexOf(row);
          if (sidx >= 0) {
            ctrl.cart.splice(sidx, 1);
            ctrl.recompute();
          }
          return;
        }

        var idx = ctrl.cart.indexOf(row);
        if (idx >= 0) {
          ctrl.cart.splice(idx, 1);
          ctrl.recompute();
        }
      };

      // EDIT-MODE: mark (or unmark) a loaded row for removal, pairing its
      // companion. Pure local state change — no server round-trip — so a
      // pending removal cannot trigger companion regeneration. Toggling back
      // off (undo) clears the flag on both the row and its companion.
      ctrl.markRemoved = function(row, removed) {
        if (!ctrl.isEdit || !row || !row._line_item_id) {
          return;
        }
        row._pending_remove = !!removed;
        // Clearing the flag (undo) also clears any typed removal reason so a
        // restored-then-re-removed row starts fresh.
        if (!removed) {
          row._removal_reason = null;
        }
        // Pair the companion: find the loaded auto row whose _companion_for
        // points at this row's cart id (or line item id).
        var companion = ctrl.findCompanionOf(row);
        if (companion) {
          companion._pending_remove = !!removed;
          if (!removed) {
            companion._removal_reason = null;
          }
        }
      };

      // EDIT-MODE: undo a pending removal (used by the "restore" affordance on
      // a struck-through row).
      ctrl.unmarkRemoved = function(row) {
        ctrl.markRemoved(row, false);
      };

      // Locate the companion (auto) row generated for a given parent row, by
      // the _companion_for back-link the companion carries. Works in both modes
      // (the link is stamped by CompanionLogic on the server and preserved on
      // load). Matches on either the parent's _cart_id or its _line_item_id so
      // it works for loaded and session-added parents.
      ctrl.findCompanionOf = function(parentRow) {
        if (!parentRow) { return null; }
        for (var i = 0; i < ctrl.cart.length; i++) {
          var r = ctrl.cart[i];
          if (!r || !r[AUTO_MARKER]) { continue; }
          var link = r._companion_for;
          if (link == null) { continue; }
          if (String(link) === String(parentRow._cart_id) ||
              (parentRow._line_item_id && String(link) === String(parentRow._line_item_id))) {
            return r;
          }
        }
        return null;
      };

      // Whether a row is marked for removal (edit-mode only).
      ctrl.isPendingRemove = function(row) {
        return !!(row && row._pending_remove);
      };

      ctrl.onRowChange = function(row) {
        var qty = parseFloat(row.qty) || 0;
        var unitPrice = parseFloat(row.unit_price) || 0;
        row.line_total = qty * unitPrice;
        // EDIT-MODE: a loaded row that changes is marked _dirty so the submit
        // knows to reverse-and-readd it; we do NOT recompute() (that round-trips
        // the whole cart through computeCompanions and would try to regenerate
        // companions for the settled lines). The local line_total above is
        // enough for the running total. A session-added row in edit-mode still
        // recomputes so its companion appears.
        if (ctrl.isEdit && row._line_item_id) {
          row._dirty = true;
          return;
        }
        ctrl.recompute();
      };

      // ---- Server sync ----------------------------------------------------
      ctrl.recompute = function() {
        if (debouncePromise) {
          $timeout.cancel(debouncePromise);
        }
        debouncePromise = $timeout(function() {
          var myGen = ++recomputeGen;
          ctrl.loading = true;
          crmApi4('AfformOrder', 'computeCompanions', {
            lineItems: ctrl.cart
          }).then(function(rows) {
            if (myGen !== recomputeGen) {
              // A newer call superseded us; ignore this reply.
              return;
            }
            ctrl.cart = rows || [];
            ctrl.persist();
            ctrl.runChecks();
            ctrl.loading = false;
          }, function(err) {
            if (myGen !== recomputeGen) {
              return;
            }
            ctrl.loading = false;
            CRM.alert(
              (err && err.error_message) || ts('Failed to recompute cart'),
              ts('Error'), 'error'
            );
          });
        }, 250);
      };

      ctrl.persist = function() {
        // Write the cart back to the form's extras slot. The af-field with
        // input_type LineItemCart registers this slot so the value survives
        // the submit pipeline.
        if (ctrl.afForm && ctrl.afForm.getFieldData) {
          ctrl.afForm.getFieldData()[ctrl.name] = ctrl.cart;
        }
      };

      // ---- Computed helpers -----------------------------------------------
      ctrl.cartTotal = function() {
        return ctrl.cart.reduce(function(sum, r) {
          return sum + (parseFloat(r.line_total) || 0);
        }, 0);
      };

      // Run all registered advisory checks against the current cart + form
      // state. Consumer extensions register checks via afOrderCartChecks.
      ctrl.runChecks = function() {
        var formData = (ctrl.afForm && ctrl.afForm.getFieldData) ? ctrl.afForm.getFieldData() : {};
        ctrl.warnings = afOrderCartChecks.run(ctrl.cart, { formData: formData });

        // Built-in check: a 'new' membership row whose type matches a
        // membership the contact already has. Mirrors CRM_Member_Form_Membership's
        // "did you mean to renew?" nudge — advisory only, so staff can
        // legitimately create a parallel membership if that's the intent.
        ctrl.cart.forEach(function(row) {
          if (!row || row.entity_table !== 'civicrm_membership') { return; }
          if (row._existing_membership_id) { return; }
          if (!row._membership_type_id) { return; }
          var matches = (ctrl.contactMemberships || []).filter(function(m) {
            return String(m.membership_type_id) === String(row._membership_type_id);
          });
          if (!matches.length) { return; }
          var top = matches[0];
          var typeLabel = top['membership_type_id:label'] || ts('membership');
          var statusLabel = top['status_id:label'] || ts('unknown status');
          var dateSuffix = top.end_date ? (' · ' + ts('ends') + ' ' + top.end_date) : '';
          ctrl.warnings.push(
            ts('This contact already has a %1 membership (%2%3). Use "Renew existing" on this line if you intend to extend it instead of creating a new one.', {
              1: typeLabel,
              2: statusLabel,
              3: dateSuffix
            })
          );
        });

        // Re-arm the acknowledgment whenever the set of warnings changes, so a
        // stale "confirmed" can't carry over after staff alter the cart or the
        // recurrence into a new discrepancy.
        var sig = ctrl.warnings.join('\u0001');
        if (cartApi && sig !== lastWarningSig) {
          lastWarningSig = sig;
          cartApi.confirmed = false;
        }
      };

      ctrl.isQtyEditable = function(row) {
        return !row[AUTO_MARKER];
      };

      ctrl.isUnitPriceEditable = function(row) {
        // Editable for any manually-added row — donations, price overrides,
        // and other line items all need adjustable amounts. Only the
        // auto-generated companion rows are locked.
        return !row[AUTO_MARKER];
      };

      ctrl.isAuto = function(row) {
        return !!row[AUTO_MARKER];
      };

      ctrl.isOverridden = function(row) {
        return !!row[OVERRIDE_FLAG];
      };

      // ---- Edit modal -----------------------------------------------------
      // Per-line editor. Opens on a working copy; saveEdit() applies the copy
      // back to the real row, flags overrides, and recomputes. Inline overlay
      // (not dialogService) to keep the wiring self-contained.
      ctrl.openEdit = function(row) {
        ctrl.editRow = row;
        ctrl.editModel = angular.copy(row);
        // For membership rows, derive the mode from whether the row already
        // points at an existing membership. The mode toggle in the modal then
        // switches between the renewal picker and the new-membership fields.
        if (row.entity_table === 'civicrm_membership') {
          ctrl.editMembershipMode = row._existing_membership_id ? 'existing' : 'new';
        }
        else {
          ctrl.editMembershipMode = 'new';
        }
        ctrl.editOpen = true;
      };

      // Toggle the modal's new-vs-existing mode. Clears the fields belonging
      // to the other mode so a save doesn't accidentally write both.
      ctrl.setEditMembershipMode = function(mode) {
        ctrl.editMembershipMode = mode;
        if (!ctrl.editModel) {
          return;
        }
        if (mode === 'existing') {
          ctrl.editModel._start_date = null;
          ctrl.editModel._end_date = null;
          ctrl.editModel._status_id = null;
          ctrl.editModel._membership_is_override = false;
        }
        else {
          ctrl.editModel._existing_membership_id = null;
        }
      };

      ctrl.cancelEdit = function() {
        ctrl.editOpen = false;
        ctrl.editRow = null;
        ctrl.editModel = null;
      };

      // Reset the modal's per-unit terms back to the membership type default.
      ctrl.resetEditTerms = function() {
        if (ctrl.editModel && ctrl.editModel._base_num_terms) {
          ctrl.editModel._num_terms_per_unit = parseInt(ctrl.editModel._base_num_terms, 10) || 1;
        }
      };

      ctrl.saveEdit = function() {
        if (!ctrl.editRow || !ctrl.editModel) {
          return;
        }
        var row = ctrl.editRow;
        var model = ctrl.editModel;
        var isAuto = !!row[AUTO_MARKER];
        var isMembership = (row.entity_table === 'civicrm_membership');
        var changed = false;

        // Label applies to every row.
        if ((model.label || '') !== (row.label || '')) {
          row.label = model.label;
          changed = true;
        }

        // Companion (auto) rows: allow staff to override unit_price (a
        // mutually-agreed amount for an arrears catch-up, etc.). Recompute
        // line_total from the new unit price and qty.
        if (isAuto) {
          var newPrice = parseFloat(model.unit_price);
          if (!isNaN(newPrice) && newPrice !== parseFloat(row.unit_price)) {
            row.unit_price = newPrice;
            row.line_total = newPrice * (parseFloat(row.qty) || 0);
            changed = true;
          }
        }

        // Membership rows: per-unit terms, mode-specific fields.
        var termsChanged = false;
        if (isMembership) {
          var newTerms = Math.max(1, parseInt(model._num_terms_per_unit, 10) || 1);
          termsChanged = newTerms !== (parseInt(row._num_terms_per_unit, 10) || 1);
          row._num_terms_per_unit = newTerms;

          if (ctrl.editMembershipMode === 'existing') {
            // Renewal: link to chosen existing membership; clear any dates,
            // status overrides, and is_override flag (renewals use the
            // existing membership's state).
            row._existing_membership_id = model._existing_membership_id || null;
            row._start_date = null;
            row._end_date = null;
            row._status_id = null;
            row._membership_is_override = false;
          }
          else {
            // New membership: carry explicit dates (blank = num_terms-driven
            // default at completion), optional status override, and explicit
            // is_override flag. Clear any renewal link.
            row._existing_membership_id = null;
            row._start_date = model._start_date || null;
            row._end_date = model._end_date || null;
            row._status_id = model._status_id || null;
            row._membership_is_override = !!model._membership_is_override;
          }
          if (termsChanged) { changed = true; }
        }

        // Override flag:
        //  - companion (auto) rows must be pinned after any edit so the next
        //    recompute preserves rather than regenerates them;
        //  - membership rows are flagged when ANY per-line customisation is
        //    present (existing link, explicit dates, or per-unit terms
        //    diverging from base);
        //  - plain rows are never flagged (nothing regenerates them).
        if (isAuto) {
          if (changed || row[OVERRIDE_FLAG]) {
            row[OVERRIDE_FLAG] = true;
          }
        }
        else if (isMembership) {
          if (isMembershipCustomized(row)) {
            row[OVERRIDE_FLAG] = true;
          }
          else {
            delete row[OVERRIDE_FLAG];
          }
        }

        // EDIT-MODE: a loaded row edited via the modal is marked _dirty so the
        // submit reverses-and-readds it. (Companion/session rows in edit-mode
        // recompute below as usual; only loaded rows carry _dirty.)
        if (ctrl.isEdit && row._line_item_id && changed) {
          row._dirty = true;
        }

        ctrl.cancelEdit();

        // EDIT-MODE: do not recompute() when the edited row is a loaded
        // (settled) line — that round-trips the whole cart through
        // computeCompanions and would try to regenerate companions for settled
        // lines. A session-added row still recomputes so its companion tracks
        // the edit.
        if (ctrl.isEdit && row._line_item_id) {
          return;
        }
        ctrl.recompute();
      };

      // Whether a membership row carries any of the per-line customisations
      // exposed in the modal. Drives the override-flag computation and the
      // "edited" highlight.
      function isMembershipCustomized(row) {
        if (row._existing_membership_id) { return true; }
        if (row._start_date || row._end_date) { return true; }
        if (row._status_id) { return true; }
        if (row._membership_is_override) { return true; }
        var base = parseInt(row._base_num_terms, 10) || 1;
        var current = parseInt(row._num_terms_per_unit, 10) || 1;
        return current !== base;
      }

      // Clear a manual override. Companion rows regenerate to the derived row
      // on recompute; membership rows reset per-line customisations to defaults
      // (terms to base; clear renewal link, explicit dates, status override,
      // and is_override flag).
      ctrl.revertOverride = function(row) {
        delete row[OVERRIDE_FLAG];
        if (!row[AUTO_MARKER] && row.entity_table === 'civicrm_membership') {
          if (row._base_num_terms) {
            row._num_terms_per_unit = parseInt(row._base_num_terms, 10) || 1;
          }
          row._existing_membership_id = null;
          row._start_date = null;
          row._end_date = null;
          row._status_id = null;
          row._membership_is_override = false;
        }
        ctrl.recompute();
      };

      // ---- Edit-mode: load, reconstruct companions, submit ----------------

      // Load an existing contribution's line items into the cart for editing.
      // Each loaded row is stamped with:
      //   _line_item_id   the real civicrm_line_item id (provenance)
      //   _cart_id        a fresh client id (for ng-repeat + companion linkage)
      // plus the UI hints the picker/modal expect (_html_type, _is_enter_qty,
      // _membership_type_id, _base_num_terms), sourced from each line's price
      // field value.
      //
      // Companion links are reconstructed (not persisted on civicrm_line_item):
      // for each driver row we ask the SAME companion engine that generated it
      // (AfformOrder.computeCompanions on a one-row cart) what companion it
      // would produce, then bind the first matching loaded line (by
      // price_field_value_id + qty, claimed once) as that driver's companion.
      // The matched line gets _companion_for + AUTO_MARKER so removal pairing,
      // the no-independent-remove rule, and recompute-preservation all work.
      //
      // Every loaded row is pinned (OVERRIDE_FLAG) so that when staff later ADD
      // a new parent and recompute() runs, the engine regenerates companions
      // for the new activity only and never disturbs the loaded (settled) lines.
      ctrl.loadExistingOrder = function(contributionId) {
        ctrl.loading = true;
        crmApi4('Contribution', 'get', {
          select: ['id', 'contribution_status_id:name', 'currency'],
          where: [['id', '=', contributionId]]
        }).then(function(contribs) {
          var contrib = (contribs && contribs[0]) || null;
          if (!contrib) {
            ctrl.loading = false;
            CRM.alert(ts('Contribution %1 not found', { 1: contributionId }), ts('Error'), 'error');
            return;
          }
          ctrl.editContributionStatus = contrib['contribution_status_id:name'];

          return crmApi4('LineItem', 'get', {
            select: [
              'id', 'contribution_id', 'entity_table', 'entity_id',
              'price_field_id', 'price_field_value_id', 'financial_type_id',
              'label', 'qty', 'unit_price', 'line_total', 'tax_amount',
              'price_field_id.html_type', 'price_field_id.is_enter_qty',
              'price_field_value_id.membership_type_id',
              'price_field_value_id.membership_num_terms'
            ],
            where: [['contribution_id', '=', contributionId]],
            orderBy: { id: 'ASC' }
          });
        }).then(function(lines) {
          if (!lines) { return; }
          ctrl.cart = (lines || []).map(function(li) {
            var isMembership = (li.entity_table === 'civicrm_membership');
            var row = {
              price_field_id: li.price_field_id,
              price_field_value_id: li.price_field_value_id,
              qty: li.qty,
              unit_price: li.unit_price,
              line_total: li.line_total,
              tax_amount: li.tax_amount,
              financial_type_id: li.financial_type_id,
              label: li.label,
              entity_table: li.entity_table,
              entity_id: li.entity_id,
              _cart_id: mintCartId(),
              _line_item_id: li.id,
              _html_type: li['price_field_id.html_type'],
              _is_enter_qty: !!li['price_field_id.is_enter_qty']
            };
            if (isMembership) {
              var baseTerms = parseInt(li['price_field_value_id.membership_num_terms'], 10) || 1;
              row._membership_type_id = li['price_field_value_id.membership_type_id'];
              row._base_num_terms = baseTerms;
              row._num_terms_per_unit = baseTerms;
            }
            return row;
          });

          // Reconstruct companion links using the live companion engine, then
          // pin every loaded row. Both steps need the engine result, so chain.
          return ctrl.reconstructCompanionLinks().then(function() {
            ctrl.cart.forEach(function(r) { r[OVERRIDE_FLAG] = true; });
            ctrl.persist();
            ctrl.loading = false;
          });
        }).catch(function(err) {
          ctrl.loading = false;
          CRM.alert(
            (err && err.error_message) || ts('Failed to load order for editing'),
            ts('Error'), 'error'
          );
        });
      };

      // For each driver row, ask the companion engine what companion it would
      // generate, then bind the first unclaimed loaded line matching that
      // shape (price_field_value_id + qty) as the driver's companion. Reuses
      // AfformOrder.computeCompanions verbatim — no provider-side match mode —
      // so the pairing rule is identical to the one that generated the lines.
      // Returns a promise resolving when all drivers have been processed.
      ctrl.reconstructCompanionLinks = function() {
        var claimed = {};
        // Build the chain of per-driver lookups sequentially; each is cheap and
        // sequencing keeps claim-once deterministic.
        var chain = $q_when();
        ctrl.cart.forEach(function(driver) {
          // A driver is any non-auto row; the engine itself decides whether a
          // given row actually spawns a companion (returns none if not a
          // driver), so we don't pre-filter on price field here.
          chain = chain.then(function() {
            // One-row cart: just this driver, with its current _cart_id so the
            // returned companion's _companion_for points back at it.
            var probe = angular.copy(driver);
            // Strip any pinning/marker so the engine treats it as a fresh
            // driver and actually emits the companion shape.
            delete probe[OVERRIDE_FLAG];
            delete probe[AUTO_MARKER];
            delete probe._companion_for;
            return crmApi4('AfformOrder', 'computeCompanions', {
              lineItems: [probe]
            }).then(function(rows) {
              (rows || []).forEach(function(r) {
                if (!r || !r[AUTO_MARKER]) { return; }
                // Find the first unclaimed loaded line matching the expected
                // companion shape: same price_field_value_id and same qty.
                for (var i = 0; i < ctrl.cart.length; i++) {
                  var cand = ctrl.cart[i];
                  if (!cand || !cand._line_item_id) { continue; }
                  if (claimed[cand._cart_id]) { continue; }
                  if (cand === driver) { continue; }
                  if (String(cand.price_field_value_id) !== String(r.price_field_value_id)) { continue; }
                  if (parseFloat(cand.qty) !== parseFloat(r.qty)) { continue; }
                  // Bind: this loaded line is driver's companion.
                  cand._companion_for = driver._cart_id;
                  cand[AUTO_MARKER] = true;
                  claimed[cand._cart_id] = true;
                  break;
                }
              });
            }, function() {
              // Engine failure: leave links unreconstructed for this driver.
              // The line stays an ordinary (independently removable) row —
              // degraded but not wrong.
            });
          });
        });
        return chain;
      };

      // Submit the edit: drive add/remove through OrderAO.modify. We know each
      // row's intent directly — no field-by-field diff needed:
      //   loaded + _pending_remove  -> remove (reversal/delete by status)
      //   loaded + _dirty           -> reverse-and-readd (remove original + add
      //                                current values; the model OrderAO.modify
      //                                expects for a changed paid line)
      //   loaded, untouched         -> nothing
      //   session-added (no id)     -> add
      // A FRESH live read is taken only as a safety check: skip a remove for a
      // line that has already vanished (the order moved since load). It is not
      // used to detect changes — the _dirty flag carries that.
      ctrl.submitEdit = function() {
        if (!ctrl.isEdit || ctrl.editSaving) { return; }
        if (!ctrl.editContributionId) {
          CRM.alert(ts('No contribution to modify'), ts('Error'), 'error');
          return;
        }
        ctrl.editSaving = true;

        // Hoisted so the catch handler can pass the intended change to a
        // refund-required consumer callback (the payload the refund request is
        // built from is exactly what we sent).
        var submittedToAdd = [];
        var submittedToRemove = [];

        // Fresh live read — the authority for what currently exists. Used to
        // detect staleness (the contribution moved since the form was opened).
        crmApi4('LineItem', 'get', {
          select: ['id'],
          where: [['contribution_id', '=', ctrl.editContributionId]]
        }).then(function(liveLines) {
          var liveIdList = (liveLines || []).map(function(li) { return String(li.id); }).sort();

          // STALENESS GUARD (optimistic concurrency): if the set of line-item
          // ids differs from what we loaded — any line added or removed by
          // another actor since the form was opened — abort the WHOLE submit.
          // We never try to merge or partially apply against state the staff
          // member didn't see; acting on a stale picture risks double-reversal
          // or re-adding an already-backed-out line. (Per-line double-reversal
          // protection needs persisted reversal provenance — see README.)
          //
          // The "ids present at load" baseline is the cart itself: every loaded
          // row still carries its _line_item_id (removed rows are marked
          // _pending_remove, not spliced; edited rows keep their id), and rows
          // added this session have no id. So we derive the baseline from the
          // cart rather than caching a separate array.
          var baseline = ctrl.cart
            .filter(function(r) { return r && r._line_item_id; })
            .map(function(r) { return String(r._line_item_id); })
            .sort();
          var diverged = baseline.length !== liveIdList.length ||
            baseline.some(function(id, i) { return id !== liveIdList[i]; });
          if (diverged) {
            ctrl.editSaving = false;
            CRM.alert(
              ts('This contribution was changed since you opened it. Please reload and try again.'),
              ts('Contribution changed'), 'error'
            );
            return;
          }

          var toAdd = [];
          var toRemove = [];

          ctrl.cart.forEach(function(row) {
            var isLoaded = !!row._line_item_id;

            // Removed loaded row -> remove by id (OrderAO.modify reverses or
            // deletes by status). The staleness guard above already proved the
            // line still exists, so no per-row presence check is needed. An
            // optional removal reason annotates the reversal line's label.
            if (isLoaded && row._pending_remove) {
              var removeEntry = { id: row._line_item_id };
              if (row._removal_reason && String(row._removal_reason).trim()) {
                removeEntry.removal_reason = String(row._removal_reason).trim();
              }
              toRemove.push(removeEntry);
              return;
            }

            // Session-added row (no provenance) -> add.
            if (!isLoaded) {
              if (row._pending_remove) { return; }
              toAdd.push(ctrl.toModifyAddSpec(row));
              return;
            }

            // Loaded row edited this session -> reverse-and-readd.
            if (row._dirty) {
              toRemove.push({ id: row._line_item_id });
              toAdd.push(ctrl.toModifyAddSpec(row));
            }
          });

          if (!toAdd.length && !toRemove.length) {
            ctrl.editSaving = false;
            CRM.alert(ts('No changes to save'), ts('Nothing to do'), 'info');
            return;
          }

          // Remember what we're submitting so the catch can hand it to a
          // refund-required handler without rebuilding it.
          submittedToAdd = toAdd;
          submittedToRemove = toRemove;

          var params = {
            contributionID: ctrl.editContributionId,
            lineItemsToAdd: toAdd,
            lineItemsToRemove: toRemove,
            // Context lets a subscriber (e.g. a refund-gate subscriber) reason
            // about the origin. The generic engine allows by default; a
            // consumer extension can veto a refund-producing edit unless it
            // came from an approved request.
            context: ctrl.editContext || 'cart_edit'
          };
          if (ctrl.editContextDetail) {
            params.contextDetail = ctrl.editContextDetail;
          }

          return crmApi4('OrderAO', 'modify', params).then(function(res) {
            ctrl.editSaving = false;
            CRM.alert(ts('Order updated'), ts('Saved'), 'success');
            if (ctrl.onEditSaved) {
              ctrl.onEditSaved({ result: (res && res[0]) || null });
            }
            // Reload so the cart reflects the new persisted state (reversal
            // lines, new lines, recomputed totals).
            ctrl.loadExistingOrder(ctrl.editContributionId);
          });
        }).catch(function(err) {
          ctrl.editSaving = false;

          // Refund-required veto: OrderAO.modify relays the validate event's
          // GENERIC metadata bag onto the exception under
          // error_data.validate_metadata. afform_order names no keys; a
          // consumer extension and this host agree on them. The host decides
          // what a given metadata key means by what it does in
          // onRefundRequired - we simply forward the whole bag plus the change
          // we submitted. With no handler bound, fall through to the error
          // alert.
          var data = (err && (err.error_data || err.errorData)) || null;
          var metadata = (data && data.validate_metadata) || null;
          if (metadata && ctrl.onRefundRequired) {
            ctrl.onRefundRequired({
              metadata: metadata,
              contributionId: ctrl.editContributionId,
              toAdd: submittedToAdd,
              toRemove: submittedToRemove
            });
            return;
          }

          CRM.alert(
            (err && err.error_message) || ts('Failed to save order changes'),
            ts('Error'), 'error'
          );
        });
      };

      // Build an OrderLineItem-create spec from a cart row, for OrderAO.modify's
      // lineItemsToAdd. Carries the price field linkage + computed totals;
      // OrderAO.modify forces contribution_id / entity_table / entity_id.
      ctrl.toModifyAddSpec = function(row) {
        var qty = parseFloat(row.qty) || 0;
        var unitPrice = parseFloat(row.unit_price) || 0;
        var spec = {
          price_field_id: row.price_field_id,
          price_field_value_id: row.price_field_value_id,
          financial_type_id: row.financial_type_id,
          label: row.label,
          qty: qty,
          unit_price: unitPrice,
          line_total: qty * unitPrice,
          entity_table: row.entity_table || 'civicrm_contribution'
        };
        // Carry membership per-line intent so a re-added membership line keeps
        // its dates/status/terms (OrderLineItem create honours entity_id.* and
        // the _num_terms machinery on the create path).
        if (row.entity_table === 'civicrm_membership') {
          if (row._existing_membership_id) { spec.entity_id = row._existing_membership_id; }
          if (row._num_terms_per_unit) { spec._num_terms_per_unit = row._num_terms_per_unit; }
          if (row._start_date) { spec['entity_id.start_date'] = row._start_date; }
          if (row._end_date) { spec['entity_id.end_date'] = row._end_date; }
          if (row._status_id) { spec['entity_id.status_id'] = row._status_id; }
        }
        return spec;
      };

      // ---- Utility --------------------------------------------------------
      function mintCartId() {
        return 'cart_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
      }
    }
  });
})(angular, CRM.$, CRM._);
