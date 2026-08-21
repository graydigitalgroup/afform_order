<?php

namespace Civi\AfformOrder;

use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the cart → line-item transformation in
 * Submit::buildLineItems (+ applyMembershipOverrides) and recur-frequency
 * resolution. These are pure (array-in / array-out, no DB), so they run as fast
 * reflection-based unit tests — no headless install.
 *
 * Matrix: new membership (default Pending), renewal (existing link, no default
 * status), form-level vs per-line existing, per-line dates + status override,
 * num-terms, non-membership rows, $0 lines, NULL drops, and recur frequency
 * (combined + separate + defaults).
 */
class SubmitBuildLineItemsTest extends TestCase {

  private function build(array $cart, array $extra = []): array {
    $submit = (new \ReflectionClass(Submit::class))->newInstanceWithoutConstructor();
    $m = new \ReflectionMethod(Submit::class, 'buildLineItems');
    $m->setAccessible(TRUE);
    return $m->invoke($submit, $cart, $extra);
  }

  private function resolveRecur(array $extra): array {
    $submit = (new \ReflectionClass(Submit::class))->newInstanceWithoutConstructor();
    $m = new \ReflectionMethod(Submit::class, 'resolveRecurFrequency');
    $m->setAccessible(TRUE);
    return $m->invoke($submit, $extra);
  }

  public function testNewMembershipDefaultsToPending(): void {
    $lines = $this->build([[
      'entity_table' => 'civicrm_membership',
      'price_field_value_id' => 10, 'qty' => 1, 'unit_price' => 100,
    ]]);
    $this->assertArrayNotHasKey('entity_id', $lines[0], 'A new membership has no entity_id.');
    $this->assertSame('Pending', $lines[0]['entity_id.status_id:name']);
  }

  public function testRenewalLinksExistingMembershipAndSkipsDefaultStatus(): void {
    $lines = $this->build([[
      'entity_table' => 'civicrm_membership',
      'price_field_value_id' => 10, '_existing_membership_id' => 42,
    ]]);
    $this->assertSame(42, $lines[0]['entity_id']);
    $this->assertArrayNotHasKey('entity_id.status_id:name', $lines[0], 'A renewal is not defaulted to Pending.');
  }

  public function testFormLevelExistingMembershipApplies(): void {
    $lines = $this->build(
      [['entity_table' => 'civicrm_membership', 'price_field_value_id' => 10]],
      ['existing_membership_id' => 7]
    );
    $this->assertSame(7, $lines[0]['entity_id']);
  }

  public function testPerLineExistingOverridesFormLevel(): void {
    $lines = $this->build(
      [['entity_table' => 'civicrm_membership', 'price_field_value_id' => 10, '_existing_membership_id' => 42]],
      ['existing_membership_id' => 7]
    );
    $this->assertSame(42, $lines[0]['entity_id'], 'Per-line existing membership wins over the form-level one.');
  }

  public function testMembershipDateAndStatusOverrides(): void {
    $line = $this->build([[
      'entity_table' => 'civicrm_membership', 'price_field_value_id' => 10,
      '_join_date' => '2026-01-01', '_start_date' => '2026-02-01', '_end_date' => '2027-01-31',
      '_status_id' => 3,
    ]])[0];
    $this->assertSame('2026-01-01', $line['entity_id.join_date']);
    $this->assertSame('2026-02-01', $line['entity_id.start_date']);
    $this->assertSame('2027-01-31', $line['entity_id.end_date']);
    $this->assertSame(3, $line['entity_id.status_id']);
    $this->assertTrue($line['entity_id.is_override'], 'Supplying a status implies override.');
    $this->assertArrayNotHasKey('entity_id.status_id:name', $line, 'Explicit status skips the Pending default.');
  }

  public function testMembershipIsOverrideFlagAlone(): void {
    $line = $this->build([[
      'entity_table' => 'civicrm_membership', 'price_field_value_id' => 10,
      '_membership_is_override' => 1,
    ]])[0];
    $this->assertTrue($line['entity_id.is_override']);
  }

  public function testNumTermsPerUnitCarried(): void {
    $line = $this->build([[
      'entity_table' => 'civicrm_membership', 'price_field_value_id' => 10,
      '_num_terms_per_unit' => 2,
    ]])[0];
    $this->assertSame(2, $line['_num_terms_per_unit']);
  }

  public function testNonMembershipRowHasNoMembershipKeys(): void {
    $line = $this->build([[
      'entity_table' => 'civicrm_contribution',
      'price_field_value_id' => 10, 'qty' => 1, 'unit_price' => 50,
    ]])[0];
    $this->assertArrayNotHasKey('entity_id', $line);
    $this->assertArrayNotHasKey('entity_id.status_id:name', $line);
    $this->assertSame('civicrm_contribution', $line['entity_table']);
  }

  public function testZeroDollarLineIsKept(): void {
    $lines = $this->build([[
      'entity_table' => 'civicrm_contribution',
      'price_field_value_id' => 10, 'qty' => 1, 'unit_price' => 0, 'line_total' => 0,
    ]]);
    $this->assertCount(1, $lines, 'A $0 (companion) line survives the NULL filter.');
    $this->assertEquals(0, $lines[0]['line_total']);
  }

  public function testNullFieldsDropped(): void {
    $line = $this->build([[
      'entity_table' => 'civicrm_contribution',
      'price_field_value_id' => 10, 'qty' => 1, 'unit_price' => 50,
    ]])[0];
    $this->assertArrayNotHasKey('price_field_id', $line);
    $this->assertArrayNotHasKey('financial_type_id', $line);
    $this->assertArrayNotHasKey('label', $line);
  }

  public function testLineTotalDefaultsToQtyTimesUnit(): void {
    $line = $this->build([[
      'entity_table' => 'civicrm_contribution', 'price_field_value_id' => 10,
      'qty' => 3, 'unit_price' => 20,
    ]])[0];
    $this->assertEquals(60, $line['line_total']);
  }

  public function testRecurCombinedFrequency(): void {
    $this->assertSame(['month', 3], $this->resolveRecur(['recur_frequency' => '3-month']));
  }

  public function testRecurSeparateFrequency(): void {
    $this->assertSame(['year', 2], $this->resolveRecur([
      'recur_frequency_unit' => 'year', 'recur_frequency_interval' => 2,
    ]));
  }

  public function testRecurDefaults(): void {
    $this->assertSame(['month', 1], $this->resolveRecur([]));
  }

}
