(function(angular, $, _) {
  "use strict";

  /**
   * afOrderPickerFilters — a client-side registry of visibility filters for the
   * line-item cart's "add a line item" picker. The third sibling of
   * afOrderCartChecks (advisory warnings) and afOrderLineLocks (read-only rows);
   * this one decides which price-field-value OPTIONS a consumer will let appear
   * in the add picker.
   *
   * Why this rather than Afform's af-if: af-if drives conditional display of
   * rendered Afform DOM fields from form data. The cart's picker is a custom
   * select2 built from an API data array (PriceFieldValue rows), not Afform
   * fields, so af-if cannot reach it. A registered filter is the seam.
   *
   * afform_order ships only the mechanism; it hides nothing itself. A consumer
   * registers a filter from a module .run() block:
   *
   *   angular.module('myModule', CRM.angRequires('myModule'))
   *     .run(['afOrderPickerFilters', function(afOrderPickerFilters) {
   *       afOrderPickerFilters.register(function(option, context) {
   *         // return false to HIDE this option; anything else shows it.
   *         return option.price_field_id != SOME_RESTRICTED_FIELD;
   *       });
   *     }]);
   *
   * A filter receives:
   *   option   a PriceFieldValue row as loaded by the picker (id, label,
   *            price_field_id, membership_type_id, amount, ...). Treat read-only.
   *   context  { contributionId, contributionStatus, isTemplate, isEdit, cart }
   *
   * A filter returns: FALSE to hide the option; any other value shows it. An
   * option is shown only if EVERY registered filter allows it.
   *
   * Async note: filters are evaluated synchronously while the picker is built.
   * A consumer whose decision needs server data should resolve+cache it and,
   * once known, $rootScope.$broadcast('afOrderPickerRefresh') so the cart
   * rebuilds the picker with the now-known answer. Until then, return the safe
   * default (typically false / hidden).
   */
  angular.module('afFieldLineItems').factory('afOrderPickerFilters', function() {
    var filters = [];

    return {
      /**
       * Register a picker-visibility filter.
       * @param {function(Object, Object): boolean} fn
       */
      register: function(fn) {
        if (typeof fn === 'function') {
          filters.push(fn);
        }
      },

      /**
       * Whether every registered filter allows this option (only an explicit
       * FALSE hides it).
       * @param {Object} option
       * @param {Object} context
       * @returns {boolean}
       */
      passes: function(option, context) {
        return filters.every(function(fn) {
          try {
            return fn(option, context || {}) !== false;
          } catch (e) {
            // A misbehaving filter must not hide every option; default to show.
            if (window.console && console.error) {
              console.error('afOrderPickerFilters: a filter threw', e);
            }
            return true;
          }
        });
      },

      /**
       * Whether any filters are registered (lets the cart skip work).
       * @returns {boolean}
       */
      has: function() {
        return filters.length > 0;
      }
    };
  });
})(angular, CRM.$, CRM._);
