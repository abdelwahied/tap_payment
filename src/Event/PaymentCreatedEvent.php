<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Event;

/**
 * Dispatched once a charge exists at Tap and the payer can be sent to it.
 *
 * Nothing has been paid yet. Treat this as "an attempt started", never as a
 * reason to release anything.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
final class PaymentCreatedEvent extends PaymentEventBase {}
