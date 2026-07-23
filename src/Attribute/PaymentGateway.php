<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Marks a class as a payment gateway plugin.
 *
 * Drupal core has no payment gateway concept, and Drupal Commerce's is bound
 * to Commerce's own order and payment entities. This module needs a third
 * thing: a gateway any module can drive, whether or not Commerce is installed.
 * So it defines its own plugin type — this attribute is its discovery marker.
 *
 * A gateway plugin is the *only* place that knows a payment provider exists.
 * Everything above it — the payment service, the events, the ledger — is
 * written against \Drupal\tap_payment\Plugin\PaymentGateway\
 * PaymentGatewayInterface, which is why supporting a second provider one day
 * means adding a plugin rather than editing this module.
 *
 * @api
 *   Public and stable since 1.0.0.
 *
 * @Attribute
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PaymentGateway extends Plugin {

  /**
   * Constructs a PaymentGateway attribute.
   *
   * @param string $id
   *   The plugin id.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The human-readable name, shown wherever a gateway is chosen.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   An optional one-line description.
   * @param class-string|null $deriver
   *   An optional deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
