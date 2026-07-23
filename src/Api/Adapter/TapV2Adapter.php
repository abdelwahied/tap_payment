<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Api\Adapter;

use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\Payment;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Exception\ApiException;
use Drupal\tap_payment\Exception\WebhookVerificationException;
use Drupal\tap_payment\Utility\CurrencyDecimals;

/**
 * Speaks Tap's v2 API: the only version Tap currently documents.
 *
 * Every field name below appears in the official Charges reference or the
 * webhook page; nothing is inferred from a community example or a plugin. The
 * two places where that discipline matters most:
 *
 * - **The signature pre-image.** Its field order is fixed and its amount must
 *   be rendered to the currency's own decimal count — `3.000` for KWD, `2.00`
 *   for SAR. Tap states this explicitly, and getting it wrong rejects real
 *   webhooks, so the amount is taken from the payload and re-formatted here
 *   rather than used as decoded.
 * - **The status.** An unrecognised status raises rather than defaulting.
 *   Mapping something unknown onto `FAILED` would be a guess, and onto
 *   `CAPTURED` would be a catastrophe.
 *
 * @internal
 *   Reached through \Drupal\tap_payment\Api\Adapter\TapApiAdapterInterface.
 *
 * @see https://developers.tap.company/reference/create-a-charge
 * @see https://developers.tap.company/reference/charges
 * @see https://developers.tap.company/docs/webhook
 * @see https://developers.tap.company/docs/idempotency
 */
final class TapV2Adapter implements TapApiAdapterInterface {

  /**
   * Constructs a TapV2Adapter.
   *
   * @param \Drupal\tap_payment\Utility\CurrencyDecimals $currencyDecimals
   *   Decides how many decimals an amount is written with.
   */
  public function __construct(private readonly CurrencyDecimals $currencyDecimals) {}

  /**
   * {@inheritdoc}
   */
  public function version(): string {
    return 'v2';
  }

  /**
   * {@inheritdoc}
   */
  public function chargePath(): string {
    return 'charges';
  }

  /**
   * {@inheritdoc}
   */
  public function retrieveChargePath(string $chargeId): string {
    return 'charges/' . rawurlencode($chargeId);
  }

  /**
   * {@inheritdoc}
   */
  public function buildChargeRequest(PaymentRequest $request, string $redirectUrl, string $webhookUrl, string $idempotencyKey): array {
    $decimals = $this->currencyDecimals->forCurrency($request->money->currency);

    $body = [
      'amount' => $request->money->toNumber($decimals),
      'currency' => $request->money->currency,
      'customer_initiated' => TRUE,
      'threeDSecure' => $request->threeDSecure,
      // The module never stores or reuses a card, so it never asks Tap to keep
      // one. Sent explicitly rather than left to the documented default, so a
      // change to that default cannot start saving cards on this site's behalf.
      'save_card' => FALSE,
      'customer' => $this->buildCustomer($request),
      'source' => ['id' => $request->sourceId],
      'redirect' => ['url' => $redirectUrl],
      'post' => ['url' => $webhookUrl],
      'reference' => array_filter([
        'transaction' => $request->transactionReference,
        'order' => $request->orderReference,
        'idempotent' => $idempotencyKey,
      ], static fn ($value): bool => $value !== NULL && $value !== ''),
    ];

    if ($request->description !== NULL && $request->description !== '') {
      $body['description'] = $request->description;
    }

    if ($request->metadata !== []) {
      $body['metadata'] = $request->metadata;
    }

    return $body;
  }

  /**
   * {@inheritdoc}
   */
  public function chargeRequestHeaders(PaymentRequest $request): array {
    return $request->languageCode === NULL ? [] : ['lang_code' => $request->languageCode];
  }

