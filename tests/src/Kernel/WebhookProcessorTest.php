<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Kernel;

use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Event\TapPaymentEvents;
use Drupal\tap_payment\Exception\WebhookVerificationException;
use Drupal\tap_payment\Webhook\WebhookProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the webhook path: what it believes, and what it refuses.
  *
  * @covers \Drupal\tap_payment\Webhook\WebhookProcessor
  *
  * @runTestsInSeparateProcesses
 */
#[CoversClass(WebhookProcessor::class)]
#[RunTestsInSeparateProcesses]
final class WebhookProcessorTest extends TapPaymentKernelTestBase {

  /**
   * A correctly signed capture settles the payment and announces it.
   */
  public function testSignedCaptureSettlesThePayment(): void {
    $transaction = $this->startedPayment();
    $payload = $this->capturePayloadFor($transaction->getChargeId());

    $this->assertTrue($this->process($payload, $this->sign($payload)));

    $settled = $this->reload($transaction);
    $this->assertSame(PaymentState::Captured, $settled->getState());
    $this->assertTrue($settled->isPaid());
    $this->assertSame('000', $settled->getResponseCode());
    $this->assertSame('Captured', $settled->getResponseMessage());

    $events = $this->recordedEvents();
    $this->assertContains(TapPaymentEvents::WEBHOOK_RECEIVED, $events);
    $this->assertContains(TapPaymentEvents::WEBHOOK_VERIFIED, $events);
    $this->assertContains(TapPaymentEvents::PAYMENT_CAPTURED, $events);

    // Nothing was asked of Tap: a signed webhook is proof in itself, and a
    // round trip per notification would be a needless dependency.
    $this->assertCount(1, $this->sentRequests());
  }

  /**
   * A forged signature changes nothing and is not announced as verified.
   */
  public function testForgedSignatureIsRefused(): void {
    $transaction = $this->startedPayment();
    $payload = $this->capturePayloadFor($transaction->getChargeId());

    try {
      $this->process($payload, str_repeat('0', 64));
      $this->fail('A forged webhook must be refused.');
    }
    catch (WebhookVerificationException $e) {
      $this->assertStringContainsString('signature did not match', $e->getMessage());
    }

    $this->assertSame(PaymentState::Initiated, $this->reload($transaction)->getState());
    $this->assertNotContains(TapPaymentEvents::WEBHOOK_VERIFIED, $this->recordedEvents());
    $this->assertNotContains(TapPaymentEvents::PAYMENT_CAPTURED, $this->recordedEvents());
  }

  /**
   * A missing signature is refused; absence is never taken as permission.
   */
  public function testMissingSignatureIsRefused(): void {
    $transaction = $this->startedPayment();
    $payload = $this->capturePayloadFor($transaction->getChargeId());

    $this->expectException(WebhookVerificationException::class);
    $this->process($payload, '');
  }

  /**
   * Signing with the wrong key fails, which is what proves the key is used.
   */
  public function testSignatureIsKeyedWithTheAccountSecret(): void {
    $transaction = $this->startedPayment();
    $payload = $this->capturePayloadFor($transaction->getChargeId());
    $adapter = $this->container->get('tap_payment.adapter_registry')->active();
    $wrong = hash_hmac('sha256', $adapter->signaturePayload($payload), 'sk_test_someoneElsesKey');

    $this->expectException(WebhookVerificationException::class);
    $this->process($payload, $wrong);
  }

  /**
   * Tampering with the amount invalidates the signature.
   */
  public function testTamperedAmountIsRefused(): void {
    $transaction = $this->startedPayment();
    $payload = $this->capturePayloadFor($transaction->getChargeId());
    $signature = $this->sign($payload);

    $payload['amount'] = 999.0;

    $this->expectException(WebhookVerificationException::class);
    $this->process($payload, $signature);
  }

  /**
   * The same capture delivered twice settles the payment exactly once.
   *
   * This is the module's idempotency guarantee, end to end.
   */
  public function testRepeatDeliveryChangesNothing(): void {
    $transaction = $this->startedPayment();
    $payload = $this->capturePayloadFor($transaction->getChargeId());
    $signature = $this->sign($payload);

    $this->assertTrue($this->process($payload, $signature));
    $this->assertFalse($this->process($payload, $signature));
    $this->assertFalse($this->process($payload, $signature));

    $captured = array_filter(
      $this->recordedEvents(),
      static fn (string $event): bool => $event === TapPaymentEvents::PAYMENT_CAPTURED,
    );
    $this->assertCount(1, $captured);
    $this->assertSame(PaymentState::Captured, $this->reload($transaction)->getState());
  }

  /**
   * A late failure cannot undo a capture that already happened.
   */
  public function testStaleOutcomeCannotOverwriteFinalOne(): void {
    $transaction = $this->startedPayment();
    $captured = $this->capturePayloadFor($transaction->getChargeId());
    $this->process($captured, $this->sign($captured));

    $failed = $captured;
    $failed['status'] = 'FAILED';
    $failed['response'] = ['code' => '401', 'message' => 'Failed'];

    $this->assertFalse($this->process($failed, $this->sign($failed)));
    $this->assertTrue($this->reload($transaction)->isPaid());
  }

