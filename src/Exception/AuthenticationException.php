<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Exception;

/**
 * Tap refused the secret key.
 *
 * Separated from the general API failure because it is never worth retrying
 * and never the payer's fault: a 401 means the site is misconfigured, and the
 * only useful response is to stop and tell an administrator.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
final class AuthenticationException extends ApiException {}
