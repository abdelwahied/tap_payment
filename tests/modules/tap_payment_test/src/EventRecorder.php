<?php

declare(strict_types=1);

namespace Drupal\tap_payment_test;

use Drupal\Core\State\StateInterface;
use Drupal\tap_payment\Event\PaymentEventBase;
use Drupal\tap_payment\Event\TapPaymentEvents;
use Drupal\tap_payment\Event\WebhookReceivedEvent;
use Drupal\tap_payment\Event\WebhookVerifiedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records the module's events so tests can assert what was announced.
 *
 * Subscribing from a separate module is also the demonstration that the
 * extension points work: nothing in tap_payment knows this class exists.
 *
 * @internal
 *   Test support.
 */
final class EventRecorder implements EventSubscriberInterface {

  /**
   * The state key holding the recorded events.
   */
  public const KEY = 'tap_payment_test.events';

  /**
   * Constructs an EventRecorder.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   Where the recording lives.
   */
  public function __construct(private readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      TapPaymentEvents::PAYMENT_CREATED => 'onPayment',
      TapPaymentEvents::PAYMENT_CAPTURED => 'onPayment',
      TapPaymentEvents::PAYMENT_FAILED => 'onPayment',
      TapPaymentEvents::PAYMENT_CANCELLED => 'onPayment',
      TapPaymentEvents::WEBHOOK_RECEIVED => 'onWebhookReceived',
      TapPaymentEvents::WEBHOOK_VERIFIED => 'onWebhookVerified',
    ];
  }

  /**
   * Records a payment lifecycle event.
   *
   * @param \Drupal\tap_payment\Event\PaymentEventBase $event
   *   The event.
   * @param string $name
   *   The event name.
   */
  public function onPayment(PaymentEventBase $event, string $name): void {
    $this->record($name, [
      'charge_id' => $event->transaction->getChargeId(),
      'state' => $event->transaction->getState()->value,
    ]);
  }

  /**
   * Records the arrival of a webhook.
   *
   * @param \Drupal\tap_payment\Event\WebhookReceivedEvent $event
   *   The event.
   * @param string $name
   *   The event name.
   */
  public function onWebhookReceived(WebhookReceivedEvent $event, string $name): void {
    $this->record($name, ['charge_id' => $event->payload['id'] ?? NULL]);
  }

  /**
   * Records a webhook that passed verification.
   *
   * @param \Drupal\tap_payment\Event\WebhookVerifiedEvent $event
   *   The event.
   * @param string $name
   *   The event name.
   */
  public function onWebhookVerified(WebhookVerifiedEvent $event, string $name): void {
    $this->record($name, ['charge_id' => $event->chargeId]);
  }

  /**
   * Everything recorded, oldest first.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   *
   * @return array<int, array{event: string, data: array<string, mixed>}>
   *   The recorded events.
   */
  public static function events(StateInterface $state): array {
    return $state->get(self::KEY, []);
  }

  /**
   * Forgets everything recorded.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public static function reset(StateInterface $state): void {
    $state->delete(self::KEY);
  }

  /**
   * Appends one entry to the recording.
   *
   * @param string $name
   *   The event name.
   * @param array<string, mixed> $data
   *   What is worth remembering about it.
   */
  private function record(string $name, array $data): void {
    $events = $this->state->get(self::KEY, []);
    $events[] = ['event' => $name, 'data' => $data];
    $this->state->set(self::KEY, $events);
  }

}
