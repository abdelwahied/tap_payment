<?php

declare(strict_types=1);

namespace Drupal\tap_payment_commerce\EventSubscriber;

use Drupal\tap_payment\Event\PaymentCapturedEvent;
use Drupal\tap_payment\Event\TapPaymentEvents;
use Drupal\tap_payment_commerce\Service\CommercePaymentRecorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Turns a captured Tap payment into a Commerce payment.
 *
 * This is why the core module dispatches events rather than knowing about
 * Commerce. The webhook — the authoritative confirmation — arrives on the core
 * module's own endpoint, which knows nothing about orders. Subscribing here is
 * what closes the gap, and it means an order is marked paid even when the
 * customer closes the tab and never returns to the site, which is the whole
 * reason Tap recommends implementing the POST URL in the first place.
 *
 * Only capture is subscribed to. A failed or cancelled attempt leaves the order
 * exactly as it was: unpaid, and available to try again.
 *
 * @internal
 *   An event subscriber; not part of the public API.
 */
final class PaymentOutcomeSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a PaymentOutcomeSubscriber.
   *
   * @param \Drupal\tap_payment_commerce\Service\CommercePaymentRecorder $recorder
   *   Writes the payment onto the order.
   */
  public function __construct(private readonly CommercePaymentRecorder $recorder) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [TapPaymentEvents::PAYMENT_CAPTURED => 'onCaptured'];
  }

  /**
   * Records the payment against its order.
   *
   * @param \Drupal\tap_payment\Event\PaymentCapturedEvent $event
   *   The capture event.
   */
  public function onCaptured(PaymentCapturedEvent $event): void {
    $this->recorder->record($event->transaction);
  }

}
