<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Kernel;

use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Event\TapPaymentEvents;
use Drupal\tap_payment\Exception\InvalidPaymentRequestException;
use Drupal\tap_payment\Service\TapPaymentService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests starting a payment: the ledger row, the request, and repeat attempts.
  *
  * @covers \Drupal\tap_payment\Service\TapPaymentService
  *
  * @runTestsInSeparateProcesses
 */
#[CoversClass(TapPaymentService::class)]
#[RunTestsInSeparateProcesses]
final class PaymentCreationTest extends TapPaymentKernelTestBase {

  /**
   * A payment records itself, sends the module's own URLs, and redirects.
   */
  public function testCreatingOnePayment(): void {
    $this->queueResponse($this->fixture('charge_initiated_response'));

    $session = $this->container->get('tap_payment.payment')->createPayment($this->paymentRequest());

    $this->assertTrue($session->needsRedirect());
    $this->assertSame(
      'https://checkout.payments.tap.company?mode=page&token=6318405da53ea40ebd4da0c0',
      $session->redirectUrl(),
    );

    $transaction = $session->transaction;
    $this->assertSame('chg_TS012520220955Rr950709475', $transaction->getChargeId());
    $this->assertSame(PaymentState::Initiated, $transaction->getState());
    $this->assertSame('1.000', $transaction->getMoney()->amount);
    $this->assertSame('KWD', $transaction->getMoney()->currency);
    $this->assertSame('tap', $transaction->getGatewayId());
    $this->assertSame('tap_payment_test', $transaction->getContextModule());
    $this->assertSame('order-1', $transaction->getContextId());
    $this->assertFalse($transaction->isPaid());
    $this->assertFalse($transaction->isLiveMode());

    $request = $this->sentRequests()[0];
    $this->assertSame('POST', $request['method']);
    $this->assertSame('charges', $request['path']);

    // The redirect and webhook URLs are the module's own routes, not the
    // caller's: that is what guarantees the outcome is re-verified.
    $this->assertStringContainsString('/tap-payment/return/' . $transaction->uuid(), $request['body']['redirect']['url']);
    $this->assertStringContainsString('/tap-payment/webhook', $request['body']['post']['url']);
    $this->assertNotSame('/thank-you', $request['body']['redirect']['url']);

    $this->assertContains(TapPaymentEvents::PAYMENT_CREATED, $this->recordedEvents());
  }

  /**
   * No payer detail beyond Tap's own customer id is kept on the ledger.
   */
  public function testNoPayerDetailIsStored(): void {
    $this->queueResponse($this->fixture('charge_initiated_response'));
    $session = $this->container->get('tap_payment.payment')->createPayment($this->paymentRequest());

    $stored = $session->transaction->toArray();
    $encoded = (string) json_encode($stored);

    $this->assertStringNotContainsString('ada@example.com', $encoded);
    $this->assertStringNotContainsString('Ada', $encoded);
    $this->assertArrayNotHasKey('card', $stored);
  }

  /**
   * The same idempotency key never creates a second ledger row.
   */
  public function testRepeatedSubmissionReusesTheSamePayment(): void {
    $payments = $this->container->get('tap_payment.payment');
    $request = $this->paymentRequest(['idempotencyKey' => 'order-1']);

    $this->queueResponse($this->fixture('charge_initiated_response'));
    $first = $payments->createPayment($request);

    // Tap returns the original charge for a repeated idempotency key; the
    // module has to recognise it as the same payment, not a new one.
    $this->queueResponse($this->fixture('charge_initiated_response'));
    $second = $payments->createPayment($request);

    $this->assertSame($first->transaction->id(), $second->transaction->id());
    $this->assertSame('order-1', $second->transaction->getIdempotencyKey());
    $this->assertCount(1, $this->transactions());

    // Announced once, not twice: a resumed payment was not created again.
    $created = array_filter(
      $this->recordedEvents(),
      static fn (string $event): bool => $event === TapPaymentEvents::PAYMENT_CREATED,
    );
    $this->assertCount(1, $created);

    // Both charge requests carried the key, which is what makes Tap return the
    // original charge rather than opening a second one.
    foreach ($this->sentRequests() as $sent) {
      $this->assertSame('order-1', $sent['body']['reference']['idempotent']);
    }
  }

  /**
   * A finished payment is never re-sent to Tap.
   */
  public function testFinishedPaymentIsNotReopened(): void {
    $payments = $this->container->get('tap_payment.payment');
    $request = $this->paymentRequest(['idempotencyKey' => 'order-2']);

    $this->queueResponse($this->fixture('charge_initiated_response'));
    $session = $payments->createPayment($request);

    $session->transaction->setState(PaymentState::Captured)->save();

    $resumed = $payments->createPayment($request);

    $this->assertFalse($resumed->needsRedirect());
    $this->assertTrue($resumed->transaction->isPaid());
    // One charge request in total: the second call answered from the ledger.
    $this->assertCount(1, $this->sentRequests());
  }

