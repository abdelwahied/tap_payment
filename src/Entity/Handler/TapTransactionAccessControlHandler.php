<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Entity\Handler;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Decides who may see or change a payment record.
 *
 * The ledger is read-only from the outside. Nothing in this module offers a
 * form to edit or delete a transaction, and this handler makes that a rule
 * rather than an omission: a payment record is evidence of what happened, and
 * an evidence trail that can be edited is not one.
 *
 * Deleting is left to the site's own retention policy, and needs the
 * administration permission.
 *
 * @internal
 *   An access handler; not part of the public API.
 */
final class TapTransactionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermissions(
        $account,
        ['view tap payment transactions', 'administer tap payment'],
        'OR',
      ),
      'delete' => AccessResult::allowedIfHasPermission($account, 'administer tap payment'),
      // Including 'update': a transaction is written by the module from what
      // Tap reported, never by a person.
      default => AccessResult::forbidden('Tap payment transactions are a read-only ledger.'),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::forbidden('Tap payment transactions are created by the payment gateway, not by users.');
  }

}
