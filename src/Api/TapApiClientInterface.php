<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Api;

use Drupal\tap_payment\Dto\ApiResponse;

/**
 * The transport, and nothing else.
 *
 * An implementation knows about HTTP: base URL, credentials, timeouts,
 * retries, status codes. It knows nothing about charges, states or payers —
 * that separation is what lets the whole payment layer be unit-tested against
 * a stub, and what stops "business logic mixed into an HTTP call" from being
 * possible in the first place.
 *
 * Implementations must never swallow a failure: every non-2xx and every
 * transport error leaves as an exception.
 *
 * @api
 *   Public and stable since 1.0.0. Swap the implementation to route Tap traffic
 *   through a proxy or a recorded fixture without touching anything else.
 */
interface TapApiClientInterface {

  /**
   * Reads a resource.
   *
   * @param string $path
   *   A path relative to the API base, e.g. `charges/chg_123`.
   * @param array<string, string> $headers
   *   Extra headers; the Authorization header is added by the implementation
   *   and cannot be overridden.
   *
   * @return \Drupal\tap_payment\Dto\ApiResponse
   *   The decoded response.
   *
   * @throws \Drupal\tap_payment\Exception\ApiException
   *   When Tap answered with an error or could not be reached.
   * @throws \Drupal\tap_payment\Exception\ConfigurationException
   *   When no usable secret key is configured.
   */
  public function get(string $path, array $headers = []): ApiResponse;

  /**
   * Creates or updates a resource.
   *
   * @param string $path
   *   A path relative to the API base, e.g. `charges`.
   * @param array<string, mixed> $body
   *   The request body, JSON encoded by the implementation.
   * @param array<string, string> $headers
   *   Extra headers; the Authorization header is added by the implementation
   *   and cannot be overridden.
   *
   * @return \Drupal\tap_payment\Dto\ApiResponse
   *   The decoded response.
   *
   * @throws \Drupal\tap_payment\Exception\ApiException
   *   When Tap answered with an error or could not be reached.
   * @throws \Drupal\tap_payment\Exception\ConfigurationException
   *   When no usable secret key is configured.
   */
  public function post(string $path, array $body, array $headers = []): ApiResponse;

}
