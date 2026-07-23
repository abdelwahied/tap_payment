<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Entity\Handler;

use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Puts the ledger's guarantees in the database rather than in PHP.
 *
 * Two independent unique keys, each guarding the same mistake from a different
 * direction — because a duplicate payment is the one error a gateway must never
 * make, and one safeguard is one point of failure:
 *
 * - `idempotency_key` is unique on *this* side. Checking for an existing row
 *   first and inserting second is a race two concurrent checkouts can both win;
 *   a unique index cannot be raced. This is what stops the site opening two
 *   charges for one order.
 * - `charge_id` is unique on *Tap's* side. Even if the idempotency key were
 *   mis-generated or reused by a bug, the same Tap charge arriving twice — from
 *   a retried webhook, a browser return, and the reconciliation queue all at
 *   once — can still only ever be recorded on one ledger row. The column is
 *   nullable (a row exists before Tap issues a charge), and every supported
 *   database treats NULLs as distinct in a unique index, so any number of
 *   not-yet-created rows coexist while every issued charge stays singular.
 *
 * `state` gets an ordinary index because it is swept by the reconciliation
 * queue and counted on the status report.
 *
 * @internal
 *   A storage handler; not part of the public API.
 */
final class TapTransactionStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping): array {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);
    $field_name = $storage_definition->getName();

    if ($table_name !== $this->storage->getBaseTable()) {
      return $schema;
    }

    match ($field_name) {
      'idempotency_key', 'charge_id' => $schema['unique keys'][$field_name] = [$field_name],
      'state' => $schema['indexes'][$field_name] = [$field_name],
      default => NULL,
    };

    return $schema;
  }

}
