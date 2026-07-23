<?php

declare(strict_types=1);

namespace Drupal\tap_payment_commerce\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\tap_payment\Entity\TapTransactionInterface;

/**
 * Writes a captured Tap payment onto the Commerce order it belongs to.
 *
 * Two decisions worth stating.
 *
 * **Only a capture creates a Commerce payment.** Commerce's default payment
 * workflow has no failed state, and a failed attempt is not a payment — it is
 * the absence of one. Recording failures as payment entities would leave every
 * abandoned checkout looking like a transaction on the order.
 *
 * **Recording is idempotent by lookup, not by luck.** Both the webhook and the
 * customer's return can reach this, in either order, and both can happen twice.
 * So the charge id is looked up first: one Tap charge yields at most one
 * Commerce payment, whatever route got here.
 *
 * @internal
 *   Injected as a service.
 */
final class CommercePaymentRecorder {

  /**
   * The context module name this recorder answers for.
   */
  public const CONTEXT = 'tap_payment_commerce';

  /**
   * Constructs a CommercePaymentRecorder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads orders and payments.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The Tap log channel, so payment problems stay in one place.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Records a captured payment against its order, once.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The settled Tap transaction.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   The Commerce payment, or NULL when there was nothing to record.
   */
  public function record(TapTransactionInterface $transaction): ?PaymentInterface {
    if ($transaction->getContextModule() !== self::CONTEXT || !$transaction->isPaid()) {
      return NULL;
    }

    $chargeId = $transaction->getChargeId();
    $order = $this->loadOrder($transaction->getContextId());

    if ($chargeId === NULL || $order === NULL) {
      return NULL;
    }

    $existing = $this->findPayment($chargeId);

    if ($existing !== NULL) {
      return $existing;
    }

    $gatewayId = $this->gatewayId($order);

    if ($gatewayId === NULL) {
      $this->logger->error('Tap charge @charge was captured but order @order names no payment gateway, so no Commerce payment was recorded.', [
        '@charge' => $chargeId,
        '@order' => $order->id(),
      ]);

      return NULL;
    }

    $money = $transaction->getMoney();

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->create([
      'state' => 'completed',
      'amount' => [
        'number' => $money->amount,
        'currency_code' => $money->currency,
      ],
      'payment_gateway' => $gatewayId,
      'order_id' => $order->id(),
      'remote_id' => $chargeId,
      'remote_state' => $transaction->getState()->value,
    ]);
    $payment->save();

    $this->logger->info('Recorded Tap charge @charge as Commerce payment @payment on order @order.', [
      '@charge' => $chargeId,
      '@payment' => $payment->id(),
      '@order' => $order->id(),
    ]);

    return $payment;
  }

  /**
   * The Commerce payment already recorded for a Tap charge, if any.
   *
   * @param string $chargeId
   *   The Tap charge identifier.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   The payment, or NULL.
   */
  public function findPayment(string $chargeId): ?PaymentInterface {
    $matches = $this->entityTypeManager->getStorage('commerce_payment')
      ->loadByProperties(['remote_id' => $chargeId]);

    $payment = reset($matches);

    return $payment instanceof PaymentInterface ? $payment : NULL;
  }

  /**
   * Loads the order a transaction belongs to.
   *
   * @param string|null $orderId
   *   The order id recorded on the transaction.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order, or NULL when it has since been deleted.
   */
  private function loadOrder(?string $orderId): ?OrderInterface {
    if ($orderId === NULL || $orderId === '') {
      return NULL;
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);

    return $order instanceof OrderInterface ? $order : NULL;
  }

  /**
   * The payment gateway the order was checked out with.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string|null
   *   The gateway entity id, or NULL when the order names none.
   */
  private function gatewayId(OrderInterface $order): ?string {
    if (!$order->hasField('payment_gateway') || $order->get('payment_gateway')->isEmpty()) {
      return NULL;
    }

    return (string) $order->get('payment_gateway')->target_id;
  }

}
