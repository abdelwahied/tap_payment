<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment_custom\Functional;

use Drupal\Core\State\StateInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\tap_payment\Traits\TapFixtureTrait;
use Drupal\tap_payment\Entity\TapTransactionInterface;
use Drupal\tap_payment\Service\TapPaymentSettings;
use Drupal\tap_payment_custom\FormSettings;
use Drupal\tap_payment_test\StubApiClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the payment form cannot be made to charge twice, or endlessly.
 *
 * Everything here is about a public endpoint that spends money on request. The
 * assertions are counted in outbound calls to Tap, because that is the unit
 * that costs something: an abuse vector is only closed if it stops the call,
 * not merely if it hides the result.
 *
 * @group tap_payment
 *
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('tap_payment')]
final class PaymentAbuseTest extends BrowserTestBase {

  use TapFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['tap_payment', 'tap_payment_custom', 'tap_payment_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config(TapPaymentSettings::CONFIG_NAME)
      ->set('environment', 'sandbox')
      ->set('sandbox_secret_key', 'sk_test_XKokBfNWv6FIYuTMg5sLPjhJ')
      ->save();

    $this->config(FormSettings::CONFIG_NAME)
      ->set('amount', '1.000')
      ->set('currency', 'KWD')
      ->set('description', 'One licence')
      ->set('source_id', 'src_all')
      ->save();

    $this->drupalLogin($this->drupalCreateUser(['make tap payments']));
  }

  /**
   * The regression: pressing Pay twice must not open two charges.
   *
   * Before the form derived an idempotency key, every submission was given a
   * fresh UUID, so the core service's duplicate protection and Tap's own
   * `reference.idempotent` were both inert. A double-click billed twice.
   */
  public function testRepeatSubmissionDoesNotChargeAgain(): void {
    $this->queueCharge();
    $this->pay();

    $first = $this->latestTransaction();
    self::assertCount(1, StubApiClient::requests($this->state()));

    // The same payer, the same details, seconds later: a double-click, a
    // browser retry, a reloaded confirmation. All the same gesture.
    $this->queueCharge();
    $this->pay();

    self::assertSame(
      $first->id(),
      $this->latestTransaction()->id(),
      'A repeat submission must rejoin the payment it already started.',
    );

    $requests = StubApiClient::requests($this->state());
    self::assertCount(2, $requests, 'The second call re-posts the same key rather than opening a charge.');
    self::assertSame(
      $requests[0]['body']['reference']['idempotent'],
      $requests[1]['body']['reference']['idempotent'],
      'Tap must be given the same idempotency key, which is what makes it return the original charge.',
    );
  }

  /**
   * A different payer on the same browser gets a payment of their own.
   */
  public function testDifferentSubmissionStartsNewPayment(): void {
    $this->queueCharge();
    $this->pay('ada@example.com');
    $first = $this->latestTransaction();

    $this->queueCharge('chg_second');
    $this->pay('grace@example.com');

    self::assertNotSame(
      $first->id(),
      $this->latestTransaction()->id(),
      'A genuinely different payer must not be handed someone else’s payment.',
    );
  }

  /**
   * Once the allowance is spent, Tap is not called at all.
   */
  public function testThrottlingStopsTheCallBeforeItIsMade(): void {
    $this->config(FormSettings::CONFIG_NAME)->set('flood_limit', 2)->save();

    for ($i = 0; $i < 2; $i++) {
      $this->queueCharge("chg_" . $i);
      // A different address each time, so this is the throttle stopping them
      // rather than the idempotency key rejoining them.
      $this->pay("payer{$i}@example.com");
    }

    $before = count(StubApiClient::requests($this->state()));

    $this->queueCharge('chg_blocked');
    $this->pay('third@example.com');

    $this->assertSession()->pageTextContains('Too many payment attempts.');
    self::assertCount(
      $before,
      StubApiClient::requests($this->state()),
      'A throttled submission must never reach Tap.',
    );
  }

  /**
   * Raising the limit in configuration raises it in practice.
   */
  public function testTheLimitIsConfigurable(): void {
    $this->config(FormSettings::CONFIG_NAME)->set('flood_limit', 1)->save();

    $this->queueCharge();
    $this->pay('one@example.com');

    $this->queueCharge('chg_two');
    $this->pay('two@example.com');
    $this->assertSession()->pageTextContains('Too many payment attempts.');

    // Same site, same payer, a limit that allows it.
    $this->config(FormSettings::CONFIG_NAME)->set('flood_limit', 50)->save();
    $this->container->get('flood')->clear('tap_payment_custom.pay.session');

    $this->queueCharge('chg_three');
    $this->pay('three@example.com');
    $this->assertSession()->pageTextNotContains('Too many payment attempts.');
  }

  /**
   * A misconfigured amount never reaches Tap.
   */
  public function testAnUnusableAmountIsRefusedLocally(): void {
    $this->config(FormSettings::CONFIG_NAME)->set('amount', 'not-a-number')->save();

    $this->drupalGet('/tap-payment/pay');

    // The form refuses to render at all, so there is nothing to submit — which
    // is the point. Before this, money() threw straight out of buildForm() and
    // the payer got an exception page.
    $this->assertSession()->pageTextContains('Payments are not available at the moment.');
    $this->assertSession()->buttonNotExists('Pay');
    self::assertCount(0, StubApiClient::requests($this->state()), 'Nothing should have been sent to Tap.');
  }

  /**
   * Queues one successful charge response.
   *
   * @param string|null $chargeId
   *   A charge id, when the test needs it to differ from the fixture's.
   */
  private function queueCharge(?string $chargeId = NULL): void {
    $charge = $this->fixture('charge_initiated_response');
    $charge['transaction']['url'] = $this->buildUrl('/user/login');

    if ($chargeId !== NULL) {
      $charge['id'] = $chargeId;
    }

    StubApiClient::queue($this->state(), $charge);
  }

  /**
   * Submits the payment form.
   *
   * @param string $email
   *   The payer's address.
   */
  private function pay(string $email = 'ada@example.com'): void {
    $this->drupalGet('/tap-payment/pay');
    $this->submitForm([
      'first_name' => 'Ada',
      'last_name' => 'Lovelace',
      'email' => $email,
    ], 'Pay');
  }

  /**
   * The most recently created transaction.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The transaction.
   */
  private function latestTransaction(): TapTransactionInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('tap_payment_transaction');
    $storage->resetCache();
    $all = $storage->loadMultiple();
    $last = end($all);

    self::assertInstanceOf(TapTransactionInterface::class, $last);

    return $last;
  }

  /**
   * The state service the stub records through.
   *
   * @return \Drupal\Core\State\StateInterface
   *   The state service.
   */
  private function state(): StateInterface {
    return $this->container->get('state');
  }

}