  /**
   * A key the module generates is unique per payment.
   */
  public function testGeneratedKeysAreUnique(): void {
    $payments = $this->container->get('tap_payment.payment');

    // Two distinct payments; Tap returns a distinct charge for each.
    $this->queueResponse($this->chargeWithId('chg_first'));
    $first = $payments->createPayment($this->paymentRequest());

    $this->queueResponse($this->chargeWithId('chg_second'));
    $second = $payments->createPayment($this->paymentRequest());

    $this->assertNotSame($first->transaction->getIdempotencyKey(), $second->transaction->getIdempotencyKey());
    $this->assertNotSame($first->transaction->getChargeId(), $second->transaction->getChargeId());
    $this->assertCount(2, $this->transactions());
  }

  /**
   * The same Tap charge is never recorded on two ledger rows.
   *
   * The second, independent layer of duplicate protection: if two payments
   * with different idempotency keys somehow resolved to the same Tap charge —
   * which a bug, not the normal flow, could cause — it stays on one row and the
   * second call defers to it rather than duplicating it.
   */
  public function testSameChargeIsNeverRecordedTwice(): void {
    $payments = $this->container->get('tap_payment.payment');

    $this->queueResponse($this->chargeWithId('chg_shared'));
    $first = $payments->createPayment($this->paymentRequest(['idempotencyKey' => 'key-a']));

    // A second payment, different key, but Tap answers with the same charge id.
    $this->queueResponse($this->chargeWithId('chg_shared'));
    $second = $payments->createPayment($this->paymentRequest(['idempotencyKey' => 'key-b']));

    // Both sessions point at the one row that holds the charge.
    $this->assertSame('chg_shared', $first->transaction->getChargeId());
    $this->assertSame($first->transaction->id(), $second->transaction->id());

    // Exactly one row carries the charge; PAYMENT_CREATED fired once.
    $this->assertCount(1, $this->container->get('entity_type.manager')
      ->getStorage('tap_payment_transaction')
      ->loadByProperties(['charge_id' => 'chg_shared']));

    $created = array_filter(
      $this->recordedEvents(),
      static fn (string $event): bool => $event === TapPaymentEvents::PAYMENT_CREATED,
    );
    $this->assertCount(1, $created);
  }

  /**
   * A destination off this site is refused before anything is sent.
    *
    * @dataProvider externalUrlProvider
   */
  #[DataProvider('externalUrlProvider')]
  public function testExternalDestinationsAreRefused(array $overrides): void {
    $this->expectException(InvalidPaymentRequestException::class);
    $this->expectExceptionMessage('must point to this site');

    $this->container->get('tap_payment.payment')->createPayment($this->paymentRequest($overrides));
  }

  /**
   * Destinations that would turn the checkout into an open redirect.
   *
   * @return array<string, array{array<string, mixed>}>
   *   Constructor overrides that must be refused.
   */
  public static function externalUrlProvider(): array {
    return [
      'external return url' => [['returnUrl' => 'https://evil.example/thanks']],
      'external cancel url' => [['cancelUrl' => 'https://evil.example/cancelled']],
      'protocol relative return url' => [['returnUrl' => '//evil.example/thanks']],
    ];
  }

  /**
   * A currency with three decimals is sent with three, not two.
   */
  public function testAmountsFollowTheCurrency(): void {
    $this->queueResponse($this->fixture('charge_initiated_response'));

    $this->container->get('tap_payment.payment')->createPayment(
      $this->paymentRequest(['money' => new Money('2.5', 'KWD')]),
    );

    $this->assertSame(2.5, $this->sentRequests()[0]['body']['amount']);
    $this->assertSame('KWD', $this->sentRequests()[0]['body']['currency']);
  }

  /**
   * A caller can find its own payments again.
   */
  public function testPaymentsAreFindableByContext(): void {
    $payments = $this->container->get('tap_payment.payment');
    $this->queueResponse($this->fixture('charge_initiated_response'));
    $session = $payments->createPayment($this->paymentRequest());

    $found = $payments->loadByContext('tap_payment_test', 'order-1');
    $this->assertCount(1, $found);
    $this->assertSame($session->transaction->id(), $found[0]->id());

    $this->assertNotNull($payments->loadByChargeId('chg_TS012520220955Rr950709475'));
    $this->assertNull($payments->loadByChargeId('chg_does_not_exist'));
    $this->assertNull($payments->loadByChargeId(''));
  }

  /**
   * The documented charge response, retargeted at a given charge id.
   *
   * @param string $chargeId
   *   The charge id the answer should carry.
   *
   * @return array<string, mixed>
   *   The response body.
   */
  private function chargeWithId(string $chargeId): array {
    $charge = $this->fixture('charge_initiated_response');
    $charge['id'] = $chargeId;

    return $charge;
  }

  /**
   * Every transaction on the ledger.
   *
   * @return array<int, \Drupal\tap_payment\Entity\TapTransactionInterface>
   *   The stored transactions.
   */
  private function transactions(): array {
    return $this->container->get('entity_type.manager')
      ->getStorage('tap_payment_transaction')
      ->loadMultiple();
  }

}
