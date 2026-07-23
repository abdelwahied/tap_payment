<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Exception;

/**
 * A webhook arrived that this site cannot prove came from Tap.
 *
 * Thrown for a missing header, an unparseable body, a signature mismatch and a
 * timestamp outside the replay window alike. The distinction matters for
 * debugging, so it lives in the message; it must not change the outcome, since
 * every one of those cases ends the same way — the payload is discarded
 * untrusted.
 *
 * @api
 *   Public and stable since 1.0.0.
 *
 * @see https://developers.tap.company/docs/webhook
 */
final class WebhookVerificationException extends TapPaymentException {}
