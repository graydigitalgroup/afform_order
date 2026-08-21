<?php

namespace Civi\AfformOrder;

use Civi\AfformOrder\Event\OrderModifyValidateEvent;
use Civi\Api4\Contribution;
use Civi\Api4\OrderAO;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for OrderAO.modify dispatching its validate (veto) seam on
 * EVERY path - specifically the PENDING path, which previously had no validate
 * seam of its own (the seam was paid-only). See OrderAO\Modify step 2.
 *
 * @group headless
 */
class OrderModifyValidateSeamTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    // Install afform_order with its dependencies resolved in order (org.civicrm
    // .afform, civi_contribute). CRM_Extension_Manager::install() does not
    // resolve <requires> itself, so we resolve the ordered set explicitly.
    return \Civi\Test::headless()
      ->callback(function () {
        $mgr = \CRM_Extension_System::singleton()->getManager();
        $mgr->install($mgr->findInstallRequirements(['afform_order']));
      }, 'install-afform_order-with-deps')
      ->apply();
  }

  /**
   * A subscriber must now RECEIVE the validate event for a Pending modify, and a
   * no-metadata veto must make OrderAO.modify throw before any restructure. The
   * veto short-circuits before line validation, so no price-field fixture is
   * needed here.
   */
  public function testValidateSeamFiresAndVetoesOnPendingModify(): void {
    $contributionId = $this->createPendingContribution();

    $captured = ['fired' => FALSE, 'status' => NULL, 'contributionId' => NULL];
    $listener = function (OrderModifyValidateEvent $event) use (&$captured) {
      $captured['fired'] = TRUE;
      $captured['status'] = $event->getContributionStatusName();
      $captured['contributionId'] = $event->getContributionID();
      $event->addError('blocked by test');
    };
    \Civi::dispatcher()->addListener(OrderModifyValidateEvent::EVENT_NAME, $listener);

    $threw = FALSE;
    try {
      OrderAO::modify(FALSE)
        ->setContributionID($contributionId)
        ->setLineItemsToAdd([['line_total' => 5, 'qty' => 1, 'unit_price' => 5]])
        ->execute();
    }
    catch (\CRM_Core_Exception $e) {
      $threw = TRUE;
      $this->assertStringContainsString('blocked by test', $e->getMessage());
    }
    finally {
      \Civi::dispatcher()->removeListener(OrderModifyValidateEvent::EVENT_NAME, $listener);
    }

    $this->assertTrue($captured['fired'], 'Validate seam must fire on a Pending modify (previously paid-only).');
    $this->assertSame('Pending', $captured['status']);
    $this->assertSame($contributionId, $captured['contributionId']);
    $this->assertTrue($threw, 'A no-metadata veto must make OrderAO.modify throw.');

    // Nothing was written: no line item was added by the vetoed modify.
    $lineCount = (int) \Civi\Api4\LineItem::get(FALSE)
      ->selectRowCount()
      ->addWhere('contribution_id', '=', $contributionId)
      ->execute()
      ->countFetched();
    $this->assertSame(0, $lineCount, 'A vetoed modify must not write any line item.');
  }

  private function createPendingContribution(): int {
    $contactId = (int) \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Seam')
      ->addValue('last_name', 'Tester')
      ->execute()
      ->first()['id'];

    return (int) Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id.name', 'Donation')
      ->addValue('total_amount', 10)
      ->addValue('contribution_status_id:name', 'Pending')
      ->execute()
      ->first()['id'];
  }

}
