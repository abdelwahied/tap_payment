<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment_custom\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment_custom\FormSettings;
use Drupal\tap_payment_custom\IdempotencyKeyFactory;
use Drupal\tap_payment_custom\PaymentThrottle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the throttle buckets and the derived idempotency key.
 *
 * @group tap_payment
 *
 * @covers \Drupal\tap_payment_custom\PaymentThrottle
 * @covers \Drupal\tap_payment_custom\IdempotencyKeyFactory
 *
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('tap_payment')]
#[CoversClass(PaymentThrottle::class)]
#[CoversClass(IdempotencyKeyFactory::class)]
final class PaymentThrottleTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'tap_payment', 'tap_payment_custom'];

  /**
   * The throttle under test.
   */
  private PaymentThrottle $throttle;

  /**
   * The key factory under test.
   */
  private IdempotencyKeyFactory $keys;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['tap_payment_custom']);
    $this->installEntitySchema('user');

    // Flood counts against the client address, so the request the kernel test
    // already pushed is given one. Pushing a second request instead would
    // leave the stack without the session KernelTestBase tears down through.
    $this->container->get('request_stack')
      ->getCurrentRequest()
      ?->server->set('REMOTE_ADDR', '203.0.113.9');

    $this->throttle = $this->container->get('tap_payment_custom.throttle');
    $this->keys = $this->container->get('tap_payment_custom.idempotency_keys');
  }

  /**
   * The shipped defaults come from the module's service parameters.
   */
  public function testDefaultsComeFromTheModuleParameters(): void {
    /** @var \Drupal\tap_payment_custom\FormSettings $settings */
    $settings = $this->container->get('tap_payment_custom.settings');

    self::assertSame(10, $settings->floodLimit());
    self::assertSame(3600, $settings->floodWindow());
    self::assertSame(10, $settings->floodIpMultiplier());
    self::assertSame(900, $settings->idempotencyLifetime());
  }

  /**
   * Configuration overrides the shipped defaults; zero means "keep them".
   */
  public function testConfigurationOverridesTheDefaults(): void {
    $this->config(FormSettings::CONFIG_NAME)
      ->set('flood_limit', 3)
      ->set('flood_window', 60)
      ->set('idempotency_lifetime', 0)
      ->save();

    /** @var \Drupal\tap_payment_custom\FormSettings $settings */
    $settings = $this->container->get('tap_payment_custom.settings');

    self::assertSame(3, $settings->floodLimit());
    self::assertSame(60, $settings->floodWindow());
    self::assertSame(900, $settings->idempotencyLifetime(), 'Zero must fall back to the shipped default.');
  }

  /**
   * A payer is refused once their own bucket is full.
   */
  public function testEmailBucketStopsOnePayer(): void {
    // Session bucket off: a kernel test runs every call inside one session, so
    // leaving it on would be testing the session bucket under another name.
    $this->config(FormSettings::CONFIG_NAME)
      ->set('flood_limit', 3)
      ->set('throttle_by_session', FALSE)
      ->save();

    for ($i = 0; $i < 3; $i++) {
      self::assertTrue($this->throttle->isAllowed('ada@example.com'), "attempt {$i} should be allowed");
      $this->throttle->register('ada@example.com');
    }

    self::assertFalse($this->throttle->isAllowed('ada@example.com'));
    self::assertSame('email', $this->throttle->exceededBucket('ada@example.com'));
  }

  /**
   * The regression this whole strategy exists for.
   *
   * One payer exhausting their allowance must not lock out the next person
   * behind the same address — which is exactly what an IP-only limit does to an
   * office, a school or a mobile carrier.
   */
  public function testOnePayerDoesNotLockOutTheirNeighbours(): void {
    // Two payers on one address are two browsers in production; a kernel test
    // has only one session, so the email bucket is what has to discriminate.
    $this->config(FormSettings::CONFIG_NAME)
      ->set('flood_limit', 2)
      ->set('throttle_by_session', FALSE)
      ->save();

    for ($i = 0; $i < 2; $i++) {
      $this->throttle->register('ada@example.com');
    }

    self::assertFalse($this->throttle->isAllowed('ada@example.com'), 'The exhausted payer is refused.');
    self::assertTrue(
      $this->throttle->isAllowed('grace@example.com'),
      'A different payer on the same address must still be allowed.',
    );
  }

  /**
   * The per-address bucket still bounds the endpoint as a whole.
   */
  public function testAddressBucketStillBoundsTheEndpoint(): void {
    $this->config(FormSettings::CONFIG_NAME)
      ->set('flood_limit', 2)
      ->set('flood_ip_multiplier', 3)
      ->set('throttle_by_session', FALSE)
      ->save();

    // Six attempts from six different payers: 2 x 3 fills the address bucket.
    for ($i = 0; $i < 6; $i++) {
      $this->throttle->register("payer{$i}@example.com");
    }

    self::assertFalse($this->throttle->isAllowed('fresh@example.com'));
    self::assertSame('ip', $this->throttle->exceededBucket('fresh@example.com'));
  }

  /**
   * Turning the email bucket off leaves only the address bucket.
   */
  public function testEmailBucketCanBeDisabled(): void {
    $this->config(FormSettings::CONFIG_NAME)
      ->set('flood_limit', 2)
      ->set('throttle_by_email', FALSE)
      ->set('throttle_by_session', FALSE)
      ->save();

    for ($i = 0; $i < 5; $i++) {
      $this->throttle->register('ada@example.com');
    }

    self::assertTrue($this->throttle->isAllowed('ada@example.com'), 'Only the looser address bucket applies now.');
  }

  /**
   * One browser is bounded by its own session, whatever address it comes from.
   *
   * The tightest and most accurate bucket: it does not care that a thousand
   * other payers share the address, and it does not depend on the payer
   * entering the same email twice.
   */
  public function testSessionBucketStopsOneBrowser(): void {
    $this->config(FormSettings::CONFIG_NAME)
      ->set('flood_limit', 2)
      ->set('throttle_by_email', FALSE)
      ->save();

    self::assertNotNull($this->throttle->owner(), 'This test needs a session to be meaningful.');

    // A different address each time would still be one browser.
    $this->throttle->register('one@example.com');
    $this->throttle->register('two@example.com');

    self::assertFalse($this->throttle->isAllowed('three@example.com'));
    self::assertSame('session', $this->throttle->exceededBucket('three@example.com'));
  }

  /**
   * An identical submission produces an identical key.
   */
  public function testTheSameSubmissionProducesTheSameKey(): void {
    $money = Money::fromNumeric('1.000', 'KWD');
    $customer = ['email' => 'ada@example.com', 'first_name' => 'Ada'];

    self::assertSame(
      $this->keys->current($money, $customer, 'sid:abc', 900),
      $this->keys->current($money, $customer, 'sid:abc', 900),
    );
  }

  /**
   * Anything a payer actually changed produces a different key.
   */
  public function testDifferentSubmissionsProduceDifferentKeys(): void {
    $money = Money::fromNumeric('1.000', 'KWD');
    $base = $this->keys->current($money, ['email' => 'ada@example.com'], 'sid:abc', 900);

    self::assertNotSame($base, $this->keys->current($money, ['email' => 'grace@example.com'], 'sid:abc', 900));
    self::assertNotSame($base, $this->keys->current(Money::fromNumeric('2.000', 'KWD'), ['email' => 'ada@example.com'], 'sid:abc', 900));
    self::assertNotSame($base, $this->keys->current(Money::fromNumeric('1.00', 'SAR'), ['email' => 'ada@example.com'], 'sid:abc', 900));
  }

  /**
   * Two browsers never share a key.
   *
   * Without the owner in the material, anyone who knew a payer's email and the
   * form's fixed price could submit the same values and be handed that payer's
   * live Tap checkout URL.
   */
  public function testTwoBrowsersNeverShareOneKey(): void {
    $money = Money::fromNumeric('1.000', 'KWD');
    $customer = ['email' => 'ada@example.com', 'first_name' => 'Ada'];

    self::assertNotSame(
      $this->keys->current($money, $customer, 'sid:one', 900),
      $this->keys->current($money, $customer, 'sid:two', 900),
    );
  }

  /**
   * The previous bucket is offered, so a retry across a boundary still lands.
   */
  public function testThePreviousBucketIsOffered(): void {
    $candidates = $this->keys->candidates(
      Money::fromNumeric('1.000', 'KWD'),
      ['email' => 'ada@example.com'],
      'sid:abc',
      900,
    );

    self::assertCount(2, $candidates);
    self::assertNotSame($candidates[0], $candidates[1]);
  }

  /**
   * A finished payment does not leave the payer without a derived key.
   *
   * The regression: resolving used to give up once every candidate belonged to
   * a completed payment and let the service mint a random key. Two clicks in
   * that state produced two charges — the exact thing the derived key exists to
   * prevent. Generations give the next attempt a key of its own that both
   * concurrent requests compute identically.
   */
  public function testEachGenerationIsItsOwnStableKey(): void {
    $money = Money::fromNumeric('1.000', 'KWD');
    $customer = ['email' => 'ada@example.com'];

    $first = $this->keys->forGeneration($money, $customer, 'sid:abc', 900, 0);
    $second = $this->keys->forGeneration($money, $customer, 'sid:abc', 900, 1);

    self::assertNotSame($first, $second, 'A later generation must be a different key.');
    self::assertSame(
      $second,
      $this->keys->forGeneration($money, $customer, 'sid:abc', 900, 1),
      'The same generation must be stable, or two concurrent requests diverge.',
    );
    self::assertSame($first, $this->keys->current($money, $customer, 'sid:abc', 900));
  }

  /**
   * The terminal key is unique, and only ever reached deliberately.
   */
  public function testUniqueKeyIsUnique(): void {
    self::assertNotSame($this->keys->unique(), $this->keys->unique());
    self::assertStringStartsWith('tpc_', $this->keys->unique());
  }

  /**
   * Field order in the material never changes the key.
   */
  public function testKeyMaterialOrderDoesNotMatter(): void {
    $money = Money::fromNumeric('1.000', 'KWD');

    self::assertSame(
      $this->keys->current($money, ['email' => 'ada@example.com', 'first_name' => 'Ada'], NULL, 900),
      $this->keys->current($money, ['first_name' => 'Ada', 'email' => 'ada@example.com'], NULL, 900),
    );
  }

  /**
   * No payer email is ever handed to the flood backend in the clear.
   *
   * Asserted against the backend rather than against a table, because which
   * backend is in use is a site's choice — the guarantee has to hold for all of
   * them. A recording double stands in for whatever the site configured.
   */
  public function testNoAddressIsHandedToTheFloodBackend(): void {
    $recorder = new RecordingFlood();
    $throttle = new PaymentThrottle(
      $recorder,
      $this->container->get('request_stack'),
      $this->container->get('current_user'),
      $this->container->get('tap_payment_custom.settings'),
    );

    $throttle->register('ada@example.com');
    $throttle->isAllowed('ada@example.com');

    self::assertNotEmpty($recorder->identifiers, 'The throttle registered nothing at all.');

    foreach ($recorder->identifiers as $identifier) {
      self::assertStringNotContainsString('ada@example.com', $identifier);
      self::assertStringNotContainsString('ada', $identifier);
    }
  }

  /**
   * Case and surrounding space never split one payer into two buckets.
   */
  public function testEmailIsNormalisedBeforeItIsCounted(): void {
    $this->config(FormSettings::CONFIG_NAME)
      ->set('flood_limit', 1)
      ->set('throttle_by_session', FALSE)
      ->save();

    $this->throttle->register(' Ada@Example.COM ');

    self::assertFalse(
      $this->throttle->isAllowed('ada@example.com'),
      'The same address written differently must land in the same bucket.',
    );
  }

}
