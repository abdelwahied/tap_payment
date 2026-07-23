<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched the moment a webhook arrives, before it has been trusted.
 *
 * The payload here is unauthenticated input from the open internet: anyone can
 * POST to the endpoint. Subscribe for monitoring — how many calls arrive, how
 * many are rejected — and never to act on the contents. Acting is what
 * WEBHOOK_VERIFIED is for.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
final class WebhookReceivedEvent extends Event {

  /**
   * Constructs a WebhookReceivedEvent.
   *
   * @param array<string, mixed> $payload
   *   The decoded, unverified body.
   */
  public function __construct(public readonly array $payload) {}

}
