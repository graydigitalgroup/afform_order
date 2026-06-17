(function(angular, $, _) {
  "use strict";

  /**
   * <af-order-details contribution-id> — edits the CONTRIBUTION-HEADER metadata
   * of an existing order, alongside (but independent of) the line-item cart.
   *
   * Scope is deliberately the "safe" header fields that carry no accounting
   * effect and so do NOT belong in OrderAO.modify (which derives status and
   * money movement from line changes): the contribution date, source note, and
   * the receipt / thank-you dates. Plus a "send a receipt now" option mirroring
   * the core contribution form's is_email_receipt + Receipt-From select.
   *
   * It saves through plain Contribution.update (and, for the receipt, the
   * Contribution.sendconfirmation APIv3 action) — entirely separate from the
   * cart's OrderAO.modify save. The two panels have independent Save buttons:
   * editing header metadata and restructuring line items are different intents
   * and need not be transactional together.
   *
   * Money-moving header concerns (changing contribution_status_id, cancelling,
   * recording a manual payment) are intentionally NOT here — those are policy
   * and belong in a consumer extension, gated by that consumer's permissions.
   */
  angular.module('afFieldLineItems').component('afOrderDetails', {
    bindings: {
      contributionId: '<',
      // Called after a successful save with {result}. The host may forward it
      // to a consumer; we do NOT close any hosting popup here (staff commonly
      // continue on to edit line items after saving header details).
      onSaved: '&?'
    },
    templateUrl: '~/afFieldLineItems/afOrderDetails.html',
    controller: function($scope, $q, crmApi4) {
      var ts = $scope.ts = CRM.ts('afform_order');
      var ctrl = this;

      ctrl.loading = false;
      ctrl.saving = false;
      ctrl.ready = false;
      // The editable header model, populated from Contribution.get.
      ctrl.model = {
        receive_date: null,
        source: null,
        receipt_date: null,
        thankyou_date: null
      };
      // Receipt-send controls (mirror core's is_email_receipt + Receipt From).
      ctrl.emailReceipt = false;
      ctrl.fromEmails = [];
      ctrl.fromEmail = null;
      // A template contribution (recurring series definition) has no meaningful
      // receive/receipt/thankyou dates and you never email a receipt for one;
      // if we are somehow pointed at one, the panel hides itself.
      ctrl.isTemplate = false;

      ctrl.$onInit = function() {
        if (!ctrl.contributionId) { return; }
        ctrl.loading = true;
        $q.all([loadContribution(), loadFromEmails()]).finally(function() {
          ctrl.loading = false;
          ctrl.ready = true;
        });
      };

      function loadContribution() {
        return crmApi4('Contribution', 'get', {
          select: [
            'receive_date', 'source', 'receipt_date', 'thankyou_date',
            'is_template'
          ],
          where: [['id', '=', ctrl.contributionId]]
        }).then(function(rows) {
          var row = (rows && rows[0]) || {};
          ctrl.isTemplate = !!row.is_template;
          ctrl.model.receive_date = row.receive_date || null;
          ctrl.model.source = row.source || null;
          ctrl.model.receipt_date = row.receipt_date || null;
          ctrl.model.thankyou_date = row.thankyou_date || null;
        });
      }

      function loadFromEmails() {
        return crmApi4('OrderAO', 'getFromEmails', {}).then(function(rows) {
          ctrl.fromEmails = rows || [];
          // Default to the first offered address (core defaults to the domain
          // address; getFromEmails preserves getFromEmail's ordering).
          if (ctrl.fromEmails.length) {
            ctrl.fromEmail = ctrl.fromEmails[0].value;
          }
        }, function() {
          // A from-email lookup failure just disables the receipt option; the
          // metadata fields still save.
          ctrl.fromEmails = [];
        });
      }

      ctrl.save = function() {
        if (ctrl.saving || !ctrl.contributionId) { return; }
        ctrl.saving = true;
        // Empty date/text fields are sent as null so clearing a value actually
        // clears it (APIv4 rejects '' for a datetime).
        var values = {
          receive_date: ctrl.model.receive_date || null,
          source: ctrl.model.source || null,
          receipt_date: ctrl.model.receipt_date || null,
          thankyou_date: ctrl.model.thankyou_date || null
        };
        crmApi4('Contribution', 'update', {
          where: [['id', '=', ctrl.contributionId]],
          values: values
        }).then(function() {
          return maybeSendReceipt();
        }).then(function(receiptSent) {
          ctrl.saving = false;
          // sendconfirmation may have stamped receipt_date; reflect it back.
          if (receiptSent) {
            return loadContribution().then(function() {
              announceSaved(receiptSent);
            });
          }
          announceSaved(receiptSent);
        }, function(err) {
          ctrl.saving = false;
          CRM.alert(
            (err && err.error_message) || ts('Could not save the contribution details.'),
            ts('Error'), 'error'
          );
        });
      };

      // Send a receipt via the APIv3 sendconfirmation action when the option is
      // checked and a from-address is selected. Returns a promise resolving to
      // TRUE if a receipt was sent, FALSE otherwise. sendconfirmation only
      // stamps receipt_date when it is currently empty, so it never clobbers a
      // date the staffer typed above.
      function maybeSendReceipt() {
        if (!ctrl.emailReceipt || !ctrl.fromEmail) {
          return $q.when(false);
        }
        var opt = _.findWhere(ctrl.fromEmails, { value: ctrl.fromEmail }) || {};
        return $q(function(resolve, reject) {
          CRM.api3('Contribution', 'sendconfirmation', {
            id: ctrl.contributionId,
            receipt_from_email: opt.email,
            receipt_from_name: opt.name
          }).then(function(r) {
            if (r && r.is_error) { reject(r); } else { resolve(true); }
          }, reject);
        });
      }

      function announceSaved(receiptSent) {
        CRM.alert(
          receiptSent
            ? ts('Contribution details saved and a receipt was emailed.')
            : ts('Contribution details saved.'),
          ts('Saved'), 'success'
        );
        if (ctrl.onSaved) {
          ctrl.onSaved({ result: { receiptSent: !!receiptSent } });
        }
      }
    }
  });
})(angular, CRM.$, CRM._);