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

/**
 * Temporary BAO for an afform_order-private LineItem create surface, used
 * while the corresponding core PR (refactoring Order->save into a calculate /
 * save / postSave pipeline with a proper hook seam) is in review. Once core
 * lands the equivalent functionality the OrderLineItem API and this BAO can
 * be retired.
 *
 * The class extends core's CRM_Price_DAO_LineItem to share the schema, but
 * routes writes through our own writeRecord() so we can fire pre/post hooks
 * under the entity name "OrderLineItem" rather than "LineItem". That keeps
 * us from colliding with core's hooks on LineItem and lets afform_order add
 * its own pre/post logic in self_hook_civicrm_pre and self_hook_civicrm_post.
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */
class CRM_AfformOrder_BAO_OrderLineItem extends CRM_Price_DAO_LineItem implements Civi\Core\HookInterface {

  /**
   * Callback for hook_civicrm_pre().
   *
   * @param \Civi\Core\Event\PreEvent $event
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public static function self_hook_civicrm_pre(\Civi\Core\Event\PreEvent $event) {
    if (!in_array($event->action, ['create', 'edit'])) {
      return;
    }

    $record = $event->params;

    if (isset($event->id) && !isset($record['id'])) {
      $record['id'] = $event->id;
    }

    // unset entity table and entity id in $params
    // we never update the entity table and entity id during update mode
    if (empty($record['id'])) {
      // Default unit_price and qty if not set
      $record['unit_price'] ??= 0;
      $record['qty'] ??= 1;
      if (!isset($record['entity_table'], $record['entity_id'])) {
        if (!isset($record['contribution_id'])) {
          throw new CRM_Core_Exception('contribution_id is required for LineItem create');
        }
        $record['entity_table'] = 'civicrm_contribution';
        $record['entity_id'] = $record['contribution_id'];
      }
    }

    // If we have qty/unit_price we can calculate missing line_total
    if (!isset($record['line_total']) && isset($record['qty'], $record['unit_price'])) {
      $record['line_total'] = $record['qty'] * $record['unit_price'];
    }

    if (empty($record['id']) && !isset($record['line_total'])) {
      throw new CRM_Core_Exception('line_total is required for LineItem create');
    }

    // Set tax amount if applicable
    if (isset($record['financial_type_id'], $record['line_total'])) {
      $record['tax_amount'] = self::getTaxAmountForLineItem($record);
    }

    $event->params = $record;
  }

  /**
   * Callback for hook_civicrm_post().
   *
   * @param \Civi\Core\Event\PostEvent $event
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function self_hook_civicrm_post(\Civi\Core\Event\PostEvent $event): void {
    /** @var \CRM_Price_DAO_LineItem $lineItem */
    $lineItem = $event->object;
    $record = $event->params;

    // This check is just for the "legacyMembershipPaymentCreateIfNotExist".
    if ($lineItem->contribution_id) {
      $contributionIsTemplate = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('is_template')
        ->addWhere('id', '=', $lineItem->contribution_id)
        ->execute()
        ->first()['is_template'];
    }
    else {
      $contributionIsTemplate = FALSE;
    }

    if ($contributionIsTemplate) {
      // We don't create related records for LineItems when it is a template:
      //   - ParticipantPayment/MembershipPayment
      //   - FinancialItems etc.
      return;
    }

    if (empty($record['id']) && empty($record['skipFinancialItems'])) {
      // This is a new lineItem - create the financialItem records
      $contributionBAO = new CRM_Contribute_BAO_Contribution();
      $contributionBAO->id = $lineItem->contribution_id;
      if (!$contributionBAO->find(TRUE)) {
        throw new CRM_Core_Exception('contribution_id is required for LineItem create');
      }

      $trxnIDs = NULL;
      if (!empty($record['financial_trxn_id'])) {
        $trxnIDs = ['id' => $record['financial_trxn_id']];
      }
      elseif (isset($lineItem->contribution_id)) {
        $trxnIDs['id'] = CRM_Core_BAO_FinancialTrxn::getFinancialTrxnId($lineItem->contribution_id, 'ASC', TRUE)['financialTrxnId'];
      }

      CRM_Financial_BAO_FinancialItem::add($lineItem, $contributionBAO, FALSE, $trxnIDs);
      if (!empty($lineItem->tax_amount)) {
        CRM_Financial_BAO_FinancialItem::add($lineItem, $contributionBAO, TRUE, $trxnIDs);
      }
      // @todo Ok, so we've created the FinancialItems, but now the calling code might do it again..
    }
    else {
      // @todo We're updating a LineItem. What should we do with FinancialItems etc?
    }

