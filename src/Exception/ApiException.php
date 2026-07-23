<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Exception;

/**
 * Tap answered, but not with what was asked for.
 *
 * Carries the HTTP status and Tap's own documented error entries so a site
 * builder can be told "1110 Redirect URL is missing" rather than "payment
 * failed". The raw response body is deliberately not carried: it is the one
 * part of the exchange that can contain payer details.
 *
 * @api
 *   Public and stable since 1.0.0.
 *
 * @see https://developers.tap.company/reference/charge-response-codes
 */
class ApiException extends TapPaymentException {

  /**
   * Constructs an ApiException.
   *
   * @param string $message
   *   What went wrong, in terms safe to log.
   * @param int $statusCode
   *   The HTTP status Tap returned, or 0 when the request never completed.
   * @param array<int, array{code: string, description: string}> $errors
   *   Tap's documented error entries, when it sent any.
   * @param \Throwable|null $previous
   *   The underlying transport failure, when there was one.
   */
  public function __construct(
    string $message,
    private readonly int $statusCode = 0,
    private readonly array $errors = [],
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * The HTTP status code Tap returned.
   *
   * @return int
   *   The status code, or 0 when the request never reached Tap.
   */
  public function getStatusCode(): int {
    return $this->statusCode;
  }

  /**
   * Tap's documented error entries.
   *
   * @return array<int, array{code: string, description: string}>
   *   One entry per reported error.
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * The error codes alone, for logging without the prose.
   *
   * @return array<int, string>
   *   The reported codes.
   */
  public function getErrorCodes(): array {
    return array_map(static fn (array $error): string => $error['code'], $this->errors);
  }

}
