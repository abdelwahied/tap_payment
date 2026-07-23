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
   */
  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

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

}