    if ($lineItem->entity_table === 'civicrm_membership' && $lineItem->contribution_id && $lineItem->entity_id) {
      CRM_Member_BAO_MembershipPayment::legacyMembershipPaymentCreateIfNotExist($lineItem->entity_id, $lineItem->contribution_id, TRUE);
    }
    if ($lineItem->entity_table === 'civicrm_participant' && $lineItem->contribution_id && $lineItem->entity_id) {
      $participantPaymentParams = [
        'participant_id' => $lineItem->entity_id,
        'contribution_id' => $lineItem->contribution_id,
      ];
      if (!civicrm_api3('ParticipantPayment', 'getcount', $participantPaymentParams)) {
        civicrm_api3('ParticipantPayment', 'create', $participantPaymentParams);
      }
    }
  }

  /**
   * Get the tax rate for the given line item.
   *
   * @param array $params
   *
   * @return float
   */
  protected static function getTaxAmountForLineItem(array $params): float {
    $taxRates = CRM_Core_PseudoConstant::getTaxRates();
    $taxRate = $taxRates[$params['financial_type_id']] ?? 0;
    if (isset($params['line_total_inclusive'])) {
      // Pseudo-field line_total_inclusive takes precedent as it is only
      // set when we are calculating the rounding back from the inclusive total.
      // An alternative might be to return a passed-in tax_amount IF i
      $lineTotalExclusive = $params['line_total_inclusive'] / (1 + ($taxRate / 100));
      $taxAmount = round($params['line_total_inclusive'] - $lineTotalExclusive, 2);
      if ($taxAmount === $params['tax_amount']) {
        return $taxAmount;
      }
    }
    return ($taxRate / 100) * $params['line_total'];
  }

  /**
   * Create or update a record from supplied params.
   *
   * If 'id' is supplied, an existing record will be updated
   * Otherwise a new record will be created.
   *
   * @param array $record
   *
   * @return static
   * @throws \CRM_Core_Exception
   */
  public static function writeRecord(array $record): CRM_Core_DAO {
    // Todo: Support composite primary keys
    $idField = static::$_primaryKey[0];
    $op = empty($record[$idField]) ? 'create' : 'edit';
    $className = 'CRM_AfformOrder_BAO_OrderLineItem';
    if ($className === 'CRM_Core_DAO') {
      throw new CRM_Core_Exception('Function writeRecord must be called on a subclass of CRM_Core_DAO');
    }
    $entityName = 'OrderLineItem';

    // For legacy reasons, empty values would sometimes be passed around as the string 'null'.
    // The DAO treats 'null' the same as '', and an empty string makes a lot more sense!
    // For the sake of hooks, normalize these values.
    $record = array_map(function ($value) {
      return $value === 'null' ? '' : $value;
    }, $record);

    // \CRM_Utils_Hook::pre($op, $entityName, $record[$idField] ?? NULL, $record);
    $event = new \Civi\Core\Event\PreEvent($op, $entityName, $record[$idField], $record);
    self::self_hook_civicrm_pre($event);

    // Fill defaults after pre hook to accept any hook modifications
    self::setDefaultsFromCallback('LineItem', $record);
    $fields = static::getSupportedFields();
    $instance = new static();
    // Ensure fields exist before attempting to write to them
    $values = array_intersect_key($record, $fields);
    $instance->copyValues($values);
    if (empty($values[$idField]) && array_key_exists('name', $fields) && empty($values['name'])) {
      $instance->makeNameFromLabel();
    }
    $instance->save();

    if (!empty($record['custom']) && is_array($record['custom'])) {
      CRM_Core_BAO_CustomValueTable::store($record['custom'], static::getTableName(), $instance->$idField, $op);
    }

    \CRM_Utils_Hook::post($op, $entityName, $instance->$idField, $instance, $record);
    $event = new \Civi\Core\Event\PostEvent($op, $entityName, $instance->$idField, $instance, $record);
    self::self_hook_civicrm_post($event);

    return $instance;
  }

  /**
   * Set default values for fields based on callback functions
   *
   * @param string $entityName
   *   The entity name
   * @param array &$record
   *   The record array to set default values for
   * @return void
   */
  private static function setDefaultsFromCallback(string $entityName, array &$record): void {
    $entity = Civi::entity($entityName);
    $idField = $entity->getMeta('primary_key');
    // Only fill values for create operations
    if (!empty($record[$idField])) {
      return;
    }
    foreach ($entity->getFields() as $fieldName => $field) {
      if (!empty($field['default_fallback'])) {
        $field += ['default_callback' => [__CLASS__, 'getDefaultFallbackValues']];
      }
      // Check if value is empty using `strlen()` to avoid php quirk of '0' == false.
      if (!empty($field['default_callback']) && !strlen((string) ($record[$fieldName] ?? ''))) {
        $record[$fieldName] = \Civi\Core\Resolver::singleton()->call($field['default_callback'], [$record, $entityName, $fieldName, $field]);
      }
    }
  }

}
