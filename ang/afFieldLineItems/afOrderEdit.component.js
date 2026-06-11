(function(angular, $, _) {
  "use strict";

  /**
   * <af-order-edit contribution-id> — generic host for editing an existing
   * order (and, when it belongs to a recurring series, the series' future
   * installments) through the afform_order edit cart.
   *
   * It wraps <af-field-line-items edit-mode> and adds the orchestration that is
   * NOT specific to any one consumer:
   *   - resolving the contribution id (binding, or the page's #?contribution_id)
   *   - a scope toggle, offered only for a LIVE recurring series:
   *       "This installment" (default) edits the opened contribution;
   *       "Future installments" edits the series' TEMPLATE contribution,
   *       resolved LAZILY via OrderAO.ensureRecurTemplate on first opt-in.
   *     Each scope mounts its own cart instance (ng-if-per-scope, because the
   *     cart loads in $onInit — toggling destroys/recreates it so each scope
   *     gets a fresh load of its own contribution). We never infer an
   *     installment-vs-future split from a single edit; staff pick each pass
   *     explicitly and each is its own OrderAO.modify call.
   *   - an inline summary of a future-scope save (the new recurring amount and
   *     the processor-amendment outcome), kept visible since the cart's own
   *     alerts are transient.
   *
   * What it deliberately does NOT know about is policy. When the cart's
   * validate step VETOES a change (a consumer wants to route it somewhere
   * instead of applying inline), the host forwards the veto's metadata bag —
   * plus the change that was submitted — to the bound `on-validate-metadata`
   * consumer callback and lets the consumer decide what it means. The consumer
   * may resolve that callback with a message (a string, which may contain
   * markup such as a link); the host then shows that message in a generic
   * completion banner and hides the carts. afform_order names no keys in the
   * metadata bag and attaches no domain meaning to a veto here — what it means
   * lives entirely in the consumer extension.
   *
   * (The inner cart's binding is still named `on-refund-required`, a
   * pre-existing afform_order naming wart; this host adapts it to the neutral
   * `on-validate-metadata` seam it exposes.)
   */
  angular.module('afFieldLineItems').component('afOrderEdit', {
    bindings: {
      contributionId: '<',
      // Called when the cart vetoes a change and returns a non-empty
      // validate_metadata bag. Receives {metadata, contributionId, toAdd,
      // toRemove}. May return (a promise of) a message string - which may
      // contain markup such as a link - to display in the completion banner, or
      // a falsy value to fall back to a generic "not applied" notice.
      onValidateMetadata: '&?',
      // Called after any successful save (either scope) with {result}.
      onSaved: '&?'
    },
    template:
      '<div class="af-order-edit">' +
        '<div ng-if="$ctrl.terminalMessage" class="messages status crm-alert af-order-edit-complete">' +
          '<i class="crm-i fa-check-circle" aria-hidden="true"></i> ' +
          '<span ng-bind-html="$ctrl.terminalMessage"></span>' +
        '</div>' +
        '<div class="af-order-edit-scope btn-group" role="group" ng-if="$ctrl.hasRecur && !$ctrl.terminalMessage">' +
          '<button type="button" class="btn btn-default" ' +
            'ng-class="{active: $ctrl.scope === \'installment\'}" ' +
            'ng-click="$ctrl.setScope(\'installment\')">' +
            '{{ ts("This installment") }}</button>' +
          '<button type="button" class="btn btn-default" ' +
            'ng-class="{active: $ctrl.scope === \'future\'}" ' +
            'ng-disabled="$ctrl.templateLoading" ' +
            'ng-click="$ctrl.setScope(\'future\')">' +
            '<i class="crm-i" ng-class="$ctrl.templateLoading ? \'fa-spinner fa-spin\' : \'fa-refresh\'" aria-hidden="true"></i> ' +
            '{{ ts("Future installments") }}</button>' +
        '</div>' +
        '<div ng-if="$ctrl.scope === \'future\' && !$ctrl.terminalMessage" class="help af-order-edit-future-note">' +
          '{{ ts("You are editing the template that defines future installments of this recurring series. Saving updates what each upcoming charge will bill (and the recurring amount); this installment and past installments are unchanged.") }}' +
        '</div>' +
        '<div ng-if="$ctrl.scope === \'future\' && $ctrl.recurSummary && !$ctrl.terminalMessage" ' +
          'class="messages af-order-edit-recur-summary" ' +
          'ng-class="$ctrl.recurSummary.notified ? \'status\' : \'warning\'">' +
          '<i class="crm-i" ng-class="$ctrl.recurSummary.notified ? \'fa-check-circle\' : \'fa-exclamation-triangle\'" aria-hidden="true"></i> ' +
          '<span>{{ $ctrl.recurSummary.text }}</span>' +
        '</div>' +
        '<af-field-line-items ng-if="$ctrl.scope === \'installment\' && $ctrl.contributionId && !$ctrl.terminalMessage" ' +
          'edit-mode="true" ' +
          'edit-contribution-id="$ctrl.contributionId" ' +
          'edit-context="\'cart_edit\'" ' +
          'on-edit-saved="$ctrl.handleSaved(result)" ' +
          'on-refund-required="$ctrl.handleVeto(metadata, contributionId, toAdd, toRemove)">' +
        '</af-field-line-items>' +
        '<af-field-line-items ng-if="$ctrl.scope === \'future\' && $ctrl.templateContributionId && !$ctrl.terminalMessage" ' +
          'edit-mode="true" ' +
          'edit-contribution-id="$ctrl.templateContributionId" ' +
          'edit-context="\'cart_edit\'" ' +
          'on-edit-saved="$ctrl.handleTemplateSaved(result)">' +
        '</af-field-line-items>' +
      '</div>',
    controller: function($scope, $q, $sce, crmApi4) {
      var ts = $scope.ts = CRM.ts('afform_order');
      var ctrl = this;

      // Terminal message surfaced by a consumer's validate-metadata handler.
      // When set, the carts and toggle are hidden in favour of the banner. It
      // is rendered as trusted HTML (ng-bind-html) so the consumer can fold a
      // link into the message; the content is consumer-authored, not user input.
      ctrl.terminalMessage = null;

      // ---- Recurring scope state ----
      // 'installment' (the opened contribution) or 'future' (the series'
      // template). The toggle renders only when hasRecur resolves TRUE.
      ctrl.scope = 'installment';
      ctrl.hasRecur = false;
      ctrl.contributionRecurId = null;
      ctrl.templateContributionId = null;
      ctrl.templateLoading = false;
      // Persistent inline summary of the last future-scope save (new recurring
      // amount + processor outcome). The cart's own alerts are transient; the
      // "adjust at the processor manually" case should stay visible.
      ctrl.recurSummary = null;

      ctrl.$onInit = function() {
        // Resolve the contribution id. Prefer the bound value (from the host
        // form's route param); if that did not resolve, fall back to parsing
        // the URL hash ourselves (the page route uses #?contribution_id=123),
        // so the component does not depend on afform route-param scoping.
        if (!ctrl.contributionId) {
          ctrl.contributionId = parseContributionIdFromUrl();
        }
        if (ctrl.contributionId) {
          discoverRecur(ctrl.contributionId);
        }
      };

      // Offer the FUTURE scope only when the opened contribution belongs to a
      // LIVE recurring series. A Cancelled/Failed/Completed series has no
      // future installments to define, and opting in would needlessly
      // materialize a template row (ensureRecurTemplate creates on demand).
      // If the opened contribution IS the template (someone routed here with
      // the template's own id), the form is already editing the future scope -
      // no toggle.
      function discoverRecur(contributionId) {
        crmApi4('Contribution', 'get', {
          select: [
            'contribution_recur_id', 'is_template',
            'contribution_recur_id.contribution_status_id:name'
          ],
          where: [['id', '=', contributionId]]
        }).then(function(rows) {
          var row = (rows && rows[0]) || {};
          var inactive = ['Cancelled', 'Failed', 'Completed'];
          if (row.contribution_recur_id && !row.is_template &&
              inactive.indexOf(row['contribution_recur_id.contribution_status_id:name']) === -1) {
            ctrl.contributionRecurId = row.contribution_recur_id;
            ctrl.hasRecur = true;
          }
        });
        // Discovery failure is silent by design: the form degrades to the
        // single-scope edit it always was; nothing recurring is lost except
        // the offer.
      }

      ctrl.setScope = function(scope) {
        if (scope === ctrl.scope) { return; }
        if (scope !== 'future') {
          ctrl.scope = scope;
          return;
        }
        if (ctrl.templateContributionId) {
          ctrl.scope = 'future';
          return;
        }
        // First entry into the future scope: resolve - creating if necessary -
        // the series' template contribution. Deliberately lazy: a template is
        // materialized only when staff actually opt in to editing future
        // installments, never speculatively at form load.
        ctrl.templateLoading = true;
        crmApi4('OrderAO', 'ensureRecurTemplate', {
          contributionRecurID: ctrl.contributionRecurId
        }).then(function(res) {
          ctrl.templateLoading = false;
          var row = (res && res[0]) || {};
          if (row.template_contribution_id) {
            ctrl.templateContributionId = row.template_contribution_id;
            ctrl.scope = 'future';
          }
          else {
            CRM.alert(ts('Could not resolve the recurring template'), ts('Error'), 'error');
          }
        }, function(err) {
          ctrl.templateLoading = false;
          CRM.alert(
            (err && err.error_message) || ts('Could not resolve the recurring template'),
            ts('Error'), 'error'
          );
        });
      };

      // Successful direct edit (either scope) — the cart already reloaded
      // itself and alerted; just forward the result to any bound consumer.
      ctrl.handleSaved = function(result) {
        if (ctrl.onSaved) {
          ctrl.onSaved({ result: result });
        }
      };

      // A future-scope save already re-synced ContributionRecur.amount and
      // attempted the processor amendment server-side (OrderAO.modify), and the
      // cart alerted both outcomes transiently. Keep a persistent inline summary
      // so the new series amount - and any required manual follow-up - stays
      // visible while staff continue working. Still forward onSaved.
      ctrl.handleTemplateSaved = function(result) {
        if (result) {
          var text = ts('The recurring amount is now %1.', { 1: CRM.formatMoney(result.recur_amount) });
          if (result.processor_message) {
            text += ' ' + result.processor_message;
          }
          ctrl.recurSummary = { notified: !!result.processor_notified, text: text };
        }
        if (ctrl.onSaved) {
          ctrl.onSaved({ result: result });
        }
      };

      // The cart hands us a vetoed change because it carried a non-empty
      // validate_metadata bag. We attach no meaning to it here: forward it to
      // the consumer's handler and, if the consumer resolves a message, show it
      // in the completion banner and hide the carts. With no handler bound, fall
      // back to a generic "not applied" notice so the change isn't dropped
      // silently.
      ctrl.handleVeto = function(metadata, contributionId, toAdd, toRemove) {
        if (!ctrl.onValidateMetadata) {
          CRM.alert(ts('This change was not applied.'), ts('Not applied'), 'warning');
          return;
        }
        var ret = ctrl.onValidateMetadata({
          metadata: metadata,
          contributionId: contributionId || ctrl.contributionId,
          toAdd: toAdd,
          toRemove: toRemove
        });
        $q.when(ret).then(function(message) {
          if (message) {
            ctrl.terminalMessage = $sce.trustAsHtml(message);
          }
        });
      };

      function parseContributionIdFromUrl() {
        try {
          var hash = window.location.hash || '';
          var qIndex = hash.indexOf('?');
          if (qIndex === -1) { return null; }
          var params = new URLSearchParams(hash.slice(qIndex + 1));
          var id = params.get('contribution_id') || params.get('contributionID');
          return id ? parseInt(id, 10) : null;
        }
        catch (e) {
          return null;
        }
      }
    }
  });
})(angular, CRM.$, CRM._);
