<?php

declare(strict_types=1);

namespace Drupal\tap_payment_custom;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\tap_payment\Dto\Money;

/**
 * Derives a stable idempotency key from what the payer actually submitted.
 *
 * WHY DETERMINISTIC — the core service already reuses a payment when it is
 * handed a key it has seen, and Tap itself returns the original charge for a
 * repeated `reference.idempotent`. Both were inert here, because the form
 * supplied no key at all and the service fell back to a fresh UUID on every
 * submission. A double-clicked Pay button therefore opened two charges. Making
 * the key a function of the submission turns both of those existing protections
 * on without changing either of them.
 *
 * WHAT GOES INTO IT — the amount, the currency and the payer's own details, so
 * that a genuinely different payment gets a different key; plus the signed-in
 * account or the current anonymous session *when there is one*, which makes two
 * people far less likely to land on the same key.
 *
 * That last part is a discriminator, not a guarantee. An anonymous visitor who
 * has no session yet contributes nothing to it — see PaymentThrottle::owner(),
 * which deliberately does not start a session just to have one — so on that
 * path the key rests on the submitted details and the amount alone. Two
 * sessionless anonymous submissions carrying identical payer details therefore
 * still resolve to one key, which is the right answer when it really is the
 * same person retrying and the wrong one when it is not.
 *
 * WHY A TIME BUCKET — a key that never changes would make a second, deliberate
 * payment impossible: the payer who buys the same thing again tomorrow would be
 * handed yesterday's charge. Folding a coarse clock into the material lets the
 * key expire on its own. The bucket before the current one is offered too, so a
 * retry that happens to straddle a boundary still finds its original.
 *
 * @internal
 *   Injected as a service.
 */
final class IdempotencyKeyFactory {

  /**
   * Namespace prefix, so a key is recognisable in the ledger.
   */
  private const PREFIX = 'tpc';

  /**
   * Constructs an IdempotencyKeyFactory.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The request time, which is stable for the whole request.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   Mints the terminal key, for the payer who has exhausted every derived
   *   one. Deliberately last: a random key gives up duplicate protection, so
   *   it is what happens after the derived keys are gone, not instead of them.
   */
  public function __construct(
    private readonly TimeInterface $time,
    private readonly UuidInterface $uuid,
  ) {}

  /**
   * The keys a submission may legitimately resolve to, newest first.
   *
   * The first is the key a new payment is created under. The second is the
   * previous time bucket, offered only so that a retry seconds after a boundary
   * still finds the payment it is retrying.
   *
   * @param \Drupal\tap_payment\Dto\Money $money
   *   What is being charged.
   * @param array<string, string|null> $customer
   *   The submitted payer details.
   * @param string|null $owner
   *   A stable identifier for the browser or account, when there is one.
   * @param int $lifetime
   *   How long one key stays in use, in seconds.
   *
   * @return array<int, string>
   *   The current key, then the previous one.
   */
  public function candidates(Money $money, array $customer, ?string $owner, int $lifetime): array {
    return [
      $this->forGeneration($money, $customer, $owner, $lifetime, 0),
      $this->previous($money, $customer, $owner, $lifetime),
    ];
  }

  /**
   * The nth key this submission may occupy in the current time bucket.
   *
   * Generation 0 is where a submission normally lands. Higher generations exist
   * for the payer who finished a payment and immediately wants an identical
   * one: without them the caller has no derived key left and has to fall back
   * to a random one, which is exactly when a double-click opens two charges.
   *
   * @param \Drupal\tap_payment\Dto\Money $money
   *   What is being charged.
   * @param array<string, string|null> $customer
   *   The submitted payer details.
   * @param string|null $owner
   *   A stable identifier for the browser or account, when there is one.
   * @param int $lifetime
   *   How long one key stays in use, in seconds.
   * @param int $generation
   *   Which attempt within the bucket, from zero.
   *
   * @return string
   *   The key.
   */
  public function forGeneration(Money $money, array $customer, ?string $owner, int $lifetime, int $generation): string {
    $bucket = intdiv($this->time->getRequestTime(), max(1, $lifetime));

    return $this->key($this->material($money, $customer, $owner), $bucket, max(0, $generation));
  }

  /**
   * The previous bucket's first key.
   *
   * Offered so a retry seconds after a bucket boundary still finds the payment
   * it is retrying rather than opening a second one.
   *
   * @param \Drupal\tap_payment\Dto\Money $money
   *   What is being charged.
   * @param array<string, string|null> $customer
   *   The submitted payer details.
   * @param string|null $owner
   *   A stable identifier for the browser or account, when there is one.
   * @param int $lifetime
   *   How long one key stays in use, in seconds.
   *
   * @return string
   *   The key.
   */
  public function previous(Money $money, array $customer, ?string $owner, int $lifetime): string {
    $bucket = intdiv($this->time->getRequestTime(), max(1, $lifetime));

    return $this->key($this->material($money, $customer, $owner), $bucket - 1, 0);
  }

  /**
   * A key belonging to nothing, for when every derived one is spent.
   *
   * @return string
   *   A unique key.
   */
  public function unique(): string {
    return self::PREFIX . '_' . $this->uuid->generate();
  }

  /**
   * The key a new payment is created under.
   *
   * @param \Drupal\tap_payment\Dto\Money $money
   *   What is being charged.
   * @param array<string, string|null> $customer
   *   The submitted payer details.
   * @param string|null $owner
   *   A stable identifier for the browser or account, when there is one.
   * @param int $lifetime
   *   How long one key stays in use, in seconds.
   *
   * @return string
   *   The current key.
   */
  public function current(Money $money, array $customer, ?string $owner, int $lifetime): string {
    return $this->forGeneration($money, $customer, $owner, $lifetime, 0);
  }

  /**
   * The stable part of the key material.
   *
   * @param \Drupal\tap_payment\Dto\Money $money
   *   What is being charged.
   * @param array<string, string|null> $customer
   *   The submitted payer details.
   * @param string|null $owner
   *   A stable identifier for the browser or account.
   *
   * @return string
   *   The material, before the clock is folded in.
   */
  private function material(Money $money, array $customer, ?string $owner): string {
    // Sorted, so the same submission produces the same string whatever order
    // the caller happened to build the array in.
    ksort($customer);

    $parts = [
      'tap_payment_custom',
      $money->amount,
      $money->currency,
      $owner ?? 'anonymous',
    ];

    foreach ($customer as $name => $value) {
      $parts[] = $name . '=' . mb_strtolower(trim((string) $value));
    }

    return implode('|', $parts);
  }

  /**
   * Hashes material and a bucket into a key Tap will accept.
   *
   * @param string $material
   *   The stable material.
   * @param int $bucket
   *   The time bucket.
   * @param int $generation
   *   Which attempt within the bucket.
   *
   * @return string
   *   The idempotency key.
   */
  private function key(string $material, int $bucket, int $generation): string {
    // hashBase64 is URL-safe and fixed length, which keeps the key inside the
    // limits Tap documents for reference fields without any trimming.
    return self::PREFIX . '_' . Crypt::hashBase64($material . '|' . $bucket . '|' . $generation);
  }

}
