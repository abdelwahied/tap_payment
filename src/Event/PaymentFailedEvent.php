<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Event;

/**
 * Dispatched when a payment will not complete.
 *
 * Covers the documented failure states — failed, declined, restricted and
 * timed out. `UNKNOWN` is not among them: it is not an outcome, and the
 * reconciliation queue keeps asking Tap until it becomes one.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
final class PaymentFailedEvent extends PaymentEventBase {}
