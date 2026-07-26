<?php

declare(strict_types=1);

namespace Drupal\tap_payment_custom;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;

/**
 * Typed, read-only access to what the standalone form collects.
 *
 * @internal
 *   Injected as a service.
 */
final class FormSettings {

  public const CONFIG_NAME = 'tap_payment_custom.settings';

  /**
   * Constructs a FormSettings.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param int $defaultFloodLimit
   *   Payment starts allowed per payer per window when config says nothing.
   * @param int $defaultFloodWindow
   *   The throttling window in seconds when config says nothing.
   * @param int $defaultFloodIpMultiplier
   *   How much looser the per-IP bucket is when config says nothing.
   * @param int $defaultIdempotencyLifetime
   *   Idempotency key lifetime in seconds when config says nothing.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly int $defaultFloodLimit = 10,
    private readonly int $defaultFloodWindow = 3600,
    private readonly int $defaultFloodIpMultiplier = 10,
    private readonly int $defaultIdempotencyLifetime = 900,
  ) {}

  /**
   * What the form charges.
   *
   * @return \Drupal\tap_payment\Dto\Money
   *   The configured amount and currency.
   *
   * @throws \Drupal\tap_payment\Exception\InvalidPaymentRequestException
   *   When the configured amount or currency is unusable.
   */
  public function money(): Money {
    $config = $this->configFactory->get(self::CONFIG_NAME);

    return Money::fromNumeric(
      (string) ($config->get('amount') ?? '0'),
      (string) ($config->get('currency') ?? 'KWD'),
    );
  }

  /**
   * What the payer sees the payment called on Tap's page.
   *
   * @return string|null
   *   The description, or NULL when none is set.
   */
  public function description(): ?string {
    $value = trim((string) ($this->configFactory->get(self::CONFIG_NAME)->get('description') ?? ''));

    return $value === '' ? NULL : $value;
  }

  /**
   * Which Tap payment source the hosted page opens with.
   *
   * @return string
   *   The source id.
   */
  public function sourceId(): string {
    $value = trim((string) ($this->configFactory->get(self::CONFIG_NAME)->get('source_id') ?? ''));

    return $value === '' ? PaymentRequest::SOURCE_ALL : $value;
  }

  /**
   * Payment starts one payer may make within the window.
   *
   * @return int
   *   The limit, always at least 1.
   */
  public function floodLimit(): int {
    return $this->positive('flood_limit', $this->defaultFloodLimit);
  }

  /**
   * The throttling window.
   *
   * @return int
   *   The window in seconds, always at least 1.
   */
  public function floodWindow(): int {
    return $this->positive('flood_window', $this->defaultFloodWindow);
  }

  /**
   * How much looser the per-IP bucket is than the per-payer one.
   *
   * @return int
   *   The multiplier, always at least 1.
   */
  public function floodIpMultiplier(): int {
    return $this->positive('flood_ip_multiplier', $this->defaultFloodIpMultiplier);
  }

  /**
   * How long an identical submission rejoins the payment it already started.
   *
   * @return int
   *   The lifetime in seconds, always at least 1.
   */
  public function idempotencyLifetime(): int {
    return $this->positive('idempotency_lifetime', $this->defaultIdempotencyLifetime);
  }

  /**
   * Whether attempts are counted per session or account.
   *
   * @return bool
   *   TRUE when the session bucket applies.
   */
  public function throttleBySession(): bool {
    return (bool) ($this->configFactory->get(self::CONFIG_NAME)->get('throttle_by_session') ?? TRUE);
  }

  /**
   * Whether attempts are counted per payer email.
   *
   * @return bool
   *   TRUE when the email bucket applies.
   */
  public function throttleByEmail(): bool {
    return (bool) ($this->configFactory->get(self::CONFIG_NAME)->get('throttle_by_email') ?? TRUE);
  }

  /**
   * A stored positive integer, or the module's own default.
   *
   * Zero and anything unusable mean "not chosen", which is what lets a site
   * inherit a later change to the shipped default instead of freezing today's
   * value into its configuration.
   *
   * @param string $key
   *   The configuration key.
   * @param int $default
   *   The value to fall back to.
   *
   * @return int
   *   A positive integer.
   */
  private function positive(string $key, int $default): int {
    $value = (int) ($this->configFactory->get(self::CONFIG_NAME)->get($key) ?? 0);

    return $value > 0 ? $value : max(1, $default);
  }

}
