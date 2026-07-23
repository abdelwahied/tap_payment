<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Event;

/**
 * Dispatched when Tap confirms the money has been taken.
 *
 * This is the only event that means paid, and it fires exactly once per
 * transaction: the state machine refuses a second move into a final state, so
 * a webhook replayed after the payer already returned cannot double-fulfil an
 * order.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
final class PaymentCapturedEvent extends PaymentEventBase {}
