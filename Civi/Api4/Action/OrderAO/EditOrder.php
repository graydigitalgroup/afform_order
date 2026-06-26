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

use Civi\Api4\Contribution;
use Civi\Api4\LineItem;
use Civi\Api4\OrderAO;
use Civi\AfformOrder\ModifyResult;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Apply an order EDIT - line-item changes AND contribution header changes - as
 * ONE atomic, correctly-ordered operation.
 *
 * This is the server-side keystone of the order-edit flow. It exists because
 * editing an order is two distinct pieces of work that MUST be transactional
 * together and MUST run in a specific order:
 *
 *   1. The LINE items (add / remove / replace) via {@see Modify}, which keeps
 *      the delta semantics + events (soft-credit sync, reclassify, template
 *      strip) that a bare Contribution::create(line_item) would lose.
 *   2. The contribution HEADER fields (financial_type_id, contribution_status_id,
 *      dates, source, custom fields) via Contribution.update - whose BAO
 *      FinancialProcessor books changeFinancialType / status adjustments.
 *
 * LINES FIRST, THEN HEADER - deliberately. The FinancialProcessor self-loads the
 * contribution's CURRENT line items and re-books over them; running the line
 * restructure first means the header pass (e.g. a financial_type change) books
 * over the FINAL line set, not a stale one. Doing it in the other order, or as
 * two separate non-transactional calls, risks rebooking over lines that are
 * about to change and leaves a window of partial state.
 *
 * ATOMICITY: {@see Modify} and Contribution.update each open their own
 * transaction; nested under this action's outer transaction they commit/roll
 * back as a unit, so a failure in the header pass undoes the line restructure.
 * afform does NOT give cross-step atomicity, so we own it here.
 *
 * VETO / ROUTING relay: for a paid contribution {@see Modify} may be vetoed by a
 * consumer's validate subscriber (e.g. a refund-producing edit routed to a
 * refund-request workflow), reported as a SUCCESSFUL result row with
 * applied=FALSE plus a $validate_metadata bag. When that happens we DO NOT apply
 * the header - the whole edit is being redirected - and relay the veto result
 * (row + metadata) verbatim to our caller, exactly as Modify produced it. This
 * action names no metadata keys and interprets none (see {@see ModifyResult}).
 *
 * NO PAYMENT ON EDIT (locked decision): completing a contribution records a
 * payment, which is a SEPARATE workflow (Payment.create / a record-payment
 * action), never a header field write here. We reject a Pending/Partially-paid
 * -> Completed transition, and reject any checkout/payment-processor field, so a
 * stray field can never move money through an order edit.
 *
 * @method $this setContributionID(int $contributionID)
 * @method int getContributionID()
 * @method $this setLineItemsToAdd(array $lineItemsToAdd)
 * @method array getLineItemsToAdd()
 * @method $this setLineItemsToRemove(array $lineItemsToRemove)
 * @method array getLineItemsToRemove()
 * @method $this setContributionFields(array $contributionFields)
 * @method array getContributionFields()
 * @method $this setContext(string $context)
 * @method string getContext()
 * @method $this setContextDetail(array $contextDetail)
 * @method array getContextDetail()
 * @method $this setExpectedLineItemIDs(array $expectedLineItemIDs)
 * @method array getExpectedLineItemIDs()
 */
class EditOrder extends AbstractAction {

  /**
   * The ID of the Contribution being edited.
   *
   * @var int
   * @required
   */
  protected ?int $contributionID = NULL;

  /**
   * Line items to add (forwarded to OrderAO.modify).
   *
   * @var array
   */
  protected array $lineItemsToAdd = [];

  /**
   * Line items to remove (forwarded to OrderAO.modify).
   *
   * @var array
   */
  protected array $lineItemsToRemove = [];

  /**
   * Contribution header fields to write after the line restructure, as
   * APIv4 field => value (incl. custom fields, e.g. 'Custom_group.Field').
   * Applied via Contribution.update so the BAO FinancialProcessor handles any
   * financial_type / status adjustments. Line-driven and payment fields are
   * filtered out (see _run); a Pending/Partially-paid -> Completed status is
   * rejected (use the record-payment workflow).
   *
   * @var array
   */
  protected array $contributionFields = [];

  /**
   * Caller-declared origin of this edit (forwarded to OrderAO.modify's validate
   * subscribers; see Modify::$context). OPTIONAL.
   *
   * @var string
   */
  protected string $context = '';

  /**
   * Optional structured payload accompanying the context (forwarded to modify).
   *
   * @var array
   */
  protected array $contextDetail = [];

  /**
   * Optional optimistic-concurrency guard. The set of line-item ids the caller
   * believed existed when it built the diff (the loaded lines). If supplied, we
   * verify - inside the transaction, before any write - that the contribution's
   * CURRENT line-item id set matches; a mismatch means another actor changed the
   * order since the form was opened, so we abort the whole edit rather than
   * apply a diff computed against a stale picture (which risks double-reversal /
   * re-adding a backed-out line). Empty ([]) disables the check.
   *
   * @var array
   */
  protected array $expectedLineItemIDs = [];

  /**
   * Header values that are line-driven (recomputed by Modify) and must never be
   * written directly on an edit - silently dropped if present.
   */
  private const LINE_DRIVEN_FIELDS = [
    'total_amount', 'tax_amount', 'net_amount', 'fee_amount',
  ];

