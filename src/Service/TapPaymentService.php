<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\tap_payment\Dto\Payment;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Dto\PaymentSession;
use Drupal\tap_payment\Entity\TapTransactionInterface;
use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Event\PaymentCancelledEvent;
use Drupal\tap_payment\Event\PaymentCapturedEvent;
use Drupal\tap_payment\Event\PaymentCreatedEvent;
use Drupal\tap_payment\Event\PaymentFailedEvent;
use Drupal\tap_payment\Event\TapPaymentEvents;
use Drupal\tap_payment\Exception\InvalidPaymentRequestException;
use Drupal\tap_payment\Exception\TapPaymentException;
use Drupal\tap_payment\PaymentGatewayManager;
use Drupal\tap_payment\State\PaymentStateMachine;
use Drupal\tap_payment\TapPaymentInterface;
use Drupal\tap_payment\Utility\CurrencyDecimals;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Starts payments, confirms them, and keeps the ledger honest.
 *
 * The interesting logic is all about the same thing: the same outcome arriving
 * more than once, from more than one direction, possibly out of order.
 *
 * - **Starting twice.** The ledger's idempotency key is unique in the database,
 *   so two concurrent submissions cannot both insert. The loser catches the
 *   constraint violation, reloads, and joins the winner's payment. The same key
 *   also goes to Tap as `reference.idempotent`, which makes Tap return the
 *   original charge — including a still-valid hosted URL — instead of creating
 *   a second one.
 * - **Confirming twice.** Applying an outcome takes a per-charge lock, reloads
 *   the row inside it, and asks the state machine whether the move is legal. A
 *   webhook that arrives after the payer already came back finds a final state
 *   and changes nothing.
 * - **Confirming something else.** Before an outcome is applied, the charge id,
 *   the currency and the amount are checked against what the site asked for. A
 *   payload about a different charge, or one whose amount does not match, is
 *   refused even if its signature was valid — a signature proves origin, not
 *   relevance.
 *
 * @internal
 *   Injected behind \Drupal\tap_payment\TapPaymentInterface.
 */
final class TapPaymentService implements TapPaymentInterface {

  /**
   * Constructs a TapPaymentService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Supplies the transaction storage.
   * @param \Drupal\tap_payment\PaymentGatewayManager $gatewayManager
   *   Loads the gateway plugin a payment is made through.
   * @param \Drupal\tap_payment\State\PaymentStateMachine $stateMachine
   *   Decides which state changes are legal.
   * @param \Drupal\tap_payment\Service\InternalUrlValidator $urlValidator
   *   Refuses return URLs that point off this site.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $urlGenerator
   *   Builds the module's own return and webhook URLs.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   Generates an idempotency key when the caller did not supply one.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   Serialises concurrent updates to one charge.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   Announces lifecycle changes to other modules.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's log channel.
   * @param \Drupal\tap_payment\Utility\CurrencyDecimals $currencyDecimals
   *   Decides the precision amounts are compared at.
   * @param int $lockTimeout
   *   How long a per-charge lock is held, in seconds.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PaymentGatewayManager $gatewayManager,
    private readonly PaymentStateMachine $stateMachine,
    private readonly InternalUrlValidator $urlValidator,
    private readonly UrlGeneratorInterface $urlGenerator,
    private readonly UuidInterface $uuid,
    private readonly LockBackendInterface $lock,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly LoggerChannelInterface $logger,
    private readonly CurrencyDecimals $currencyDecimals,
    private readonly int $lockTimeout,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentRequest $request, string $gatewayId = 'tap'): PaymentSession {
    $this->assertInternal($request->returnUrl, 'return');
    $this->assertInternal($request->cancelUrl(), 'cancel');

    $key = $request->idempotencyKey ?? $this->uuid->generate();
    $transaction = $this->loadByIdempotencyKey($key) ?? $this->openTransaction($request, $gatewayId, $key);

    // A row that already carries a charge is a resumed attempt, not a new one.
    // Re-posting with the same idempotency key is Tap's documented way to get
    // the original charge back, hosted URL included, so a payer who reloaded
    // the checkout page lands on the page they left rather than on a second
    // charge.
    $gateway = $this->gatewayManager->getGateway($transaction->getGatewayId());

    if ($transaction->getState()->isFinal()) {
      return new PaymentSession($transaction, $this->describe($transaction));
    }

    $payment = $gateway->createCharge(
      $request,
      $this->returnUrl($transaction),
      $this->webhookUrl(),
      $key,
    );

    $wasNew = $transaction->getChargeId() === NULL;
    $recorded = $this->recordCharge($transaction, $payment);

    // Announce a genuinely new charge only when it landed on the row this call
    // started. On a charge-id collision recordCharge() defers to the row that
    // already holds the charge, which was announced when it was created.
    if ($wasNew && $recorded->id() === $transaction->id()) {
      $this->eventDispatcher->dispatch(
        new PaymentCreatedEvent($recorded, $payment),
        TapPaymentEvents::PAYMENT_CREATED,
      );
    }

    return new PaymentSession($recorded, $payment);
  }

  /**
   * {@inheritdoc}
   */
  public function verifyPayment(TapTransactionInterface $transaction): TapTransactionInterface {
    $chargeId = $transaction->getChargeId();

    if ($chargeId === NULL) {
      // Nothing was ever created at Tap, so there is nothing to verify. This
      // is not an error: it is what a payment abandoned before redirect looks
      // like.
      return $transaction;
    }

    $gateway = $this->gatewayManager->getGateway($transaction->getGatewayId());
    $this->applyOutcome($transaction, $gateway->retrieveCharge($chargeId));

    return $this->reload($transaction);
  }

