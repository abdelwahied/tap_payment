<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Plugin\PaymentGateway;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\tap_payment\Dto\Payment;
use Drupal\tap_payment\Dto\PaymentRequest;

/**
 * What a payment gateway has to be able to do.
 *
 * Four operations, and no more. Everything the module does — starting a
 * payment, bringing a payer back, believing a webhook, reconciling a stuck
 * charge — is composed from these, which is what keeps the layer above
 * provider-agnostic.
 *
 * Note what is *not* here: no configuration form, no credentials, no HTTP.
 * A gateway is handed its collaborators by the container. That is what makes
 * one testable with a stubbed client and no network at all.
 *
 * @api
 *   Public and stable since 1.0.0. This is the module's main extension point.
 */
interface PaymentGatewayInterface extends PluginInspectionInterface {

  /**
   * The human-readable name of this gateway.
   *
   * @return string
   *   The label.
   */
  public function label(): string;

  /**
   * Creates a charge at the provider and returns what it said.
   *
   * The redirect and webhook URLs are supplied by the module, not the caller,
   * so that a payer always comes back through a route that re-verifies and a
   * notification always lands on a route that checks a signature.
   *
   * @param \Drupal\tap_payment\Dto\PaymentRequest $request
   *   What the caller asked for.
   * @param string $redirectUrl
   *   The module's own return route.
   * @param string $webhookUrl
   *   The module's own webhook route.
   * @param string $idempotencyKey
   *   The key that makes a repeated submission return the original charge.
   *
   * @return \Drupal\tap_payment\Dto\Payment
   *   The created charge, normally carrying a hosted payment URL.
   *
   * @throws \Drupal\tap_payment\Exception\TapPaymentException
   *   When the charge could not be created.
   */
  public function createCharge(PaymentRequest $request, string $redirectUrl, string $webhookUrl, string $idempotencyKey): Payment;

  /**
   * Reads a charge back from the provider.
   *
   * This is the module's only source of truth about an outcome. A browser
   * redirect never is: query parameters are attacker-controlled.
   *
   * @param string $chargeId
   *   The provider's charge identifier.
   *
   * @return \Drupal\tap_payment\Dto\Payment
   *   The charge as the provider currently sees it.
   *
   * @throws \Drupal\tap_payment\Exception\TapPaymentException
   *   When the charge could not be read.
   */
  public function retrieveCharge(string $chargeId): Payment;

  /**
   * Whether a webhook body really came from the provider.
   *
   * Implementations must compare in constant time and must not fall back to
   * "accept when no signature was sent".
   *
   * @param array<string, mixed> $payload
   *   The decoded webhook body.
   * @param string $signature
   *   The signature the provider sent in a header.
   *
   * @return bool
   *   TRUE when the signature matches.
   *
   * @throws \Drupal\tap_payment\Exception\WebhookVerificationException
   *   When the payload is missing a field the signature is built from.
   */
  public function verifyWebhookSignature(array $payload, string $signature): bool;

  /**
   * Reads a webhook body into a payment, once it has been verified.
   *
   * @param array<string, mixed> $payload
   *   The verified webhook body.
   *
   * @return \Drupal\tap_payment\Dto\Payment
   *   The mapped payment.
   *
   * @throws \Drupal\tap_payment\Exception\TapPaymentException
   *   When the body cannot be read.
   */
  public function mapWebhookPayload(array $payload): Payment;

}
