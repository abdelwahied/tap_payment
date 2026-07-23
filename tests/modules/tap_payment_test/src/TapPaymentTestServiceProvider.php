<?php

declare(strict_types=1);

namespace Drupal\tap_payment_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\tap_payment\Api\TapApiClientInterface;

/**
 * Points the module's transport at the stub, everywhere.
 *
 * Done by altering the compiled container rather than by redeclaring the alias
 * in a services.yml, so it cannot depend on module weight: whatever order the
 * modules are installed in, the alias ends up here.
 *
 * The gateway plugin, the payment service and the webhook processor are all
 * left untouched — the substitution happens at the one seam the architecture
 * put there for it, which is the point being demonstrated.
 *
 * @internal
 *   Test support.
 */
final class TapPaymentTestServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->has('tap_payment.api_client')) {
      return;
    }

    $container->setAlias(TapApiClientInterface::class, 'tap_payment_test.api_client');
    $container->setAlias('tap_payment.api_client', 'tap_payment_test.api_client');
  }

}
