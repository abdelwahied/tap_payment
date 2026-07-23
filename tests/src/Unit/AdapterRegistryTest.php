<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tap_payment\Api\Adapter\AdapterRegistry;
use Drupal\tap_payment\Api\Adapter\TapApiAdapterInterface;
use Drupal\tap_payment\Api\Adapter\TapV2Adapter;
use Drupal\tap_payment\Exception\ConfigurationException;
use Drupal\tap_payment\Utility\CurrencyDecimals;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests that a future API version needs no change above the adapter layer.
  *
  * @covers \Drupal\tap_payment\Api\Adapter\AdapterRegistry
 */
#[CoversClass(AdapterRegistry::class)]
final class AdapterRegistryTest extends UnitTestCase {

  /**
   * The registry serves the adapter for the configured version.
   */
  public function testSelectsTheConfiguredVersion(): void {
    $v2 = new TapV2Adapter(new CurrencyDecimals([]));
    $v3 = $this->stubAdapter('v3');

    $registry = new AdapterRegistry([$v2, $v3], 'v2');

    $this->assertSame($v2, $registry->active());
    $this->assertSame(['v2', 'v3'], $registry->versions());
  }

  /**
   * Pinning a different version picks a different adapter, and nothing else.
   *
   * This is the whole point of the layer: the module above it is unchanged.
   */
  public function testPinningAnotherVersionSwapsTheAdapter(): void {
    $v3 = $this->stubAdapter('v3');
    $registry = new AdapterRegistry([new TapV2Adapter(new CurrencyDecimals([])), $v3], 'v3');

    $this->assertSame($v3, $registry->active());
  }

  /**
   * A version with no adapter fails loudly, naming what is available.
   */
  public function testUnknownVersionIsRefused(): void {
    $registry = new AdapterRegistry([new TapV2Adapter(new CurrencyDecimals([]))], 'v9');

    $this->expectException(ConfigurationException::class);
    $this->expectExceptionMessage('available: v2');
    $registry->active();
  }

  /**
   * An empty registry says so rather than reporting an empty list.
   */
  public function testEmptyRegistry(): void {
    $registry = new AdapterRegistry([], 'v2');

    $this->assertSame([], $registry->versions());
    $this->expectException(ConfigurationException::class);
    $this->expectExceptionMessage('available: none');
    $registry->active();
  }

  /**
   * An adapter that reports a given version.
   *
   * @param string $version
   *   The version to report.
   *
   * @return \Drupal\tap_payment\Api\Adapter\TapApiAdapterInterface
   *   The stub.
   */
  private function stubAdapter(string $version): TapApiAdapterInterface {
    $adapter = $this->createMock(TapApiAdapterInterface::class);
    $adapter->method('version')->willReturn($version);

    return $adapter;
  }

}
