<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Kernel;

use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Event\TapPaymentEvents;
use Drupal\tap_payment\Plugin\QueueWorker\PaymentReconciliationWorker;
use Drupal\tap_payment\Service\PaymentReconciler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests re-reading a payment from Tap: on return, and on cron.
  *
  * @covers \Drupal\tap_payment\Service\PaymentReconciler
  * @covers \Drupal\tap_payment\Plugin\QueueWorker\PaymentReconciliationWorker
  *
  * @runTestsInSeparateProcesses
 */
#[CoversClass(PaymentReconciler::class)]
#[CoversClass(PaymentReconciliationWorker::class)]
#[RunTestsInSeparateProcesses]
final class PaymentVerificationTest extends TapPaymentKernelTestBase {

  /**
   * Verifying re-reads the charge from Tap rather than trusting anything local.
   */
  public function testVerifyingAsksTapAndSettles(): void {
    $transaction = $this->startedPayment();
    $this->queueResponse($this->capturedCharge($transaction->getChargeId()));

    $verified = $this->container->get('tap_payment.payment')->verifyPayment($transaction);

    $this->assertSame(PaymentState::Captured, $verified->getState());
    $this->assertTrue($verified->isPaid());
    $this->assertContains(TapPaymentEvents::PAYMENT_CAPTURED, $this->recordedEvents());

    $read = $this->sentRequests()[1];
    $this->assertSame('GET', $read['method']);
    $this->assertSame('charges/' . $transaction->getChargeId(), $read['path']);
  }

  /**
   * A payment with no charge at Tap is left alone rather than queried.
   */
  public function testUnstartedPaymentIsNotQueried(): void {
    $transaction = $this->container->get('entity_type.manager')
      ->getStorage('tap_payment_transaction')
      ->create([
        'idempotency_key' => 'never-started',
        'state' => PaymentState::Initiated->value,
        'amount' => '1.000',
        'currency' => 'KWD',
        'gateway' => 'tap',
        'return_url' => '/thank-you',
      ]);
    $transaction->save();

    $this->container->get('tap_payment.payment')->verifyPayment($transaction);

    $this->assertSame([], $this->sentRequests());
  }

  /**
   * Verifying a payment a webhook already settled changes nothing.
   */
  public function testVerifyingAfterWebhookChangesNothing(): void {
    $transaction = $this->startedPayment();
    $transaction->setState(PaymentState::Captured)->save();

    $this->queueResponse($this->capturedCharge($transaction->getChargeId()));
    $verified = $this->container->get('tap_payment.payment')->verifyPayment($transaction);

    $this->assertTrue($verified->isPaid());
    $this->assertNotContains(TapPaymentEvents::PAYMENT_CAPTURED, $this->recordedEvents());
  }

  /**
   * Cron queues a payment that has been open longer than Tap's own expiry.
   */
  public function testCronQueuesStalePayments(): void {
    $transaction = $this->startedPayment();
    $this->ageTransaction($transaction, 7200);

    $this->assertSame(1, $this->container->get('tap_payment.reconciler')->queueStale());
    $this->assertSame(1, $this->queue()->numberOfItems());
  }

  /**
   * A payment that is still fresh is left for the webhook to settle.
   */
  public function testFreshPaymentsAreNotChased(): void {
    $this->startedPayment();

    $this->assertSame(0, $this->container->get('tap_payment.reconciler')->queueStale());
  }

  /**
   * A settled payment is never chased again.
   */
  public function testSettledPaymentsAreNotChased(): void {
    $transaction = $this->startedPayment();
    $transaction->setState(PaymentState::Captured)->save();
    $this->ageTransaction($transaction, 7200);

    $this->assertSame(0, $this->container->get('tap_payment.reconciler')->queueStale());
  }

  /**
   * A payment older than the window is left to Tap's own records.
   */
  public function testAncientPaymentsAreNotChased(): void {
    $transaction = $this->startedPayment();
    $this->ageTransaction($transaction, 999 * 24 * 3600);

    $this->assertSame(0, $this->container->get('tap_payment.reconciler')->queueStale());
  }

  /**
   * The worker settles the payment the webhook never reported.
   *
   * This is the case Tap's documentation creates: three delivery attempts and
   * then the notification is abandoned. Without this, the money is taken and
   * the site never finds out.
   */
  public function testWorkerSettlesPaymentNoWebhookReported(): void {
    $transaction = $this->startedPayment();
    $this->ageTransaction($transaction, 7200);
    $this->container->get('tap_payment.reconciler')->queueStale();

    $this->queueResponse($this->capturedCharge($transaction->getChargeId()));

    $item = $this->queue()->claimItem();
    $this->worker()->processItem($item->data);
    $this->queue()->deleteItem($item);

    $storage = $this->container->get('entity_type.manager')->getStorage('tap_payment_transaction');
    $storage->resetCache([$transaction->id()]);

    $this->assertTrue($storage->load($transaction->id())->isPaid());
    $this->assertContains(TapPaymentEvents::PAYMENT_CAPTURED, $this->recordedEvents());
  }

  /**
   * A queue item for a payment that settled meanwhile costs no API call.
   */
  public function testWorkerSkipsPaymentsThatSettledMeanwhile(): void {
    $transaction = $this->startedPayment();
    $this->ageTransaction($transaction, 7200);
    $this->container->get('tap_payment.reconciler')->queueStale();

    $transaction->setState(PaymentState::Captured)->save();

    $item = $this->queue()->claimItem();
    $this->worker()->processItem($item->data);

    // Only the original charge creation; the worker asked Tap nothing.
    $this->assertCount(1, $this->sentRequests());
  }

  /**
   * A queue item naming a payment that no longer exists is simply dropped.
   */
  public function testWorkerToleratesDeletedPayment(): void {
    $this->worker()->processItem(['transaction_id' => 99999]);
    $this->worker()->processItem([]);

    $this->assertSame([], $this->sentRequests());
  }

  /**
   * Starts a payment and returns its ledger row.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The transaction.
   */
  private function startedPayment() {
    $this->queueResponse($this->fixture('charge_initiated_response'));

    return $this->container->get('tap_payment.payment')
      ->createPayment($this->paymentRequest())
      ->transaction;
  }

  /**
   * Makes a transaction look as though it was last touched long ago.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The transaction to age.
   * @param int $seconds
   *   How far back to move it.
   */
  private function ageTransaction($transaction, int $seconds): void {
    $this->container->get('database')->update('tap_payment_transaction')
      ->fields(['changed' => time() - $seconds])
      ->condition('id', $transaction->id())
      ->execute();
  }

  /**
   * The documented charge response, retargeted and marked captured.
   *
   * @param string|null $chargeId
   *   The charge the answer should be about.
   *
   * @return array<string, mixed>
   *   The response body.
   */
  private function capturedCharge(?string $chargeId): array {
    $charge = $this->fixture('charge_initiated_response');
    $charge['id'] = (string) $chargeId;
    $charge['status'] = 'CAPTURED';
    $charge['response'] = ['code' => '000', 'message' => 'Captured'];
    unset($charge['transaction']['url']);

    return $charge;
  }

  /**
   * The reconciliation queue.
   *
   * @return \Drupal\Core\Queue\QueueInterface
   *   The queue.
   */
  private function queue() {
    return $this->container->get('queue')->get(PaymentReconciler::QUEUE_NAME);
  }

  /**
   * The reconciliation queue worker.
   *
   * @return \Drupal\Core\Queue\QueueWorkerInterface
   *   The worker.
   */
  private function worker() {
    return $this->container->get('plugin.manager.queue_worker')
      ->createInstance('tap_payment_reconciliation');
  }

}
