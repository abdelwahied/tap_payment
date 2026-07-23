<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Plugin\PaymentGateway;

use Drupal\Component\Plugin\PluginBase;

/**
 * Shared plumbing for payment gateway plugins.
 *
 * Only what every gateway needs regardless of provider: reading its own label
 * out of the plugin definition. Anything provider-specific stays in the
 * plugin, where it can be read in one place.
 *
 * @api
 *   Public and stable since 1.0.0. Extend this when writing a gateway.
 */
abstract class PaymentGatewayBase extends PluginBase implements PaymentGatewayInterface {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) ($this->pluginDefinition['label'] ?? $this->getPluginId());
  }

}