  /**
   * Header values that would move money or trigger checkout, or that belong to
   * the deferred send-receipt action - rejected outright to honour the
   * "no payment on the edit form" decision.
   */
  private const REJECTED_FIELDS = [
    'checkout_option', 'checkout_params', 'payment_processor_id',
    'is_email_receipt', 'from_email_address',
  ];

  /**
   * The api4 action provider reads the @return annotation on execute() to pick
   * the Result subclass. We reuse Modify's ModifyResult so a relayed veto's
   * $validate_metadata reaches the client (see Modify::execute / ModifyResult).
   *
   * @return \Civi\AfformOrder\ModifyResult
   * @throws \CRM_Core_Exception
   */
  public function execute() {
    return parent::execute();
  }

  /**
   * @param \Civi\AfformOrder\ModifyResult $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $contributionID = $this->contributionID;
    if (empty($contributionID)) {
      throw new \CRM_Core_Exception('Order edit: contributionID is required');
    }

    $headerFields = $this->sanitizeHeaderFields($contributionID);

    // One outer transaction over BOTH the line restructure and the header
    // write, so a header failure rolls back the lines (afform gives no
    // cross-step atomicity; we own it here).
    \CRM_Core_Transaction::create()->run(function (\CRM_Core_Transaction $tx) use ($contributionID, $headerFields, $result) {
      // 0. Optimistic-concurrency guard (optional): abort if the order changed
      //    under us since the caller built the diff.
      $this->assertNotStale($contributionID);

      // 1. LINES FIRST. Permissions already enforced on this action; run the
      //    inner modify unchecked.
      $modifyResult = OrderAO::modify(FALSE)
        ->setContributionID($contributionID)
        ->setLineItemsToAdd($this->lineItemsToAdd)
        ->setLineItemsToRemove($this->lineItemsToRemove)
        ->setContext($this->context)
        ->setContextDetail($this->contextDetail)
        ->execute();

      $modifyRow = $modifyResult->first() ?: [];

      // 2. VETO / ROUTING. A consumer's validate subscriber declined to apply
      //    the change and attached outcome metadata (e.g. routed to a refund
      //    request). The whole edit is being redirected - DO NOT apply the
      //    header - relay the veto verbatim. Nothing was written, so the empty
      //    commit below is a no-op.
      if (array_key_exists('applied', $modifyRow) && $modifyRow['applied'] === FALSE) {
        $result->validate_metadata = $modifyResult->validate_metadata ?? [];
        $result[] = $modifyRow + ['header_applied' => FALSE];
        return;
      }

      // 3. HEADER AFTER LINES, so the FinancialProcessor books over the final
      //    line set.
      $headerApplied = FALSE;
      if (!empty($headerFields)) {
        Contribution::update(FALSE)
          ->addWhere('id', '=', $contributionID)
          ->setValues($headerFields)
          ->execute();
        $headerApplied = TRUE;
      }

      $result[] = ($modifyRow ?: ['id' => $contributionID, 'applied' => TRUE])
        + ['header_applied' => $headerApplied];
    });
  }

  /**
   * Optimistic-concurrency check: if the caller declared the line-item ids it
   * built the diff against, verify the contribution's current set still matches.
   *
   * @param int $contributionID
   * @throws \CRM_Core_Exception when the order changed since the diff was built
   */
  private function assertNotStale(int $contributionID): void {
    if (empty($this->expectedLineItemIDs)) {
      return;
    }
    $currentIDs = (array) LineItem::get(FALSE)
      ->addSelect('id')
      ->addWhere('contribution_id', '=', $contributionID)
      ->execute()
      ->column('id');
    $expected = array_map('intval', $this->expectedLineItemIDs);
    $current = array_map('intval', $currentIDs);
    sort($expected);
    sort($current);
    if ($expected !== $current) {
      throw new \CRM_Core_Exception(
        ts('This contribution was changed since you opened it. Please reload and try again.'),
        0,
        ['show_detailed_error' => TRUE]
      );
    }
  }

  /**
   * Filter the requested header fields: drop line-driven values, reject
   * money/checkout fields, and reject a Pending/Partially-paid -> Completed
   * status transition (which must go through the record-payment workflow).
   *
   * @param int $contributionID
   * @return array sanitized field => value, safe to hand to Contribution.update
   * @throws \CRM_Core_Exception
   */
  private function sanitizeHeaderFields(int $contributionID): array {
    $fields = $this->contributionFields;

    foreach (self::LINE_DRIVEN_FIELDS as $f) {
      unset($fields[$f]);
    }
    foreach (self::REJECTED_FIELDS as $f) {
      if (array_key_exists($f, $fields)) {
        throw new \CRM_Core_Exception(
          'Order edit will not set "' . $f . '": payment is a separate workflow and is never recorded through an order edit.'
        );
      }
    }

    if (array_key_exists('contribution_status_id', $fields) && !empty($fields['contribution_status_id'])) {
      $newStatus = \CRM_Core_PseudoConstant::getName(
        'CRM_Contribute_BAO_Contribution', 'contribution_status_id', $fields['contribution_status_id']
      );
      if ($newStatus === 'Completed') {
        $current = Contribution::get(FALSE)
          ->addSelect('contribution_status_id:name')
          ->addWhere('id', '=', $contributionID)
          ->execute()
          ->first();
        $currentStatus = $current['contribution_status_id:name'] ?? NULL;
        if (in_array($currentStatus, ['Pending', 'Partially paid'], TRUE)) {
          throw new \CRM_Core_Exception(
            'Completing a contribution records a payment; use the record-payment workflow, not an order edit.'
          );
        }
      }
    }

    return $fields;
  }

}