  /**
   * Applies an outcome that has already been proven to come from Tap.
   *
   * Used by the webhook processor and by verifyPayment(). Everything that makes
   * repeat and out-of-order delivery safe happens here.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The transaction the outcome belongs to.
   * @param \Drupal\tap_payment\Dto\Payment $payment
   *   What Tap reported.
   *
   * @return bool
   *   TRUE when the transaction changed; FALSE when the outcome was a repeat,
   *   arrived too late to matter, or did not belong to this transaction.
   */
  public function applyOutcome(TapTransactionInterface $transaction, Payment $payment): bool {
    $lockId = 'tap_payment:charge:' . ($transaction->getChargeId() ?? $transaction->getIdempotencyKey());

    if (!$this->lock->acquire($lockId, (float) $this->lockTimeout)) {
      // Another request is already applying an outcome for this charge. Waiting
      // and re-reading is cheaper than risking two writers, and the other
      // request will have done the work by the time this one looks again.
      $this->lock->wait($lockId, $this->lockTimeout);
      $transaction = $this->reload($transaction);

      if ($transaction->getState()->isFinal()) {
        return FALSE;
      }

      if (!$this->lock->acquire($lockId, (float) $this->lockTimeout)) {
        $this->logger->warning('Could not lock Tap charge @charge to apply an outcome; leaving it for reconciliation.', [
          '@charge' => $payment->chargeId,
        ]);

        return FALSE;
      }
    }

    try {
      $transaction = $this->reload($transaction);

      if (!$this->belongsTo($transaction, $payment)) {
        return FALSE;
      }

      $from = $transaction->getState();

      if (!$this->stateMachine->canTransition($from, $payment->state)) {
        $this->logger->info('Ignored a @to outcome for Tap charge @charge: it is already @from.', [
          '@to' => $payment->state->value,
          '@charge' => $payment->chargeId,
          '@from' => $from->value,
        ]);

        return FALSE;
      }

      $transaction->setState($payment->state);
      $this->copyResponseFields($transaction, $payment);
      $transaction->save();

      $this->logger->info('Tap charge @charge moved from @from to @to.', [
        '@charge' => $payment->chargeId,
        '@from' => $from->value,
        '@to' => $payment->state->value,
      ]);

      $this->announce($transaction, $payment);

      return TRUE;
    }
    finally {
      $this->lock->release($lockId);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadByChargeId(string $chargeId): ?TapTransactionInterface {
    return $this->loadOneBy('charge_id', $chargeId);
  }

  /**
   * {@inheritdoc}
   */
  public function loadByIdempotencyKey(string $idempotencyKey): ?TapTransactionInterface {
    return $this->loadOneBy('idempotency_key', $idempotencyKey);
  }

  /**
   * {@inheritdoc}
   */
  public function loadByContext(string $module, string $contextId): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('context_module', $module)
      ->condition('context_id', $contextId)
      ->sort('created', 'DESC')
      ->execute();

    /** @var array<int, \Drupal\tap_payment\Entity\TapTransactionInterface> $transactions */
    $transactions = $this->storage()->loadMultiple($ids);

    return array_values($transactions);
  }

  /**
   * Creates the ledger row for a new attempt.
   *
   * @param \Drupal\tap_payment\Dto\PaymentRequest $request
   *   What the caller asked for.
   * @param string $gatewayId
   *   The gateway plugin id.
   * @param string $key
   *   The idempotency key.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The new row, or the one a concurrent request inserted first.
   */
  private function openTransaction(PaymentRequest $request, string $gatewayId, string $key): TapTransactionInterface {
    /** @var \Drupal\tap_payment\Entity\TapTransactionInterface $transaction */
    $transaction = $this->storage()->create([
      'idempotency_key' => $key,
      'state' => $this->stateMachine->initialState()->value,
      'amount' => $request->money->amount,
      'currency' => $request->money->currency,
      'gateway' => $gatewayId,
      'context_module' => $request->contextModule,
      'context_id' => $request->contextId,
      'return_url' => $request->returnUrl,
      'cancel_url' => $request->cancelUrl(),
    ]);

    try {
      $transaction->save();
    }
    catch (EntityStorageException $e) {
      // Entity storage wraps the driver's error, so the constraint violation
      // is one level down. Anything else is a real storage failure and must
      // not be mistaken for a duplicate.
      if (!$e->getPrevious() instanceof IntegrityConstraintViolationException) {
        throw $e;
      }

      // Another request inserted the same key between the lookup and this
      // save. That request owns the payment; join it rather than competing.
      $existing = $this->loadByIdempotencyKey($key);

      if ($existing === NULL) {
        throw new TapPaymentException('A payment with this idempotency key exists but could not be loaded.');
      }

      return $existing;
    }

    return $transaction;
  }

  /**
   * Writes what Tap said about a freshly created charge.
   *
   * The charge id is unique in the database, so this is also where the second
   * layer of duplicate protection fires: if another row already holds this Tap
   * charge — which the idempotency key should have prevented, but a bug or a
   * race might not — the save is refused and this defers to the existing row
   * rather than recording the same charge twice.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The row to update.
   * @param \Drupal\tap_payment\Dto\Payment $payment
   *   Tap's answer.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The row the charge is recorded on: normally the one passed in, or the
   *   pre-existing holder of the charge id on a collision.
   */
  private function recordCharge(TapTransactionInterface $transaction, Payment $payment): TapTransactionInterface {
    $transaction->setChargeId($payment->chargeId);
    $transaction->set('live_mode', $payment->liveMode);
    $this->copyResponseFields($transaction, $payment);

    if ($this->stateMachine->canTransition($transaction->getState(), $payment->state)) {
      $transaction->setState($payment->state);
    }

    try {
      $transaction->save();
    }
    catch (EntityStorageException $e) {
      if (!$e->getPrevious() instanceof IntegrityConstraintViolationException) {
        throw $e;
      }

      $existing = $this->loadByChargeId($payment->chargeId);

      if ($existing === NULL || $existing->id() === $transaction->id()) {
        // The violation was not the charge-id collision this guards against, so
        // do not swallow it as one.
        throw new TapPaymentException(sprintf(
          'Tap charge %s could not be recorded and no existing holder was found.',
          $payment->chargeId,
        ));
      }

      $this->logger->warning('Tap charge @charge is already recorded on transaction @id; a duplicate attempt on transaction @dup was discarded.', [
        '@charge' => $payment->chargeId,
        '@id' => $existing->id(),
        '@dup' => $transaction->id() ?? 'new',
      ]);

      return $existing;
    }

    return $transaction;
  }

  /**
   * Copies the reporting fields shared by every outcome.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The row to update.
   * @param \Drupal\tap_payment\Dto\Payment $payment
   *   Tap's answer.
   */
  private function copyResponseFields(TapTransactionInterface $transaction, Payment $payment): void {
    $transaction->set('response_code', $payment->responseCode);
    $transaction->set('response_message', $payment->responseMessage);
    $transaction->set('gateway_reference', $payment->gatewayReference);
    $transaction->set('payment_reference', $payment->paymentReference);
    $transaction->set('remote_created', $payment->createdTimestamp);
    $transaction->set('tap_customer_id', $payment->customerId);
  }

  /**
   * Whether an outcome is really about this transaction.
   *
   * A valid signature proves a payload came from Tap. It does not prove the
   * payload is about the charge being updated, and it does not prove the amount
   * is the one the site asked for — a Tap account can have many charges, and
   * this site created only some of them.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The transaction.
   * @param \Drupal\tap_payment\Dto\Payment $payment
   *   The reported outcome.
   *
   * @return bool
   *   TRUE when the outcome may be applied.
   */
  private function belongsTo(TapTransactionInterface $transaction, Payment $payment): bool {
    $chargeId = $transaction->getChargeId();

    if ($chargeId !== NULL && $chargeId !== $payment->chargeId) {
      $this->logger->warning('Refused an outcome for charge @reported against transaction @id, which holds charge @stored.', [
        '@reported' => $payment->chargeId,
        '@id' => $transaction->id(),
        '@stored' => $chargeId,
      ]);

      return FALSE;
    }

    $expected = $transaction->getMoney();

    if (!$expected->equals($payment->money, $this->currencyDecimals->forCurrency($expected->currency))) {
      $this->logger->error('Refused an outcome for Tap charge @charge: it reports a different amount than the site requested.', [
        '@charge' => $payment->chargeId,
      ]);

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Dispatches the lifecycle event a state deserves, if any.
   *
   * Pending states announce nothing: `INITIATED` and `IN_PROGRESS` are not
   * outcomes, and `UNKNOWN` is Tap saying it does not know — none of the three
   * is something another module should act on.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The updated transaction.
   * @param \Drupal\tap_payment\Dto\Payment $payment
   *   Tap's answer.
   */
  private function announce(TapTransactionInterface $transaction, Payment $payment): void {
    $event = match ($payment->state) {
      PaymentState::Captured => [new PaymentCapturedEvent($transaction, $payment), TapPaymentEvents::PAYMENT_CAPTURED],
      PaymentState::Failed, PaymentState::Declined, PaymentState::Restricted, PaymentState::TimedOut =>
        [new PaymentFailedEvent($transaction, $payment), TapPaymentEvents::PAYMENT_FAILED],
      PaymentState::Cancelled, PaymentState::Abandoned, PaymentState::Void =>
        [new PaymentCancelledEvent($transaction, $payment), TapPaymentEvents::PAYMENT_CANCELLED],
      default => NULL,
    };

    if ($event !== NULL) {
      $this->eventDispatcher->dispatch($event[0], $event[1]);
    }
  }

  /**
   * Describes a transaction that is already finished, without calling Tap.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The finished transaction.
   *
   * @return \Drupal\tap_payment\Dto\Payment
   *   The stored outcome, as a payment.
   */
  private function describe(TapTransactionInterface $transaction): Payment {
    return new Payment(
      chargeId: $transaction->getChargeId() ?? '',
      state: $transaction->getState(),
      money: $transaction->getMoney(),
      liveMode: $transaction->isLiveMode(),
      responseCode: $transaction->getResponseCode(),
      responseMessage: $transaction->getResponseMessage(),
    );
  }

  /**
   * The absolute URL Tap sends the payer back to.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The transaction being paid.
   *
   * @return string
   *   An absolute URL on this site.
   */
  private function returnUrl(TapTransactionInterface $transaction): string {
    return $this->urlGenerator->generateFromRoute(
      'tap_payment.return',
      ['uuid' => $transaction->uuid()],
      ['absolute' => TRUE],
    );
  }

  /**
   * The absolute URL Tap posts the outcome to.
   *
   * @return string
   *   An absolute URL on this site.
   */
  private function webhookUrl(): string {
    return $this->urlGenerator->generateFromRoute('tap_payment.webhook', [], ['absolute' => TRUE]);
  }

  /**
   * Refuses a URL that would send a browser off this site.
   *
   * @param string $url
   *   The URL to check.
   * @param string $which
   *   Which URL it is, for the message.
   *
   * @throws \Drupal\tap_payment\Exception\InvalidPaymentRequestException
   *   When the URL is not internal.
   */
  private function assertInternal(string $url, string $which): void {
    if (!$this->urlValidator->isInternal($url)) {
      throw new InvalidPaymentRequestException(sprintf(
        'The %s URL must point to this site; an off-site redirect after a payment is how phishing pages get their credibility.',
        $which,
      ));
    }
  }

  /**
   * Reads a transaction back from storage, discarding any in-memory state.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The transaction to reload.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The stored version, or the one passed in when it has no id yet.
   */
  private function reload(TapTransactionInterface $transaction): TapTransactionInterface {
    $id = $transaction->id();

    if ($id === NULL) {
      return $transaction;
    }

    $this->storage()->resetCache([$id]);
    $fresh = $this->storage()->load($id);

    return $fresh instanceof TapTransactionInterface ? $fresh : $transaction;
  }

  /**
   * Loads the single transaction matching one field.
   *
   * @param string $field
   *   The field name.
   * @param string $value
   *   The value to match.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface|null
   *   The transaction, or NULL.
   */
  private function loadOneBy(string $field, string $value): ?TapTransactionInterface {
    if (trim($value) === '') {
      return NULL;
    }

    $matches = $this->storage()->loadByProperties([$field => $value]);
    $transaction = reset($matches);

    return $transaction instanceof TapTransactionInterface ? $transaction : NULL;
  }

  /**
   * The transaction storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The storage handler.
   */
  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('tap_payment_transaction');
  }

}
