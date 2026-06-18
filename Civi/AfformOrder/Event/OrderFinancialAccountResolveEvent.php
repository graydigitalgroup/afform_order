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

namespace Civi\AfformOrder\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired when afform_order resolves the financial account to use for a
 * FinancialItem it is about to create (both normal OrderLineItem creates and
 * paid-line reversals).
 *
 * afform_order's DEFAULT resolution is AP-first / Income-fallback: use the
 * "Accounts Payable Account is" account for the line's financial type if one is
 * configured, otherwise the "Income Account is" account. This is a safe generic
 * default - an install that only ever wants Income simply never configures AP on
 * a price field's financial type, so the fallback is all that fires; the AP
 * branch only activates when someone deliberately set AP up, which is exactly
 * when they want it used.
 *
 * This event lets others ADJUST that decision: afform_order computes the default
 * account, then dispatches this event so a listener can override it via
 * setFinancialAccountID(). Most installs (including any whose requirement IS
 * AP-first/Income-fallback) need no listener at all.
 */
class OrderFinancialAccountResolveEvent extends Event {

  public const EVENT_NAME = 'civi.afform_order.resolve_financial_account';

  /**
   * The financial type id of the line whose FinancialItem account is being
   * resolved.
   *
   * @var int
   */
  private int $financialTypeID;

  /**
   * The account afform_order resolved by default (AP-first/Income-fallback).
   * May be NULL if neither relationship is configured (a misconfiguration).
   * A listener may overwrite this via setFinancialAccountID().
   *
   * @var int|null
   */
  private ?int $financialAccountID;

  /**
   * Whether this resolution is for a reversal (paid-line back-out) rather than
   * a normal create. Lets a listener treat the two differently if it wants;
   * afform_order itself resolves both the same way.
   *
   * @var bool
   */
  private bool $isReversal;

  /**
   * @param int $financialTypeID
   * @param int|null $financialAccountID The default afform_order resolved.
   * @param bool $isReversal
   */
  public function __construct(int $financialTypeID, ?int $financialAccountID, bool $isReversal = FALSE) {
    $this->financialTypeID = $financialTypeID;
    $this->financialAccountID = $financialAccountID;
    $this->isReversal = $isReversal;
  }

  public function getFinancialTypeID(): int {
    return $this->financialTypeID;
  }

  public function getFinancialAccountID(): ?int {
    return $this->financialAccountID;
  }

  /**
   * Override the financial account to use.
   *
   * @param int|null $financialAccountID
   * @return void
   */
  public function setFinancialAccountID(?int $financialAccountID): void {
    $this->financialAccountID = $financialAccountID;
  }

  public function getIsReversal(): bool {
    return $this->isReversal;
  }

}
