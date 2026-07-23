<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tap_payment\Entity\TapTransactionInterface;
use Drupal\tap_payment\Exception\ApiException;
use Drupal\tap_payment\Exception\ConfigurationException;
use Drupal\tap_payment\Exception\RateLimitException;
use Drupal\tap_payment\TapPaymentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-reads one open payment from Tap.
 *
 * The failure handling is the substance here. A queue item that throws is
 * released and tried again later, which is right for a transient problem and
 * wrong for a permanent one — and telling them apart is what stops a
 * misconfigured site from hammering Tap once a minute forever:
 *
 * - Missing credentials, or a charge Tap will not talk about, cannot be fixed
 *   by retrying. The item is dropped and the reason logged.
 * - Rate limiting is Tap asking for quiet. The whole queue is suspended for
 *   this cron run rather than the item retried, because every other item would
 *   hit the same wall.
 * - Anything else is transient until proven otherwise: the item is released and
 *   comes back on the next run.
 *
 * @internal
 *   A queue worker; not part of the public API.
 */
#[QueueWorker(
  id: 'tap_payment_reconciliation',
  title: new TranslatableMarkup('Tap payment reconciliation'),
  cron: ['time' => 30],
)]
final class PaymentReconciliationWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a PaymentReconciliationWorker.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\tap_payment\TapPaymentInterface $payments
   *   Re-reads the charge from Tap.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the transaction.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's log channel.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected TapPaymentInterface $payments,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('tap_payment.payment'),
      $container->get('entity_type.manager'),
      $container->get('logger.channel.tap_payment'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $id = is_array($data) ? (int) ($data['transaction_id'] ?? 0) : 0;

    if ($id === 0) {
      return;
    }

    $transaction = $this->entityTypeManager->getStorage('tap_payment_transaction')->load($id);

    if (!$transaction instanceof TapTransactionInterface || $transaction->getState()->isFinal()) {
      // Settled between being queued and being processed — which is the normal
      // case when a webhook arrived in the meantime.
      return;
    }

    try {
      $this->payments->verifyPayment($transaction);
    }
    catch (RateLimitException $e) {
      throw new SuspendQueueException('Tap is rate limiting this site; reconciliation will resume on the next cron run.', 0, $e);
    }
    catch (ConfigurationException | ApiException $e) {
      // Neither a missing key nor a charge Tap rejects will resolve by being
      // asked again in a minute. Drop the item; the next cron sweep re-queues
      // the transaction if it is still open, by which time an administrator
      // may have fixed the cause.
      $this->logger->warning('Gave up reconciling Tap transaction @id for now: @reason', [
        '@id' => $id,
        '@reason' => $e->getMessage(),
      ]);
    }
  }

}
