<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\tap_payment\Enum\PaymentState;

/**
 * Chases up payments nobody ever told the site about.
 *
 * Two things Tap documents make this necessary rather than defensive:
 *
 * - A webhook is attempted three times in total and then marked ERROR. If the
 *   site was down, or a certificate was briefly invalid, that is the end of it
 *   — Tap will not try again, and a captured payment stays `INITIATED` here
 *   forever.
 * - The payer may simply never come back. They close the tab after paying, and
 *   the return route never runs.
 *
 * Between those two, an unattended site can hold money it does not know it has.
 * So every cron run collects the transactions that are still open past the
 * point where Tap's own transaction expiry says they should have resolved, and
 * queues them to be re-read. Re-reading is idempotent by construction: it goes
 * through the same state machine as everything else, so a payment that was
 * already settled by a webhook a second earlier changes nothing.
 *
 * Queued rather than done inline because each item is an HTTP round trip, and a
 * cron run that makes a hundred of them serially is a cron run that times out.
 *
 * @internal
 *   Injected as a service.
 *
 * @see https://developers.tap.company/docs/webhook
 */
final class PaymentReconciler {

  /**
   * The queue that holds transactions waiting to be re-read.
   */
  public const QUEUE_NAME = 'tap_payment_reconciliation';

  /**
   * Constructs a PaymentReconciler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Supplies the transaction storage.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Supplies the reconciliation queue.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The request time.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's log channel.
   * @param int $graceSeconds
   *   How long a payment may stay open before it is chased.
   * @param int $maxAgeSeconds
   *   How far back to look. Beyond this a payment is left alone: Tap's own
   *   records are the place to settle something that old, and re-reading every
   *   abandoned checkout since launch on every cron run is not reconciliation,
   *   it is a self-inflicted rate limit.
   * @param int $batchSize
   *   How many transactions to queue per cron run.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
    private readonly int $graceSeconds,
    private readonly int $maxAgeSeconds,
    private readonly int $batchSize,
  ) {}

  /**
   * Queues every payment that has been open too long.
   *
   * @return int
   *   How many were queued.
   */
  public function queueStale(): int {
    $now = $this->time->getRequestTime();

    $ids = $this->entityTypeManager->getStorage('tap_payment_transaction')->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', $this->openStates(), 'IN')
      ->condition('charge_id', NULL, 'IS NOT NULL')
      ->condition('changed', $now - $this->graceSeconds, '<')
      ->condition('changed', $now - $this->maxAgeSeconds, '>')
      ->sort('changed', 'ASC')
      ->range(0, $this->batchSize)
      ->execute();

    if ($ids === []) {
      return 0;
    }

    $queue = $this->queueFactory->get(self::QUEUE_NAME);

    foreach ($ids as $id) {
      $queue->createItem(['transaction_id' => (int) $id]);
    }

    $this->logger->info('Queued @count Tap payment(s) for reconciliation.', ['@count' => count($ids)]);

    return count($ids);
  }

  /**
   * The states a payment can still move out of.
   *
   * @return array<int, string>
   *   The open state values.
   */
  private function openStates(): array {
    return array_values(array_map(
      static fn (PaymentState $state): string => $state->value,
      array_filter(PaymentState::cases(), static fn (PaymentState $state): bool => !$state->isFinal()),
    ));
  }

}
