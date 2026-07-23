<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Dto;

use Drupal\tap_payment\Entity\TapTransactionInterface;

/**
 * What a caller gets back after starting a payment.
 *
 * Two views of the same moment: the site's own record, which persists, and
 * Tap's answer, which does not. Callers need both — the record to correlate an
 * order with later, and the answer to know where to send the payer.
 *
 * The hosted URL is deliberately not stored on the entity. It expires (Tap
 * defaults to thirty minutes) and a stale one is worse than none: it sends a
 * customer to a dead page instead of starting a fresh attempt.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
final class PaymentSession {

  /**
   * Constructs a PaymentSession.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The site's record of the attempt.
   * @param \Drupal\tap_payment\Dto\Payment $payment
   *   Tap's answer to the charge request.
   */
  public function __construct(
    public readonly TapTransactionInterface $transaction,
    public readonly Payment $payment,
  ) {}

  /**
   * Where to send the payer, when Tap is still waiting for them.
   *
   * @return string|null
   *   The hosted payment URL, or NULL when there is nothing left to do —
   *   which happens when a resumed payment has already reached an outcome.
   */
  public function redirectUrl(): ?string {
    return $this->payment->needsRedirect() ? $this->payment->hostedPaymentUrl : NULL;
  }

  /**
   * Whether the payer still has to be sent to Tap.
   *
   * @return bool
   *   TRUE when a redirect is required.
   */
  public function needsRedirect(): bool {
    return $this->redirectUrl() !== NULL;
  }

}