  /**
   * {@inheritdoc}
   */
  public function mapCharge(array $data): Payment {
    $chargeId = $this->string($data, 'id');

    if ($chargeId === NULL) {
      throw new ApiException('The Tap charge response carried no charge id.');
    }

    $status = $this->string($data, 'status');
    $state = $status === NULL ? NULL : PaymentState::fromStatus($status);

    if ($state === NULL) {
      throw new ApiException(sprintf(
        'Tap reported an undocumented status for charge %s; refusing to guess what it means.',
        $chargeId,
      ));
    }

    $currency = $this->string($data, 'currency');
    $amount = $data['amount'] ?? NULL;

    if ($currency === NULL || !is_numeric($amount)) {
      throw new ApiException(sprintf('The Tap response for charge %s carried no usable amount.', $chargeId));
    }

    $reference = is_array($data['reference'] ?? NULL) ? $data['reference'] : [];
    $transaction = is_array($data['transaction'] ?? NULL) ? $data['transaction'] : [];
    $response = is_array($data['response'] ?? NULL) ? $data['response'] : [];
    $customer = is_array($data['customer'] ?? NULL) ? $data['customer'] : [];

    return new Payment(
      chargeId: $chargeId,
      state: $state,
      money: Money::fromNumeric(is_string($amount) ? $amount : (float) $amount, $currency),
      liveMode: (bool) ($data['live_mode'] ?? FALSE),
      hostedPaymentUrl: $this->string($transaction, 'url'),
      responseCode: $this->string($response, 'code'),
      responseMessage: $this->string($response, 'message'),
      gatewayReference: $this->string($reference, 'gateway'),
      paymentReference: $this->string($reference, 'payment'),
      createdTimestamp: $this->string($transaction, 'created'),
      customerId: $this->string($customer, 'id'),
      orderReference: $this->string($reference, 'order'),
      transactionReference: $this->string($reference, 'transaction'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function signaturePayload(array $data): string {
    $id = $this->string($data, 'id');
    $currency = $this->string($data, 'currency');
    $status = $this->string($data, 'status');
    $amount = $data['amount'] ?? NULL;

    $transaction = is_array($data['transaction'] ?? NULL) ? $data['transaction'] : [];
    $reference = is_array($data['reference'] ?? NULL) ? $data['reference'] : [];
    $created = $this->string($transaction, 'created');

    if ($id === NULL || $currency === NULL || $status === NULL || $created === NULL || !is_numeric($amount)) {
      throw new WebhookVerificationException('The webhook payload is missing a field the Tap signature is computed from.');
    }

    $money = Money::fromNumeric(is_string($amount) ? $amount : (float) $amount, $currency);

    return 'x_id' . $id
      . 'x_amount' . $money->format($this->currencyDecimals->forCurrency($currency))
      . 'x_currency' . $money->currency
      // Tap documents an absent gateway reference as an empty value, not as an
      // omitted segment: the separator still has to be there.
      . 'x_gateway_reference' . ($this->string($reference, 'gateway') ?? '')
      . 'x_payment_reference' . ($this->string($reference, 'payment') ?? '')
      . 'x_status' . $status
      . 'x_created' . $created;
  }

  /**
   * Builds the customer object Tap expects.
   *
   * When the caller already knows Tap's own customer id, that is all that is
   * sent: Tap documents the remaining fields as unnecessary in that case, and
   * sending a name that disagrees with the stored one is what error 1107 is.
   *
   * @param \Drupal\tap_payment\Dto\PaymentRequest $request
   *   What the caller asked for.
   *
   * @return array<string, mixed>
   *   The customer object.
   */
  private function buildCustomer(PaymentRequest $request): array {
    $customer = $request->customer;

    if ($customer->tapCustomerId !== NULL && $customer->tapCustomerId !== '') {
      return ['id' => $customer->tapCustomerId];
    }

    $built = [
      'first_name' => $customer->firstName,
      'email' => $customer->email,
    ];

    if ($customer->middleName !== NULL && $customer->middleName !== '') {
      $built['middle_name'] = $customer->middleName;
    }

    if ($customer->lastName !== NULL && $customer->lastName !== '') {
      $built['last_name'] = $customer->lastName;
    }

    if ($customer->hasPhone()) {
      $built['phone'] = [
        'country_code' => (int) $customer->phoneCountryCode,
        'number' => (int) $customer->phoneNumber,
      ];
    }

    return $built;
  }

  /**
   * Reads a value as a non-empty string, or NULL.
   *
   * Tap sends numbers as JSON numbers in some places and as strings in others
   * — `transaction.created` is quoted, `amount` is not — so every read that
   * feeds a signature goes through one conversion rather than each caller
   * casting differently.
   *
   * @param array<string, mixed> $data
   *   The array to read from.
   * @param string $key
   *   The key.
   *
   * @return string|null
   *   The trimmed value, or NULL when absent, empty or not scalar.
   */
  private function string(array $data, string $key): ?string {
    $value = $data[$key] ?? NULL;

    if (!is_scalar($value)) {
      return NULL;
    }

    $value = trim((string) $value);

    return $value === '' ? NULL : $value;
  }

}
