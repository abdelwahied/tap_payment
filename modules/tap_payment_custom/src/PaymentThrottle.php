<?php

declare(strict_types=1);

namespace Drupal\tap_payment_custom;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Bounds how often one payer may start a payment.
 *
 * WHY THIS IS NOT PLAIN IP FLOOD CONTROL — the module's webhook and return
 * routes are flood limited on the client IP alone, and for those that is right:
 * a forged webhook and a replayed return are attacks, so a shared IP paying the
 * cost of one is acceptable. The payment form is the opposite case. Behind
 * carrier-grade NAT, an office, a school or a university, a few hundred
 * legitimate payers share one public address; an IP-only limit tight enough to
 * stop abuse is tight enough to lock all of them out on a busy afternoon.
 *
 * So the limit is checked against three buckets, and a request has to be within
 * *all* of them:
 *
 * - **Session or account.** The tightest and the most accurate: one browser,
 *   one counter. Applies whenever a session exists — always for an
 *   authenticated user, and for an anonymous one that already has a session.
 * - **Email.** The bucket that carries the weight for anonymous payers with no
 *   session. The address is hashed before it is used, so the flood table never
 *   holds a payer's email in the clear.
 * - **Client IP.** Kept as a backstop against a client that presents neither,
 *   but multiplied so it bounds a whole network rather than one person. This is
 *   the bucket NAT shares, so it is the one deliberately loosened.
 *
 * A bucket that cannot be identified is skipped rather than guessed at. Two
 * always remain, and the IP bucket always applies.
 *
 * @internal
 *   Injected as a service.
 */
final class PaymentThrottle {

  /**
   * The flood event for the session or account bucket.
   */
  private const EVENT_SESSION = 'tap_payment_custom.pay.session';

  /**
   * The flood event for the email bucket.
   */
  private const EVENT_EMAIL = 'tap_payment_custom.pay.email';

  /**
   * The flood event for the client-address bucket.
   */
  private const EVENT_IP = 'tap_payment_custom.pay.ip';

  /**
   * Constructs a PaymentThrottle.
   *
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   Counts attempts per identifier.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Supplies the client address and the session, when there is one.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   Identifies an authenticated payer more stably than a session id does.
   * @param \Drupal\tap_payment_custom\FormSettings $settings
   *   The configured limits.
   */
  public function __construct(
    private readonly FloodInterface $flood,
    private readonly RequestStack $requestStack,
    private readonly AccountInterface $currentUser,
    private readonly FormSettings $settings,
  ) {}

  /**
   * Whether this payer may start another payment.
   *
   * @param string $email
   *   The address entered on the form.
   *
   * @return bool
   *   TRUE when every applicable bucket is still under its limit.
   */
  public function isAllowed(string $email): bool {
    foreach ($this->buckets($email) as [$event, $identifier, $limit]) {
      if (!$this->flood->isAllowed($event, $limit, $this->settings->floodWindow(), $identifier)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Counts one attempt against every applicable bucket.
   *
   * Called on submission only. Registering during a form build would let a
   * crawler that never submits anything exhaust a real payer's allowance.
   *
   * @param string $email
   *   The address entered on the form.
   */
  public function register(string $email): void {
    foreach ($this->buckets($email) as [$event, $identifier]) {
      $this->flood->register($event, $this->settings->floodWindow(), $identifier);
    }
  }

  /**
   * Which bucket was exceeded, for the log.
   *
   * @param string $email
   *   The address entered on the form.
   *
   * @return string
   *   The identifier kind: `session`, `email`, `ip`, or `none`.
   */
  public function exceededBucket(string $email): string {
    foreach ($this->buckets($email) as [$event, $identifier, $limit]) {
      if (!$this->flood->isAllowed($event, $limit, $this->settings->floodWindow(), $identifier)) {
        return match ($event) {
          self::EVENT_SESSION => 'session',
          self::EVENT_EMAIL => 'email',
          default => 'ip',
        };
      }
    }

    return 'none';
  }

  /**
   * The buckets that apply to the current request.
   *
   * @param string $email
   *   The address entered on the form.
   *
   * @return array<int, array{string, string, int}>
   *   Event name, identifier and limit per bucket.
   */
  private function buckets(string $email): array {
    $limit = $this->settings->floodLimit();
    $buckets = [];

    $owner = $this->owner();

    if ($owner !== NULL && $this->settings->throttleBySession()) {
      $buckets[] = [self::EVENT_SESSION, $owner, $limit];
    }

    $hashed = $this->hashedEmail($email);

    if ($hashed !== NULL && $this->settings->throttleByEmail()) {
      $buckets[] = [self::EVENT_EMAIL, $hashed, $limit];
    }

    // Always present, always the loosest. `$limit * multiplier` is what makes
    // an office of shared-IP payers workable while still bounding the endpoint.
    $buckets[] = [self::EVENT_IP, $this->clientIp(), $limit * $this->settings->floodIpMultiplier()];

    return $buckets;
  }

  /**
   * A stable identifier for the browser or account making the request.
   *
   * @return string|null
   *   The identifier, or NULL when the payer is anonymous and sessionless.
   */
  public function owner(): ?string {
    if ($this->currentUser->isAuthenticated()) {
      return 'uid:' . $this->currentUser->id();
    }

    $request = $this->requestStack->getCurrentRequest();

    if ($request === NULL || !$request->hasSession()) {
      return NULL;
    }

    $session = $request->getSession();

    // Deliberately not started here. Starting a session for every anonymous
    // visitor to a public page would cost the page cache for all of them, to
    // gain a bucket the email bucket already covers.
    if (!$session->isStarted()) {
      return NULL;
    }

    $id = $session->getId();

    return $id === '' ? NULL : 'sid:' . Crypt::hashBase64($id);
  }

  /**
   * The email, hashed, so the flood table never stores an address.
   *
   * @param string $email
   *   The raw address.
   *
   * @return string|null
   *   The hashed address, or NULL when there is none to hash.
   */
  private function hashedEmail(string $email): ?string {
    $email = mb_strtolower(trim($email));

    return $email === '' ? NULL : 'mail:' . Crypt::hashBase64($email);
  }

  /**
   * The client address, as Drupal resolved it.
   *
   * @return string
   *   The client IP, or a constant when the request has none.
   */
  private function clientIp(): string {
    return $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
  }

}
