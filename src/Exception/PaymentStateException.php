<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Exception;

/**
 * Something tried to move a payment to a state it cannot reach from here.
 *
 * The common cause is not a bug but the network: a webhook retried after the
 * payer already came back, or two notifications delivered out of order. The
 * state machine refuses the move and the caller treats it as a no-op, which is
 * exactly how the module stays idempotent without keeping a list of seen
 * message identifiers.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
final class PaymentStateException extends TapPaymentException {}
