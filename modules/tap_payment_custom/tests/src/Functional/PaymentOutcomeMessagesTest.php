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
 * Tests what the payer is told when Tap returns no hosted page.
 *
 * `PaymentSession::redirectUrl()` returns NULL for four different reasons, and
 * the regression these tests exist for was answering all four with the same
 * reassuring sentence. A payer whose card Tap declined on the spot was told
 * their payment was "already being processed" and sent away to wait for a
 * confirmation email that was never going to arrive.
 *
 * The distinction cannot be recovered downstream — a rejoined ledger row and a
 * freshly failed one look alike — so each case is asserted here against the
 * message a payer would actually read.
 *
 * @group tap_payment
 *
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('tap_payment')]
final class PaymentOutcomeMessagesTest extends BrowserTestBase {

  use TapFixtureTrait;

  /**
   * The class Drupal puts on the form, from its form id.
   */
  private const FORM_CLASS = 'tap-payment-custom-payment';

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
   * A card Tap declines on the spot is reported as a failure.
   *
   * The release blocker. Tap answers a declined charge with HTTP 200 and a
   * DECLINED status rather than an error, so it arrives through the same path
   * as a success and used to be described as one.
   */
  public function testDeclinedPaymentIsReportedAsFailure(): void {
    $this->queueCharge(status: 'DECLINED', hostedPage: FALSE);
    $this->pay();

    $this->assertSession()->pageTextContains('The payment was not completed.');
    $this->assertSession()->pageTextContains('Your card was not charged');

    // The whole point: the payer must not be sent away to wait for an email.
    $this->assertSession()->pageTextNotContains('already being processed');
    $this->assertSession()->pageTextNotContains('check your email for confirmation');

    self::assertSame('DECLINED', $this->latestTransaction()->getState()->value);
  }

  /**
   * A charge Tap fails outright is reported as a failure.
   */
  public function testFailedPaymentIsReportedAsFailure(): void {
    $this->queueCharge(status: 'FAILED', hostedPage: FALSE);
    $this->pay();

    $this->assertSession()->pageTextContains('The payment was not completed.');
    $this->assertSession()->pageTextNotContains('already being processed');

    self::assertSame('FAILED', $this->latestTransaction()->getState()->value);
  }

  /**
   * A repeat submission on a payment still under way keeps the old message.
   *
   * This is the one case the reassuring sentence was written for, and it has to
   * go on saying exactly what it said before.
   */
  public function testRepeatSubmissionOnPendingPaymentSaysItIsProcessing(): void {
    // The first submission starts a payment and is redirected to Tap.
    $this->queueCharge();
    $this->pay();
    $first = $this->latestTransaction();

    // The same payer presses Pay again. The key resolves onto the payment that
    // is already open, and this time Tap answers without a hosted page.
    $this->queueCharge(hostedPage: FALSE);
    $this->pay();

    $this->assertSession()->pageTextContains('Your payment is already being processed.');
    $this->assertSession()->pageTextNotContains('The payment was not completed.');
    $this->assertSession()->pageTextNotContains('could not be started');

    self::assertSame(
      $first->id(),
      $this->latestTransaction()->id(),
      'The second submission must have rejoined the first payment, not opened another.',
    );
  }

  /**
   * A payment that has already been captured says so, rather than "processing".
   */
  public function testCapturedPaymentSaysItIsAlreadyComplete(): void {
    $this->queueCharge();
    $this->pay();

    $this->queueCharge(status: 'CAPTURED', hostedPage: FALSE);
    $this->pay();

    $this->assertSession()->pageTextContains('This payment has already been completed.');
    $this->assertSession()->pageTextNotContains('The payment was not completed.');

    self::assertSame('CAPTURED', $this->latestTransaction()->getState()->value);
  }

  /**
   * A new charge with nowhere to send the payer keeps the original message.
   *
   * Not a failure Tap reported and not a duplicate: the charge is open, but
   * there is no hosted page, so nothing is in flight on the payer's behalf.
   */
  public function testNewChargeWithoutHostedPageCannotBeStarted(): void {
    $this->queueCharge(hostedPage: FALSE);
    $this->pay();

    $this->assertSession()->pageTextContains('The payment could not be started.');
    $this->assertSession()->pageTextNotContains('already being processed');
  }

  /**
   * The form keeps the CSS classes Drupal put on it.
   *
   * FormBuilder::retrieveForm() attaches the form's own class to the render
   * array before buildForm() is called, so a buildForm() that returns a fresh
   * array silently drops it. Asserted in both states, because the state that
   * lost it was the one nobody looks at.
   */
  public function testFormKeepsItsCssClasses(): void {
    $this->drupalGet('/tap-payment/pay');
    $this->assertSession()->buttonExists('Pay');
    $this->assertSession()->elementExists('css', 'form.' . self::FORM_CLASS);

    // Now the same page with the gateway switched off, which is the path that
    // used to return a replacement render array.
    $this->config(TapPaymentSettings::CONFIG_NAME)->set('sandbox_secret_key', '')->save();

    $this->drupalGet('/tap-payment/pay');
    $this->assertSession()->pageTextContains('Payments are not available at the moment.');
    $this->assertSession()->buttonNotExists('Pay');
    $this->assertSession()->elementExists('css', 'form.' . self::FORM_CLASS);
  }

  /**
   * The unusable-amount path keeps the classes too.
   */
  public function testMisconfiguredAmountKeepsTheFormCssClasses(): void {
    $this->config(FormSettings::CONFIG_NAME)->set('amount', 'not-a-number')->save();

    $this->drupalGet('/tap-payment/pay');

    $this->assertSession()->pageTextContains('Payments are not available at the moment.');
    $this->assertSession()->elementExists('css', 'form.' . self::FORM_CLASS);
  }

  /**
   * Queues one charge response.
   *
   * @param string|null $status
   *   The Tap status to report, or NULL for the fixture's own INITIATED.
   * @param bool $hostedPage
   *   Whether the answer carries a hosted payment URL.
   * @param string|null $chargeId
   *   A charge id, when the test needs it to differ from the fixture's.
   */
  private function queueCharge(?string $status = NULL, bool $hostedPage = TRUE, ?string $chargeId = NULL): void {
    $charge = $this->fixture('charge_initiated_response');

    if ($status !== NULL) {
      $charge['status'] = $status;
    }

    if ($hostedPage) {
      $charge['transaction']['url'] = $this->buildUrl('/user/login');
    }
    else {
      // What Tap sends when there is no page to send anyone to — a charge it
      // has already decided about, or one it opened without a checkout.
      unset($charge['transaction']['url']);
    }

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
