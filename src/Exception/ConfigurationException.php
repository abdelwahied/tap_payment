<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Exception;

/**
 * The module cannot act because the site has not finished setting it up.
 *
 * Raised before any HTTP request is attempted — a missing or wrong-environment
 * secret key is caught here rather than being sent to Tap and coming back as a
 * 401, so the message can say which setting is at fault.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
final class ConfigurationException extends TapPaymentException {}
