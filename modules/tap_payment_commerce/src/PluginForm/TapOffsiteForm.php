<?php

declare(strict_types=1);

namespace Drupal\tap_payment_commerce\PluginForm;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\tap_payment\Dto\Customer;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Exception\TapPaymentException;
use Drupal\tap_payment\TapPaymentInterface;
use Drupal\tap_payment_commerce\Service\CommercePaymentRecorder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Starts the Tap charge and sends the customer to it.
 *
 * The one piece of real thinking here is the idempotency key, because Commerce
 * and Tap disagree about what a retry is.
 *
 * Tap honours an idempotency key for twenty-four hours: send the same one twice
 * and you get the first charge back rather than a second one. That is exactly
 * right for a double-clicked "Pay" button — and exactly wrong for a customer
 * whose card was declined and who is trying again with another one, because
 * they would be handed the declined charge for the rest of the day.
 *
 * So the key is stored on the order and reused only while the attempt it
 * belongs to is still open. Once an attempt reaches an outcome, the next
 * checkout gets a fresh key. Same order, same attempt, same charge; same
 * order, new attempt, new charge.
 *
 * @internal
 *   A Commerce plugin form; not part of the public API.
 */
final class TapOffsiteForm extends PaymentOffsiteForm implements ContainerInjectionInterface {

  /**
   * The order key holding the current attempt's idempotency key.
   */
  private const ATTEMPT_KEY = 'tap_payment_idempotency_key';

  /**
   * Constructs a TapOffsiteForm.
   *
   * @param \Drupal\tap_payment\TapPaymentInterface $payments
   *   The one service this form needs.
   */
  public function __construct(private readonly TapPaymentInterface $payments) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tap_payment.payment'));
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $order = $payment->getOrder();

    if ($order === NULL) {
      throw new PaymentGatewayException('The Tap payment form was built without an order.');
    }

    try {
      $session = $this->payments->createPayment(new PaymentRequest(
        money: new Money(
          $payment->getAmount()->getNumber(),
          $payment->getAmount()->getCurrencyCode(),
        ),
        customer: $this->customer($order),
        returnUrl: $form['#return_url'],
        cancelUrl: $form['#cancel_url'],
        description: (string) $this->t('Order @number', ['@number' => $order->getOrderNumber() ?: $order->id()]),
        idempotencyKey: $this->idempotencyKey($order),
        orderReference: (string) ($order->getOrderNumber() ?: $order->id()),
        contextModule: CommercePaymentRecorder::CONTEXT,
        contextId: (string) $order->id(),
      ));
    }
    catch (TapPaymentException $e) {
      // Commerce shows this to the customer as a checkout error and keeps them
      // on the payment step, which is the right place to be when a gateway is
      // misconfigured or unreachable.
      throw new PaymentGatewayException('The payment could not be started with Tap.', 0, $e);
    }

    $url = $session->redirectUrl();

    if ($url === NULL) {
      throw new PaymentGatewayException('Tap did not return a payment page for this charge.');
    }

    // A GET redirect: Tap hands back a URL that already carries the charge, so
    // there is nothing to POST.
    return $this->buildRedirectForm($form, $form_state, $url, [], self::REDIRECT_GET);
  }

  /**
   * The idempotency key for the order's current payment attempt.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order being paid.
   *
   * @return string
   *   A key that is stable within one attempt and fresh after an outcome.
   */
  private function idempotencyKey(OrderInterface $order): string {
    $stored = $order->getData(self::ATTEMPT_KEY);

    if (is_string($stored) && $stored !== '') {
      $existing = $this->payments->loadByIdempotencyKey($stored);

      // Still open — the customer reloaded, or double-submitted. Reuse it, and
      // Tap will hand back the charge they already started.
      if ($existing === NULL || !$existing->getState()->isFinal()) {
        return $stored;
      }
    }

    $key = sprintf('commerce-%s-%s', $order->id(), bin2hex(random_bytes(8)));
    $order->setData(self::ATTEMPT_KEY, $key);
    $order->save();

    return $key;
  }

  /**
   * The payer, from what the order already knows.
   *
   * Tap requires a first name and an email. Commerce guarantees the email;
   * the name comes from the billing profile when there is one, and falls back
   * to the order number, because a placeholder is better than failing a
   * checkout over a field the customer was never asked for.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order being paid.
   *
   * @return \Drupal\tap_payment\Dto\Customer
   *   The customer.
   */
  private function customer(OrderInterface $order): Customer {
    $first = '';
    $last = NULL;
    $profile = $order->getBillingProfile();

    if ($profile !== NULL && $profile->hasField('address') && !$profile->get('address')->isEmpty()) {
      $address = $profile->get('address')->first();
      $first = (string) $address->get('given_name')->getValue();
      $last = (string) $address->get('family_name')->getValue() ?: NULL;
    }

    if (trim($first) === '') {
      $first = (string) $this->t('Order @number', ['@number' => $order->getOrderNumber() ?: $order->id()]);
    }

    return new Customer(
      firstName: $first,
      email: (string) $order->getEmail(),
      lastName: $last,
    );
  }

}
