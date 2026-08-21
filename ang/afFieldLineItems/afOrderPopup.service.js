(function(angular, $, _) {
  "use strict";

  /**
   * afOrderPopup - shared helper for custom Angular components (ones that save
   * via the API directly, NOT through afform.submit) to behave correctly when
   * they are launched inside a CRM popup.
   *
   * THE PROBLEM it centralizes: a component that does its own crmApi4 save never
   * triggers afform's submit pipeline, so the "close the dialog + reload the
   * opener (SearchKit / livePage)" behaviour a normal form gets is not fired for
   * it. And it is a TWO-step signal that is easy to get half-right:
   *
   *   - crm.ajax's CRM.popup BUFFERS a 'crmFormSuccess' event on the dialog but
   *     does NOT close it; it only re-fires 'crmPopupFormSuccess' on the opener
   *     (which SearchKit reloads on) when the dialog actually CLOSES
   *     ('dialogclose'). So firing crmFormSuccess alone leaves the popup open
   *     AND never reloads the opener.
   *
   * So success handling must: fire crmFormSuccess (to buffer it), THEN close the
   * dialog (which triggers crmPopupFormSuccess -> reload). This helper does both,
   * in order, and is a NO-OP outside a popup (standalone page) so the same call
   * is safe everywhere - the component stays put on a full page.
   *
   * Usage (in a component controller that injects $element + afOrderPopup):
   *   ...->execute().then(function() {
   *     CRM.alert(...);
   *     afOrderPopup.closeOnSuccess($element);
   *   });
   */
  angular.module('afFieldLineItems').factory('afOrderPopup', function() {
    return {
      /**
       * Close the enclosing CRM popup (if any) and signal success so the opener
       * reloads. No-op when not in a popup.
       *
       * @param {Element|jQuery} element  any element inside the component.
       * @return {boolean} TRUE if a popup was found + closed; FALSE on a
       *   standalone page (so the caller can fall back to its own in-place
       *   refresh, e.g. `if (!afOrderPopup.closeOnSuccess($element)) ctrl.load();`).
       */
      closeOnSuccess: function(element) {
        var $dialog = CRM.$(element).closest('.ui-dialog-content');
        if (!$dialog.length) {
          // Standalone page (not a popup): nothing to close, leave the component.
          return false;
        }
        // Buffer the success first, then close - crm.ajax fires
        // crmPopupFormSuccess (the SearchKit/livePage reload) on dialogclose
        // only when a success was buffered. crm.ajax.js's buffering handler
        // stores the event's second trigger argument as the "success" flag
        // (`formData = data`) and only relays crmPopupFormSuccess `if
        // (formData)` - so this MUST pass a truthy payload, or the buffered
        // flag stays undefined/falsy and the opener never reloads (verified
        // live: the popup closes fine either way, only the reload is silently
        // lost).
        $dialog.trigger('crmFormSuccess', [{}]);
        $dialog.dialog('close');
        return true;
      }
    };
  });
})(angular, CRM.$, CRM._);
