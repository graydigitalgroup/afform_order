<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Api4\Action\OrderAO;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * List the "from" addresses a staffer may send a contribution receipt as -
 * the same set core's contribution form offers in its "Receipt From" select
 * (CRM_Core_BAO_Email::getFromEmail(): configured domain addresses, plus the
 * logged-in user's own addresses when the site allows it).
 *
 * Why a dedicated action rather than a metadata getoptions: getFromEmail()
 * returns a mixed-keyed, HTML-encoded map (RFC822 strings for domain addresses,
 * integer email ids for contact addresses) that is awkward to consume from JS
 * and gives no clean name/email split. We parse it here, on the server, into
 * flat rows the af-order-details panel can render directly and hand straight to
 * Contribution.sendconfirmation (which wants receipt_from_name +
 * receipt_from_email as separate values).
 *
 * Returns one row per address: { value, label, name, email }.
 *   - value: the original getFromEmail() key (a stable identifier for the
 *     select; not sent to the mailer)
 *   - label: the human-readable, HTML-decoded address line for display
 *   - name / email: the parsed display-name and address, passed to
 *     sendconfirmation as receipt_from_name / receipt_from_email
 */
class GetFromEmails extends AbstractAction {

  public function _run(Result $result): void {
    foreach (\CRM_Core_BAO_Email::getFromEmail() as $key => $label) {
      // getFromEmail() HTML-encodes its labels (it builds them for direct use
      // in a select); decode before parsing or displaying.
      $decoded = html_entity_decode((string) $label, ENT_QUOTES);
      $name = '';
      $email = $decoded;
      // Both shapes carry the address inside angle brackets:
      //   "Display Name" <addr@example.org>
      //   Display Name <addr@example.org> Work (preferred)
      // The name is whatever precedes the first '<'; trailing location/preferred
      // annotations after '>' are display-only and dropped.
      if (preg_match('/<([^>]+)>/', $decoded, $m)) {
        $email = trim($m[1]);
        $name = trim(substr($decoded, 0, strpos($decoded, '<')));
        $name = trim($name, " \t\"");
      }
      $result[] = [
        'value' => (string) $key,
        'label' => $decoded,
        'name' => $name,
        'email' => $email,
      ];
    }
  }

}