<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Plugin\PaymentGateway;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tap_payment\Api\Adapter\AdapterRegistry;
use Drupal\tap_payment\Api\TapApiClientInterface;
use Drupal\tap_payment\Attribute\PaymentGateway;
use Drupal\tap_payment\Dto\Payment;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Service\TapPaymentSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tap Payments, over the documented hosted checkout.
 *
 * The plugin is the join between the transport and the version adapter, and it
 * owns exactly one secret: how a webhook signature is produced. Tap's scheme is
 * an HMAC-SHA256 over a fixed concatenation of charge fields, keyed with the
 * account's secret key — which means the same key that authorises charges also
 * authenticates notifications, and it must never appear anywhere but here and
 * the Authorization header.
 *
 * The comparison uses hash_equals. A plain `===` on a hash leaks, through
 * timing, how many leading characters an attacker got right, and a webhook
 * endpoint is a place where an attacker can take as many guesses as they like.
 *
 * @internal
 *   Reached through the plugin manager; type-hint PaymentGatewayInterface.
 *
 * @see https://developers.tap.company/docs/webhook
 */
#[PaymentGateway(
  id: 'tap',
  label: new TranslatableMarkup('Tap Payments'),
  description: new TranslatableMarkup("Tap's hosted checkout, confirmed by a signed webhook."),
)]
final class TapGateway extends PaymentGatewayBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a TapGateway.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\tap_payment\Api\TapApiClientInterface $client
   *   The transport.
   * @param \Drupal\tap_payment\Api\Adapter\AdapterRegistry $adapters
   *   Supplies the adapter for the API version in use.
   * @param \Drupal\tap_payment\Service\TapPaymentSettings $settings
   *   Supplies the secret key the signature is keyed with.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's log channel.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly TapApiClientInterface $client,
    private readonly AdapterRegistry $adapters,
    private readonly TapPaymentSettings $settings,
    private readonly LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('tap_payment.api_client'),
      $container->get('tap_payment.adapter_registry'),
      $container->get('tap_payment.settings'),
      $container->get('logger.channel.tap_payment'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function createCharge(PaymentRequest $request, string $redirectUrl, string $webhookUrl, string $idempotencyKey): Payment {
    $adapter = $this->adapters->active();

    $response = $this->client->post(
      $adapter->chargePath(),
      $adapter->buildChargeRequest($request, $redirectUrl, $webhookUrl, $idempotencyKey),
      $adapter->chargeRequestHeaders($request),
    );

    $payment = $adapter->mapCharge($response->data);

    $this->logger->info('Created Tap charge @charge in state @state.', [
      '@charge' => $payment->chargeId,
      '@state' => $payment->state->value,
    ]);

    return $payment;
  }

  /**
   * {@inheritdoc}
   */
  public function retrieveCharge(string $chargeId): Payment {
    $adapter = $this->adapters->active();
    $response = $this->client->get($adapter->retrieveChargePath($chargeId));

    return $adapter->mapCharge($response->data);
  }

  /**
   * {@inheritdoc}
   */
  public function verifyWebhookSignature(array $payload, string $signature): bool {
    if (trim($signature) === '') {
      return FALSE;
    }

    $expected = hash_hmac(
      'sha256',
      $this->adapters->active()->signaturePayload($payload),
      $this->settings->secretKey(),
    );

    return hash_equals($expected, trim($signature));
  }

  /**
   * {@inheritdoc}
   */
  public function mapWebhookPayload(array $payload): Payment {
    return $this->adapters->active()->mapCharge($payload);
  }

}
