<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Enum\PaymentState;

/**
 * The site's own record of one payment attempt.
 *
 * This is the module's ledger, and it is deliberately thin. It holds what is
 * needed to recognise a repeat submission, to re-read a charge from Tap, to
 * decide whether an order is paid, and to let an administrator audit any of
 * that. It holds nothing about the payer beyond the pseudonymous customer id
 * Tap issues — no name, no email, no phone, and of course no card. A payment
 * ledger that accumulates personal data becomes a liability the moment the
 * payments themselves stop being interesting.
 *
 * @api
 *   Public and stable since 1.0.0. Type-hint this, not the implementation.
 */
interface TapTransactionInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * The Tap charge identifier, once Tap has issued one.
   *
   * @return string|null
   *   The `chg_…` identifier, or NULL before the charge was created.
   */
  public function getChargeId(): ?string;

  /**
   * Records the Tap charge identifier.
   *
   * @param string $chargeId
   *   The `chg_…` identifier.
   *
   * @return static
   *   The entity, for chaining.
   */
  public function setChargeId(string $chargeId): static;

  /**
   * The key that makes a repeated submission return the original charge.
   *
   * @return string
   *   The idempotency key, unique across the ledger.
   */
  public function getIdempotencyKey(): string;

  /**
   * Where this payment currently stands.
   *
   * @return \Drupal\tap_payment\Enum\PaymentState
   *   The state.
   */
  public function getState(): PaymentState;

  /**
   * Moves the payment to a new state without checking the move is legal.
   *
   * Callers must go through the payment service, which asks the state machine
   * first; this setter exists for it and for tests.
   *
   * @param \Drupal\tap_payment\Enum\PaymentState $state
   *   The new state.
   *
   * @return static
   *   The entity, for chaining.
   *
   * @internal
   *   Bypasses the state machine.
   */
  public function setState(PaymentState $state): static;

  /**
   * What the site asked to collect.
   *
   * @return \Drupal\tap_payment\Dto\Money
   *   The amount and currency.
   */
  public function getMoney(): Money;

  /**
   * Whether the charge was created against live credentials.
   *
   * @return bool
   *   TRUE for a real payment.
   */
  public function isLiveMode(): bool;

  /**
   * The payment gateway plugin that owns this transaction.
   *
   * @return string
   *   The plugin id.
   */
  public function getGatewayId(): string;

  /**
   * The module that asked for this payment, when one identified itself.
   *
   * @return string|null
   *   The module name, or NULL.
   */
  public function getContextModule(): ?string;

  /**
   * The owning module's identifier for whatever is being paid for.
   *
   * @return string|null
   *   The context id, or NULL.
   */
  public function getContextId(): ?string;

  /**
   * Where the payer is sent once the outcome has been verified.
   *
   * @return string
   *   An internal URL.
   */
  public function getReturnUrl(): string;

  /**
   * Where a payer who did not complete is sent.
   *
   * @return string
   *   An internal URL.
   */
  public function getCancelUrl(): string;

  /**
   * Tap's own response code for the last outcome seen, e.g. `000`.
   *
   * @return string|null
   *   The code, or NULL before any outcome.
   */
  public function getResponseCode(): ?string;

  /**
   * Tap's wording for the last response code.
   *
   * @return string|null
   *   The message, or NULL before any outcome.
   */
  public function getResponseMessage(): ?string;

  /**
   * Whether the money has actually been taken.
   *
   * @return bool
   *   TRUE only for a captured charge.
   */
  public function isPaid(): bool;

  /**
   * When the transaction was created, as a Unix timestamp.
   *
   * @return int
   *   The creation time.
   */
  public function getCreatedTime(): int;

}
