<?php

declare(strict_types=1);

namespace Drupal\tap_payment_commerce\Plugin\Commerce\PaymentGateway;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\tap_payment\Entity\TapTransactionInterface;
use Drupal\tap_payment\TapPaymentInterface;
use Drupal\tap_payment_commerce\PluginForm\TapOffsiteForm;
use Drupal\tap_payment_commerce\Service\CommercePaymentRecorder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tap Payments as a Commerce off-site gateway.
 *
 * The gateway configuration form is deliberately empty. Credentials live once,
 * on the Tap Payment settings page, and are shared by every integration —
 * repeating them per Commerce gateway would mean two places to rotate a secret
 * and one of them getting forgotten.
 *
 * `onNotify()` is likewise not implemented, and that is not an omission. Tap is
 * told to post to the core module's own webhook route, which verifies the
 * signature and settles the payment; the order is then updated by
 * \Drupal\tap_payment_commerce\EventSubscriber\PaymentOutcomeSubscriber. A
 * second notification endpoint here would be a second signature check to keep
 * correct.
 *
 * So `onReturn()` has one job: make sure the order reflects a payment that has
 * *already* been verified. It never reads the request.
 *
 * @internal
 *   A Commerce plugin; not part of the public API.
 */
#[CommercePaymentGateway(
  id: 'tap_offsite',
  label: new TranslatableMarkup('Tap Payments (off-site redirect)'),
  display_label: new TranslatableMarkup('Tap'),
  forms: [
    'offsite-payment' => TapOffsiteForm::class,
  ],
  payment_method_types: ['credit_card'],
  requires_billing_information: FALSE,
)]
final class TapOffsite extends OffsitePaymentGatewayBase {

  /**
   * Records the payment onto the order.
   */
  private CommercePaymentRecorder $recorder;

  /**
   * The Tap payment service.
   */
  private TapPaymentInterface $payments;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->recorder = $container->get('tap_payment_commerce.recorder');
    $instance->payments = $container->get('tap_payment.payment');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request): void {
    $transaction = $this->latestTransaction($order);

    if ($transaction === NULL) {
      throw new PaymentGatewayException('No Tap payment was started for this order.');
    }

    // The core module's return route already re-read the charge from Tap, and
    // the webhook may have settled it before that. Verifying again is cheap
    // insurance for the case where Commerce is reached by some other path, and
    // it cannot double-apply: the state machine refuses a second final move.
    $transaction = $this->payments->verifyPayment($transaction);

    if (!$transaction->isPaid()) {
      throw new PaymentGatewayException(sprintf(
        'The Tap payment for order %s was not completed (%s).',
        $order->id(),
        $transaction->getState()->value,
      ));
    }

    $this->recorder->record($transaction);
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request): void {
    $this->messenger()->addWarning($this->t('You cancelled the payment. Nothing has been charged, and your order is still waiting.'));
  }

  /**
   * The most recent Tap transaction started for an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface|null
   *   The transaction, or NULL when none was started.
   */
  private function latestTransaction(OrderInterface $order): ?TapTransactionInterface {
    $transactions = $this->payments->loadByContext(
      CommercePaymentRecorder::CONTEXT,
      (string) $order->id(),
    );

    $transaction = reset($transactions);

    return $transaction instanceof TapTransactionInterface ? $transaction : NULL;
  }

}
