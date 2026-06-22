(function(angular, $, _) {
  "use strict";

  /**
   * Opt-in decorator on crm-ui-datepicker: auto-complete a date entered with NO
   * time to a default time, so a date-only entry on a DateTime field is a
   * complete (valid) value instead of being flagged ng-invalid-incomplete-date-time
   * (which blocks afform.submit()). Lets staff "just enter a date" on a datetime
   * field; the server stores it at the default time.
   *
   * OPT-IN per field via input_attrs: a field requests it with
   *   defn="{input_attrs: {defaultTimeOnDate: true}}"   // -> 00:00:00
   *   defn="{input_attrs: {defaultTimeOnDate: '12:00:00'}}"
   * With no flag (every other datepicker on the site) this directive no-ops, so
   * it cannot affect fields that didn't ask for it. It also no-ops when time is
   * disabled (a true date-only field needs no completion).
   *
   * This is a SECOND directive registered under the core directive's name
   * (crmUiDatepicker), at lower priority so it links after core sets the widget
   * up. It takes NO scope (so it does not collide with core's isolate scope) and
   * works purely through the hidden field's value + ngModel/change events. The
   * af-field encapsulation gives no other hook: input_attrs is the only thing a
   * form author can pass through to the datepicker.
   */
  angular.module('afFieldLineItems').directive('crmUiDatepicker', function($timeout) {
    return {
      restrict: 'A',
      require: 'ngModel',
      // Link AFTER core's crmUiDatepicker (default priority 0) so the widget
      // and its own change handler are already wired.
      priority: -1,
      link: function(scope, element, attrs, ngModel) {
        var settings = scope.$eval(attrs.crmUiDatepicker) || {};
        if (!settings.defaultTimeOnDate || settings.time === false) {
          return;
        }
        var defaultTime = (settings.defaultTimeOnDate === true)
          ? '00:00:00'
          : String(settings.defaultTimeOnDate);

        // The crm-hidden-date field carries the combined value. Core writes a
        // 10-char "Y-m-d" when a date is picked but the time left blank; append
        // the default time to make it the 19-char "Y-m-d H:i:s" the validator
        // expects. Re-triggering change lets core sync the visible time field
        // (which then shows the default) and re-run its validity check; the
        // re-entry sees a 19-char value and no-ops, so there is no loop.
        element.on('change.afoDefaultTime', function() {
          var val = element.val();
          if (val && val.length === 10) {
            $timeout(function() {
              element.val(val + ' ' + defaultTime).triggerHandler('change');
            });
          }
        });
      }
    };
  });
})(angular, CRM.$, CRM._);
