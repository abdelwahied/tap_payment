<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Exception;

/**
 * A caller asked for a payment Tap would reject.
 *
 * Every constraint enforced through this exception is one Tap documents — a
 * length limit behind an 11xx error code, a required field, a currency format.
 * Failing locally turns a round trip and an opaque code into an immediate,
 * readable message, and it is the reason no unvalidated caller input ever
 * reaches the request builder.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
final class InvalidPaymentRequestException extends TapPaymentException {}
