<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Dto;

use Drupal\tap_payment\Enum\PaymentState;

/**
 * A Tap charge, reduced to the facts this module acts on.
 *
 * A charge response carries card fingerprints, tokens, saved-card agreements
 * and the payer's phone number. None of that is needed to decide whether an
 * order is paid, so none of it survives the mapping: what cannot be held
 * cannot be leaked into a log, a cache or a database column.
 *
 * The two reference fields are kept for one specific reason — they are inputs
 * to the webhook signature, so being able to recompute a signature from a
 * stored payment is what makes verification testable.
 *
 * @api
 *   Public and stable since 1.0.0.
 *
 * @see https://developers.tap.company/reference/charges
 */
final class Payment {

  /**
   * Constructs a Payment.
   *
   * @param string $chargeId
   *   The `chg_…` identifier.
   * @param \Drupal\tap_payment\Enum\PaymentState $state
   *   The charge state.
   * @param \Drupal\tap_payment\Dto\Money $money
   *   The amount and currency Tap reports, which is authoritative.
   * @param bool $liveMode
   *   FALSE when the charge was created with a test key.
   * @param string|null $hostedPaymentUrl
   *   Tap's `transaction.url`; present while the payer still has to act.
   * @param string|null $responseCode
   *   The documented charge response code, e.g. `000`.
   * @param string|null $responseMessage
   *   Tap's own wording for that code.
   * @param string|null $gatewayReference
   *   The acquirer reference `reference.gateway`, a webhook signature input.
   * @param string|null $paymentReference
   *   Tap's own `reference.payment`, a webhook signature input.
   * @param string|null $createdTimestamp
   *   The `transaction.created` value in milliseconds, exactly as sent — also
   *   a signature input, so it is kept as a string rather than re-rendered.
   * @param string|null $customerId
   *   The `cus_…` identifier Tap assigns, when it sent one.
   * @param string|null $orderReference
   *   The caller's own order reference, echoed back.
   * @param string|null $transactionReference
   *   The caller's own transaction reference, echoed back.
   */
  public function __construct(
    public readonly string $chargeId,
    public readonly PaymentState $state,
    public readonly Money $money,
    public readonly bool $liveMode,
    public readonly ?string $hostedPaymentUrl = NULL,
    public readonly ?string $responseCode = NULL,
    public readonly ?string $responseMessage = NULL,
    public readonly ?string $gatewayReference = NULL,
    public readonly ?string $paymentReference = NULL,
    public readonly ?string $createdTimestamp = NULL,
    public readonly ?string $customerId = NULL,
    public readonly ?string $orderReference = NULL,
    public readonly ?string $transactionReference = NULL,
  ) {}

  /**
   * Whether the payer still has to be sent to Tap.
   *
   * @return bool
   *   TRUE when a hosted payment page is waiting.
   */
  public function needsRedirect(): bool {
    return $this->hostedPaymentUrl !== NULL && $this->state->isPending();
  }

}
