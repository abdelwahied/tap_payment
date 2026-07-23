<?php

declare(strict_types=1);

namespace Drupal\tap_payment_test;

use Drupal\Core\State\StateInterface;
use Drupal\tap_payment\Api\TapApiClientInterface;
use Drupal\tap_payment\Dto\ApiResponse;
use Drupal\tap_payment\Exception\ApiException;

/**
 * A Tap transport that answers from a script instead of from the network.
 *
 * Kept in state rather than in a property because kernel and functional tests
 * script the responses from one process and the module consumes them in
 * another. Requests are recorded too, so a test can assert what was actually
 * sent — which is the half of an HTTP contract that a response stub alone
 * cannot check.
 *
 * @internal
 *   Test support.
 */
final class StubApiClient implements TapApiClientInterface {

  /**
   * The state key holding the queued responses.
   */
  public const QUEUE_KEY = 'tap_payment_test.queue';

  /**
   * The state key holding the recorded requests.
   */
  public const LOG_KEY = 'tap_payment_test.requests';

  /**
   * Constructs a StubApiClient.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   Where the script and the recording live.
   */
  public function __construct(private readonly StateInterface $state) {}

  /**
   * Queues one answer.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param array<string, mixed> $body
   *   The decoded body to answer with.
   * @param int $status
   *   The HTTP status to report.
   */
  public static function queue(StateInterface $state, array $body, int $status = 200): void {
    $queued = $state->get(self::QUEUE_KEY, []);
    $queued[] = ['status' => $status, 'body' => $body];
    $state->set(self::QUEUE_KEY, $queued);
  }

  /**
   * Everything the module sent, oldest first.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   *
   * @return array<int, array{method: string, path: string, body: array<string, mixed>, headers: array<string, string>}>
   *   The recorded requests.
   */
  public static function requests(StateInterface $state): array {
    return $state->get(self::LOG_KEY, []);
  }

  /**
   * Forgets every queued answer and recorded request.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public static function reset(StateInterface $state): void {
    $state->delete(self::QUEUE_KEY);
    $state->delete(self::LOG_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $path, array $headers = []): ApiResponse {
    return $this->answer('GET', $path, [], $headers);
  }

  /**
   * {@inheritdoc}
   */
  public function post(string $path, array $body, array $headers = []): ApiResponse {
    return $this->answer('POST', $path, $body, $headers);
  }

  /**
   * Records the request and returns the next scripted answer.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path.
   * @param array<string, mixed> $body
   *   The request body.
   * @param array<string, string> $headers
   *   The extra headers.
   *
   * @return \Drupal\tap_payment\Dto\ApiResponse
   *   The scripted answer.
   */
  private function answer(string $method, string $path, array $body, array $headers): ApiResponse {
    $log = $this->state->get(self::LOG_KEY, []);
    $log[] = ['method' => $method, 'path' => $path, 'body' => $body, 'headers' => $headers];
    $this->state->set(self::LOG_KEY, $log);

    $queue = $this->state->get(self::QUEUE_KEY, []);
    $next = array_shift($queue);
    $this->state->set(self::QUEUE_KEY, $queue);

    if ($next === NULL) {
      throw new ApiException(sprintf('No scripted Tap answer for %s %s.', $method, $path));
    }

    $response = new ApiResponse(
      (int) $next['status'],
      (array) $next['body'],
      (string) json_encode($next['body']),
    );

    if (!$response->isSuccessful()) {
      throw new ApiException(
        sprintf('Tap rejected %s %s with HTTP %d.', $method, $path, $response->statusCode),
        $response->statusCode,
        $response->errors(),
      );
    }

    return $response;
  }

}
