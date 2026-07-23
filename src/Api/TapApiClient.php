<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Api;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\tap_payment\Dto\ApiResponse;
use Drupal\tap_payment\Exception\ApiException;
use Drupal\tap_payment\Exception\AuthenticationException;
use Drupal\tap_payment\Exception\RateLimitException;
use Drupal\tap_payment\Logger\LogSanitizer;
use Drupal\tap_payment\Service\TapPaymentSettings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Talks to Tap over Drupal's HTTP client.
 *
 * Three decisions worth stating, because each one is a failure mode this
 * module must not have:
 *
 * - **Retries are for transport, never for intent.** A 429, a 5xx or a dropped
 *   connection is retried with exponential backoff; a 4xx never is. Retrying a
 *   rejected charge cannot make it succeed, and retrying one that *did* succeed
 *   is how a customer gets billed twice — the request-level `reference
 *   .idempotent` string is the second line of defence for exactly that case.
 * - **`http_errors` is off.** Guzzle's own exception carries the request in its
 *   message, Authorization header included. Reading the status from the
 *   response instead means the key never enters an exception at all.
 * - **Nothing leaves this class unreported.** Every non-2xx becomes a typed
 *   exception. Suppressing one would let a failed payment look like a
 *   successful one to the layer above.
 *
 * @internal
 *   Injected behind \Drupal\tap_payment\Api\TapApiClientInterface.
 *
 * @see https://developers.tap.company/docs/get-started
 * @see https://developers.tap.company/reference/charge-response-codes
 */
final class TapApiClient implements TapApiClientInterface {

  /**
   * Constructs a TapApiClient.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Drupal's shared HTTP client.
   * @param \Drupal\tap_payment\Service\TapPaymentSettings $settings
   *   Supplies the secret key for the active environment.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's log channel.
   * @param \Drupal\tap_payment\Logger\LogSanitizer $sanitizer
   *   Last gate before anything reaches the log.
   * @param string $baseUrl
   *   The API base, with a trailing slash.
   * @param float $timeout
   *   Seconds to wait for a complete response.
   * @param float $connectTimeout
   *   Seconds to wait for the connection itself.
   * @param int $maxRetries
   *   How many times a retryable failure is repeated.
   * @param int $retryBaseDelayMs
   *   The first backoff delay; each further attempt doubles it.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly TapPaymentSettings $settings,
    private readonly LoggerChannelInterface $logger,
    private readonly LogSanitizer $sanitizer,
    private readonly string $baseUrl,
    private readonly float $timeout,
    private readonly float $connectTimeout,
    private readonly int $maxRetries,
    private readonly int $retryBaseDelayMs,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get(string $path, array $headers = []): ApiResponse {
    return $this->request('GET', $path, NULL, $headers);
  }

  /**
   * {@inheritdoc}
   */
  public function post(string $path, array $body, array $headers = []): ApiResponse {
    return $this->request('POST', $path, $body, $headers);
  }

  /**
   * Performs one logical request, retrying only what is safe to retry.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path relative to the API base.
   * @param array<string, mixed>|null $body
   *   The JSON body, or NULL for a bodiless request.
   * @param array<string, string> $headers
   *   Caller-supplied headers.
   *
   * @return \Drupal\tap_payment\Dto\ApiResponse
   *   The decoded response.
   *
   * @throws \Drupal\tap_payment\Exception\ApiException
   *   When Tap answered with an error or could not be reached.
   * @throws \Drupal\tap_payment\Exception\ConfigurationException
   *   When no usable secret key is configured.
   */
  private function request(string $method, string $path, ?array $body, array $headers): ApiResponse {
    $url = $this->baseUrl . ltrim($path, '/');
    $options = $this->buildOptions($body, $headers);
    $attempt = 0;

    while (TRUE) {
      $attempt++;

      try {
        $response = $this->httpClient->request($method, $url, $options);
        $decoded = $this->decode($response);

        if ($decoded->isSuccessful()) {
          $this->logger->debug('Tap @method @path answered @status.', [
            '@method' => $method,
            '@path' => $path,
            '@status' => $decoded->statusCode,
          ]);

          return $decoded;
        }

        if ($this->isRetryable($decoded->statusCode) && $attempt <= $this->maxRetries) {
          $this->backOff($attempt);
          continue;
        }

        throw $this->toException($method, $path, $decoded);
      }
      catch (ConnectException | RequestException $e) {
        // A transport failure never reached Tap, or never got an answer back.
        // Retrying is safe for a GET and is the only way a POST can recover
        // from a dropped connection; the idempotency key covers the case where
        // the charge was in fact created.
        if ($attempt <= $this->maxRetries) {
          $this->backOff($attempt);
          continue;
        }

        throw new ApiException(
          sprintf('Could not reach Tap for %s %s: %s', $method, $path, $this->sanitizer->sanitizeMessage($e->getMessage())),
          0,
          [],
          $e,
        );
      }
      catch (TransferException $e) {
        throw new ApiException(
          sprintf('The request to Tap for %s %s failed: %s', $method, $path, $this->sanitizer->sanitizeMessage($e->getMessage())),
          0,
          [],
          $e,
        );
      }
    }
  }

