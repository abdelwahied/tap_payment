<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\tap_payment\Traits\TapFixtureTrait;
use Drupal\tap_payment\Controller\ReturnController;
use Drupal\tap_payment\Controller\WebhookController;
use Drupal\tap_payment\Dto\Customer;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Service\TapPaymentSettings;
use Drupal\tap_payment\Webhook\WebhookProcessor;
use Drupal\tap_payment_test\StubApiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the two public endpoints over real HTTP.
 *
 * The unit and kernel tests prove the logic; these prove the routes are
 * reachable without a session, answer with the status codes Tap's retry
 * behaviour depends on, and cannot be talked into anything by a query string.
  *
  * @covers \Drupal\tap_payment\Controller\WebhookController
  * @covers \Drupal\tap_payment\Controller\ReturnController
  *
  * @runTestsInSeparateProcesses
 */
#[CoversClass(WebhookController::class)]
#[CoversClass(ReturnController::class)]
#[RunTestsInSeparateProcesses]
final class PaymentEndpointsTest extends BrowserTestBase {

  use TapFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['tap_payment', 'tap_payment_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The secret key the test signs with.
   */
  private const SECRET = 'sk_test_XKokBfNWv6FIYuTMg5sLPjhJ';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config(TapPaymentSettings::CONFIG_NAME)
      ->set('environment', 'sandbox')
      ->set('sandbox_secret_key', self::SECRET)
      ->save();
  }

