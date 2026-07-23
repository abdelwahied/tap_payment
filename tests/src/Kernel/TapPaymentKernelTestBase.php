<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\tap_payment\Traits\TapFixtureTrait;
use Drupal\tap_payment\Dto\Customer;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Service\TapPaymentSettings;
use Drupal\tap_payment_test\EventRecorder;
use Drupal\tap_payment_test\StubApiClient;

/**
 * Shared setup for the kernel tests: real services, scripted transport.
 *
 * Only the HTTP client is substituted. Everything else — the gateway plugin,
 * the adapter, the state machine, the entity storage, the event dispatcher —
 * is the real thing, because the behaviour worth testing lives in how those
 * fit together, not in any one of them.
 */
abstract class TapPaymentKernelTestBase extends KernelTestBase {

  use TapFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['tap_payment', 'tap_payment_test', 'user', 'system'];

  /**
   * The secret key the tests sign and verify with.
   */
  protected const SECRET = 'sk_test_XKokBfNWv6FIYuTMg5sLPjhJ';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('tap_payment_transaction');
    $this->installConfig(['tap_payment']);

    $this->config(TapPaymentSettings::CONFIG_NAME)
      ->set('environment', 'sandbox')
      ->set('sandbox_secret_key', self::SECRET)
      ->save();

    StubApiClient::reset($this->container->get('state'));
    EventRecorder::reset($this->container->get('state'));
  }

  /**
   * Queues one scripted answer from Tap.
   *
   * @param array<string, mixed> $body
   *   The decoded body to answer with.
   * @param int $status
   *   The HTTP status to report.
   */
  protected function queueResponse(array $body, int $status = 200): void {
    StubApiClient::queue($this->container->get('state'), $body, $status);
  }

  /**
   * Everything the module sent to Tap.
   *
   * @return array<int, array<string, mixed>>
   *   The recorded requests.
   */
  protected function sentRequests(): array {
    return StubApiClient::requests($this->container->get('state'));
  }

  /**
   * The events other modules were told about, in order.
   *
   * @return array<int, string>
   *   The event names.
   */
  protected function recordedEvents(): array {
    return array_column(EventRecorder::events($this->container->get('state')), 'event');
  }

  /**
   * A representative payment request.
   *
   * @param array<string, mixed> $overrides
   *   Constructor arguments to replace.
   *
   * @return \Drupal\tap_payment\Dto\PaymentRequest
   *   The request.
   */
  protected function paymentRequest(array $overrides = []): PaymentRequest {
    return new PaymentRequest(...array_merge([
      'money' => new Money('1.000', 'KWD'),
      'customer' => new Customer('Ada', 'ada@example.com'),
      'returnUrl' => '/thank-you',
      'cancelUrl' => '/checkout',
      'contextModule' => 'tap_payment_test',
      'contextId' => 'order-1',
    ], $overrides));
  }

  /**
   * Signs a payload the way Tap would.
   *
   * @param array<string, mixed> $payload
   *   The webhook body.
   *
   * @return string
   *   The hashstring header value.
   */
  protected function sign(array $payload): string {
    $adapter = $this->container->get('tap_payment.adapter_registry')->active();

    return hash_hmac('sha256', $adapter->signaturePayload($payload), self::SECRET);
  }

}
