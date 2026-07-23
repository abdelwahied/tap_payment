<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Api\Adapter;

use Drupal\tap_payment\Dto\Payment;
use Drupal\tap_payment\Dto\PaymentRequest;

/**
 * Everything that knows the shape of one Tap API version.
 *
 * This is the seam the module is built around. Paths, field names, the order
 * of the webhook signature pre-image — all of it is version-specific detail,
 * and all of it lives behind this interface. Nothing above it names a Tap
 * field.
 *
 * The point is the migration that has not happened yet: if Tap publishes a v3
 * with a different charge body, supporting it is one new class implementing
 * this interface plus one tagged service. `TapPaymentInterface`, the events,
 * the entity and every integration built on them keep working untouched. An
 * adapter is also the only honest place to put a change like that, because a
 * version difference is exactly a difference in how requests are shaped and
 * responses are read — not in what a payment means.
 *
 * @api
 *   Public and stable since 1.0.0. Implement it and tag the service
 *   `tap_payment_api_adapter` to add support for another API version.
 */
interface TapApiAdapterInterface {

  /**
   * The API version this adapter speaks, e.g. `v2`.
   *
   * @return string
   *   The version identifier, matched against the configured version.
   */
  public function version(): string;

  /**
   * The path a charge is created at, relative to the API base.
   *
   * @return string
   *   The path, e.g. `charges`.
   */
  public function chargePath(): string;

  /**
   * The path one charge is read from, relative to the API base.
   *
   * @param string $chargeId
   *   The charge identifier.
   *
   * @return string
   *   The path, e.g. `charges/chg_123`.
   */
  public function retrieveChargePath(string $chargeId): string;

  /**
   * Builds the charge request body.
   *
   * @param \Drupal\tap_payment\Dto\PaymentRequest $request
   *   What the caller asked for.
   * @param string $redirectUrl
   *   The module's own return route, which Tap sends the payer back to.
   * @param string $webhookUrl
   *   The module's own webhook route, which Tap posts the outcome to.
   * @param string $idempotencyKey
   *   The key that makes a repeated submission return the original charge.
   *
   * @return array<string, mixed>
   *   The body to send.
   */
  public function buildChargeRequest(PaymentRequest $request, string $redirectUrl, string $webhookUrl, string $idempotencyKey): array;

  /**
   * Extra headers a charge request needs, such as the page language.
   *
   * @param \Drupal\tap_payment\Dto\PaymentRequest $request
   *   What the caller asked for.
   *
   * @return array<string, string>
   *   Headers to merge into the request.
   */
  public function chargeRequestHeaders(PaymentRequest $request): array;

  /**
   * Reads a charge response into the module's own payment object.
   *
   * @param array<string, mixed> $data
   *   A decoded charge body, from an API call or from a webhook.
   *
   * @return \Drupal\tap_payment\Dto\Payment
   *   The mapped payment.
   *
   * @throws \Drupal\tap_payment\Exception\ApiException
   *   When a field the module depends on is missing or undocumented.
   */
  public function mapCharge(array $data): Payment;

  /**
   * Builds the exact string a webhook signature is computed over.
   *
   * @param array<string, mixed> $data
   *   The decoded webhook body.
   *
   * @return string
   *   The pre-image, ready to be hashed with the secret key.
   *
   * @throws \Drupal\tap_payment\Exception\WebhookVerificationException
   *   When the body is missing a field the signature is built from.
   */
  public function signaturePayload(array $data): string;

}
