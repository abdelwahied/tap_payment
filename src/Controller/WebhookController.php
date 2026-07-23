<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use Drupal\tap_payment\Exception\WebhookVerificationException;
use Drupal\tap_payment\Webhook\WebhookProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives Tap's server-to-server payment notifications.
 *
 * A thin shell over the processor, because everything interesting is testable
 * without HTTP. What it does own is the answer Tap gets, and each status is
 * chosen for what it makes Tap do next:
 *
 * - **200** for anything handled, including a repeat delivery and a charge this
 *   site does not know. Tap stops retrying, which is right: retrying would not
 *   change the outcome.
 * - **401** for a signature that does not verify. Tap's own deliveries always
 *   verify, so this is only ever reached by something that is not Tap, and it
 *   should not be encouraged to try again.
 * - **500** for an internal fault. Tap retries twice, which is exactly the
 *   behaviour wanted when the site was briefly unable to record a real payment.
 *
 * The route has no CSRF token, and cannot: Tap posts from its own servers with
 * no session. The signature is the authentication, which is why the flood
 * counter below is registered on *verification failures* rather than on
 * requests — genuine Tap traffic never trips it, and a forger runs out of
 * attempts.
 *
 * @internal
 *   A controller; not part of the public API.
 *
 * @see https://developers.tap.company/docs/webhook
 */
final class WebhookController implements ContainerInjectionInterface {

  /**
   * The flood event name for failed verifications.
   */
  private const FLOOD_EVENT = 'tap_payment.webhook_rejected';

  /**
   * Constructs a WebhookController.
   *
   * @param \Drupal\tap_payment\Webhook\WebhookProcessor $processor
   *   Verifies and applies the notification.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   Counts failed verification attempts per client.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's log channel.
   * @param int $floodLimit
   *   How many failures a client may cause within the window.
   * @param int $floodWindow
   *   The counting window, in seconds.
   */
  public function __construct(
    private readonly WebhookProcessor $processor,
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
      $container->get('tap_payment.webhook_processor'),
      $container->get('flood'),
      $container->get('logger.channel.tap_payment'),
      (int) $container->getParameter('tap_payment.webhook_flood_limit'),
      (int) $container->getParameter('tap_payment.webhook_flood_window'),
    );
  }

  /**
   * Handles one notification.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   What Tap is told.
   */
  public function handle(Request $request): JsonResponse {
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, $this->floodLimit, $this->floodWindow)) {
      $this->logger->warning('Refused a Tap webhook: too many failed verifications from this client.');

      return new JsonResponse(['status' => 'rate_limited'], 429);
    }

    try {
      $changed = $this->processor->process($request);

      return new JsonResponse(['status' => $changed ? 'applied' : 'ignored'], 200);
    }
    catch (WebhookVerificationException $e) {
      $this->flood->register(self::FLOOD_EVENT, $this->floodWindow);
      $this->logger->warning('Rejected a Tap webhook: @reason', ['@reason' => $e->getMessage()]);

      return new JsonResponse(['status' => 'rejected'], 401);
    }
    catch (\Throwable $e) {
      // Tap retries a 500 twice, which is the right outcome: the notification
      // was probably genuine and the site was momentarily unable to record it.
      Error::logException($this->logger, $e);

      return new JsonResponse(['status' => 'error'], 500);
    }
  }

}
