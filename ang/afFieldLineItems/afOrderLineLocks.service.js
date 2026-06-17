(function(angular, $, _) {
  "use strict";

  /**
   * afOrderLineLocks — a small client-side registry of "lock" predicates for the
   * line-item cart. The structural sibling of afOrderCartChecks (advisory
   * warnings), but for a different purpose: a registered predicate declares that
   * a given cart row must be treated as READ-ONLY in the cart.
   *
   * A locked row renders with its qty / unit_price disabled and without the
   * per-line edit pencil or the remove button — the cart will not let staff
   * change or delete it directly. This is the generic mechanism a consumer uses
   * when a line must only be changed through some other, consumer-owned flow
   * (e.g. a suspense/placeholder line that is only ever drawn down by an
   * allocation UI, never edited as an ordinary line).
   *
   * afform_order ships only the mechanism; it locks nothing itself. A consumer
   * registers a predicate from a module .run() block:
   *
   *   angular.module('myModule', CRM.angRequires('myModule'))
   *     .run(['afOrderLineLocks', function(afOrderLineLocks) {
   *       afOrderLineLocks.register(function(row, context) {
   *         return row.price_field_id == MY_PLACEHOLDER_PRICE_FIELD_ID;
   *       });
   *     }]);
   *
   * A predicate receives:
   *   row      a cart row (treat as read-only)
   *   context  { contributionId, contributionStatus, isTemplate, isEdit }
   *
   * A predicate returns: truthy to lock the row, falsy otherwise. A row is
   * locked if ANY registered predicate locks it.
   */
  angular.module('afFieldLineItems').factory('afOrderLineLocks', function() {
    var predicates = [];

    return {
      /**
       * Register a lock predicate.
       * @param {function(Object, Object): boolean} fn
       */
      register: function(fn) {
        if (typeof fn === 'function') {
          predicates.push(fn);
        }
      },

      /**
       * Whether any predicate locks this row.
       * @param {Object} row
       * @param {Object} context
       * @returns {boolean}
       */
      isLocked: function(row, context) {
        return predicates.some(function(fn) {
          try {
            return !!fn(row, context || {});
          } catch (e) {
            // A misbehaving predicate must never break the cart UI; default to
            // "not locked" so the row stays usable.
            if (window.console && console.error) {
              console.error('afOrderLineLocks: a predicate threw', e);
            }
            return false;
          }
        });
      },

      /**
       * Whether any predicates are registered (lets the cart skip work).
       * @returns {boolean}
       */
      has: function() {
        return predicates.length > 0;
      }
    };
  });
})(angular, CRM.$, CRM._);