  /**
   * A signed webhook is accepted anonymously and settles the payment.
   */
  public function testWebhookAcceptsSignedNotification(): void {
    $transaction = $this->startPayment();
    $payload = $this->capturePayload($transaction->getChargeId());

    $response = $this->postWebhook($payload, $this->sign($payload));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['status' => 'applied'], json_decode((string) $response->getBody(), TRUE));
    $this->assertTrue($this->reload($transaction)->isPaid());
  }

  /**
   * An unsigned or wrongly signed webhook is refused with 401.
   *
   * The status matters: Tap retries a 5xx, and telling a forger to come back
   * later is not the intention.
   */
  public function testWebhookRefusesForgery(): void {
    $transaction = $this->startPayment();
    $payload = $this->capturePayload($transaction->getChargeId());

    $forged = $this->postWebhook($payload, str_repeat('a', 64));
    $this->assertSame(401, $forged->getStatusCode());

    $unsigned = $this->postWebhook($payload, NULL);
    $this->assertSame(401, $unsigned->getStatusCode());

    $this->assertSame(PaymentState::Initiated, $this->reload($transaction)->getState());
  }

  /**
   * A repeat delivery is accepted with 200 but changes nothing.
   */
  public function testWebhookRepeatIsAcceptedAndIgnored(): void {
    $transaction = $this->startPayment();
    $payload = $this->capturePayload($transaction->getChargeId());
    $signature = $this->sign($payload);

    $this->assertSame('applied', $this->body($this->postWebhook($payload, $signature))['status']);
    $repeat = $this->postWebhook($payload, $signature);

    $this->assertSame(200, $repeat->getStatusCode());
    $this->assertSame('ignored', $this->body($repeat)['status']);
  }

  /**
   * The webhook route takes POST only.
   */
  public function testWebhookRejectsOtherMethods(): void {
    $this->drupalGet('/tap-payment/webhook');
    $this->assertSession()->statusCodeEquals(405);
  }

  /**
   * Returning from Tap re-reads the charge and lands on the site's own page.
   */
  public function testReturnVerifiesAndRedirects(): void {
    $transaction = $this->startPayment();

    $charge = $this->fixture('charge_initiated_response');
    $charge['id'] = $transaction->getChargeId();
    $charge['status'] = 'CAPTURED';
    $charge['response'] = ['code' => '000', 'message' => 'Captured'];
    $charge['amount'] = 1.0;
    $charge['currency'] = 'KWD';
    StubApiClient::queue($this->container->get('state'), $charge);

    $this->drupalGet('/tap-payment/return/' . $transaction->uuid());

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressMatches('#/user/login#');
    $this->assertTrue($this->reload($transaction)->isPaid());
  }

  /**
   * A `tap_id` in the query string decides nothing.
   *
   * The payer controls that value. If it were believed, anyone could mark
   * their own order paid by editing the URL they were redirected to.
   */
  public function testReturnIgnoresTheTapIdParameter(): void {
    $transaction = $this->startPayment();

    $charge = $this->fixture('charge_initiated_response');
    $charge['id'] = $transaction->getChargeId();
    $charge['status'] = 'DECLINED';
    $charge['response'] = ['code' => '501', 'message' => 'Declined'];
    $charge['amount'] = 1.0;
    $charge['currency'] = 'KWD';
    StubApiClient::queue($this->container->get('state'), $charge);

    // A payer claiming somebody else's successful charge id changes nothing:
    // the module reads the charge it recorded, not the one it was handed.
    $this->drupalGet('/tap-payment/return/' . $transaction->uuid(), [
      'query' => ['tap_id' => 'chg_someone_elses_captured_charge'],
    ]);

    $settled = $this->reload($transaction);
    $this->assertSame(PaymentState::Declined, $settled->getState());
    $this->assertFalse($settled->isPaid());
  }

  /**
   * An unknown or malformed transaction identifier is a 404.
   */
  public function testReturnRefusesUnknownTransactions(): void {
    $this->drupalGet('/tap-payment/return/2a1c6c4e-0d7f-4e5b-9b3e-000000000000');
    $this->assertSession()->statusCodeEquals(404);

    // Not a UUID at all: the route pattern rejects it before any lookup.
    $this->drupalGet('/tap-payment/return/1');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Starts a payment and returns its ledger row.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The transaction.
   */
  private function startPayment() {
    StubApiClient::queue($this->container->get('state'), $this->fixture('charge_initiated_response'));

    return $this->container->get('tap_payment.payment')->createPayment(new PaymentRequest(
      money: new Money('1.000', 'KWD'),
      customer: new Customer('Ada', 'ada@example.com'),
      returnUrl: '/user/login',
      contextModule: 'tap_payment_test',
      contextId: 'order-1',
    ))->transaction;
  }

  /**
   * Posts a webhook body to the module's endpoint.
   *
   * @param array<string, mixed> $payload
   *   The body.
   * @param string|null $signature
   *   The hashstring header, or NULL to send none.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  private function postWebhook(array $payload, ?string $signature) {
    $headers = ['Content-Type' => 'application/json'];

    if ($signature !== NULL) {
      $headers[WebhookProcessor::SIGNATURE_HEADER] = $signature;
    }

    return $this->getHttpClient()->post($this->buildUrl('/tap-payment/webhook'), [
      'headers' => $headers,
      'body' => json_encode($payload),
      'http_errors' => FALSE,
    ]);
  }

  /**
   * The decoded body of a response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   *
   * @return array<string, mixed>
   *   The decoded body.
   */
  private function body($response): array {
    return (array) json_decode((string) $response->getBody(), TRUE);
  }

  /**
   * The documented capture webhook, retargeted at one charge.
   *
   * @param string|null $chargeId
   *   The charge the payload should be about.
   *
   * @return array<string, mixed>
   *   The payload.
   */
  private function capturePayload(?string $chargeId): array {
    $payload = $this->fixture('charge_captured_webhook');
    $payload['id'] = (string) $chargeId;
    $payload['amount'] = 1.0;
    $payload['currency'] = 'KWD';
    $payload['transaction']['created'] = (string) (time() * 1000);

    return $payload;
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
  private function sign(array $payload): string {
    $adapter = $this->container->get('tap_payment.adapter_registry')->active();

    return hash_hmac('sha256', $adapter->signaturePayload($payload), self::SECRET);
  }

  /**
   * Reads a transaction back from storage.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The transaction to reload.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The stored version.
   */
  private function reload($transaction) {
    $storage = $this->container->get('entity_type.manager')->getStorage('tap_payment_transaction');
    $storage->resetCache([$transaction->id()]);

    return $storage->load($transaction->id());
  }

}
