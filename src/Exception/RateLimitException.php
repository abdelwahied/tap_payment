<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Exception;

/**
 * Tap answered 429 and asked for the traffic to slow down.
 *
 * Thrown only after the client has exhausted its own backoff, so reaching this
 * means the retries did not help and the caller has to decide — which is why
 * it is distinguishable from an ordinary API failure rather than folded into
 * one.
 *
 * @api
 *   Public and stable since 1.0.0.
 *
 * @see https://developers.tap.company/reference/charge-response-codes
 */
final class RateLimitException extends ApiException {}
