<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Logger;

/**
 * Strips anything from a value that must never reach a log.
 *
 * The module's own code is careful about what it passes to the logger, but
 * "careful" is not a guarantee: a Guzzle exception message can contain the
 * request it failed on, headers and all, and an integration can hand this
 * module a metadata array holding whatever it likes. This is the last gate
 * before dblog, and it is applied to every message and every context value —
 * belt and braces, because a secret key in a watchdog table is not something
 * that can be un-logged.
 *
 * What it removes:
 * - Tap secret and public keys (`sk_…`, `pk_…`) and card tokens (`tok_…`).
 * - `Authorization` header values.
 * - Card objects and PANs.
 * - Email addresses and long digit runs that could be a phone or a card.
 *
 * @internal
 *   Injected as a service; call it, do not subclass it.
 */
final class LogSanitizer {

  /**
   * What every redacted value is replaced with.
   */
  public const REDACTED = '[redacted]';

  /**
   * Context and body keys whose value is dropped whole, whatever it holds.
   */
  private const SENSITIVE_KEYS = [
    'authorization',
    'card',
    'card_number',
    'cvv',
    'cvc',
    'email',
    'first_six',
    'first_eight',
    'last_four',
    'number',
    'pan',
    'password',
    'phone',
    'pk',
    'public_key',
    'secret',
    'secret_key',
    'sk',
    'token',
  ];

  /**
   * Patterns replaced anywhere they appear in a string.
   *
   * Ordered widest-first: the header rule has to win before the bare-key rule
   * sees the same text, or the word `Bearer` would survive on its own.
   */
  private const PATTERNS = [
    // The scheme is part of the value: matching only up to "Bearer" would
    // redact the word and leave the token standing next to it.
    '/\bauthorization\s*[:=]\s*(?:bearer\s+)?[^\s,;}\]]+/i' => 'authorization: ' . self::REDACTED,
    '/\bbearer\s+[^\s,;}\]]+/i' => 'Bearer ' . self::REDACTED,
    '/\b(?:sk|pk)_(?:test|live)_[A-Za-z0-9]+/' => self::REDACTED,
    '/\btok_[A-Za-z0-9]+/' => self::REDACTED,
    '/\bcard_[A-Za-z0-9]+/' => self::REDACTED,
    '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => self::REDACTED,
    '/\b\d[\d \-]{11,}\d\b/' => self::REDACTED,
  ];

  /**
   * Cleans a log message.
   *
   * @param string $message
   *   The message, possibly holding a secret.
   *
   * @return string
   *   The message with every known secret shape replaced.
   */
  public function sanitizeMessage(string $message): string {
    foreach (self::PATTERNS as $pattern => $replacement) {
      $message = (string) preg_replace($pattern, $replacement, $message);
    }

    return $message;
  }

  /**
   * Cleans a structure destined for a log context or a debug dump.
   *
   * Keys are matched case-insensitively and on their last path segment, so
   * both `card` and `source.card` are caught.
   *
   * @param mixed $value
   *   Any value; arrays are walked, scalars are cleaned, objects are dropped
   *   because there is no safe general way to inspect one.
   *
   * @return mixed
   *   The cleaned value.
   */
  public function sanitize(mixed $value): mixed {
    if (is_string($value)) {
      return $this->sanitizeMessage($value);
    }

    if (is_array($value)) {
      $clean = [];

      foreach ($value as $key => $item) {
        $clean[$key] = $this->isSensitiveKey((string) $key) ? self::REDACTED : $this->sanitize($item);
      }

      return $clean;
    }

    if (is_object($value)) {
      return self::REDACTED;
    }

    return $value;
  }

  /**
   * Whether a key names something that must not be logged.
   *
   * @param string $key
   *   The array key or header name.
   *
   * @return bool
   *   TRUE when the value should be dropped whole.
   */
  private function isSensitiveKey(string $key): bool {
    $needle = strtolower(trim($key));
    $needle = str_contains($needle, '.') ? substr(strrchr($needle, '.') ?: '', 1) : $needle;

    return in_array($needle, self::SENSITIVE_KEYS, TRUE);
  }

}