  /**
   * A signed webhook for another site's charge is ignored, not an error.
   *
   * One Tap account can serve several sites, so a charge this site did not
   * create is somebody else's business.
   */
  public function testUnknownChargeIsAcceptedButIgnored(): void {
    $payload = $this->capturePayloadFor('chg_belongs_to_someone_else');

    $this->assertFalse($this->process($payload, $this->sign($payload)));
    $this->assertContains(TapPaymentEvents::WEBHOOK_VERIFIED, $this->recordedEvents());
  }

  /**
   * A signed outcome whose amount is not the one requested is refused.
   *
   * A signature proves origin. It does not prove the payload is about a charge
   * this site raised for this amount.
   */
  public function testOutcomeForDifferentAmountIsRefused(): void {
    $transaction = $this->startedPayment();

    $payload = $this->capturePayloadFor($transaction->getChargeId());
    $payload['amount'] = 0.5;
    $payload['currency'] = 'KWD';

    $this->assertFalse($this->process($payload, $this->sign($payload)));
    $this->assertSame(PaymentState::Initiated, $this->reload($transaction)->getState());
  }

  /**
   * A body that is not a JSON object never reaches the verifier.
   */
  public function testMalformedBodyIsRefused(): void {
    $request = Request::create('/tap-payment/webhook', 'POST', [], [], [], [], 'not json');
    $request->headers->set(WebhookProcessor::SIGNATURE_HEADER, 'anything');

    $this->expectException(WebhookVerificationException::class);
    $this->expectExceptionMessage('not a JSON object');
    $this->container->get('tap_payment.webhook_processor')->process($request);
  }

  /**
   * A payload with no charge id is refused before any lookup.
   */
  public function testPayloadWithoutChargeIdIsRefused(): void {
    $payload = $this->capturePayloadFor('chg_x');
    unset($payload['id']);

    $this->expectException(WebhookVerificationException::class);
    $this->expectExceptionMessage('no charge id');
    $this->process($payload, 'anything');
  }

  /**
   * A charge dated implausibly far in the past is refused.
   */
  public function testStaleTimestampIsRefused(): void {
    $transaction = $this->startedPayment();
    $payload = $this->capturePayloadFor($transaction->getChargeId());
    $payload['transaction']['created'] = (string) ((time() - 5 * 365 * 24 * 3600) * 1000);

    $this->expectException(WebhookVerificationException::class);
    $this->expectExceptionMessage('outside the accepted window');
    $this->process($payload, $this->sign($payload));
  }

  /**
   * A charge dated in the future is refused.
   */
  public function testFutureTimestampIsRefused(): void {
    $transaction = $this->startedPayment();
    $payload = $this->capturePayloadFor($transaction->getChargeId());
    $payload['transaction']['created'] = (string) ((time() + 86400) * 1000);

    $this->expectException(WebhookVerificationException::class);
    $this->expectExceptionMessage('outside the accepted window');
    $this->process($payload, $this->sign($payload));
  }

  /**
   * A cancellation is announced as a cancellation, not as a failure.
   */
  public function testCancellationIsAnnouncedSeparately(): void {
    $transaction = $this->startedPayment();
    $payload = $this->capturePayloadFor($transaction->getChargeId());
    $payload['status'] = 'CANCELLED';
    $payload['response'] = ['code' => '302', 'message' => 'Cancelled'];

    $this->assertTrue($this->process($payload, $this->sign($payload)));

    $this->assertContains(TapPaymentEvents::PAYMENT_CANCELLED, $this->recordedEvents());
    $this->assertNotContains(TapPaymentEvents::PAYMENT_FAILED, $this->recordedEvents());
    $this->assertFalse($this->reload($transaction)->isPaid());
  }

  /**
   * Runs one webhook delivery through the processor.
   *
   * @param array<string, mixed> $payload
   *   The body.
   * @param string $signature
   *   The hashstring header value.
   *
   * @return bool
   *   Whether a transaction changed.
   */
  private function process(array $payload, string $signature): bool {
    $request = Request::create(
      '/tap-payment/webhook',
      'POST',
      [],
      [],
      [],
      [],
      (string) json_encode($payload),
    );
    $request->headers->set(WebhookProcessor::SIGNATURE_HEADER, $signature);

    return $this->container->get('tap_payment.webhook_processor')->process($request);
  }

  /**
   * Starts a payment and returns its ledger row.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The transaction, in the initiated state.
   */
  private function startedPayment() {
    $this->queueResponse($this->fixture('charge_initiated_response'));

    return $this->container->get('tap_payment.payment')
      ->createPayment($this->paymentRequest())
      ->transaction;
  }

  /**
   * The documented capture webhook, retargeted at one charge and amount.
   *
   * @param string|null $chargeId
   *   The charge the payload should be about.
   *
   * @return array<string, mixed>
   *   The payload.
   */
  private function capturePayloadFor(?string $chargeId): array {
    $payload = $this->fixture('charge_captured_webhook');
    $payload['id'] = (string) $chargeId;
    $payload['amount'] = 1.0;
    $payload['currency'] = 'KWD';
    $payload['transaction']['created'] = (string) (time() * 1000);

    return $payload;
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
