<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Traits;

/**
 * Loads the recorded Tap responses the tests are written against.
 *
 * The fixtures are copied verbatim from Tap's own documentation — the charge
 * response on the Create a Charge reference and the captured-charge webhook
 * body on the webhook page. Writing tests against invented payloads would only
 * prove the module agrees with itself.
 */
trait TapFixtureTrait {

  /**
   * Decodes a fixture.
   *
   * @param string $name
   *   The file name without the extension.
   *
   * @return array<string, mixed>
   *   The decoded payload.
   */
  protected function fixture(string $name): array {
    $path = __DIR__ . '/../../fixtures/' . $name . '.json';
    $contents = file_get_contents($path);

    $this->assertIsString($contents, sprintf('Fixture %s is readable.', $name));
    $decoded = json_decode($contents, TRUE);
    $this->assertIsArray($decoded, sprintf('Fixture %s is a JSON object.', $name));

    return $decoded;
  }

}
