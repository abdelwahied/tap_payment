<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Controller;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Utility\Error;
use Drupal\tap_payment\Entity\TapTransactionInterface;
use Drupal\tap_payment\Service\InternalUrlValidator;
use Drupal\tap_payment\TapPaymentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Brings the payer back from Tap's hosted page.
 *
 * Tap appends a `tap_id` to this URL. The module reads it only to log a
 * mismatch: a query parameter is written by whoever controls the browser, and
 * treating one as proof of payment is the classic way a checkout gets bypassed
 * for free. What actually happens here is a fresh `GET /charges/{id}` against
 * the charge this site itself recorded, and the answer to that is what decides
 * where the payer goes next.
 *
 * The transaction is addressed by UUID rather than by its serial id, so the
 * URL cannot be walked to enumerate other people's payments.
 *
 * The route is anonymous by necessity — the payer may have no session by the
 * time they return — so it is flood limited per client. Each hit costs an
 * outbound API call, and an endpoint that spends someone else's API quota on
 * request is one worth bounding.
 *
 * @internal
 *   A controller; not part of the public API.
 *
 * @see https://developers.tap.company/docs/redirect
 */
final class ReturnController implements ContainerInjectionInterface {

  /**
   * The flood event name for return hits.
   */
  private const FLOOD_EVENT = 'tap_payment.return';

  /**
   * Constructs a ReturnController.
   *
   * @param \Drupal\tap_payment\TapPaymentInterface $payments
   *   Re-reads the charge from Tap.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the transaction by UUID.
   * @param \Drupal\tap_payment\Service\InternalUrlValidator $urlValidator
   *   Checks the stored destination is still on this site.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   Bounds how often one client may trigger a verification.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's log channel.
   * @param int $floodLimit
   *   How many returns a client may make within the window.
   * @param int $floodWindow
   *   The counting window, in seconds.
   */
  public function __construct(
    private readonly TapPaymentInterface $payments,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly InternalUrlValidator $urlValidator,
    private readonly FloodInterface $flood,
    private readonly LoggerChannelInterface $logger,
    private readonly int $floodLimit,
    private readonly int $floodWindow,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tap_payment.payment'),
      $container->get('entity_type.manager'),
      $container->get('tap_payment.internal_url_validator'),
      $container->get('flood'),
      $container->get('logger.channel.tap_payment'),
      (int) $container->getParameter('tap_payment.return_flood_limit'),
      (int) $container->getParameter('tap_payment.return_flood_window'),
    );
  }

  /**
   * Verifies the payment and sends the payer on.
   *
   * @param string $uuid
   *   The transaction UUID from the path.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Drupal\Core\Routing\LocalRedirectResponse
   *   A redirect to the site's own success or cancel destination.
   */
  public function handle(string $uuid, Request $request): LocalRedirectResponse {
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, $this->floodLimit, $this->floodWindow)) {
      throw new TooManyRequestsHttpException(NULL, 'Too many payment returns from this client.');
    }

    $this->flood->register(self::FLOOD_EVENT, $this->floodWindow);
    $transaction = $this->loadByUuid($uuid);

    if ($transaction === NULL) {
      throw new NotFoundHttpException();
    }

    $this->warnOnMismatchedTapId($transaction, $request);

    try {
      $transaction = $this->payments->verifyPayment($transaction);
    }
    catch (\Throwable $e) {
      // The payer is not the right audience for an API failure, and the site
      // has the webhook as a second chance to learn the outcome. Send them to
      // the cancel destination and record the detail for an administrator.
      Error::logException($this->logger, $e);
    }

    return $this->redirectTo($transaction);
  }

  /**
   * Loads a transaction by its UUID.
   *
   * @param string $uuid
   *   The UUID from the path.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface|null
   *   The transaction, or NULL when there is none.
   */
  private function loadByUuid(string $uuid): ?TapTransactionInterface {
    if (!Uuid::isValid($uuid)) {
      return NULL;
    }

    $matches = $this->entityTypeManager
      ->getStorage('tap_payment_transaction')
      ->loadByProperties(['uuid' => $uuid]);

    $transaction = reset($matches);

    return $transaction instanceof TapTransactionInterface ? $transaction : NULL;
  }

  /**
   * Notes a `tap_id` that disagrees with the charge this site recorded.
   *
   * Nothing is refused on this basis — the parameter is not trusted either way
   * — but a mismatch is worth an administrator's attention, because the only
   * innocent explanation is a payer pasting somebody else's return link.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The transaction being returned to.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   */
  private function warnOnMismatchedTapId(TapTransactionInterface $transaction, Request $request): void {
    $reported = trim((string) $request->query->get('tap_id', ''));
    $stored = $transaction->getChargeId();

    if ($reported !== '' && $stored !== NULL && $reported !== $stored) {
      $this->logger->warning('A payment return for transaction @id carried a tap_id that does not match the recorded charge; it was ignored.', [
        '@id' => $transaction->uuid(),
      ]);
    }
  }

  /**
   * Chooses and validates where the payer goes next.
   *
   * @param \Drupal\tap_payment\Entity\TapTransactionInterface $transaction
   *   The verified transaction.
   *
   * @return \Drupal\Core\Routing\LocalRedirectResponse
   *   The redirect.
   */
  private function redirectTo(TapTransactionInterface $transaction): LocalRedirectResponse {
    $state = $transaction->getState();
    $destination = $state->isSuccessful() || $state->isPending()
      ? $transaction->getReturnUrl()
      : $transaction->getCancelUrl();

    // The destination was validated when the payment was created, but a site
    // can change host between then and now, and a stored value is still data.
    // Re-checking costs nothing and closes the gap.
    if (!$this->urlValidator->isInternal($destination)) {
      $this->logger->error('The stored destination for transaction @id is no longer internal; sending the payer to the front page instead.', [
        '@id' => $transaction->uuid(),
      ]);

      $destination = '/';
    }

    $destination .= (str_contains($destination, '?') ? '&' : '?')
      . http_build_query(['tap_transaction' => $transaction->uuid()]);

    $response = new LocalRedirectResponse($destination);
    // A payment outcome is per-payer and changes under the visitor's feet;
    // caching this redirect would send the next customer to the last one's
    // result page.
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));

    return $response;
  }

}
