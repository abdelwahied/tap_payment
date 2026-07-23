<?php

declare(strict_types=1);

namespace Drupal\tap_payment;

use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Dto\PaymentSession;
use Drupal\tap_payment\Entity\TapTransactionInterface;

/**
 * The one service an integration needs.
 *
 * Everything a module has to do to take a payment is here: start one, send the
 * payer to the URL it hands back, and later ask what happened. The gateway
 * plugin, the API version adapter, the HTTP client, the signature scheme and
 * the ledger are all behind this interface, and none of them appear in an
 * integration's code — which is the point. A submodule written against this
 * interface keeps working when Tap changes its API, because only an adapter
 * changes.
 *
 * The rule that shapes every method here: **a browser is never believed**. A
 * payer coming back from Tap proves nothing, so every method that reports an
 * outcome has re-read it from Tap or verified a signature first.
 *
 * @api
 *   Public and stable since 1.0.0. Injected as `tap_payment.payment`, or by
 *   type-hinting this interface.
 */
interface TapPaymentInterface {

  /**
   * Starts a payment and returns where to send the payer.
   *
   * Safe to call twice with the same idempotency key: the second call returns
   * the first payment rather than creating another. That is what makes a
   * double-clicked pay button, a refreshed checkout page and a retried
   * background job harmless.
   *
   * @param \Drupal\tap_payment\Dto\PaymentRequest $request
   *   What to collect, from whom, and where to send them afterwards.
   * @param string $gatewayId
   *   The payment gateway plugin to use.
   *
   * @return \Drupal\tap_payment\Dto\PaymentSession
   *   The site's record of the attempt, and Tap's answer.
   *
   * @throws \Drupal\tap_payment\Exception\InvalidPaymentRequestException
   *   When the request could not be accepted, including a return URL that
   *   points off this site.
   * @throws \Drupal\tap_payment\Exception\ConfigurationException
   *   When the module has no usable credentials.
   * @throws \Drupal\tap_payment\Exception\ApiException
   *   When Tap refused the charge or could not be reached.
   */
  public function createPayment(PaymentRequest $request, string $gatewayId = 'tap'): PaymentSession;

  /**
   * Re-reads a payment from Tap and updates the site's record.
   *
   * This is the call that turns "the payer came back" into a fact. It contacts
   * Tap every time; there is no cached answer, because the whole purpose is to
   * not trust what arrived in the browser.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The transaction to refresh.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The refreshed transaction.
   *
   * @throws \Drupal\tap_payment\Exception\ApiException
   *   When Tap could not be reached.
   */
  public function verifyPayment(TapTransactionInterface $transaction): TapTransactionInterface;

  /**
   * Finds a transaction by the Tap charge identifier.
   *
   * @param string $chargeId
   *   The `chg_…` identifier.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface|null
   *   The transaction, or NULL when this site did not create that charge.
   */
  public function loadByChargeId(string $chargeId): ?TapTransactionInterface;

  /**
   * Finds a transaction by the idempotency key it was created with.
   *
   * @param string $idempotencyKey
   *   The key.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface|null
   *   The transaction, or NULL when there is none.
   */
  public function loadByIdempotencyKey(string $idempotencyKey): ?TapTransactionInterface;

  /**
   * Finds the transactions another module created.
   *
   * @param string $module
   *   The context module name.
   * @param string $contextId
   *   The context identifier that module used.
   *
   * @return array<int, \Drupal\tap_payment\Entity\TapTransactionInterface>
   *   The matching transactions, newest first.
   */
  public function loadByContext(string $module, string $contextId): array;

}
