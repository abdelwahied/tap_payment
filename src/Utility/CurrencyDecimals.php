<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Utility;

/**
 * How many decimal places a currency is written with.
 *
 * This exists for one reason: Tap's webhook signature is computed over the
 * amount *rendered to the currency's own precision*. Send `3.00` where the
 * documentation says `3.000` and the hash differs, the webhook is rejected,
 * and a captured payment silently never reaches the site. The examples in the
 * table below are Tap's own, quoted from the webhook page.
 *
 * The map arrives from a container parameter rather than being hard-coded, so
 * a site whose account is enabled for a currency this list has not heard of can
 * add it in its own `services.yml` without patching the module. Anything
 * unlisted falls back to two places, which is the ISO 4217 default and the
 * right answer for every currency Tap documents outside the Gulf.
 *
 * @internal
 *   Injected as a service; not part of the public payment API.
 *
 * @see https://developers.tap.company/docs/webhook
 */
final class CurrencyDecimals {

  /**
   * The ISO 4217 default, used for any currency not in the map.
   */
  public const DEFAULT_DECIMALS = 2;

  /**
   * Constructs a CurrencyDecimals.
   *
   * @param array<string, int> $decimals
   *   Decimal places keyed by upper-case ISO 4217 code.
   */
  public function __construct(private readonly array $decimals) {}

  /**
   * The number of decimal places a currency uses.
   *
   * @param string $currency
   *   An ISO 4217 code, in any case.
   *
   * @return int
   *   The decimal places, defaulting to two.
   */
  public function forCurrency(string $currency): int {
    $code = strtoupper(trim($currency));

    return isset($this->decimals[$code]) ? (int) $this->decimals[$code] : self::DEFAULT_DECIMALS;
  }

}
