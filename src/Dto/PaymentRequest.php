<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Dto;

use Drupal\tap_payment\Exception\InvalidPaymentRequestException;

/**
 * Everything a caller has to decide to start a payment.
 *
 * Deliberately absent: the redirect URL and the webhook URL Tap is told about.
 * Those belong to this module, not to the caller — the module hands Tap its own
 * routes so that the return trip is always re-verified against the API and the
 * webhook always lands on a signature-checking endpoint. A caller that could
 * choose them could also skip both checks. What a caller *does* choose is where
 * the payer ends up afterwards: `$returnUrl` and `$cancelUrl`.
 *
 * The length limits enforced here are Tap's own, taken from its documented
 * 11xx error codes, so an over-long description fails locally with a clear
 * message instead of costing a round trip to be told `1121`.
 *
 * @api
 *   Public and stable since 1.0.0.
 *
 * @see https://developers.tap.company/reference/create-a-charge
 * @see https://developers.tap.company/reference/charge-response-codes
 */
final class PaymentRequest {

  /**
   * The hosted page showing every method enabled on the Tap account.
   */
  public const SOURCE_ALL = 'src_all';

  /**
   * The hosted page showing card methods only.
   */
  public const SOURCE_CARD = 'src_card';

  /**
   * Constructs a PaymentRequest.
   *
   * @param \Drupal\tap_payment\Dto\Money $money
   *   How much to collect, and in what currency.
   * @param \Drupal\tap_payment\Dto\Customer $customer
   *   Who is paying.
   * @param string $returnUrl
   *   Where to send the payer once the module has verified the outcome.
   * @param string|null $cancelUrl
   *   Where to send a payer who did not complete; defaults to $returnUrl.
   * @param string $sourceId
   *   The Tap payment source, e.g. `src_all` or a specific `src_kw.knet`.
   * @param string|null $description
   *   A description shown on the Tap side, at most 1000 characters.
   * @param string|null $idempotencyKey
   *   The caller's own key for this payment; Tap honours it for 24 hours. Left
   *   NULL, the module generates one and stores it, which is enough to make a
   *   double-clicked pay button harmless.
   * @param string|null $transactionReference
   *   The caller's transaction reference, at most 100 characters.
   * @param string|null $orderReference
   *   The caller's order reference, at most 100 characters.
   * @param array<string, string> $metadata
   *   Key/value pairs echoed back on every Tap response.
   * @param string|null $languageCode
   *   `en` or `ar`; selects the language of Tap's hosted page.
   * @param bool $threeDSecure
   *   Whether to request 3-D Secure. Tap enforces it for customer-initiated
   *   transactions regardless, so this only ever adds protection.
   * @param string|null $contextModule
   *   The module owning this payment, recorded so an integration can find its
   *   own transactions later.
   * @param string|null $contextId
   *   The owning module's identifier for whatever is being paid for.
   *
   * @throws \Drupal\tap_payment\Exception\InvalidPaymentRequestException
   *   When a value exceeds a limit Tap documents, or a URL is empty.
   */
  public function __construct(
    public readonly Money $money,
    public readonly Customer $customer,
    public readonly string $returnUrl,
    public readonly ?string $cancelUrl = NULL,
    public readonly string $sourceId = self::SOURCE_ALL,
    public readonly ?string $description = NULL,
    public readonly ?string $idempotencyKey = NULL,
    public readonly ?string $transactionReference = NULL,
    public readonly ?string $orderReference = NULL,
    public readonly array $metadata = [],
    public readonly ?string $languageCode = NULL,
    public readonly bool $threeDSecure = TRUE,
    public readonly ?string $contextModule = NULL,
    public readonly ?string $contextId = NULL,
  ) {
    if (trim($returnUrl) === '') {
      throw new InvalidPaymentRequestException('A return URL is required so the payer has somewhere to come back to.');
    }

    if (trim($sourceId) === '') {
      throw new InvalidPaymentRequestException('A Tap payment source id is required.');
    }

    if ($description !== NULL && mb_strlen($description) > 1000) {
      throw new InvalidPaymentRequestException('The description must be 1000 characters or fewer.');
    }

    if ($transactionReference !== NULL && mb_strlen($transactionReference) > 100) {
      throw new InvalidPaymentRequestException('The transaction reference must be 100 characters or fewer.');
    }

    if ($orderReference !== NULL && mb_strlen($orderReference) > 100) {
      throw new InvalidPaymentRequestException('The order reference must be 100 characters or fewer.');
    }

    if ($languageCode !== NULL && !in_array($languageCode, ['en', 'ar'], TRUE)) {
      throw new InvalidPaymentRequestException('Tap renders its hosted page in "en" or "ar" only.');
    }

    foreach ($metadata as $key => $value) {
      if (!is_string($key) || !is_string($value)) {
        throw new InvalidPaymentRequestException('Payment metadata must be a map of strings to strings.');
      }

      if (mb_strlen($key) > 250) {
        throw new InvalidPaymentRequestException('A metadata key must be 250 characters or fewer.');
      }

      if (mb_strlen($value) > 1000) {
        throw new InvalidPaymentRequestException('A metadata value must be 1000 characters or fewer.');
      }
    }
  }

  /**
   * Where a payer who did not complete should be sent.
   *
   * @return string
   *   The cancel URL, falling back to the return URL.
   */
  public function cancelUrl(): string {
    return $this->cancelUrl ?? $this->returnUrl;
  }

}
