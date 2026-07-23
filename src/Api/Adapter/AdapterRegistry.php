<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Api\Adapter;

use Drupal\tap_payment\Exception\ConfigurationException;

/**
 * Finds the adapter that speaks the API version the site is pinned to.
 *
 * Adapters are collected from the `tap_payment_api_adapter` tag, so a new API
 * version is a class and a service definition — in this module or in another
 * one — and never an edit to a switch statement here.
 *
 * The version is a container parameter rather than a setting on the
 * administration form on purpose. It is not a decision a site owner is in a
 * position to make: choosing an API version the deployed code has no adapter
 * for would break every payment, and the failure would not look like a
 * configuration mistake. A site that genuinely needs to pin a different version
 * overrides the parameter in its own services.yml, deliberately.
 *
 * @internal
 *   Injected as a service.
 */
final class AdapterRegistry {

  /**
   * The collected adapters, keyed by version.
   *
   * @var array<string, \Drupal\tap_payment\Api\Adapter\TapApiAdapterInterface>
   */
  private array $adapters = [];

  /**
   * Constructs an AdapterRegistry.
   *
   * @param iterable<\Drupal\tap_payment\Api\Adapter\TapApiAdapterInterface> $adapters
   *   Every service tagged `tap_payment_api_adapter`.
   * @param string $version
   *   The API version to use.
   */
  public function __construct(iterable $adapters, private readonly string $version) {
    foreach ($adapters as $adapter) {
      $this->adapters[$adapter->version()] = $adapter;
    }
  }

  /**
   * The adapter for the configured version.
   *
   * @return \Drupal\tap_payment\Api\Adapter\TapApiAdapterInterface
   *   The active adapter.
   *
   * @throws \Drupal\tap_payment\Exception\ConfigurationException
   *   When nothing implements the configured version.
   */
  public function active(): TapApiAdapterInterface {
    if (!isset($this->adapters[$this->version])) {
      throw new ConfigurationException(sprintf(
        'No Tap API adapter is registered for version "%s"; available: %s.',
        $this->version,
        $this->versions() === [] ? 'none' : implode(', ', $this->versions()),
      ));
    }

    return $this->adapters[$this->version];
  }

  /**
   * Every version an adapter has been registered for.
   *
   * @return array<int, string>
   *   The known versions.
   */
  public function versions(): array {
    return array_keys($this->adapters);
  }

}
