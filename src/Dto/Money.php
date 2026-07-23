<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Dto;

use Drupal\tap_payment\Exception\InvalidPaymentRequestException;

/**
 * An amount and the currency it is denominated in.
 *
 * The amount is kept as a decimal *string*, never a float. Tap's webhook
 * signature is computed over the amount rendered to the currency's own number
 * of decimals, so a value that drifts by one unit in the last place turns a
 * legitimate webhook into a rejected one. A string survives every round trip
 * through JSON, the database and the hash unchanged; a float does not.
 *
 * @api
 *   Public and stable since 1.0.0.
 *
 * @see \Drupal\tap_payment\Utility\CurrencyDecimals
 */
final class Money {

  /**
   * Constructs a Money.
   *
   * @param string $amount
   *   A positive decimal amount, e.g. `10.500`.
   * @param string $currency
   *   An upper-case three-letter ISO 4217 code.
   *
   * @throws \Drupal\tap_payment\Exception\InvalidPaymentRequestException
   *   When the amount is not a positive decimal or the code is malformed.
   */
  public function __construct(
    public readonly string $amount,
    public readonly string $currency,
  ) {
    if (preg_match('/^\d+(\.\d+)?$/', $amount) !== 1 || (float) $amount <= 0.0) {
      throw new InvalidPaymentRequestException('The payment amount must be a positive decimal number.');
    }

    if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
      throw new InvalidPaymentRequestException('The currency must be a three-letter ISO 4217 code in upper case.');
    }
  }

  /**
   * Builds a Money from whatever a caller happens to hold.
   *
   * Integrations hand over ints, floats and strings interchangeably; this is
   * the single place that normalises them, so the constructor can stay strict.
   *
   * @param int|float|string $amount
   *   The amount.
   * @param string $currency
   *   The currency code, in any case.
   *
   * @return self
   *   The normalised amount.
   *
   * @throws \Drupal\tap_payment\Exception\InvalidPaymentRequestException
   *   When the value cannot be read as a positive amount.
   */
  public static function fromNumeric(int|float|string $amount, string $currency): self {
    if (is_float($amount)) {
      // Enough places for the widest documented currency, trailing zeros
      // trimmed so the canonical form does not depend on how it arrived.
      $amount = rtrim(rtrim(sprintf('%.6F', $amount), '0'), '.');
    }

    return new self(trim((string) $amount), strtoupper(trim($currency)));
  }

  /**
   * The amount rendered to a fixed number of decimals.
   *
   * @param int $decimals
   *   How many decimal places the currency uses.
   *
   * @return string
   *   The amount, e.g. `10.500`.
   */
  public function format(int $decimals): string {
    return number_format((float) $this->amount, $decimals, '.', '');
  }

  /**
   * The amount as a JSON number, rounded to the currency's own precision.
   *
   * @param int $decimals
   *   How many decimal places the currency uses.
   *
   * @return float
   *   The amount for the request body.
   */
  public function toNumber(int $decimals): float {
    return round((float) $this->amount, $decimals);
  }

  /**
   * Whether two amounts are the same money.
   *
   * @param self $other
   *   The amount to compare with.
   * @param int $decimals
   *   The precision to compare at.
   *
   * @return bool
   *   TRUE when currency and rounded amount both match.
   */
  public function equals(self $other, int $decimals): bool {
    return $this->currency === $other->currency
      && $this->format($decimals) === $other->format($decimals);
  }

}
