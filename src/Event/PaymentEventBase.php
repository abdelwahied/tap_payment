<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\tap_payment\Dto\Payment;
use Drupal\tap_payment\Entity\TapTransactionInterface;

/**
 * What every payment lifecycle event carries.
 *
 * The transaction is the site's own record and is already saved by the time
 * subscribers see it, so a subscriber that throws cannot roll back a fact that
 * Tap has already established. The payment DTO is Tap's version of the same
 * moment, present whenever the event was raised from a real Tap response.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
abstract class PaymentEventBase extends Event {

  /**
   * Constructs a payment event.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The site's record of the payment.
   * @param \Drupal\tap_payment\Dto\Payment|null $payment
   *   Tap's own view of the charge, when the event came from a response.
   */
  public function __construct(
    public readonly TapTransactionInterface $transaction,
    public readonly ?Payment $payment = NULL,
  ) {}

}
