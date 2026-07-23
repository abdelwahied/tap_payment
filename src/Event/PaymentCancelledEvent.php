<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Event;

/**
 * Dispatched when the payer stopped rather than the payment breaking.
 *
 * Cancelled, abandoned and voided charges arrive here. Kept apart from failure
 * because the two deserve different words to the customer and different
 * treatment in reporting.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
final class PaymentCancelledEvent extends PaymentEventBase {}
