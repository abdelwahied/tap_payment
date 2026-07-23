<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched once a webhook's signature has been checked and matches.
 *
 * From here on the payload is Tap's word. It is still not the site's record:
 * the transaction is updated after this event, and the payment lifecycle
 * events follow.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
final class WebhookVerifiedEvent extends Event {

  /**
   * Constructs a WebhookVerifiedEvent.
   *
   * @param array<string, mixed> $payload
   *   The decoded body, now known to have come from Tap.
   * @param string $chargeId
   *   The charge the payload is about.
   */
  public function __construct(
    public readonly array $payload,
    public readonly string $chargeId,
  ) {}

}
