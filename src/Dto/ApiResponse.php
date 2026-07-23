<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Dto;

/**
 * One HTTP exchange with Tap, decoded but not yet interpreted.
 *
 * This is the boundary between the transport and everything else: the client
 * produces it and knows nothing about charges, the adapter consumes it and
 * knows nothing about Guzzle. Keeping the raw body alongside the decoded array
 * lets an error be reported precisely without re-encoding a structure that may
 * not have parsed in the first place.
 *
 * @internal
 *   Shape may change; the payment DTOs are the stable surface.
 */
final class ApiResponse {

  /**
   * Constructs an ApiResponse.
   *
   * @param int $statusCode
   *   The HTTP status code.
   * @param array<string, mixed> $data
   *   The decoded JSON body, or an empty array when it was not JSON.
   * @param string $rawBody
   *   The body exactly as received.
   */
  public function __construct(
    public readonly int $statusCode,
    public readonly array $data,
    public readonly string $rawBody,
  ) {}

  /**
   * Whether Tap answered with a 2xx.
   *
   * @return bool
   *   TRUE for a successful exchange.
   */
  public function isSuccessful(): bool {
    return $this->statusCode >= 200 && $this->statusCode < 300;
  }

  /**
   * The documented `errors` entries, when Tap rejected the request.
   *
   * @return array<int, array{code: string, description: string}>
   *   One entry per reported error.
   */
  public function errors(): array {
    $errors = [];

    foreach ($this->data['errors'] ?? [] as $error) {
      if (!is_array($error)) {
        continue;
      }

      $errors[] = [
        'code' => (string) ($error['code'] ?? ''),
        'description' => (string) ($error['description'] ?? ''),
      ];
    }

    return $errors;
  }

}
