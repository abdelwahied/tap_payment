<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Tests\tap_payment\Traits\TapFixtureTrait;
use Drupal\tap_payment\Api\Adapter\TapV2Adapter;
use Drupal\tap_payment\Dto\Customer;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Exception\ApiException;
use Drupal\tap_payment\Exception\WebhookVerificationException;
use Drupal\tap_payment\Utility\CurrencyDecimals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the v2 adapter against Tap's own documented payloads.
  *
  * @covers \Drupal\tap_payment\Api\Adapter\TapV2Adapter
 */
#[CoversClass(TapV2Adapter::class)]
final class TapV2AdapterTest extends UnitTestCase {

  use TapFixtureTrait;

  /**
   * The adapter under test.
   */
  private TapV2Adapter $adapter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->adapter = new TapV2Adapter(new CurrencyDecimals([
      'KWD' => 3,
      'BHD' => 3,
      'SAR' => 2,
    ]));
  }

  /**
   * The endpoints match the documented paths.
   */
  public function testPaths(): void {
    $this->assertSame('v2', $this->adapter->version());
    $this->assertSame('charges', $this->adapter->chargePath());
    $this->assertSame('charges/chg_123', $this->adapter->retrieveChargePath('chg_123'));
  }

  /**
   * A charge id with a slash in it cannot escape the charges collection.
   */
  public function testRetrievePathEscapesTheIdentifier(): void {
    $this->assertSame(
      'charges/chg_123%2F..%2Frefunds',
      $this->adapter->retrieveChargePath('chg_123/../refunds'),
    );
  }

  /**
   * The request body carries every field Tap marks required, and no card.
   */
  public function testBuildChargeRequestSendsTheDocumentedFields(): void {
    $body = $this->adapter->buildChargeRequest(
      $this->request(),
      'https://example.com/tap-payment/return/abc',
      'https://example.com/tap-payment/webhook',
      'order-42',
    );

    $this->assertSame(10.5, $body['amount']);
    $this->assertSame('KWD', $body['currency']);
    $this->assertTrue($body['customer_initiated']);
    $this->assertTrue($body['threeDSecure']);
    $this->assertSame(['id' => 'src_all'], $body['source']);
    $this->assertSame(['url' => 'https://example.com/tap-payment/return/abc'], $body['redirect']);
    $this->assertSame(['url' => 'https://example.com/tap-payment/webhook'], $body['post']);
    $this->assertSame('order-42', $body['reference']['idempotent']);
    $this->assertSame('Ada', $body['customer']['first_name']);
    $this->assertSame('ada@example.com', $body['customer']['email']);
    $this->assertSame(['country_code' => 966, 'number' => 512345678], $body['customer']['phone']);

    // The module never asks Tap to keep a card, and never sends one.
    $this->assertFalse($body['save_card']);
    $this->assertArrayNotHasKey('card', $body['source']);
  }

  /**
   * An amount is rounded to the currency's own precision, not to two places.
   */
  public function testAmountUsesCurrencyPrecision(): void {
    $request = new PaymentRequest(
      money: new Money('3.4567', 'KWD'),
      customer: $this->customer(),
      returnUrl: '/done',
    );

    $body = $this->adapter->buildChargeRequest($request, '/r', '/w', 'k');

    $this->assertSame(3.457, $body['amount']);
  }

  /**
   * A known Tap customer id replaces the rest of the customer object.
   */
  public function testKnownCustomerIsSentByIdAlone(): void {
    $request = new PaymentRequest(
      money: new Money('1', 'KWD'),
      customer: new Customer(
        firstName: 'Ada',
        email: 'ada@example.com',
        tapCustomerId: 'cus_123',
      ),
      returnUrl: '/done',
    );

    $body = $this->adapter->buildChargeRequest($request, '/r', '/w', 'k');

    $this->assertSame(['id' => 'cus_123'], $body['customer']);
  }

  /**
   * The page language travels as a header, which is where Tap reads it.
   */
  public function testLanguageIsSentInHeader(): void {
    $this->assertSame([], $this->adapter->chargeRequestHeaders($this->request()));

    $arabic = new PaymentRequest(
      money: new Money('1', 'KWD'),
      customer: $this->customer(),
      returnUrl: '/done',
      languageCode: 'ar',
    );

    $this->assertSame(['lang_code' => 'ar'], $this->adapter->chargeRequestHeaders($arabic));
  }

  /**
   * The documented charge response maps onto the module's payment object.
   */
  public function testMapsTheDocumentedChargeResponse(): void {
    $payment = $this->adapter->mapCharge($this->fixture('charge_initiated_response'));

    $this->assertSame('chg_TS012520220955Rr950709475', $payment->chargeId);
    $this->assertSame(PaymentState::Initiated, $payment->state);
    $this->assertSame('KWD', $payment->money->currency);
    $this->assertSame('1.000', $payment->money->format(3));
    $this->assertFalse($payment->liveMode);
    $this->assertSame(
      'https://checkout.payments.tap.company?mode=page&token=6318405da53ea40ebd4da0c0',
      $payment->hostedPaymentUrl,
    );
    $this->assertSame('100', $payment->responseCode);
    $this->assertSame('Initiated', $payment->responseMessage);
    $this->assertTrue($payment->needsRedirect());
  }

  /**
   * The documented webhook body maps too, and reports capture.
   */
  public function testMapsTheDocumentedWebhookBody(): void {
    $payment = $this->adapter->mapCharge($this->fixture('charge_captured_webhook'));

    $this->assertSame(PaymentState::Captured, $payment->state);
    $this->assertTrue($payment->state->isSuccessful());
    $this->assertSame('4327230736106619650', $payment->paymentReference);
    $this->assertSame('mada_pg70983e7a-a686-40ba-83e2-c5e9f4074fe5', $payment->gatewayReference);
    $this->assertSame('1698392202943', $payment->createdTimestamp);
    $this->assertSame('cus_TS07A5420232136o2K52709053', $payment->customerId);
    $this->assertFalse($payment->needsRedirect());
  }

  /**
   * An undocumented status raises rather than being guessed at.
   */
  public function testUndocumentedStatusIsRefused(): void {
    $payload = $this->fixture('charge_initiated_response');
    $payload['status'] = 'SETTLED_LATER';

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('undocumented status');
    $this->adapter->mapCharge($payload);
  }

  /**
   * A response without a charge id is refused.
   */
  public function testMissingChargeIdIsRefused(): void {
    $payload = $this->fixture('charge_initiated_response');
    unset($payload['id']);

    $this->expectException(ApiException::class);
    $this->adapter->mapCharge($payload);
  }

  /**
   * The signature pre-image matches Tap's documented concatenation exactly.
   */
  public function testSignaturePayloadFollowsTheDocumentedOrder(): void {
    $payload = $this->fixture('charge_captured_webhook');

    $this->assertSame(
      'x_idchg_TS05A4120230736x9K22710693'
      . 'x_amount1.00'
      . 'x_currencySAR'
      . 'x_gateway_referencemada_pg70983e7a-a686-40ba-83e2-c5e9f4074fe5'
      . 'x_payment_reference4327230736106619650'
      . 'x_statusCAPTURED'
      . 'x_created1698392202943',
      $this->adapter->signaturePayload($payload),
    );
  }

  /**
   * The amount in the signature carries the currency's decimals.
   *
   * This is the detail Tap calls out and the one that silently rejects real
   * webhooks when it is wrong.
    *
    * @dataProvider currencyAmountProvider
   */
  #[DataProvider('currencyAmountProvider')]
  public function testSignatureAmountUsesCurrencyDecimals(string $currency, float $amount, string $expected): void {
    $payload = $this->fixture('charge_captured_webhook');
    $payload['currency'] = $currency;
    $payload['amount'] = $amount;

    $this->assertStringContainsString(
      'x_amount' . $expected . 'x_currency' . $currency,
      $this->adapter->signaturePayload($payload),
    );
  }

  /**
   * Amounts and the decimals their currency is written with.
   *
   * @return array<string, array{string, float, string}>
   *   Currency, amount, expected rendering.
   */
  public static function currencyAmountProvider(): array {
    return [
      'three-decimal Kuwaiti dinar' => ['KWD', 3.0, '3.000'],
      'three-decimal Bahraini dinar' => ['BHD', 2.5, '2.500'],
      'two-decimal Saudi riyal' => ['SAR', 2.0, '2.00'],
      'currency absent from the map falls back to two' => ['USD', 2.0, '2.00'],
    ];
  }

  /**
   * A missing gateway reference becomes an empty segment, not a missing one.
   */
  public function testAbsentGatewayReferenceKeepsItsSeparator(): void {
    $payload = $this->fixture('charge_captured_webhook');
    unset($payload['reference']['gateway']);

    $this->assertStringContainsString(
      'x_gateway_referencex_payment_reference',
      $this->adapter->signaturePayload($payload),
    );
  }

  /**
   * A payload missing a signature input cannot be verified, and says so.
   */
  public function testSignaturePayloadRefusesAnIncompleteBody(): void {
    $payload = $this->fixture('charge_captured_webhook');
    unset($payload['transaction']['created']);

    $this->expectException(WebhookVerificationException::class);
    $this->adapter->signaturePayload($payload);
  }

  /**
   * A representative payment request.
   *
   * @return \Drupal\tap_payment\Dto\PaymentRequest
   *   The request.
   */
  private function request(): PaymentRequest {
    return new PaymentRequest(
      money: new Money('10.5', 'KWD'),
      customer: $this->customer(),
      returnUrl: '/thank-you',
      description: 'One licence',
    );
  }

  /**
   * A representative customer.
   *
   * @return \Drupal\tap_payment\Dto\Customer
   *   The customer.
   */
  private function customer(): Customer {
    return new Customer(
      firstName: 'Ada',
      email: 'ada@example.com',
      lastName: 'Lovelace',
      phoneCountryCode: '966',
      phoneNumber: '512345678',
    );
  }

}
