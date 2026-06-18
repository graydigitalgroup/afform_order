(function(angular, $, _) {
  "use strict";

  /**
   * afOrderCartChecks — a small client-side registry of advisory "checks" for
   * the line-item cart, the Angular analogue of the server-side
   * Civi\AfformOrder\Event\OrderCreateEvent seam.
   *
   * Consumer extensions register a check from a module .run() block:
   *
   *   angular.module('myModule', CRM.angRequires('myModule'))
   *     .run(['afOrderCartChecks', function(afOrderCartChecks) {
   *       afOrderCartChecks.register(function(cart, context) {
   *         // return a string, an array of strings, or [] for no warning.
   *       });
   *     }]);
   *
   * The cart directive runs every registered check whenever the cart or the
   * form's field data changes, and surfaces the returned messages as
   * non-blocking warnings. Checks are advisory only: they never mutate the
   * cart and never block submission — they exist so staff can SEE that
   * something may need attention (e.g. a qty that doesn't line up with the
   * selected recurrence) and decide for themselves.
   *
   * A check receives:
   *   cart     the current cart rows (treat as read-only)
   *   context  { formData } where formData is afForm.getFieldData() — the
   *            form's non-entity values, including recur inputs such as
   *            is_recur / recur_frequency_unit / recur_frequency_interval.
   *
   * A check returns: a warning string, an array of warning strings, or a
   * falsy/empty value for "nothing to warn about".
   */
  angular.module('afFieldLineItems').factory('afOrderCartChecks', function() {
    var checks = [];

    return {
      /**
       * Register an advisory check.
       * @param {function(Array, Object): (string|Array<string>|void)} fn
       */
      register: function(fn) {
        if (typeof fn === 'function') {
          checks.push(fn);
        }
      },

      /**
       * Run all registered checks and collect their warnings.
       * @param {Array} cart
       * @param {Object} context
       * @returns {Array<string>}
       */
      run: function(cart, context) {
        var warnings = [];
        checks.forEach(function(fn) {
          var result;
          try {
            result = fn(angular.copy(cart || []), context || {});
          } catch (e) {
            // A misbehaving check must never break the cart UI.
            if (window.console && console.error) {
              console.error('afOrderCartChecks: a check threw', e);
            }
            return;
          }
          if (!result) {
            return;
          }
          if (typeof result === 'string') {
            warnings.push(result);
          } else if (Array.isArray(result)) {
            result.forEach(function(w) {
              if (w) {
                warnings.push(String(w));
              }
            });
          }
        });
        return warnings;
      },

      /**
       * Whether any checks are registered (lets the directive skip work).
       * @returns {boolean}
       */
      has: function() {
        return checks.length > 0;
      }
    };
  });
})(angular, CRM.$, CRM._);
