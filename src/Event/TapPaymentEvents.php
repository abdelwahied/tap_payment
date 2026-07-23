<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Event;

/**
 * The names of every event this module dispatches.
 *
 * These are the module's extension points. Fulfilling an order, sending a
 * receipt, writing to an external ledger — none of that belongs in a payment
 * gateway, and none of it requires touching this module: subscribe to
 * `PAYMENT_CAPTURED` and do it there.
 *
 * Subscribers run inside the request that discovered the outcome, which is
 * usually Tap's webhook call. Anything slow belongs on a queue, not in a
 * subscriber — Tap gives a webhook two retries and then gives up.
 *
 * @api
 *   Public and stable since 1.0.0. The constant *values* are the contract.
 */
final class TapPaymentEvents {

  /**
   * A charge has been created at Tap and the payer is about to be sent there.
   *
   * @Event
   *
   * @var string
   */
  public const PAYMENT_CREATED = 'tap_payment.payment_created';

  /**
   * The money has been taken. This is the only success signal.
   *
   * @Event
   *
   * @var string
   */
  public const PAYMENT_CAPTURED = 'tap_payment.payment_captured';

  /**
   * The payment will not complete: failed, declined, restricted or timed out.
   *
   * @Event
   *
   * @var string
   */
  public const PAYMENT_FAILED = 'tap_payment.payment_failed';

  /**
   * The payer walked away — cancelled, abandoned, or the charge was voided.
   *
   * @Event
   *
   * @var string
   */
  public const PAYMENT_CANCELLED = 'tap_payment.payment_cancelled';

  /**
   * A webhook arrived, before anything about it has been trusted.
   *
   * Useful for monitoring how many calls arrive and how many survive
   * verification. Never act on this one: at this point the payload is
   * unauthenticated input from the open internet.
   *
   * @Event
   *
   * @var string
   */
  public const WEBHOOK_RECEIVED = 'tap_payment.webhook_received';

  /**
   * A webhook's signature has been checked and matches.
   *
   * @Event
   *
   * @var string
   */
  public const WEBHOOK_VERIFIED = 'tap_payment.webhook_verified';

}
