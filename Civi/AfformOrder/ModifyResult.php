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

namespace Civi\AfformOrder;

use Civi\Api4\Generic\Result;

/**
 * Result type for OrderAO.modify.
 *
 * Adds a single DECLARED public property, $validate_metadata, to the standard
 * api4 Result. The declaration is the whole point of this class:
 * CRM_Api4_Page_AJAX::execute() forwards extra Result metadata to the HTTP
 * client by iterating get_class_vars(get_class($result)) - which sees DECLARED
 * public properties only. A value assigned to a dynamic (undeclared) property
 * would be invisible there and would never reach the browser. Declaring it here
 * is the same device core uses for its ReplaceResult::$deleted.
 *
 * WHY A RESULT PROPERTY RATHER THAN A THROWN EXCEPTION:
 *   A modify-validate subscriber can veto a paid edit AND attach engine-neutral
 *   metadata describing the outcome (e.g. a consumer's "this edit should become
 *   a refund request; here is the intended change"). That metadata has to reach
 *   the caller. The EXCEPTION path cannot carry it: the api4 AJAX endpoint emits
 *   only error_id / error_code / error_message on a thrown error and drops the
 *   rest of CRM_Core_Exception::getErrorData(). A SUCCESSFUL result, by
 *   contrast, is returned whole - the AJAX page copies declared Result
 *   properties alongside `values`, and the client's arrayObject() (crm.ajax.js)
 *   copies every non-`values` response key onto the resolved result. So
 *   OrderAO.modify reports a vetoed-with-metadata change as a normal (HTTP 200)
 *   result whose row is flagged applied=FALSE and whose $validate_metadata
 *   carries the bag - readable on the client as result.validate_metadata.
 *
 *   A veto WITHOUT metadata is a genuine rejection (e.g. an unverifiable
 *   refund-request context, or a double-reversal collision) and is still THROWN
 *   by OrderAO.modify, so it surfaces to the user as an error.
 *
 * afform_order defines no metadata KEYS and interprets none; $validate_metadata
 * is a verbatim relay of whatever a subscriber attached via the validate
 * event's setMetadata(). A consumer extension owns the key names.
 *
 * NAMING ON THE WIRE: the property is snake_case ON PURPOSE. The AJAX page
 * forwards it under its literal PHP property name, so the client reads it as
 * result.validate_metadata (not validateMetadata).
 */
class ModifyResult extends Result {

  /**
   * Engine-neutral metadata bag, relayed from the modify-validate event when a
   * subscriber vetoed the change but attached structured outcome data. Empty
   * ([]) when the modify applied normally.
   *
   * @var array
   */
  public $validate_metadata = [];

}
