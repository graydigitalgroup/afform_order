(function(angular, $, _) {
  "use strict";

  /**
   * <af-existing-membership-select> — lets staff link the cart's line items
   * to an existing membership instead of creating a new one (e.g. catch-up
   * payment + new recur on a previously-cancelled membership).
   *
   * Bindings:
   *   name        string  extras field name (e.g. 'existing_membership_id')
   *   label       string  display label
   *   entityName  string  af-entity name of the contact (e.g. 'Contact1')
   *
   * Requires:
   *   ^^afForm  reads the prefilled contact id from afForm.getData() and writes
   *             the selected membership id to the extras slot via getFieldData().
   *             (Uses ^^ to match the proven af-field-line-items / af-checkout
   *             pattern for directives placed directly in an afform layout.)
   *
   * The contact id arrives via Afform prefill (async, after $onInit), so we
   * watch for it and load the contact's memberships once it's available.
   *
   * Renders a loading indicator while the contact's memberships are fetched,
   * then either the picker (if any exist) or a short note confirming a new
   * membership will be created — so its state stays legible to staff rather
   * than hiding silently.
   *
   * The submit subscriber (Civi\AfformOrder\Submit) reads the chosen id from
   * the conventional 'existing_membership_id' extra and links membership lines
   * to it.
   */
  angular.module('afFieldLineItems').component('afExistingMembershipSelect', {
    bindings: {
      name: '@',
      label: '@',
      entityName: '@'
    },
    require: {
      afForm: '?^^afForm'
    },
    templateUrl: '~/afFieldLineItems/afExistingMembershipSelect.html',
    controller: function($scope, crmApi4) {
      var ts = $scope.ts = CRM.ts('afform_order');
      var ctrl = this;

      ctrl.select2Data = [];
      ctrl.hasMemberships = false;
      ctrl.loaded = false;
      ctrl.loading = false;
      ctrl.contactId = null;
      ctrl.selectedMembershipId = null;

      ctrl.$onInit = function() {
        // Seed from extras (e.g. returning to a saved draft / edit mode).
        if (ctrl.afForm && ctrl.afForm.getFieldData) {
          var existing = ctrl.afForm.getFieldData()[ctrl.name];
          ctrl.selectedMembershipId = existing ? String(existing) : null;
        }

        // The contact id is prefilled asynchronously; watch for it, then load
        // the contact's memberships once (re-load if the contact changes).
        $scope.$watch(getContactId, function(cid) {
          if (cid && cid !== ctrl.contactId) {
            ctrl.contactId = cid;
            ctrl.loadMemberships(cid);
          }
        });
      };

      // Resolve the contact id from the af-form's loaded entity data. After
      // prefill this lives at getData(entityName)[0].fields.id; fall back to a
      // record-level id just in case a future load mode puts it there.
      function getContactId() {
        if (!ctrl.afForm || !ctrl.entityName || !ctrl.afForm.getData) {
          return null;
        }
        var d = ctrl.afForm.getData(ctrl.entityName);
        if (d && d[0]) {
          return (d[0].fields && d[0].fields.id) || d[0].id || null;
        }
        return null;
      }

      ctrl.loadMemberships = function(contactId) {
        ctrl.loading = true;
        crmApi4('Membership', 'get', {
          select: [
            'id', 'membership_type_id:label', 'status_id:label',
            'start_date', 'end_date'
          ],
          where: [['contact_id', '=', contactId]],
          orderBy: { 'end_date': 'DESC' }
        }).then(function(rows) {
          ctrl.select2Data = (rows || []).map(function(m) {
            var meta = [];
            if (m['status_id:label']) {
              meta.push(m['status_id:label']);
            }
            if (m.end_date) {
              meta.push(ts('through %1', { 1: m.end_date }));
            }
            var text = (m['membership_type_id:label'] || ts('Membership')) +
              (meta.length ? ' (' + meta.join(', ') + ')' : '');
            return { id: String(m.id), text: text };
          });
          ctrl.hasMemberships = ctrl.select2Data.length > 0;
          ctrl.loaded = true;
          ctrl.loading = false;
        }, function(err) {
          ctrl.loaded = true;
          ctrl.loading = false;
          CRM.alert(
            (err && err.error_message) || ts('Failed to load memberships'),
            ts('Error'), 'error'
          );
        });
      };

      ctrl.onSelectionChange = function() {
        if (ctrl.afForm && ctrl.afForm.getFieldData) {
          ctrl.afForm.getFieldData()[ctrl.name] = ctrl.selectedMembershipId || null;
        }
      };
    }
  });
})(angular, CRM.$, CRM._);
