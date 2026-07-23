<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment_commerce\Kernel;

use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\tap_payment\Entity\TapTransactionInterface;
use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Event\PaymentCapturedEvent;
use Drupal\tap_payment\Event\TapPaymentEvents;
use Drupal\tap_payment_commerce\EventSubscriber\PaymentOutcomeSubscriber;
use Drupal\tap_payment_commerce\Service\CommercePaymentRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a captured Tap payment reaches its Commerce order exactly once.
 */
#[CoversClass(CommercePaymentRecorder::class)]
#[CoversClass(PaymentOutcomeSubscriber::class)]
#[RunTestsInSeparateProcesses]
final class CommercePaymentRecorderTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_payment', 'tap_payment', 'tap_payment_commerce'];

  /**
   * The order being paid.
   */
  private Order $order;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('tap_payment_transaction');
    $this->installConfig(['commerce_payment', 'tap_payment']);

    $gateway = PaymentGateway::create([
      'id' => 'tap',
      'label' => 'Tap',
      'plugin' => 'tap_offsite',
    ]);
    $gateway->save();

    $this->order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'mail' => 'ada@example.com',
      'store_id' => $this->store->id(),
      'payment_gateway' => $gateway->id(),
    ]);
    $this->order->save();
  }

  /**
   * A captured payment becomes exactly one Commerce payment.
   */
  public function testCaptureIsRecordedOnce(): void {
    $transaction = $this->transaction(PaymentState::Captured);
    $recorder = $this->container->get('tap_payment_commerce.recorder');

    $payment = $recorder->record($transaction);

    $this->assertNotNull($payment);
    $this->assertSame('completed', $payment->getState()->getId());
    $this->assertSame($transaction->getChargeId(), $payment->getRemoteId());
    $this->assertSame('10.500', $payment->getAmount()->getNumber());
    $this->assertSame('KWD', $payment->getAmount()->getCurrencyCode());
    $this->assertSame($this->order->id(), $payment->getOrderId());

    // The webhook and the customer's return both reach here, in either order
    // and possibly twice. One charge, one payment.
    $this->assertSame($payment->id(), $recorder->record($transaction)->id());
    $this->assertCount(1, $this->payments());
  }

  /**
   * The event alone is enough: nothing has to call the recorder by hand.
   *
   * This is what lets an order be marked paid by a webhook that arrives after
   * the customer has closed their browser.
   */
  public function testTheCaptureEventRecordsThePayment(): void {
    $transaction = $this->transaction(PaymentState::Captured);

    $this->container->get('event_dispatcher')->dispatch(
      new PaymentCapturedEvent($transaction),
      TapPaymentEvents::PAYMENT_CAPTURED,
    );

    $this->assertCount(1, $this->payments());
  }

  /**
   * A payment that was not captured leaves the order untouched.
   */
  public function testUncapturedPaymentsAreNotRecorded(): void {
    foreach ([PaymentState::Declined, PaymentState::Cancelled, PaymentState::Initiated] as $state) {
      $this->assertNull($this->container->get('tap_payment_commerce.recorder')->record($this->transaction($state)));
    }

    $this->assertSame([], $this->payments());
  }

  /**
   * A transaction another module started is none of this one's business.
   */
  public function testTransactionsFromOtherModulesAreIgnored(): void {
    $transaction = $this->transaction(PaymentState::Captured, 'tap_payment_custom');

    $this->assertNull($this->container->get('tap_payment_commerce.recorder')->record($transaction));
  }

  /**
   * A transaction naming an order that no longer exists is not an error.
   */
  public function testMissingOrderIsTolerated(): void {
    $transaction = $this->transaction(PaymentState::Captured);
    $transaction->set('context_id', '99999')->save();

    $this->assertNull($this->container->get('tap_payment_commerce.recorder')->record($transaction));
  }

  /**
   * Builds a Tap transaction for the order.
   *
   * @param \Drupal\tap_payment\Enum\PaymentState $state
   *   The state to record.
   * @param string $context
   *   The context module to attribute it to.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The saved transaction.
   */
  private function transaction(PaymentState $state, string $context = CommercePaymentRecorder::CONTEXT): TapTransactionInterface {
    $key = 'commerce-' . $this->order->id() . '-' . $state->value . '-' . $context;

    /** @var \Drupal\tap_payment\Entity\TapTransactionInterface $transaction */
    $transaction = $this->container->get('entity_type.manager')
      ->getStorage('tap_payment_transaction')
      ->create([
        'idempotency_key' => $key,
        // A charge id unique to this row: the ledger enforces one charge per
        // row, so each fixture transaction needs its own.
        'charge_id' => 'chg_' . substr(md5($key), 0, 16),
        'state' => $state->value,
        'amount' => '10.500',
        'currency' => 'KWD',
        'gateway' => 'tap',
        'context_module' => $context,
        'context_id' => (string) $this->order->id(),
        'return_url' => '/checkout',
      ]);
    $transaction->save();

    return $transaction;
  }

  /**
   * Every Commerce payment on the site.
   *
   * @return array<int, \Drupal\commerce_payment\Entity\PaymentInterface>
   *   The payments.
   */
  private function payments(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');
    $storage->resetCache();

    return $storage->loadMultiple();
  }

}
