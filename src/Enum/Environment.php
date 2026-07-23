<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Enum;

/**
 * Which set of Tap credentials a request is made with.
 *
 * Tap has no separate sandbox host: the same `api.tap.company` endpoints run in
 * test or live mode purely according to the prefix of the secret key sent. So
 * the environment is not a URL switch, it is a key selector — and getting it
 * wrong means real money, which is why it is an explicit enum rather than a
 * `test_mode` boolean buried in config.
 *
 * @api
 *   Public and stable since 1.0.0.
 *
 * @see https://developers.tap.company/docs/get-started
 */
enum Environment: string {

  case Sandbox = 'sandbox';
  case Production = 'production';

  /**
   * The secret-key prefix Tap issues for this environment.
   *
   * @return string
   *   The documented key prefix.
   */
  public function keyPrefix(): string {
    return match ($this) {
      self::Sandbox => 'sk_test_',
      self::Production => 'sk_live_',
    };
  }

  /**
   * Whether a key looks like it belongs to this environment.
   *
   * A live key in the sandbox field is a configuration mistake that would
   * otherwise only be discovered by charging somebody.
   *
   * @param string $key
   *   The secret key to check.
   *
   * @return bool
   *   TRUE when the prefix matches.
   */
  public function matchesKey(string $key): bool {
    return str_starts_with(trim($key), $this->keyPrefix());
  }

}
