<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\tap_payment\Enum\Environment;
use Drupal\tap_payment\Exception\ConfigurationException;

/**
 * Typed, read-only access to tap_payment.settings.
 *
 * Config arrives as mixed, and every consumer would otherwise repeat the same
 * cast-and-default dance — which is where an empty string quietly becomes a
 * request sent with no credentials. This is the one place that knows the shape
 * of the configuration.
 *
 * It reads through the config factory on every call rather than caching:
 * saving the settings form is a request like any other, and a stale secret key
 * is a bug that only shows up in production.
 *
 * The active key is chosen by the configured environment and checked against
 * the prefix Tap issues for it, so a live key pasted into the sandbox field
 * fails here rather than by charging somebody.
 *
 * @api
 *   Public and stable since 1.0.0. The config keys it reads are part of the
 *   contract; the values it returns never appear in a log or an exception.
 */
final class TapPaymentSettings {

  public const CONFIG_NAME = 'tap_payment.settings';

  /**
   * Constructs a TapPaymentSettings.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  /**
   * The immutable settings object.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The module's configuration.
   */
  public function config(): ImmutableConfig {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

  /**
   * The environment payments are created in.
   *
   * Falls back to the sandbox: if the setting is missing or corrupt, the safe
   * reading is "not live yet", never "charge real cards".
   *
   * @return \Drupal\tap_payment\Enum\Environment
   *   The configured environment.
   */
  public function environment(): Environment {
    $value = $this->config()->get('environment');

    return is_string($value)
      ? (Environment::tryFrom($value) ?? Environment::Sandbox)
      : Environment::Sandbox;
  }

  /**
   * Whether the module has everything it needs to talk to Tap.
   *
   * @return bool
   *   TRUE when the active environment has a usable secret key.
   */
  public function isConfigured(): bool {
    try {
      $this->secretKey();
      return TRUE;
    }
    catch (ConfigurationException) {
      return FALSE;
    }
  }

  /**
   * The secret key for the active environment.
   *
   * @return string
   *   The key, exactly as configured.
   *
   * @throws \Drupal\tap_payment\Exception\ConfigurationException
   *   When the key is missing or was issued for the other environment.
   */
  public function secretKey(): string {
    $environment = $this->environment();
    $key = trim((string) ($this->config()->get($this->secretKeyName($environment)) ?? ''));

    if ($key === '') {
      throw new ConfigurationException(sprintf(
        'No Tap secret key is configured for the %s environment.',
        $environment->value,
      ));
    }

    if (!$environment->matchesKey($key)) {
      throw new ConfigurationException(sprintf(
        'The configured %s secret key does not start with "%s"; it appears to belong to the other environment.',
        $environment->value,
        $environment->keyPrefix(),
      ));
    }

    return $key;
  }

  /**
   * The config key holding an environment's secret.
   *
   * @param \Drupal\tap_payment\Enum\Environment $environment
   *   The environment.
   *
   * @return string
   *   The config key name.
   */
  public function secretKeyName(Environment $environment): string {
    return match ($environment) {
      Environment::Sandbox => 'sandbox_secret_key',
      Environment::Production => 'live_secret_key',
    };
  }

  /**
   * Whether a secret key is stored for an environment.
   *
   * Used by the settings form to show that a key exists without ever putting
   * the key itself back into an HTML response.
   *
   * @param \Drupal\tap_payment\Enum\Environment $environment
   *   The environment to check.
   *
   * @return bool
   *   TRUE when a key is stored.
   */
  public function hasSecretKey(Environment $environment): bool {
    return trim((string) ($this->config()->get($this->secretKeyName($environment)) ?? '')) !== '';
  }

}
