<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Kernel;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountInterface;
use Drupal\tap_payment\Entity\TapTransaction;
use Drupal\tap_payment\Entity\Handler\TapTransactionAccessControlHandler;
use Drupal\tap_payment\Enum\PaymentState;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the ledger's storage guarantees and its read-only access rules.
  *
  * @covers \Drupal\tap_payment\Entity\TapTransaction
  * @covers \Drupal\tap_payment\Entity\Handler\TapTransactionAccessControlHandler
  *
  * @runTestsInSeparateProcesses
 */
#[CoversClass(TapTransaction::class)]
#[CoversClass(TapTransactionAccessControlHandler::class)]
#[RunTestsInSeparateProcesses]
final class TransactionLedgerTest extends TapPaymentKernelTestBase {

  /**
   * The database refuses a second row with the same idempotency key.
   *
   * Checking in PHP before inserting is a race two concurrent checkouts can
   * both win; a unique index cannot be raced. This test is the proof that the
   * guarantee lives in the schema, not in a query.
   */
  public function testIdempotencyKeyIsUniqueInTheDatabase(): void {
    $this->makeTransaction('order-1')->save();

    try {
      $this->makeTransaction('order-1')->save();
      $this->fail('A duplicate idempotency key must be refused by the database.');
    }
    catch (EntityStorageException $e) {
      // Entity storage wraps the driver error; the constraint violation is the
      // one underneath, and that is what the payment service unwraps to
      // recognise a concurrent insert.
      $this->assertInstanceOf(IntegrityConstraintViolationException::class, $e->getPrevious());
    }
  }

  /**
   * Different keys coexist happily.
   */
  public function testDifferentKeysCoexist(): void {
    $this->makeTransaction('order-1')->save();
    $this->makeTransaction('order-2')->save();

    $this->assertCount(2, $this->container->get('entity_type.manager')
      ->getStorage('tap_payment_transaction')
      ->loadMultiple());
  }

  /**
   * The database refuses a second row holding the same Tap charge id.
   *
   * This is the second, independent layer of duplicate protection: even if the
   * idempotency key were mis-generated, the same charge from Tap can only ever
   * be recorded once.
   */
  public function testChargeIdIsUniqueInTheDatabase(): void {
    $first = $this->makeTransaction('order-1');
    $first->setChargeId('chg_1')->save();

    $second = $this->makeTransaction('order-2');
    $second->setChargeId('chg_1');

    try {
      $second->save();
      $this->fail('A duplicate Tap charge id must be refused by the database.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(IntegrityConstraintViolationException::class, $e->getPrevious());
    }
  }

  /**
   * Rows without a charge id yet do not collide with one another.
   *
   * The column is nullable until Tap issues a charge, and any number of
   * not-yet-created payments must be able to sit alongside each other.
   */
  public function testManyRowsWithoutChargeIdCoexist(): void {
    $this->makeTransaction('order-1')->save();
    $this->makeTransaction('order-2')->save();
    $this->makeTransaction('order-3')->save();

    $count = (int) $this->container->get('entity_type.manager')
      ->getStorage('tap_payment_transaction')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('charge_id', NULL, 'IS NULL')
      ->count()
      ->execute();

    $this->assertSame(3, $count);
  }

  /**
   * The stored amount comes back byte for byte.
   *
   * A float column would round `1.000` to `1`, and the webhook signature is
   * computed over the rendered amount — so the ledger has to be exact.
   */
  public function testAmountsSurviveStorageExactly(): void {
    $transaction = $this->makeTransaction('order-1');
    $transaction->set('amount', '1.000')->set('currency', 'KWD')->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('tap_payment_transaction');
    $storage->resetCache();
    $reloaded = $storage->load($transaction->id());

    $this->assertSame('1.000', $reloaded->getMoney()->amount);
    $this->assertSame('1.000', $reloaded->getMoney()->format(3));
  }

  /**
   * A row whose state cannot be read is treated as open, never as settled.
   */
  public function testUnreadableStateIsNotAnOutcome(): void {
    $transaction = $this->makeTransaction('order-1');
    $transaction->set('state', 'GIBBERISH')->save();

    $this->assertSame(PaymentState::Unknown, $transaction->getState());
    $this->assertFalse($transaction->getState()->isFinal());
    $this->assertFalse($transaction->isPaid());
  }

  /**
   * The cancel destination falls back to the return destination.
   */
  public function testCancelUrlFallsBack(): void {
    $transaction = $this->makeTransaction('order-1');
    $transaction->set('cancel_url', NULL)->save();

    $this->assertSame('/thank-you', $transaction->getCancelUrl());
  }

  /**
   * The ledger is read-only: nobody may edit or create a payment record.
   */
  public function testLedgerCannotBeEditedByAnyone(): void {
    $this->installEntitySchema('user');

    $transaction = $this->makeTransaction('order-1');
    $transaction->save();

    $admin = $this->createUser(['administer tap payment']);
    $auditor = $this->createUser(['view tap payment transactions']);
    $nobody = $this->createUser([]);

    $this->assertTrue($transaction->access('view', $admin));
    $this->assertTrue($transaction->access('view', $auditor));
    $this->assertFalse($transaction->access('view', $nobody));

    // Not even an administrator: a payment record is evidence, and evidence
    // that can be edited is not evidence.
    $this->assertFalse($transaction->access('update', $admin));

    $this->assertTrue($transaction->access('delete', $admin));
    $this->assertFalse($transaction->access('delete', $auditor));

    $handler = $this->container->get('entity_type.manager')
      ->getAccessControlHandler('tap_payment_transaction');
    $this->assertFalse($handler->createAccess(NULL, $admin));
  }

  /**
   * Builds an unsaved transaction.
   *
   * @param string $key
   *   The idempotency key.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The transaction.
   */
  private function makeTransaction(string $key) {
    return $this->container->get('entity_type.manager')
      ->getStorage('tap_payment_transaction')
      ->create([
        'idempotency_key' => $key,
        'state' => PaymentState::Initiated->value,
        'amount' => '1.000',
        'currency' => 'KWD',
        'gateway' => 'tap',
        'return_url' => '/thank-you',
      ]);
  }

  /**
   * Creates a user holding the given permissions.
   *
   * @param array<int, string> $permissions
   *   The permissions to grant.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The user.
   */
  private function createUser(array $permissions): AccountInterface {
    $role = $this->container->get('entity_type.manager')->getStorage('user_role')->create([
      'id' => 'role_' . substr(md5(implode(',', $permissions) . count($permissions)), 0, 8),
      'label' => 'Test role',
      'permissions' => $permissions,
    ]);
    $role->save();

    $user = User::create([
      'name' => 'user_' . $role->id(),
      'status' => 1,
      'roles' => [$role->id()],
    ]);
    $user->save();

    return $user;
  }

}
