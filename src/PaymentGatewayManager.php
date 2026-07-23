<?php

declare(strict_types=1);

namespace Drupal\tap_payment;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\tap_payment\Attribute\PaymentGateway;
use Drupal\tap_payment\Plugin\PaymentGateway\PaymentGatewayInterface;

/**
 * Discovers payment gateway plugins.
 *
 * Attribute discovery only — no annotation fallback. The module requires PHP
 * 8.3 and Drupal 10.3, both of which understand attributes, and carrying a
 * parallel annotation class would mean two declarations of the same plugin
 * that can silently disagree.
 *
 * @api
 *   Public and stable since 1.0.0. Injected as
 *   `plugin.manager.tap_payment_gateway`.
 */
final class PaymentGatewayManager extends DefaultPluginManager {

  /**
   * Constructs a PaymentGatewayManager.
   *
   * @param \Traversable $namespaces
   *   Root paths keyed by namespace.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The discovery cache.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/PaymentGateway',
      $namespaces,
      $module_handler,
      PaymentGatewayInterface::class,
      PaymentGateway::class,
    );

    $this->alterInfo('tap_payment_gateway_info');
    $this->setCacheBackend($cache_backend, 'tap_payment_gateway_plugins');
  }

  /**
   * Loads a gateway plugin.
   *
   * @param string $pluginId
   *   The plugin id.
   *
   * @return \Drupal\tap_payment\Plugin\PaymentGateway\PaymentGatewayInterface
   *   The gateway.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *   When no such gateway exists.
   */
  public function getGateway(string $pluginId): PaymentGatewayInterface {
    $gateway = $this->createInstance($pluginId);
    assert($gateway instanceof PaymentGatewayInterface);

    return $gateway;
  }

}
