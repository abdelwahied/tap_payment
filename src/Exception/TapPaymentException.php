<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Exception;

/**
 * The base every failure in this module extends.
 *
 * One base means an integration can wrap a whole payment attempt in a single
 * catch and still be sure nothing from this module escaped uncaught, without
 * having to enumerate subclasses it does not care about.
 *
 * None of these carry an API key, a token or a card number: their messages are
 * assembled from codes and identifiers only, because an exception message ends
 * up in a log, a stack trace and sometimes a bug report.
 *
 * @api
 *   Public and stable since 1.0.0.
 */
class TapPaymentException extends \RuntimeException {}
