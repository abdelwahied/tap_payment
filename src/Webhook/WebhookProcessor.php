<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Webhook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\tap_payment\Event\TapPaymentEvents;
use Drupal\tap_payment\Event\WebhookReceivedEvent;
use Drupal\tap_payment\Event\WebhookVerifiedEvent;
use Drupal\tap_payment\Exception\WebhookVerificationException;
use Drupal\tap_payment\PaymentGatewayManager;
use Drupal\tap_payment\Service\TapPaymentService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Turns an unauthenticated HTTP request into a trusted payment outcome.
 *
 * The webhook is the authoritative confirmation. A payer's browser may never
 * come back — they close the tab, they lose signal — and Tap says so plainly:
 * the POST URL is the only sure way to learn the final status. So this path has
 * to be both the most trusted and the most suspicious code in the module.
 *
 * The order of operations is the point:
 *
 * 1. Decode. Announce that *something* arrived, for monitoring only.
 * 2. Verify the signature against the account secret. Nothing before this line
 *    is believed, and nothing after it proceeds without it.
 * 3. Bound the timestamp. This is a sanity check, not the replay defence — see
 *    the note on the window below.
 * 4. Find the site's own record, and let the payment service decide whether the
 *    outcome may be applied.
 *
 * **On replay.** The real protection against a replayed webhook is not a
 * nonce table but the state machine: a captured payment cannot be captured
 * twice, so replaying a genuine notification changes nothing. The freshness
 * window here is deliberately wide, because a legitimate asynchronous payment
 * can be confirmed long after its charge was created — Fawry lets a customer
 * pay at a shop days later — and a tight window would reject real money.
 *
 * @internal
 *   Injected as a service; the controller is a thin wrapper over it.
 *
 * @see https://developers.tap.company/docs/webhook
 */
final class WebhookProcessor {

  /**
   * The header Tap puts its signature in.
   */
  public const SIGNATURE_HEADER = 'hashstring';

  /**
   * Constructs a WebhookProcessor.
   *
   * @param \Drupal\tap_payment\PaymentGatewayManager $gatewayManager
   *   Loads the gateway that knows the signature scheme.
   * @param \Drupal\tap_payment\Service\TapPaymentService $payments
   *   Applies the outcome to the ledger.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   Announces receipt and verification.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's log channel.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The request time, for the freshness bound.
   * @param string $defaultGatewayId
   *   The gateway used when the payload names no known transaction.
   * @param int $freshnessWindow
   *   How far into the past a charge may have been created, in seconds.
   */
  public function __construct(
    private readonly PaymentGatewayManager $gatewayManager,
    private readonly TapPaymentService $payments,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly LoggerChannelInterface $logger,
    private readonly TimeInterface $time,
    private readonly string $defaultGatewayId,
    private readonly int $freshnessWindow,
  ) {}

  /**
   * Handles one webhook delivery.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return bool
   *   TRUE when the outcome changed a transaction, FALSE when it was a repeat,
   *   was about a charge this site does not know, or arrived too late to
   *   matter. Both are a successful delivery as far as Tap is concerned.
   *
   * @throws \Drupal\tap_payment\Exception\WebhookVerificationException
   *   When the request cannot be proven to come from Tap.
   */
  public function process(Request $request): bool {
    $payload = $this->decode($request);
    $this->eventDispatcher->dispatch(new WebhookReceivedEvent($payload), TapPaymentEvents::WEBHOOK_RECEIVED);

    $chargeId = is_scalar($payload['id'] ?? NULL) ? trim((string) $payload['id']) : '';

    if ($chargeId === '') {
      throw new WebhookVerificationException('The webhook payload carried no charge id.');
    }

    // The stored transaction decides which gateway verifies the signature. That
    // is a routing decision, not a trust decision: the signature still has to
    // pass, and a payload naming a charge this site never created falls back to
    // the default gateway and is verified just as strictly.
    $transaction = $this->payments->loadByChargeId($chargeId);
    $gatewayId = $transaction?->getGatewayId() ?? $this->defaultGatewayId;
    $gateway = $this->gatewayManager->getGateway($gatewayId);

    if (!$gateway->verifyWebhookSignature($payload, (string) $request->headers->get(self::SIGNATURE_HEADER, ''))) {
      // Logged without any of the payload: an unverified body is attacker
      // input, and copying it into the log is how a log viewer gets attacked.
      $this->logger->warning('Rejected a Tap webhook for charge @charge: the signature did not match.', [
        '@charge' => $chargeId,
      ]);

      throw new WebhookVerificationException('The webhook signature did not match.');
    }

    $this->eventDispatcher->dispatch(new WebhookVerifiedEvent($payload, $chargeId), TapPaymentEvents::WEBHOOK_VERIFIED);
    $this->assertFresh($payload, $chargeId);

    if ($transaction === NULL) {
      // A Tap account can serve more than one site. A correctly signed webhook
      // for a charge this site did not create is somebody else's business, not
      // an error, and answering anything but 200 would make Tap retry it.
      $this->logger->notice('Ignored a verified Tap webhook for charge @charge: this site did not create it.', [
        '@charge' => $chargeId,
      ]);

      return FALSE;
    }

    return $this->payments->applyOutcome($transaction, $gateway->mapWebhookPayload($payload));
  }

  /**
   * Reads the request body as a JSON object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array<string, mixed>
   *   The decoded payload.
   *
   * @throws \Drupal\tap_payment\Exception\WebhookVerificationException
   *   When the body is not a JSON object.
   */
  private function decode(Request $request): array {
    $decoded = json_decode($request->getContent(), TRUE);

    if (!is_array($decoded)) {
      throw new WebhookVerificationException('The webhook body was not a JSON object.');
    }

    return $decoded;
  }

  /**
   * Refuses a payload whose charge was created implausibly long ago.
   *
   * @param array<string, mixed> $payload
   *   The verified payload.
   * @param string $chargeId
   *   The charge id, for the message.
   *
   * @throws \Drupal\tap_payment\Exception\WebhookVerificationException
   *   When the timestamp is outside the window.
   */
  private function assertFresh(array $payload, string $chargeId): void {
    $transaction = is_array($payload['transaction'] ?? NULL) ? $payload['transaction'] : [];
    $created = $transaction['created'] ?? NULL;

    if (!is_numeric($created)) {
      throw new WebhookVerificationException(sprintf(
        'The webhook for charge %s carried no creation timestamp.',
        $chargeId,
      ));
    }

    // Tap sends milliseconds.
    $createdSeconds = (int) ((float) $created / 1000);
    $now = $this->time->getRequestTime();

    // A timestamp in the future is never legitimate; a small allowance covers
    // ordinary clock drift between two servers.
    if ($createdSeconds > $now + 300 || $createdSeconds < $now - $this->freshnessWindow) {
      throw new WebhookVerificationException(sprintf(
        'The webhook for charge %s is dated outside the accepted window.',
        $chargeId,
      ));
    }
  }

}
