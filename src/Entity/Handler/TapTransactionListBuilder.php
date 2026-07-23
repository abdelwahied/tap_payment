<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Entity\Handler;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use Drupal\tap_payment\Entity\TapTransactionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists payment transactions for an administrator.
 *
 * Newest first, because the only question anyone opens this page with is about
 * a payment that just happened. Every cell is a plain string rendered through
 * the render array, so Tap's own values — which are remote input — are escaped
 * by the theme layer and never by hand.
 *
 * @internal
 *   A list builder; not part of the public API.
 */
final class TapTransactionListBuilder extends EntityListBuilder {

  /**
   * Constructs a TapTransactionListBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The transaction storage.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Formats the creation time in the viewer's timezone.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's log channel.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected DateFormatterInterface $dateFormatter,
    protected LoggerChannelInterface $logger,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
      $container->get('logger.channel.tap_payment'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    return $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->pager($this->limit)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'created' => $this->t('Created'),
      'charge_id' => $this->t('Charge'),
      'state' => $this->t('State'),
      'amount' => $this->t('Amount'),
      'mode' => $this->t('Mode'),
      'context' => $this->t('Requested by'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof TapTransactionInterface);

    $money = NULL;

    try {
      $money = $entity->getMoney();
    }
    catch (\Throwable $e) {
      // A row whose amount cannot be read is still worth listing — it is
      // exactly the row somebody is looking for.
      Error::logException($this->logger, $e);
    }

    $row = [
      'created' => $this->dateFormatter->format($entity->getCreatedTime(), 'short'),
      'charge_id' => $entity->getChargeId() ?? $this->t('Not created'),
      'state' => $entity->getState()->value,
      'amount' => $money === NULL ? $this->t('Unreadable') : $money->amount . ' ' . $money->currency,
      'mode' => $entity->isLiveMode() ? $this->t('Live') : $this->t('Test'),
      'context' => $entity->getContextModule() ?? $this->t('None'),
    ];

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No payments have been attempted yet.');

    return $build;
  }

}
