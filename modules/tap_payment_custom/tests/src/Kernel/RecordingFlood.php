<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment_custom\Kernel;

use Drupal\Core\Flood\FloodInterface;

/**
 * A flood backend that records the identifiers it is given.
 *
 * Exists so the privacy guarantee — that a payer's email never leaves the
 * throttle unhashed — can be asserted against the boundary itself rather than
 * against one backend's table, which is a site's choice and not this module's.
 */
final class RecordingFlood implements FloodInterface {

  /**
   * Every identifier handed to this backend.
   *
   * @var array<int, string>
   */
  public array $identifiers = [];

  /**
   * {@inheritdoc}
   */
  public function register($name, $window = 3600, $identifier = NULL): void {
    $this->identifiers[] = (string) $identifier;
  }

  /**
   * {@inheritdoc}
   */
  public function clear($name, $identifier = NULL): void {
    $this->identifiers[] = (string) $identifier;
  }

  /**
   * {@inheritdoc}
   */
  public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL): bool {
    $this->identifiers[] = (string) $identifier;

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection(): void {}

}
