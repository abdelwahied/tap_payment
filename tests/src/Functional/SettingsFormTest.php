<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\tap_payment\Form\SettingsForm;
use Drupal\tap_payment\Service\TapPaymentSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the administration form: what it accepts, and what it never shows.
  *
  * @covers \Drupal\tap_payment\Form\SettingsForm
  *
  * @runTestsInSeparateProcesses
 */
#[CoversClass(SettingsForm::class)]
#[RunTestsInSeparateProcesses]
final class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['tap_payment'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The settings path.
   */
  private const PATH = '/admin/config/services/tap-payment';

  /**
   * Only an administrator reaches the credentials.
   */
  public function testAccessIsRestricted(): void {
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view tap payment transactions']));
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer tap payment']));
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * The form asks for exactly what Tap requires, and nothing more.
   */
  public function testTheFormIsMinimal(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer tap payment']));
    $this->drupalGet(self::PATH);

    $this->assertSession()->fieldExists('environment');
    $this->assertSession()->fieldExists('sandbox_secret_key');
    $this->assertSession()->fieldExists('live_secret_key');

    // Tap's public key is for its browser SDKs; no endpoint this module calls
    // accepts one, so there is nowhere to put it.
    $this->assertSession()->fieldNotExists('public_key');
    $this->assertSession()->fieldNotExists('merchant_id');
    $this->assertSession()->fieldNotExists('api_base_url');
    $this->assertSession()->fieldNotExists('webhook_url');
  }

  /**
   * A merchant with no account is told where to get one.
   */
  public function testGettingStartedHelp(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer tap payment']));
    $this->drupalGet(self::PATH);

    $this->assertSession()->pageTextContains('Getting started with Tap');
    $this->assertSession()->pageTextContains('Do not have a Tap account yet?');
    $this->assertSession()->linkExists('Create a Tap account');
    $this->assertSession()->linkExists('Tap dashboard');
    $this->assertSession()->linkExists('Tap developer documentation');

    // The webhook address is shown so it can be pasted into Tap, rather than
    // left for the administrator to work out from the routing file.
    $this->assertSession()->pageTextContains('/tap-payment/webhook');

    // Both credentials an administrator might come looking for are explained,
    // and neither gets a field — the module sends neither.
    $this->assertSession()->pageTextContains('Public API key');
    $this->assertSession()->pageTextContains('Webhook secret');
    $this->assertSession()->fieldNotExists('public_key');
    $this->assertSession()->fieldNotExists('webhook_secret');
  }

  /**
   * Every off-site link opens safely in a new tab.
   *
   * `target="_blank"` without `rel="noopener"` lets the opened page navigate
   * this administration page through `window.opener`.
   */
  public function testExternalLinksCannotReachBack(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer tap payment']));
    $this->drupalGet(self::PATH);

    $links = $this->getSession()->getPage()->findAll('css', 'a[target="_blank"]');
    self::assertNotEmpty($links, 'The help section should offer links to Tap.');

    foreach ($links as $link) {
      $rel = (string) $link->getAttribute('rel');
      self::assertStringContainsString('noopener', $rel, 'Link "' . $link->getText() . '" is missing rel="noopener".');
    }
  }

  /**
   * The help opens itself only while there is nothing configured yet.
   */
  public function testHelpCollapsesOnceConfigured(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer tap payment']));

    $this->drupalGet(self::PATH);
    self::assertNotNull(
      $this->getSession()->getPage()->find('css', 'details[open] summary:contains("Getting started")'),
      'An unconfigured site should see the help without having to open it.',
    );

    $this->config(TapPaymentSettings::CONFIG_NAME)
      ->set('environment', 'sandbox')
      ->set('sandbox_secret_key', 'sk_test_XKokBfNWv6FIYuTMg5sLPjhJ')
      ->save();

    $this->drupalGet(self::PATH);
    self::assertNull(
      $this->getSession()->getPage()->find('css', 'details[open] summary:contains("Getting started")'),
      'Once configured the help should be out of the way.',
    );
  }

  /**
   * A saved key is stored, and never rendered back into a page.
   */
  public function testKeysAreWriteOnly(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer tap payment']));

    $key = 'sk_test_XKokBfNWv6FIYuTMg5sLPjhJ';
    $this->drupalGet(self::PATH);
    $this->submitForm([
      'environment' => 'sandbox',
      'sandbox_secret_key' => $key,
    ], 'Save configuration');
    $this->assertSession()->statusMessageExists();

    $this->assertSame($key, $this->config(TapPaymentSettings::CONFIG_NAME)->get('sandbox_secret_key'));

    // The page that just saved it must not contain it, and neither must the
    // page that renders the form again.
    $this->assertSession()->responseNotContains($key);
    $this->drupalGet(self::PATH);
    $this->assertSession()->responseNotContains($key);
    $this->assertSession()->pageTextContains('A key is currently stored; leave this blank to keep it.');
  }

  /**
   * Submitting the form with an empty key keeps the stored one.
   */
  public function testEmptySubmissionKeepsTheStoredKey(): void {
    $key = 'sk_test_XKokBfNWv6FIYuTMg5sLPjhJ';
    $this->config(TapPaymentSettings::CONFIG_NAME)->set('sandbox_secret_key', $key)->save();

    $this->drupalLogin($this->drupalCreateUser(['administer tap payment']));
    $this->drupalGet(self::PATH);
    $this->submitForm(['environment' => 'sandbox'], 'Save configuration');

    $this->assertSame($key, $this->config(TapPaymentSettings::CONFIG_NAME)->get('sandbox_secret_key'));
  }

  /**
   * A live key in the sandbox field is refused before it can charge anybody.
   */
  public function testKeyForTheOtherEnvironmentIsRefused(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer tap payment']));
    $this->drupalGet(self::PATH);
    $this->submitForm([
      'environment' => 'sandbox',
      'sandbox_secret_key' => 'sk_live_realMoney',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('A sandbox key must begin with sk_test_');
    $this->assertSame('', $this->config(TapPaymentSettings::CONFIG_NAME)->get('sandbox_secret_key'));
  }

  /**
   * Going live without a live key is refused.
   */
  public function testProductionNeedsItsOwnKey(): void {
    $this->config(TapPaymentSettings::CONFIG_NAME)
      ->set('sandbox_secret_key', 'sk_test_XKokBfNWv6FIYuTMg5sLPjhJ')
      ->save();

    $this->drupalLogin($this->drupalCreateUser(['administer tap payment']));
    $this->drupalGet(self::PATH);
    $this->submitForm(['environment' => 'production'], 'Save configuration');

    $this->assertSession()->pageTextContains('Selecting the production environment requires its secret key.');
    $this->assertSame('sandbox', $this->config(TapPaymentSettings::CONFIG_NAME)->get('environment'));
  }

  /**
   * The transaction ledger is visible to an auditor and to nobody else.
   */
  public function testLedgerAccess(): void {
    $path = self::PATH . '/transactions';

    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view tap payment transactions']));
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No payments have been attempted yet.');

    $this->drupalLogin($this->drupalCreateUser([]));
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);
  }

}
