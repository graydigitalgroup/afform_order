(function(angular, $, _) {
  "use strict";

  /**
   * <af-order-edit-cart contribution-id="…"> — minimal generic host that mounts
   * the edit cart for the AFFORM SUBMIT flow.
   *
   * It exists for one reason: the cart (<af-field-line-items>) must be embedded
   * in a COMPONENT TEMPLATE STRING, not as a bare element in .aff.html markup -
   * Angular preserves the binding attributes here, but afform's markup parser
   * does not reliably preserve them on a bare custom element. (Consumer wrapper
   * components embed the cart the same way.)
   *
   * It adds NO save UI: the cart runs in afform-submit mode (stashes its diff
   * into the 'order_edit' extra), and the surrounding afform's own submit button
   * drives afform.submit() → Civi\AfformOrder\Submit → OrderAO.editOrder. Header
   * fields are native af-fields on the afform's Contribution entity; this host
   * does not touch them.
   *
   * Future installments are edited as their own contribution (open the form at
   * the recur template's id); there is no scope toggle here.
   */
  angular.module('afFieldLineItems').component('afOrderEditCart', {
    bindings: {
      // The contribution being edited (e.g. from the afform route arg).
      contributionId: '<'
    },
    require: {
      // The cart resolves ^^afForm itself; declaring it here is not required,
      // but keeps the host afform optional-safe for non-afform reuse.
      afForm: '?^^afForm'
    },
    template:
      '<af-field-line-items ng-if="$ctrl.contributionId" ' +
        'edit-mode="true" ' +
        'afform-submit="true" ' +
        'edit-contribution-id="$ctrl.contributionId" ' +
        'edit-context="\'cart_edit\'">' +
      '</af-field-line-items>',
    controller: function() {}
  });
})(angular, CRM.$, CRM._);
