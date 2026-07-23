<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment_custom\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\tap_payment\Traits\TapFixtureTrait;
use Drupal\tap_payment\Dto\Customer;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Service\TapPaymentSettings;
use Drupal\tap_payment_custom\FormSettings;
use Drupal\tap_payment_test\StubApiClient;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the standalone form end to end, through the public API only.
 */
#[RunTestsInSeparateProcesses]
final class StandalonePaymentTest extends BrowserTestBase {

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
   * Submitting the form starts a charge and sends the payer to Tap.
   */
  public function testPayingRedirectsToTap(): void {
    // Point Tap's hosted URL at an on-site path so the redirect the form issues
    // stays on this site during the test instead of reaching for the real Tap
    // checkout host.
    $charge = $this->fixture('charge_initiated_response');
    $charge['transaction']['url'] = $this->buildUrl('/user/login');
    StubApiClient::queue($this->container->get('state'), $charge);

    $this->drupalGet('/tap-payment/pay');
    $this->assertSession()->pageTextContains('1.000 KWD');

    $this->submitForm([
      'first_name' => 'Ada',
      'last_name' => 'Lovelace',
      'email' => 'ada@example.com',
    ], 'Pay');

    $sent = StubApiClient::requests($this->container->get('state'))[0];
    $this->assertSame('charges', $sent['path']);
    $this->assertSame('Ada', $sent['body']['customer']['first_name']);
    $this->assertSame('One licence', $sent['body']['description']);
    $this->assertSame(1.0, $sent['body']['amount']);
    $this->assertSame('KWD', $sent['body']['currency']);

    // The redirect and webhook URLs Tap was given are this site's own routes.
    $this->assertStringContainsString('/tap-payment/return/', $sent['body']['redirect']['url']);
    $this->assertStringContainsString('/tap-payment/webhook', $sent['body']['post']['url']);

    $transaction = $this->latestTransaction();
    $this->assertSame(PaymentState::Initiated, $transaction->getState());
    $this->assertSame('tap_payment_custom', $transaction->getContextModule());
  }

  /**
   * Half a phone number is refused before anything is sent.
   */
  public function testIncompletePhoneIsRefused(): void {
    $this->drupalGet('/tap-payment/pay');
    $this->submitForm([
      'first_name' => 'Ada',
      'email' => 'ada@example.com',
      'phone_country_code' => '966',
    ], 'Pay');

    $this->assertSession()->pageTextContains('A phone number needs both a country code and a number');
    $this->assertSame([], StubApiClient::requests($this->container->get('state')));
  }

  /**
   * With no credentials the form says so instead of failing on submit.
   */
  public function testUnconfiguredGatewaySaysSo(): void {
    $this->config(TapPaymentSettings::CONFIG_NAME)->set('sandbox_secret_key', '')->save();

    $this->drupalGet('/tap-payment/pay');
    $this->assertSession()->pageTextContains('Payments are not available at the moment.');
    $this->assertSession()->buttonNotExists('Pay');
  }

  /**
   * The completion page reports what the ledger says, not what the URL says.
   */
  public function testCompletionPageReadsTheLedger(): void {
    StubApiClient::queue($this->container->get('state'), $this->fixture('charge_initiated_response'));

    $transaction = $this->container->get('tap_payment.payment')->createPayment(new PaymentRequest(
      money: new Money('1.000', 'KWD'),
      customer: new Customer('Ada', 'ada@example.com'),
      returnUrl: '/tap-payment/complete',
      contextModule: 'tap_payment_custom',
    ))->transaction;

    // Still pending: the payer has not paid yet, and the page must not pretend
    // otherwise just because they arrived at it.
    $this->drupalGet('/tap-payment/complete', ['query' => ['tap_transaction' => $transaction->uuid()]]);
    $this->assertSession()->pageTextContains('has not been confirmed yet');

    $transaction->setState(PaymentState::Captured)->save();
    $this->drupalGet('/tap-payment/complete', ['query' => ['tap_transaction' => $transaction->uuid()]]);
    $this->assertSession()->pageTextContains('Thank you. Your payment of 1.000 KWD was received.');

    $this->drupalGet('/tap-payment/complete', ['query' => ['tap_transaction' => 'not-a-uuid']]);
    $this->assertSession()->pageTextContains('No payment was found for this address.');
  }

  /**
   * The form needs a permission.
   */
  public function testAccess(): void {
    $this->drupalLogin($this->drupalCreateUser([]));
    $this->drupalGet('/tap-payment/pay');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The most recently created transaction.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface
   *   The transaction.
   */
  private function latestTransaction() {
    $storage = $this->container->get('entity_type.manager')->getStorage('tap_payment_transaction');
    $storage->resetCache();
    $all = $storage->loadMultiple();

    return end($all);
  }

}
