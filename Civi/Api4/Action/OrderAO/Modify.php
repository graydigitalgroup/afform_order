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
use Civi\Api4\ContributionRecur;
use Civi\Api4\LineItem;
use Civi\Api4\OrderLineItem;
use Civi\AfformOrder\Event\OrderModifyValidateEvent;
use Civi\AfformOrder\Event\OrderModifyEvent;
use Civi\AfformOrder\Event\OrderModifiedEvent;
use Civi\AfformOrder\Event\OrderLineReversedEvent;
use Civi\AfformOrder\ModifyResult;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Modify the line items of an existing Order (Pending, paid, or a recurring
 * series' template contribution).
 *
 * afform_order-private orchestrator, used while core's Order.modify
 * (civicrm/civicrm-core#35433) is in review. Deliberately self-contained: it
 * does NOT call CRM_Financial_BAO_Order::update() (which does not exist in
 * stock core) and instead drives the change through the OrderLineItem
 * create / delete primitives, then recomputes the Contribution totals by
 * summing the stored line values.
 *
 * SCOPE:
 *   - PENDING (unpaid) contributions: full add/remove restructure. "remove"
 *     deletes the line (and its Unpaid FinancialItems).
 *   - TEMPLATE contributions (is_template = 1 - the editable definition of a
 *     recurring series' future installments): same restructure as Pending. A
 *     template is never "paid" (its lines carry no FinancialItems - see the
 *     OrderLineItem hooks), so "remove" deletes rather than reverses and none
 *     of the paid-path validate/balance machinery applies. After the
 *     restructure the ContributionRecur amount is re-synced to the new
 *     template total and, best-effort, the payment processor is asked to
 *     amend the live subscription (see notifyProcessorOfAmountChange).
 *   - PAID contributions (Completed / Partially paid): classifies the net effect
 *     (increase / net_zero / decrease) and dispatches a validate event
 *     (OrderModifyValidateEvent) so a subscriber can veto - e.g. a consumer
 *     extension vetoes refund-producing edits and routes them to a
 *     refund-request workflow. If not vetoed it
 *     performs the restructure: "remove" REVERSES the line (negated copy, paid
 *     history preserved), "add" creates a new line. It then derives the
 *     contribution status and records an adjustment FinancialTrxn for the new
 *     balance (see beginPaidAdjustment), mirroring core's recordAdjustedAmt.
 *     Actual money movement (a refund/extra payment) remains a SEPARATE step.
 *
 * The validate event is dispatched explicitly here, BEFORE any writes. Core's
 * Order validate event (civicrm/civicrm-core#35433) is not yet part of the save
 * pipeline; when it is, this explicit dispatch becomes redundant.
 *
 * Ownership contract:
 *   - FinancialItem lifecycle is owned entirely by OrderLineItem. This action
 *     never calls FinancialItem::add/delete directly; create/delete of a line
 *     does the financial work via the OrderLineItem hooks.
 *   - Connected-entity reconciliation (membership / participant) for *removed*
 *     lines is intentionally NOT performed here yet - it is policy that needs
 *     UI confirmation. See the marked seam below.
 *
 * @method $this setContributionID(int $contributionID)
 * @method int getContributionID()
 * @method $this setLineItemsToAdd(array $lineItemsToAdd)
 * @method array getLineItemsToAdd()
 * @method $this setLineItemsToRemove(array $lineItemsToRemove)
 * @method array getLineItemsToRemove()
 * @method $this setContext(string $context)
 * @method string getContext()
 * @method $this setContextDetail(array $contextDetail)
 * @method array getContextDetail()
 */
class Modify extends AbstractAction {

  /**
   * The ID of the Contribution whose line items we are modifying.
   *
   * @var int
   * @required
   */
  protected ?int $contributionID = NULL;

  /**
   * Line items to add. Each entry is a line spec suitable for
   * OrderLineItem::create (must resolve to a line_total, e.g. via
   * qty + unit_price, and should carry financial_type_id).
   *
   * @var array
   */
  protected array $lineItemsToAdd = [];

  /**
   * Line items to remove. Each entry must contain at least 'id'.
   *
   * @var array
   */
  protected array $lineItemsToRemove = [];

  /**
   * Caller-declared origin of this modify, e.g. 'cart_edit', or a consumer
   * workflow's own identifier.
   * OPTIONAL (default ''): carried verbatim to validate subscribers on paid
   * modifies; ignored on Pending and template contributions (which never
   * reach the validate event).
   *
   * This is a coordination signal, NOT proof of authorization - a subscriber
   * that allows a refund-producing edit on the strength of a context must
   * independently verify the claim (see OrderModifyValidateEvent).
   *
   * @var string
   */
  protected string $context = '';

  /**
   * Optional structured payload accompanying the context, for a subscriber to
   * verify against, e.g. ['activity_id' => N].
   *
   * @var array
   */
  protected array $contextDetail = [];

  /**
   * Ids of line items CREATED during this modify (added/corrected lines),
   * accumulated as the restructure runs and reported in OrderModifiedEvent.
   *
   * @var int[]
   */
  private array $modifyAddedLineIDs = [];

  /**
   * Map [removedLineItemID => addedLineItemID] for corrected lines, built from
   * the `_replaces_line_item_id` provenance on add specs. Reported in
   * OrderModifiedEvent so a consumer can follow a line's identity across the
   * remove-and-re-add.
   *
   * @var array<int, int>
   */
  private array $modifyReplacements = [];

  /**
   * Convenience setter: queue a single line item for addition.
   *
   * @param array $lineItem
   * @return $this
   */
  public function addLineItem(array $lineItem): Modify {
    $this->lineItemsToAdd[] = $lineItem;
    return $this;
  }

  /**
   * Convenience setter: queue a single line item for removal.
   *
   * @param array $lineItem
   * @return $this
   */
  public function removeLineItem(array $lineItem): Modify {
    $this->lineItemsToRemove[] = $lineItem;
    return $this;
  }

  /**
   * Declare the result class so a vetoed-with-metadata modify can carry the
   * engine-neutral bag back to the caller on a SUCCESSFUL (HTTP 200) result.
   *
   * The api4 action provider (ActionObjectProvider::getResultClass) reads the
   * @return annotation on THIS method to decide which Result subclass to
   * instantiate and hand to _run(); the runtime body just delegates to the
   * parent. This is the same idiom core uses for BasicReplaceAction (whose
   * execute() @return names ReplaceResult). See ModifyResult for why the
   * exception path could not carry the metadata.
   *
   * @return \Civi\AfformOrder\ModifyResult
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
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
      throw new \CRM_Core_Exception('Order modify: contributionID is required');
    }

    // 1. Load the contribution.
    $contribution = Contribution::get(FALSE)
      ->addSelect('id', 'contribution_status_id:name', 'currency', 'total_amount', 'is_template', 'contribution_recur_id')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first();
    if (empty($contribution)) {
      throw new \CRM_Core_Exception('Order modify: contribution ' . $contributionID . ' not found');
    }
    $statusName = $contribution['contribution_status_id:name'];
    $isPending = ($statusName === 'Pending');
    // A recurring series' template contribution takes the Pending-style
    // restructure path: it is never "paid" (no payments; its lines carry no
    // FinancialItems - the OrderLineItem hooks skip financial records for
    // templates), so "remove" means delete (not reversal) and none of the
    // paid-path balance/status machinery applies. Without this flag its
    // status - 'Template', not 'Pending' - would mis-route it down the paid
    // branch: the validate event would classify a template decrease as
    // refund-producing (there is no money to refund) and beginPaidAdjustment
    // would book AR adjustments against a contribution that owes nothing.
    $isTemplate = !empty($contribution['is_template']);

    // 2. For PAID contributions, classify the net effect and dispatch a validate
    //    event so a subscriber can veto (e.g. route refund-producing edits to a
    //    refund-request workflow). We do this BEFORE any writes. With no
    //    subscriber the change proceeds (generic "just works"); if not vetoed we
    //    perform the paid restructure (line reversal) below.
    if (!$isPending && !$isTemplate) {
      // NOTE: context is OPTIONAL. OrderAO.modify is generic; requiring a
      // context would be surprising for a non-core API and would leak a
      // consumer's refund policy into the shared engine. We simply carry
      // whatever context the caller set (default '') and hand it to validate
      // subscribers. Any gating of refund-producing edits is a SUBSCRIBER's job
      // (e.g. a consumer extension vetoes a decrease unless it came from an
      // approved refund request). An install with no such subscriber gets the
      // generic "just works" behaviour: the paid reversal proceeds.
      $projectedTotal = $this->projectNewTotal($contributionID);
      $currentTotal = (float) ($contribution['total_amount'] ?? 0);
      $netDelta = $projectedTotal - $currentTotal;
      if (abs($netDelta) < 0.005) {
        $netEffect = OrderModifyValidateEvent::EFFECT_NET_ZERO;
      }
      elseif ($netDelta > 0) {
        $netEffect = OrderModifyValidateEvent::EFFECT_INCREASE;
      }
      else {
        $netEffect = OrderModifyValidateEvent::EFFECT_DECREASE;
      }

      $event = new OrderModifyValidateEvent(
        $contributionID,
        $statusName,
        $netEffect,
        $netDelta,
        $this->lineItemsToAdd,
        $this->lineItemsToRemove,
        $this->context,
        $this->contextDetail
      );
      \Civi::dispatcher()->dispatch(OrderModifyValidateEvent::EVENT_NAME, $event);
      $errors = $event->getErrors();
      if ($errors) {
        // A subscriber vetoed the modification. Nothing has been written.
        //
        // HOW THE OUTCOME REACHES THE CALLER depends on whether the subscriber
        // attached engine-neutral metadata to the validate event:
        //
        //  - VETO WITH METADATA (e.g. a consumer's gate routing a
        //    refund-producing edit: "don't apply this; turn it into a refund
        //    request, here is the intended change"). This is NOT an error - it
        //    is a valid outcome the engine declined to auto-apply and is
        //    reporting back. We return it as a SUCCESSFUL result: a row flagged
        //    applied=FALSE carrying the veto message(s), and the metadata bag on
        //    the ModifyResult::$validate_metadata property. This is the ONLY
        //    reliable transport to the api4 AJAX client: a thrown
        //    CRM_Core_Exception is flattened by CRM_Api4_Page_AJAX to
        //    error_id/error_code/error_message and the rest of getErrorData() is
        //    DROPPED, so structured metadata cannot ride out on an exception. A
        //    200 result, by contrast, is returned whole (declared Result
        //    properties are forwarded alongside `values`, and the client's
        //    arrayObject() copies them onto the resolved result). afform_order
        //    names no keys in the bag; a consumer (and its client) agree on them
        //    (e.g. 'refund_required').
        //
        //  - VETO WITHOUT METADATA (e.g. an unverifiable refund-request context,
        //    or a double-reversal collision). This is a genuine rejection the
        //    user must see as an error, so we THROW as before.
        //
        // In BOTH cases nothing was written (the veto fires before any
        // restructure).
        $metadata = $event->getAllMetadata();
        if (!empty($metadata)) {
          $result->validate_metadata = $metadata;
          $result[] = [
            'id' => $contributionID,
            'applied' => FALSE,
            'net_effect' => $netEffect,
            'net_delta' => $netDelta,
            'messages' => $errors,
          ];
          return;
        }
        // No metadata: a hard rejection. Keep show_detailed_error so the
        // message itself (not a generic "an error occurred") reaches the user
        // even without 'view debug output' permission (see CRM_Api4_Page_AJAX).
        throw new \CRM_Core_Exception(implode("\n", $errors), 0, ['show_detailed_error' => TRUE]);
      }

      // Not vetoed - shape the lines being added (pre-save mutate seam), THEN
      // restructure. The validate event above is the gate; this alter seam runs
      // only after it passes, so a consumer can never reshape lines that the
      // gate then rejects. (Order matters: validate -> alter -> write, the same
      // lifecycle as create.) See dispatchModifyAlter / OrderModifyEvent.
      $this->dispatchModifyAlter($contributionID, $isTemplate);

      // Perform the PAID restructure. Unlike the Pending path, "remove" here
      // does NOT delete (OrderLineItem.delete throws on paid lines, and paid
      // financial history must be preserved). Instead a remove REVERSES the
      // line: an ordinary create with negated unit_price and the exact negated
      // stored tax, leaving the original line intact. An "add" is a normal
      // create. A line CORRECTION is expressed by the caller as a remove
      // (reverse the old) + add (the corrected) pair.
      //
      // The restructure follows core's changeFeeSelections ORDER (see the
      // numbered steps below): reverse removed lines, THEN move the contribution
      // status + book the AR adjustment trxn (beginPaidAdjustment), THEN create
      // the added lines (so their FinancialItems pick up the adjusted status and
      // allocate to the AR trxn), THEN persist the recomputed total/tax. This
      // mirrors CRM_Price_BAO_LineItem::recordAdjustedAmt: when a paid
      // contribution's line total changes and no payment is taken, the status is
      // moved directly (Completed -> Partially paid for an increase, -> Pending
      // refund for a decrease) and an AR adjustment trxn records the now-owed /
      // now-owed-back balance; a later Record Payment / Record Refund settles
      // it. The status write bypasses the APIv4 status-transition guard (which
      // rejects e.g. Completed -> Partially paid) via direct DAO, as core does.
      $totalAmount = 0.0;
      $taxAmount = 0.0;
      // Capture the contribution total BEFORE this call's restructure. The
      // adjustment FinancialTrxn must record only the change THIS call makes
      // (new total - prior total), not the full outstanding balance: modify is
      // called per-edit, so booking the whole balance each time would stack
      // overlapping AR trxns that overstate what is owed. (Core's
      // _recordAdjustedAmt books the full balance because changeFeeSelections
      // runs once for the entire new selection, not incrementally.)
      $priorTotal = (float) ($contribution['total_amount'] ?? 0);
      \CRM_Core_Transaction::create()->run(function (\CRM_Core_Transaction $tx) use ($contributionID, $priorTotal, $projectedTotal, $netEffect, &$totalAmount, &$taxAmount) {
        // 1. Reverse each "removed" line FIRST, while the contribution is still
        //    in its pre-edit status. A reversal line's FinancialItem is created
        //    against the original payment (the established, refund-flow-tested
        //    behaviour); we deliberately do NOT re-point it. Optional
        //    removal_reason annotates the reversal label (composed in
        //    reverseLine where the original label is in hand).
        foreach ($this->lineItemsToRemove as $lineItem) {
          if (empty($lineItem['id'])) {
            throw new \CRM_Core_Exception('Order modify: each lineItemsToRemove entry requires an id');
          }
          $this->reverseLine((int) $lineItem['id'], $contributionID, $lineItem['removal_reason'] ?? NULL);
        }

        // 2. Adjust the contribution STATUS + book the AR adjustment trxn
        //    BEFORE creating the added lines, mirroring core's
        //    changeFeeSelections order (_recordAdjustedAmt THEN
        //    addFinancialItemsOnLineItemsChange). This is the fix for paid-add
        //    line financials: a line added to a still-Completed contribution
        //    would otherwise get a Paid FinancialItem allocated against the
        //    original payment. With the status moved first (e.g. -> Partially
        //    paid) and the AR trxn booked, the new lines' FinancialItems come
        //    out with the adjusted status and are allocated to the AR trxn (see
        //    step 3). Uses the projected final total computed pre-restructure
        //    (membership term shaping does not change line totals); the AR trxn
        //    is booked for only THIS call's increment (projected - prior).
        $arTrxnId = $this->beginPaidAdjustment($contributionID, $projectedTotal, $priorTotal);

        // 3. Add each new/corrected line. The created line's FinancialItem(s)
        //    are allocated to the AR adjustment trxn (OrderLineItem's create
        //    hook honours financial_trxn_id) rather than the original payment,
        //    and inherit the now-adjusted contribution status.
        foreach ($this->lineItemsToAdd as $lineItem) {
          if (empty($lineItem['price_field_value_id'])) {
            throw new \CRM_Core_Exception(
              'Order modify: each lineItemsToAdd entry requires a price_field_value_id ' .
              '(a line item must reference a price field value).'
            );
          }
          // Provenance: a corrected line carries the id of the line it replaces
          // (set by the cart). Pull it off before create (not a LineItem column)
          // and record the old->new pairing for OrderModifiedEvent.
          $replaces = $lineItem['_replaces_line_item_id'] ?? NULL;
          unset($lineItem['_replaces_line_item_id']);
          // A membership (or other connected-entity) line with no entity_id is
          // CREATED here (see resolveLineItemEntity): a Pending membership that
          // renews when the balance payment completes the contribution.
          $lineItem = $this->resolveLineItemEntity($lineItem, $contributionID);
          $lineItem['contribution_id'] = $contributionID;
          $lineItem['entity_table'] ??= 'civicrm_contribution';
          $lineItem['entity_id'] ??= $contributionID;
          // Allocate the new line's FinancialItem(s) to the AR adjustment trxn
          // ONLY on a net INCREASE - a genuinely new, not-yet-paid line (money
          // now owed). On a net DECREASE these adds are a refund's RETAINED
          // re-add (reverse the original line, add back the kept portion); that
          // retained amount is ALREADY-PAID money, so it must allocate to the
          // original payment - which it does when financial_trxn_id is left
          // unset (the OrderLineItem hook falls back to the earliest payment
          // trxn), the pre-reorder behaviour. Routing it to the negative AR
          // adjustment instead would misallocate the retained, paid amount.
          if ($arTrxnId && $netEffect === OrderModifyValidateEvent::EFFECT_INCREASE) {
            $lineItem['financial_trxn_id'] = $arTrxnId;
          }
          $createdLine = civicrm_api4('OrderLineItem', 'create', [
            'checkPermissions' => FALSE,
            'values' => $lineItem,
          ])->first();
          $this->recordAddedLine($createdLine, $replaces);
        }

        // 4. Recompute the contribution total/tax from the stored lines (now
        //    including reversal + new lines) and persist them. The status was
        //    already set in step 2; this writes only the amounts.
        $lines = LineItem::get(FALSE)
          ->addSelect('line_total', 'tax_amount')
          ->addWhere('contribution_id', '=', $contributionID)
          ->execute();
        foreach ($lines as $line) {
          $totalAmount += (float) ($line['line_total'] ?? 0) + (float) ($line['tax_amount'] ?? 0);
          $taxAmount += (float) ($line['tax_amount'] ?? 0);
        }
        $this->persistContributionTotals($contributionID, $totalAmount, $taxAmount);

        // Post-restructure seam (inside the txn so a listener's writes are
        // atomic with the modify). Paid path is never a template.
        $this->dispatchModified($contributionID, FALSE);
      });

      $result[] = [
        'id' => $contributionID,
        'applied' => TRUE,
        'total_amount' => $totalAmount,
        'tax_amount' => $taxAmount,
        'net_effect' => $netEffect,
        'net_delta' => $netDelta,
      ];
      return;
    }

    // 3. PENDING / TEMPLATE path: full add/remove restructure inside one
    //    transaction.

    // Pre-save mutate seam over the lines being added. The Pending/template
    // path has no validate (veto) seam of its own - that is the paid path's
    // refund gate - so the alter seam simply runs here before the restructure.
    // (On the paid path it runs after the veto passes; see above.)
    $this->dispatchModifyAlter($contributionID, $isTemplate);

    //    We use CRM_Core_Transaction::create()->run($callable) - the idiom
    //    documented in that class - rather than a hand-rolled try/catch. Under
    //    CiviCRM's reference-counted transactions, rollback() only MARKS the
    //    frame rollback-only; the unwind happens when the frame resolves. run()
    //    guarantees that on an exception it calls rollback()->commit() (forcing
    //    the resolution) before rethrowing, so a fatal mid-modify cannot leave a
    //    partially-changed order. A bare rollback() + throw does not, because the
    //    inner API calls may already have pseudo-committed their own frames.
    $totalAmount = 0.0;
    $taxAmount = 0.0;
    \CRM_Core_Transaction::create()->run(function (\CRM_Core_Transaction $tx) use ($contributionID, $isTemplate, &$totalAmount, &$taxAmount) {
      // 3a. Remove requested lines. OrderLineItem::delete tears down the line's
      //    Unpaid FinancialItems (and would throw on a Paid line - a second
      //    guard behind the Pending check above).
      foreach ($this->lineItemsToRemove as $lineItem) {
        if (empty($lineItem['id'])) {
          throw new \CRM_Core_Exception('Order modify: each lineItemsToRemove entry requires an id');
        }
        // CONNECTED-ENTITY SEAM: if this line points at a membership or
        // participant, follow core's QuickForm behaviour for editing/deleting a
        // Pending contribution's lines (the membership/participant + its payment
        // link can be removed). Not yet implemented here - tracked for the cart
        // edit UI work.
        OrderLineItem::delete(FALSE)
          ->addWhere('id', '=', $lineItem['id'])
          ->execute();
      }

      // 4. Add requested lines. OrderLineItem::create makes the line and its
      //    FinancialItem(s) via the post hook. contribution_id is forced to the
      //    order under modification so callers cannot retarget another order.
      //
      //    Each added line must reference a price field/value, exactly as any
      //    new CiviCRM line item does: a line without price_field_value_id is
      //    malformed and will fatal later when core tries to title or recalc it
      //    (CRM_Financial_BAO_Order::getPriceFieldSpec on a null price_field_id).
      //    We require it up front rather than guessing a default.
      //
      //    We invoke via civicrm_api4() with an explicit 'values' param rather
      //    than the fluent ->setValues() setter: the fluent setter routes
      //    through AbstractAction::__call -> paramExists('values'), which is not
      //    reliably registered on this OrderLineItem create subclass and throws
      //    "Unknown api parameter: setValues". The array form is stable.
      foreach ($this->lineItemsToAdd as $lineItem) {
        if (empty($lineItem['price_field_value_id'])) {
          throw new \CRM_Core_Exception(
            'Order modify: each lineItemsToAdd entry requires a price_field_value_id ' .
            '(a line item must reference a price field value).'
          );
        }
        // Provenance: a corrected line carries the id of the line it replaces
        // (set by the cart). Pull it off before create (not a LineItem column)
        // and record the old->new pairing for OrderModifiedEvent.
        $replaces = $lineItem['_replaces_line_item_id'] ?? NULL;
        unset($lineItem['_replaces_line_item_id']);
        // A membership line with no membership to point at is CREATED here: a
        // Pending, dateless membership, recur-linked when the contribution has
        // a recur. This is the pending/template path, where the
        // create-then-complete model is correct (dates land when the first
        // payment completes). See resolveLineItemEntity.
        $lineItem = $this->resolveLineItemEntity($lineItem, $contributionID);
        $lineItem['contribution_id'] = $contributionID;
        // entity_table / entity_id for a contribution line are determined, not
        // guessed: the line belongs to civicrm_contribution and to this
        // contribution id. The BAO pre-hook sets the same values, but the create
        // required-field gate (AbstractCreateAction::checkRequiredFields) runs
        // BEFORE the hook, so we supply them here. (The spec-provider relaxation
        // is not reliably honoured by the required-field check on this core
        // version, hence setting the values rather than relying on it.)
        $lineItem['entity_table'] ??= 'civicrm_contribution';
        $lineItem['entity_id'] ??= $contributionID;
        $createdLine = civicrm_api4('OrderLineItem', 'create', [
          'checkPermissions' => FALSE,
          'values' => $lineItem,
        ])->first();
        $this->recordAddedLine($createdLine, $replaces);
      }

      // 5. Recompute Contribution totals by summing the *stored* line values
      //    (option a). Faithful to what is in the DB: each line's tax_amount
      //    was computed by the OrderLineItem create hook, so we do not re-derive.
      $lines = LineItem::get(FALSE)
        ->addSelect('line_total', 'tax_amount')
        ->addWhere('contribution_id', '=', $contributionID)
        ->execute();
      foreach ($lines as $line) {
        $totalAmount += (float) ($line['line_total'] ?? 0) + (float) ($line['tax_amount'] ?? 0);
        $taxAmount += (float) ($line['tax_amount'] ?? 0);
      }

      // Write the two scalar columns directly via the DAO rather than through
      // Contribution::update. The APIv4 update path re-enters core's order
      // machinery (CRM_Financial_BAO_Order::getLineItems -> calculateLineItems
      // -> getLineItemTitle -> getPriceFieldSpec), which rebuilds line items in
      // a context where price_field_id is not carried and fatals on a null id
      // (Order.php getPriceFieldSpec(int $id)). We have already computed the
      // authoritative totals from the stored lines, so we deliberately bypass
      // any recalculation and just persist them.
      $contributionDAO = new \CRM_Contribute_DAO_Contribution();
      $contributionDAO->id = $contributionID;
      $contributionDAO->total_amount = $totalAmount;
      $contributionDAO->tax_amount = $taxAmount;
      $contributionDAO->save();

      if ($isTemplate) {
        // The direct DAO save above deliberately bypasses hook_civicrm_post
        // (see the comment above) - which also bypasses core's automatic
        // template->recur amount sync (CRM_Contribute_BAO_Contribution::
        // self_hook_civicrm_post -> updateOnTemplateUpdated). Re-establish the
        // core invariant (ContributionRecur.amount == template total) by
        // calling the same core helper the hook would have, rather than
        // re-implementing its currency-aware comparison and modified_date
        // handling. The helper needs a loaded DAO (it early-returns unless
        // is_template / contribution_recur_id are populated), fetched via a
        // FRESH id-only DAO: find() builds its WHERE from every set property,
        // so reusing the save DAO would also match on total_amount/tax_amount
        // and silently find nothing if MySQL's decimal rounding stored a value
        // differing from the PHP float. Inside the transaction on purpose - if
        // the sync fails, the template restructure rolls back with it rather
        // than leaving the series advertising a stale amount.
        $savedContribution = new \CRM_Contribute_DAO_Contribution();
        $savedContribution->id = $contributionID;
        $savedContribution->find(TRUE);
        \CRM_Contribute_BAO_ContributionRecur::updateOnTemplateUpdated($savedContribution);
      }

      // Post-restructure seam (inside the txn so a listener's writes are atomic
      // with the modify).
      $this->dispatchModified($contributionID, $isTemplate);
    });

    // 6. For a template, ask the payment processor to amend the live
    //    subscription to the new amount. AFTER the transaction on purpose:
    //    this is a network call, and it is best-effort by design - the local
    //    template + recur changes hold regardless, and the outcome (updated /
    //    processor can't / processor failed) is reported on the result row for
    //    the caller to surface.
    $processorOutcome = NULL;
    if ($isTemplate && !empty($contribution['contribution_recur_id'])) {
      $processorOutcome = $this->notifyProcessorOfAmountChange(
        (int) $contribution['contribution_recur_id'],
        $totalAmount
      );
    }

    // 7. Return the updated contribution summary.
    $row = [
      'id' => $contributionID,
      'applied' => TRUE,
      'total_amount' => $totalAmount,
      'tax_amount' => $taxAmount,
    ];
    if ($isTemplate) {
      $row['is_template'] = TRUE;
      $row['contribution_recur_id'] = $contribution['contribution_recur_id'] ?? NULL;
      // The synced series amount == the new template total (incl. tax), the
      // same figure core's updateOnTemplateUpdated wrote to the recur.
      $row['recur_amount'] = $totalAmount;
      if ($processorOutcome !== NULL) {
        $row['processor_notified'] = $processorOutcome['notified'];
        $row['processor_message'] = $processorOutcome['message'];
      }
    }
    $result[] = $row;
  }

  /**
   * Dispatch the pre-save mutate seam (OrderModifyEvent) over the lines being
   * ADDED, letting a consumer shape them the same way an OrderCreateEvent
   * listener shapes a create (e.g. recompute a membership line's term count
   * from its quantity — a pricing-model policy that lives in the consumer, not
   * this generic engine). The shaped lines are written back to
   * $this->lineItemsToAdd so the restructure picks them up.
   *
   * ORDER: this runs AFTER the validate (veto) seam, never before — a consumer
   * must not be able to reshape lines that the gate then rejects. On the paid
   * path it is called once the veto passes; on the Pending/template path (which
   * has no validate seam) it is called at the start of the restructure. Only
   * the lines being ADDED are exposed: reversal lines are built internally
   * (reverseLine) from lineItemsToRemove and carry exact negated amounts that
   * must not be reshaped.
   *
   * @param int $contributionID
   * @param bool $isTemplate
   * @return void
   */
  private function dispatchModifyAlter(int $contributionID, bool $isTemplate): void {
    $event = new OrderModifyEvent($contributionID, $this->lineItemsToAdd, $isTemplate);
    \Civi::dispatcher()->dispatch(OrderModifyEvent::NAME, $event);
    $this->lineItemsToAdd = $event->getLineItems();
  }

  /**
   * Record a line just created by the restructure: its id (for
   * addedLineItemIDs) and, when it corrects an existing line, the old->new
   * pairing (for the replacements map). Reported in OrderModifiedEvent.
   *
   * @param array|null $createdLine The OrderLineItem.create result row.
   * @param int|null $replaces The id of the line this one replaces, if any.
   * @return void
   */
  private function recordAddedLine(?array $createdLine, $replaces): void {
    $newId = (int) ($createdLine['id'] ?? 0);
    if (!$newId) {
      return;
    }
    $this->modifyAddedLineIDs[] = $newId;
    if (!empty($replaces)) {
      $this->modifyReplacements[(int) $replaces] = $newId;
    }
  }

  /**
   * Fire the post-restructure seam (OrderModifiedEvent) describing the change:
   * the lines added, the lines the caller asked to remove, and the old->new
   * pairing for corrected lines. Dispatched from INSIDE the restructure
   * transaction so a listener's writes commit/roll back with the modify.
   *
   * afform_order ships no listener; this is provenance for consumers (e.g.
   * re-establishing a soft credit on a replacement line). See OrderModifiedEvent.
   *
   * @param int $contributionID
   * @param bool $isTemplate
   * @return void
   */
  private function dispatchModified(int $contributionID, bool $isTemplate): void {
    $removedLineIDs = [];
    foreach ($this->lineItemsToRemove as $lineItem) {
      if (!empty($lineItem['id'])) {
        $removedLineIDs[] = (int) $lineItem['id'];
      }
    }
    $event = new OrderModifiedEvent(
      $contributionID,
      $this->modifyAddedLineIDs,
      $removedLineIDs,
      $this->modifyReplacements,
      $isTemplate
    );
    \Civi::dispatcher()->dispatch(OrderModifiedEvent::EVENT_NAME, $event);
  }

  /**
   * Ensure an added line that targets a related entity (membership, participant,
   * ...) says WHICH one — resolving an existing link or creating the entity.
   *
   * The entity_id default applied to a line after this returns is the
   * contribution id — correct only for a civicrm_contribution line. Letting it
   * apply to e.g. a civicrm_membership line would silently stamp the
   * contribution id into a membership reference: a dangling or wrong-record
   * link written without complaint. So a related-entity line MUST resolve to a
   * real entity_id here.
   *
   *   - Line already carries entity_id (an existing record the staff picked, or
   *     a loaded line's own link) -> returned unchanged.
   *   - Related-entity line, no entity_id -> CREATE the entity via the generic
   *     saveLineItemEntity mirror and stamp its id on the line. Any entity type
   *     Order.create can build from a line, this can too. This applies on EVERY
   *     modify path (Pending, template, paid): adding a line to a paid
   *     contribution is a first-class operation (-> Partially paid + AR
   *     adjustment; settle by recording the balance), and a connected entity so
   *     added is created Pending and activated/renewed when that balance payment
   *     completes the contribution — the same create-then-complete model as a
   *     pending order.
   *
   * Membership lines get a small amount of PREP first, mirroring what
   * Order.create's pipeline does upstream of saveLineItemEntity (and what
   * Submit.php does on our create path): derive membership_type_id from the
   * price field value if absent, and default the new membership to Pending
   * (status passed -> saveLineItemEntity skips its date-based status calc, so
   * we get a Pending dateless membership — correct for a pay-later order and a
   * future-installment template add; dates land when the first payment
   * completes). This prep stays in the orchestration layer, NOT in the saver,
   * so the saver remains a faithful generic drop-in for core's eventual
   * Order.modify entity handling.
   *
   * @param array $lineItem
   * @param int $contributionID
   * @return array The line, with entity_id resolved when an entity was created.
   * @throws \CRM_Core_Exception
   */
  private function resolveLineItemEntity(array $lineItem, int $contributionID): array {
    $entityTable = $lineItem['entity_table'] ?? 'civicrm_contribution';
    if ($entityTable === 'civicrm_contribution' || !empty($lineItem['entity_id'])) {
      return $lineItem;
    }

    if ($entityTable === 'civicrm_membership') {
      $lineItem = $this->prepNewMembershipLine($lineItem);
    }
    $lineItem['entity_id'] = $this->saveLineItemEntity($lineItem, $contributionID);
    return $lineItem;
  }

  /**
   * Prep a new membership line before saveLineItemEntity, mirroring what
   * Order.create's pipeline / Submit.php do on the create path: ensure
   * membership_type_id (from the price field value) and default the new
   * membership to Pending unless the line set a status explicitly.
   *
   * @param array $lineItem
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function prepNewMembershipLine(array $lineItem): array {
    if (empty($lineItem['membership_type_id']) && !empty($lineItem['price_field_value_id'])) {
      $lineItem['membership_type_id'] = \Civi\Api4\PriceFieldValue::get(FALSE)
        ->addSelect('membership_type_id')
        ->addWhere('id', '=', $lineItem['price_field_value_id'])
        ->execute()
        ->first()['membership_type_id'] ?? NULL;
    }
    if (empty($lineItem['membership_type_id'])) {
      throw new \CRM_Core_Exception(
        'Order modify: cannot create a membership for a line whose price field ' .
        'value has no membership type'
      );
    }
    // Pending default unless the line carries an explicit entity_id.status_id*.
    $hasStatus = FALSE;
    foreach ($lineItem as $key => $value) {
      if (strpos($key, 'entity_id.status_id') === 0) {
        $hasStatus = TRUE;
        break;
      }
    }
    if (!$hasStatus) {
      $lineItem['entity_id.status_id:name'] = 'Pending';
    }
    // "Member since" (join_date) = the date the membership was ADDED (now).
    // saveLineItemEntity would otherwise default it to the contribution's
    // receive_date, which for an edit of an older paid contribution predates
    // the add. start_date/end_date are deliberately left UNSET so they are
    // derived when the first payment completes the contribution (the line's
    // membership_num_terms drives the term length) — i.e. the membership
    // "starts" when paid, not when added.
    if (empty($lineItem['entity_id.join_date'])) {
      $lineItem['entity_id.join_date'] = date('Y-m-d');
    }
    return $lineItem;
  }

  /**
   * Save the entity related to a line item, returning its id.
   *
   * FAITHFUL MIRROR of CRM_Financial_BAO_Order::saveLineItemEntity (PRIVATE in
   * core, hence unreachable — lifted from core, same as the balance-adjustment
   * logic in beginPaidAdjustment). Entity-agnostic
   * by design: it resolves the entity from entity_table and creates whatever a
   * line names (membership, participant, ...), exactly as core does, so it is a
   * drop-in once core's Order.modify owns entity creation. The only adaptation
   * is the source of contribution context: core reads $this->contributionValues
   * (the contribution being created); we load the EXISTING contribution's
   * values, since modify operates on one that already exists.
   *
   * Mirrors core's behaviour for a NEW entity (no entity_id):
   *  - extract entity_id.* line keys into entity values;
   *  - carry over contribution values that are also fields on the target entity
   *    (contact_id, campaign_id, is_test, ...);
   *  - Participant: default fee_amount/fee_level from the line's unit_price/label;
   *  - Membership: link contribution_recur_id when the contribution has a recur,
   *    take membership_type_id from the line if not already set, default
   *    join_date to the contribution's receive_date, and calculate status from
   *    dates when none was passed (our caller pre-sets Pending, so that calc is
   *    skipped on our path — it is here for fidelity / other callers).
   *
   * @param array $lineItem
   * @param int $contributionID
   * @return int The id of the saved (created) entity.
   * @throws \CRM_Core_Exception
   */
  private function saveLineItemEntity(array $lineItem, int $contributionID): int {
    $entityTable = $lineItem['entity_table'] ?? NULL;
    $entity = \CRM_Core_DAO_AllCoreTables::getEntityNameForTable($entityTable);
    if (empty($entity)) {
      throw new \CRM_Core_Exception('Order modify: unknown entity_table ' . $entityTable);
    }

    // Contribution context (core uses $this->contributionValues; we load the
    // existing contribution). A broad-ish set; the intersect below keeps only
    // the columns that are also fields on the target entity.
    $contribution = Contribution::get(FALSE)
      ->addSelect('contact_id', 'campaign_id', 'financial_type_id', 'currency',
        'is_test', 'receive_date', 'source', 'contribution_recur_id')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first();

    $entityValues = empty($lineItem['entity_id']) ? [] : ['id' => $lineItem['entity_id']];
    foreach ($lineItem as $fieldName => $fieldValue) {
      if (strpos($fieldName, 'entity_id.') === 0) {
        $entityValues[substr($fieldName, 10)] = $fieldValue;
      }
    }

    if (empty($entityValues['id'])) {
      // New entity: carry over contribution values that are also fields here.
      $fields = (array) civicrm_api4($entity, 'getFields', [
        'checkPermissions' => FALSE,
        'action' => 'create',
      ])->indexBy('name');
      $carryOverFields = array_intersect_key($contribution, $fields);
      // CRITICAL: never carry the CONTRIBUTION's identity onto the new entity.
      // APIv4 get returns 'id' even when unselected, and the target entity also
      // has an 'id' field, so the intersect above copies the contribution id in
      // - which would make this a (doomed) UPDATE of a non-existent record of
      // that id rather than a create. Core's saveLineItemEntity is immune
      // because its contributionValues is a create-shape array with no id; we
      // load an existing contribution, so we must strip it. entity_id/
      // entity_table are stripped for the same defensive reason.
      unset($carryOverFields['id'], $carryOverFields['entity_id'], $carryOverFields['entity_table']);

      if ($entity === 'Participant') {
        $carryOverFields += array_filter([
          'fee_amount' => $lineItem['unit_price'] ?? NULL,
          'fee_level' => $lineItem['label'] ?? NULL,
        ]);
      }
      $entityValues += $carryOverFields;

      if ($entity === 'Membership') {
        if (empty($entityValues['contribution_recur_id']) && !empty($contribution['contribution_recur_id'])) {
          $entityValues['contribution_recur_id'] = $contribution['contribution_recur_id'];
        }
        // membership_type_id is special-cased in core: its own PriceFieldValue
        // field. If the line carries one and entityValues has no
        // membership_type_id* key yet, use the line's.
        if (!empty($lineItem['membership_type_id'])) {
          $hasType = FALSE;
          foreach ($entityValues as $k => $v) {
            if (strpos($k, 'membership_type_id') === 0) {
              $hasType = TRUE;
              break;
            }
          }
          if (!$hasType) {
            $entityValues['membership_type_id'] = $lineItem['membership_type_id'];
          }
        }
        if (empty($entityValues['join_date']) && !empty($contribution['receive_date'])) {
          $entityValues['join_date'] = $contribution['receive_date'];
        }
        // Calculate status from dates only when none was passed (mirrors core).
        $hasStatus = FALSE;
        foreach ($entityValues as $k => $v) {
          if (strpos($k, 'status_id') === 0 && !empty($v)) {
            $hasStatus = TRUE;
            break;
          }
        }
        if (!$hasStatus) {
          $entityValues['status_id'] = \CRM_Member_BAO_MembershipStatus::getMembershipStatusByDate(
            $entityValues['start_date'] ?? NULL,
            $entityValues['end_date'] ?? NULL,
            $entityValues['join_date'] ?? NULL,
            $contribution['receive_date'] ?? 'now',
            TRUE,
            $entityValues['membership_type_id'] ?? NULL
          )['id'];
        }
      }
    }

    if (array_keys($entityValues) === ['id']) {
      // Nothing to change on an existing entity.
      return (int) $entityValues['id'];
    }
    // For a NEW entity use the CREATE action, not save. Core's saveLineItemEntity
    // uses 'save' generically, but our resolver only ever creates (an existing
    // entity_id short-circuits in resolveLineItemEntity), and the CREATE action
    // matters for Membership: MembershipCreationSpecProvider applies ONLY to
    // 'create' and sets the `version = 4` flag that bypasses the membership
    // BAO's legacy date/status recalculation ("dark magic"). Via 'save' that
    // provider never fires, so the BAO recalculates dates/status and our
    // Pending-dateless intent is lost. (An existing entity, the dead branch
    // here, would route through 'save'.)
    if (empty($entityValues['id'])) {
      return (int) civicrm_api4($entity, 'create', [
        'values' => $entityValues,
        'checkPermissions' => FALSE,
      ])->first()['id'];
    }
    return (int) civicrm_api4($entity, 'save', [
      'records' => [$entityValues],
      'checkPermissions' => FALSE,
    ])->first()['id'];
  }

  /**
   * Compute what the contribution total WOULD be after applying the proposed
   * add/remove, without writing anything. Used only to classify the net effect
   * for the validate event on a paid contribution.
   *
   * = (sum of current lines, excluding those being removed)
   *   + (sum of lines being added)
   * each term including tax.
   *
   * Added-line totals are taken from the supplied line spec: line_total if
   * given, else qty * unit_price; tax from tax_amount if given. This mirrors
   * what the create path would store but does not run it. (A more exact figure
   * would re-derive tax from the price field value, but for classification
   * - increase vs decrease vs net-zero - the supplied values are sufficient and
   * avoid extra lookups.)
   *
   * @param int $contributionID
   * @return float
   * @throws \CRM_Core_Exception
   */
  private function projectNewTotal(int $contributionID): float {
    $removeIDs = [];
    foreach ($this->lineItemsToRemove as $lineItem) {
      if (!empty($lineItem['id'])) {
        $removeIDs[(int) $lineItem['id']] = TRUE;
      }
    }

    $projected = 0.0;
    $currentLines = LineItem::get(FALSE)
      ->addSelect('id', 'line_total', 'tax_amount')
      ->addWhere('contribution_id', '=', $contributionID)
      ->execute();
    foreach ($currentLines as $line) {
      if (isset($removeIDs[(int) $line['id']])) {
        continue;
      }
      $projected += (float) ($line['line_total'] ?? 0) + (float) ($line['tax_amount'] ?? 0);
    }

    foreach ($this->lineItemsToAdd as $lineItem) {
      if (isset($lineItem['line_total'])) {
        $lineTotal = (float) $lineItem['line_total'];
      }
      elseif (isset($lineItem['qty'], $lineItem['unit_price'])) {
        $lineTotal = (float) $lineItem['qty'] * (float) $lineItem['unit_price'];
      }
      else {
        $lineTotal = 0.0;
      }
      $projected += $lineTotal + (float) ($lineItem['tax_amount'] ?? 0);
    }

    return $projected;
  }

  /**
   * Reverse a paid line item by creating a negated copy of it.
   *
   * The reversal is an ordinary OrderLineItem create with:
   *   - negated unit_price (qty stays positive, so line_total comes out negative)
   *   - the EXACT negated stored tax_amount passed explicitly (NOT recomputed),
   *     so the reversal cancels the original to the cent even if the original's
   *     tax had manual adjustment or rounding quirks
   *   - the same price_field_id / price_field_value_id / financial_type_id / label
   *   - isReversal = TRUE (so the account resolver / hooks can treat it as a
   *     reversal if they wish; the resolved financial account is the same
   *     AP-first/Income-fallback as the original)
   *
   * Through the OrderLineItem create hook this yields a correctly-signed
   * negative FinancialItem (Paid status, derived from the Completed contribution)
   * plus a negative tax FinancialItem when tax applies. The original line is left
   * intact - the negative line documents the back-out alongside it, so the
   * contribution view and the financials reconcile line-for-line.
   *
   * @param int $lineItemID
   * @param int $contributionID
   * @param string|null $removalReason Optional staff-supplied reason; when set,
   *   appended to the reversal line's label as " - REVERSED: <reason>" so the
   *   contribution view documents why the line was backed out.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  private function reverseLine(int $lineItemID, int $contributionID, ?string $removalReason = NULL): void {
    $orig = LineItem::get(FALSE)
      ->addSelect('*')
      ->addWhere('id', '=', $lineItemID)
      ->addWhere('contribution_id', '=', $contributionID)
      ->execute()
      ->first();
    if (empty($orig)) {
      throw new \CRM_Core_Exception(
        'Order modify: line ' . $lineItemID . ' not found on contribution ' . $contributionID . ' to reverse'
      );
    }

    // Compose the reversal label: the original label, optionally annotated with
    // the staff-supplied removal reason. Done here (not in a create hook) because
    // this is the one place both the original label and the caller's reason are
    // in hand; a generic create hook has no business knowing about removal
    // reasons.
    $reversalLabel = $orig['label'] ?? NULL;
    $reason = is_string($removalReason) ? trim($removalReason) : '';
    if ($reason !== '') {
      $reversalLabel = ($reversalLabel ? $reversalLabel . ' ' : '') . '- REVERSED: ' . $reason;
    }

    $reversal = [
      'contribution_id' => $contributionID,
      'entity_table' => $orig['entity_table'] ?? 'civicrm_contribution',
      // For a contribution line entity_id is the contribution; for a
      // membership/participant line we keep the original entity_id so the
      // reversal stays associated with the same entity. (Membership/participant
      // STATUS changes are the refund step's responsibility, not modify's.)
      'entity_id' => ($orig['entity_table'] ?? 'civicrm_contribution') === 'civicrm_contribution'
        ? $contributionID
        : ($orig['entity_id'] ?? $contributionID),
      'price_field_id' => $orig['price_field_id'] ?? NULL,
      'price_field_value_id' => $orig['price_field_value_id'] ?? NULL,
      'financial_type_id' => $orig['financial_type_id'] ?? NULL,
      'label' => $reversalLabel,
      'qty' => $orig['qty'] ?? 1,
      // Negate the unit_price; line_total falls out as qty * unit_price (negative).
      'unit_price' => -1 * (float) ($orig['unit_price'] ?? 0),
      'line_total' => -1 * (float) ($orig['line_total'] ?? 0),
      // Pass the EXACT negated stored tax so the reversal cancels precisely.
      // The create hook recomputes tax when financial_type_id + line_total are
      // present; passing tax_amount here is the value we WANT, but note the hook
      // currently overwrites tax_amount via getTaxAmountForLineItem. See below.
      'tax_amount' => -1 * (float) ($orig['tax_amount'] ?? 0),
      'isReversal' => TRUE,
    ];

    $result = civicrm_api4('OrderLineItem', 'create', [
      'checkPermissions' => FALSE,
      'values' => $reversal,
    ]);

    // Provenance seam: hand a listener the exact original->reversal pairing at
    // the one moment it is known for certain. afform_order records nothing
    // itself; a consumer can persist this (e.g. an Activity, a custom field, or
    // eventually an explicit reverses-line column). See OrderLineReversedEvent
    // and the README known-limitation on reversal provenance.
    $reversalLineID = (int) ($result->first()['id'] ?? 0);
    if ($reversalLineID) {
      $reversedEvent = new OrderLineReversedEvent(
        $contributionID,
        $lineItemID,
        $reversalLineID,
        -1 * (float) ($orig['line_total'] ?? 0)
      );
      \Civi::dispatcher()->dispatch(OrderLineReversedEvent::EVENT_NAME, $reversedEvent);
    }
  }

  /**
   * Set the contribution STATUS and book the AR adjustment FinancialTrxn for a
   * paid restructure — BEFORE the changed line items are created — and return
   * the booked AR trxn id (or NULL when none was booked).
   *
   * This is the first half of what used to be applyAdjustedBalance, split out so
   * it runs in core's order (changeFeeSelections: _recordAdjustedAmt THEN
   * addFinancialItemsOnLineItemsChange). Running it first means the new lines'
   * FinancialItems are created against the ADJUSTED contribution status (e.g.
   * Partially paid, not Paid) and allocated to the returned AR trxn rather than
   * the original payment. The final total/tax are persisted afterwards by
   * persistContributionTotals once the stored lines are in place.
   *
   * Faithfully mirrors core's CRM_Price_BAO_LineItem::_recordAdjustedAmt status
   * logic, on the ABSOLUTE balance (updatedAmount - paidAmount):
   *   - balanceAmt != 0 and paidAmount == 0  -> leave status (nothing paid yet)
   *   - balanceAmt > 0                        -> Partially paid (more now owed)
   *   - balanceAmt < 0                        -> Pending refund (owed back)
   *   - balanceAmt == 0                       -> Completed (fully settled)
   * Status is written via DIRECT DAO save (the API/BAO path rejects transitions
   * like Completed -> Partially paid; core writes the DAO directly here too).
   *
   * The AR trxn records only THIS call's INCREMENTAL change (updatedAmount -
   * priorTotal), NOT the full balance — our one deliberate divergence from core
   * (which books the full balance because changeFeeSelections runs once for an
   * entire new selection, whereas OrderAO.modify is called per-edit; booking the
   * full balance each call would stack overlapping AR trxns). The trxn's own
   * status_id is Completed (a completed accounting event), per core. No money
   * has moved — a later Record Payment / Record Refund settles the balance.
   *
   * @param int $contributionID
   * @param float $updatedAmount Projected total (incl. tax) after this call.
   * @param float $priorTotal Contribution total BEFORE this call's restructure.
   *
   * @return int|null The AR adjustment FinancialTrxn id, or NULL if none booked.
   * @throws \CRM_Core_Exception
   */
  private function beginPaidAdjustment(int $contributionID, float $updatedAmount, float $priorTotal): ?int {
    // includeRefund = TRUE so paidAmount is the NET cash received: core's
    // getTotalPayments counts only Completed payments by default and EXCLUDES
    // refund trxns (status 'Refunded', is_payment=1, negative). On a
    // previously-refunded contribution that overstates what was paid and would
    // mis-set the status here (reading an overpayment that the refund already
    // returned). Netting refunds makes balanceAmt the true outstanding balance.
    $paidAmount = (float) \CRM_Core_BAO_FinancialTrxn::getTotalPayments($contributionID, TRUE);
    $balanceAmt = $updatedAmount - $paidAmount;
    // The change THIS call introduced - the amount to book on the AR account.
    $incrementAmt = $updatedAmount - $priorTotal;

    $statuses = \CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $partiallyPaidStatusId = array_search('Partially paid', $statuses);
    $pendingRefundStatusId = array_search('Pending refund', $statuses);
    $completedStatusId = array_search('Completed', $statuses);

    $existing = Contribution::get(FALSE)
      ->addSelect('financial_type_id', 'payment_instrument_id', 'currency')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first();

    // Determine + write the status (only). Total/tax are persisted later, after
    // the lines are created (persistContributionTotals).
    $newStatusId = NULL;
    if (abs($balanceAmt) >= 0.005) {
      // Status only changes when something has actually been paid.
      if ($paidAmount != 0.0) {
        $newStatusId = $balanceAmt > 0 ? $partiallyPaidStatusId : $pendingRefundStatusId;
      }
    }
    else {
      // Balance is zero - the change fully settles the contribution (CRM-17151).
      $newStatusId = $completedStatusId;
    }
    if ($newStatusId) {
      $dao = new \CRM_Contribute_DAO_Contribution();
      $dao->id = $contributionID;
      $dao->contribution_status_id = $newStatusId;
      $dao->save();
    }

    // Book the AR adjustment trxn for the increment (guarded on the increment,
    // not the balance: a call that doesn't change the total books nothing).
    if (abs($incrementAmt) >= 0.005) {
      $arAccount = \CRM_Contribute_PseudoConstant::getRelationalFinancialAccount(
        $existing['financial_type_id'],
        'Accounts Receivable Account is'
      );
      $trxn = \CRM_Core_BAO_FinancialTrxn::create([
        'from_financial_account_id' => NULL,
        'to_financial_account_id' => $arAccount,
        'total_amount' => $incrementAmt,
        'net_amount' => $incrementAmt,
        'status_id' => $completedStatusId,
        'payment_instrument_id' => $existing['payment_instrument_id'],
        'contribution_id' => $contributionID,
        'trxn_date' => date('YmdHis'),
        'currency' => $existing['currency'],
      ]);
      return (int) $trxn->id;
    }
    return NULL;
  }

  /**
   * Persist the contribution total/net/tax (NOT status) after a paid
   * restructure — the second half of the old applyAdjustedBalance. Status was
   * already set by beginPaidAdjustment; this writes only the amounts, summed
   * from the stored lines. Direct DAO save (mirrors core: net = total,
   * fee_amount = 0).
   *
   * @param int $contributionID
   * @param float $totalAmount Total (incl. tax) of the stored lines after the restructure.
   * @param float $taxAmount Total tax of the stored lines.
   *
   * @return void
   */
  private function persistContributionTotals(int $contributionID, float $totalAmount, float $taxAmount): void {
    $dao = new \CRM_Contribute_DAO_Contribution();
    $dao->id = $contributionID;
    $dao->total_amount = $dao->net_amount = $totalAmount;
    $dao->fee_amount = 0;
    $dao->tax_amount = $taxAmount;
    $dao->save();
  }

  /**
   * Best-effort: ask the recurring contribution's payment processor to amend
   * the live subscription to the new amount, after a template edit.
   *
   * Mirrors what core's CRM_Contribute_Form_UpdateSubscription::postProcess
   * does: gate on $processor->supports('changeSubscriptionAmount') (the
   * capability contract every processor answers), then call
   * changeSubscriptionAmount($message, $params) with the same param shape the
   * core form passes (amount + the recur id under both 'id' and
   * 'contributionRecurID', the processor's subscription reference under both
   * 'subscriptionId' and 'recurProcessorID' - processors read different keys).
   *
   * NEVER throws. The local template + recur changes are already committed and
   * hold regardless of the processor outcome (the deliberate best-effort
   * model: a processor that can't amend automatically just means staff adjust
   * it at the processor manually). Every outcome - no processor, unsupported,
   * declined, exploded - is reduced to a reportable
   * ['notified' => bool, 'message' => string] for the result row.
   *
   * If a second consumer ever needs policy control here (suppress auto-push,
   * log an activity, per-processor handling), this block is the extraction
   * seam: dispatch an event carrying (recurID, newAmount) and move this body
   * to a subscriber.
   *
   * @param int $recurID
   * @param float $newAmount The new series amount (the template total, incl. tax).
   *
   * @return array{notified: bool, message: string}
   */
  private function notifyProcessorOfAmountChange(int $recurID, float $newAmount): array {
    try {
      $recur = ContributionRecur::get(FALSE)
        ->addSelect('payment_processor_id', 'processor_id', 'currency')
        ->addWhere('id', '=', $recurID)
        ->execute()
        ->first();
      if (empty($recur['payment_processor_id'])) {
        return [
          'notified' => FALSE,
          'message' => ts('No payment processor is linked to this recurring contribution; no automatic update was attempted.'),
        ];
      }

      $processor = \Civi\Payment\System::singleton()->getById((int) $recur['payment_processor_id']);
      if (!$processor->supports('changeSubscriptionAmount')) {
        return [
          'notified' => FALSE,
          'message' => ts('This payment processor cannot amend the subscription amount automatically. The change is saved in CiviCRM; adjust the subscription at the processor manually.'),
        ];
      }

      $message = '';
      $outcome = $processor->changeSubscriptionAmount($message, [
        'amount' => $newAmount,
        'currency' => $recur['currency'],
        'id' => $recurID,
        'contributionRecurID' => $recurID,
        'subscriptionId' => $recur['processor_id'],
        'recurProcessorID' => $recur['processor_id'],
      ]);
      // Legacy processors signal failure by returning a CRM_Core_Error (or
      // FALSE) instead of throwing - core's form still checks for it, so we do.
      if ($outcome instanceof \CRM_Core_Error || !$outcome) {
        return [
          'notified' => FALSE,
          'message' => ts('The payment processor could not update the subscription amount. The change is saved in CiviCRM; adjust the subscription at the processor manually.'),
        ];
      }
      return [
        'notified' => TRUE,
        'message' => trim((string) $message) !== ''
          ? (string) $message
          : ts('The subscription amount was updated at the payment processor.'),
      ];
    }
    catch (\Throwable $e) {
      // Vendor processor code; any failure mode is possible. Reduce it to a
      // reportable outcome rather than failing a modify whose local changes
      // are already committed.
      return [
        'notified' => FALSE,
        'message' => ts('Updating the subscription at the payment processor failed: %1 The change is saved in CiviCRM; adjust the subscription at the processor manually.', [1 => $e->getMessage()]),
      ];
    }
  }

}