  /**
   * Builds the Guzzle options for one request.
   *
   * @param array<string, mixed>|null $body
   *   The JSON body, or NULL.
   * @param array<string, string> $headers
   *   Caller-supplied headers.
   *
   * @return array<string, mixed>
   *   The request options.
   *
   * @throws \Drupal\tap_payment\Exception\ConfigurationException
   *   When no usable secret key is configured.
   */
  private function buildOptions(?array $body, array $headers): array {
    // The caller's headers go in first so that Authorization, added second,
    // can never be replaced by one passed in from an integration.
    $options = [
      RequestOptions::HEADERS => $headers + [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
      ],
      RequestOptions::TIMEOUT => $this->timeout,
      RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
      RequestOptions::HTTP_ERRORS => FALSE,
    ];

    $options[RequestOptions::HEADERS]['Authorization'] = 'Bearer ' . $this->settings->secretKey();

    if ($body !== NULL) {
      $options[RequestOptions::JSON] = $body;
    }

    return $options;
  }

  /**
   * Reads a PSR-7 response into the module's own value object.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   *
   * @return \Drupal\tap_payment\Dto\ApiResponse
   *   The decoded response; a body that is not JSON yields empty data rather
   *   than an exception, so the status code still gets reported.
   */
  private function decode(ResponseInterface $response): ApiResponse {
    $raw = (string) $response->getBody();
    $data = json_decode($raw, TRUE);

    return new ApiResponse(
      $response->getStatusCode(),
      is_array($data) ? $data : [],
      $raw,
    );
  }

  /**
   * Whether a status code describes a condition that may pass on its own.
   *
   * @param int $statusCode
   *   The HTTP status code.
   *
   * @return bool
   *   TRUE for throttling and server-side faults.
   */
  private function isRetryable(int $statusCode): bool {
    return $statusCode === 429 || $statusCode >= 500;
  }

  /**
   * Waits before the next attempt, doubling each time.
   *
   * @param int $attempt
   *   The attempt that just failed, counting from one.
   */
  private function backOff(int $attempt): void {
    usleep($this->retryBaseDelayMs * 1000 * (2 ** ($attempt - 1)));
  }

  /**
   * Turns a rejected response into the most specific exception that fits.
   *
   * @param string $method
   *   The HTTP method, for the message.
   * @param string $path
   *   The path, for the message.
   * @param \Drupal\tap_payment\Dto\ApiResponse $response
   *   The rejected response.
   *
   * @return \Drupal\tap_payment\Exception\ApiException
   *   The exception to throw.
   */
  private function toException(string $method, string $path, ApiResponse $response): ApiException {
    $errors = $response->errors();
    $codes = array_filter(array_map(static fn (array $error): string => $error['code'], $errors));

    $this->logger->error('Tap rejected @method @path with HTTP @status@codes.', [
      '@method' => $method,
      '@path' => $path,
      '@status' => $response->statusCode,
      '@codes' => $codes === [] ? '' : ' (error codes: ' . implode(', ', $codes) . ')',
    ]);

    $message = sprintf(
      'Tap rejected %s %s with HTTP %d%s.',
      $method,
      $path,
      $response->statusCode,
      $codes === [] ? '' : ' (error codes: ' . implode(', ', $codes) . ')',
    );

    return match (TRUE) {
      $response->statusCode === 401 || $response->statusCode === 403 =>
        new AuthenticationException($message, $response->statusCode, $errors),
      $response->statusCode === 429 =>
        new RateLimitException($message, $response->statusCode, $errors),
      default => new ApiException($message, $response->statusCode, $errors),
    };
  }

}
