(function(angular, $, _) {
  "use strict";

  /**
   * <af-membership-status-select> — a select of membership statuses for the
   * membership override fields.
   *
   * An afform "extra" Select field can't derive its options from an entity
   * (a cart form has no Membership entity to read status_id options from), so
   * this component loads the active statuses via API4 and binds the chosen id
   * back to the form's extras slot.
   *
   * Bindings:
   *   name    string  extras field name (e.g. 'membership_status_id')
   *   label   string  display label
   *
   * Requires:
   *   ^afForm   writes the selected status id to the extras slot via
   *             getFieldData().
   *
   * The submit subscriber (Civi\AfformOrder\Submit::applyMembershipOverrides)
   * reads the chosen id from the membership_status_id extra and applies it to
   * the membership line as entity_id.status_id + entity_id.is_override (a
   * status is meaningless unless the BAO's auto-recalculation is overridden).
   * Leaving it blank lets the status be calculated from the dates as usual.
   */
  angular.module('afFieldLineItems').component('afMembershipStatusSelect', {
    bindings: {
      name: '@',
      label: '@'
    },
    require: {
      afForm: '?^afForm'
    },
    templateUrl: '~/afFieldLineItems/afMembershipStatusSelect.html',
    controller: function($scope, crmApi4) {
      var ts = $scope.ts = CRM.ts('afform_order');
      var ctrl = this;

      ctrl.select2Data = [];
      ctrl.loaded = false;
      ctrl.selectedStatusId = null;

      ctrl.$onInit = function() {
        // Seed from extras (e.g. returning to a saved draft / edit mode).
        if (ctrl.afForm && ctrl.afForm.getFieldData) {
          var existing = ctrl.afForm.getFieldData()[ctrl.name];
          ctrl.selectedStatusId = existing ? String(existing) : null;
        }
        ctrl.loadStatuses();
      };

      ctrl.loadStatuses = function() {
        crmApi4('MembershipStatus', 'get', {
          select: ['id', 'label'],
          where: [['is_active', '=', true]],
          orderBy: { 'weight': 'ASC' }
        }).then(function(rows) {
          ctrl.select2Data = (rows || []).map(function(s) {
            return { id: String(s.id), text: s.label };
          });
          ctrl.loaded = true;
        }, function(err) {
          CRM.alert(
            (err && err.error_message) || ts('Failed to load membership statuses'),
            ts('Error'), 'error'
          );
        });
      };

      ctrl.onSelectionChange = function() {
        if (ctrl.afForm && ctrl.afForm.getFieldData) {
          ctrl.afForm.getFieldData()[ctrl.name] = ctrl.selectedStatusId || null;
        }
      };
    }
  });
})(angular, CRM.$, CRM._);
