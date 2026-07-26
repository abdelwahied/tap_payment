<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment_custom\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tap_payment_custom\FormSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that an upgraded site ends up with the configuration a fresh one has.
 *
 * A `config/install` file is read once, at install. Keys added to it afterwards
 * reach new sites and no existing one, so without an update hook the same
 * module produces two different `config:export` outputs depending on when the
 * site happened to install it. The behaviour is identical either way — that is
 * what makes the difference easy to miss, and worth pinning down here.
 *
 * The fresh install is not described in this file; it is taken from the module
 * as installed and then deliberately damaged to look like a legacy site. That
 * way the test cannot drift from the shipped defaults the way a hard-coded
 * expectation would.
 *
 * @group tap_payment
 *
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
#[Group('tap_payment')]
final class SettingsUpdateTest extends KernelTestBase {

  /**
   * The keys this release introduced.
   */
  private const NEW_KEYS = [
    'flood_limit',
    'flood_window',
    'flood_ip_multiplier',
    'throttle_by_session',
    'throttle_by_email',
    'idempotency_lifetime',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'tap_payment', 'tap_payment_custom'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['tap_payment_custom']);
    \Drupal::moduleHandler()->loadInclude('tap_payment_custom', 'install');
  }

  /**
   * An upgraded site finishes byte-identical to a freshly installed one.
   *
   * This is the assertion the update hook exists for: after it runs, the two
   * scenarios export the same file, with the same keys in the same order.
   */
  public function testUpgradedConfigurationMatchesFreshInstall(): void {
    $fresh = $this->rawSettings();

    foreach (self::NEW_KEYS as $key) {
      self::assertArrayHasKey($key, $fresh, sprintf('A fresh install ships %s.', $key));
    }

    $this->makeLegacySite();
    self::assertNotSame($fresh, $this->rawSettings(), 'The legacy site must actually differ to begin with.');

    tap_payment_custom_update_10001();

    self::assertSame(
      $fresh,
      $this->rawSettings(),
      'After the update an upgraded site must export exactly what a fresh one does.',
    );
  }

  /**
   * The numbers are written as shipped, not as resolved.
   *
   * The shipped value is 0, which FormSettings reads as "use the module's own
   * default". Writing the resolved 10 or 900 instead would freeze today's
   * default into the site's configuration and opt it out of every later change
   * to that default — the opposite of what the zero is for.
   */
  public function testTheUpdateWritesTheSentinelNotTheResolvedDefault(): void {
    $this->makeLegacySite();
    tap_payment_custom_update_10001();

    $settings = $this->rawSettings();

    self::assertSame(0, $settings['flood_limit']);
    self::assertSame(0, $settings['flood_window']);
    self::assertSame(0, $settings['flood_ip_multiplier']);
    self::assertSame(0, $settings['idempotency_lifetime']);
    self::assertTrue($settings['throttle_by_session']);
    self::assertTrue($settings['throttle_by_email']);

    // And the sentinel still resolves to the shipped default, so nothing about
    // how the form behaves has moved.
    $resolved = $this->container->get(FormSettings::class);

    self::assertSame(10, $resolved->floodLimit());
    self::assertSame(3600, $resolved->floodWindow());
    self::assertSame(10, $resolved->floodIpMultiplier());
    self::assertSame(900, $resolved->idempotencyLifetime());
  }

  /**
   * Nothing an administrator chose is overwritten.
   *
   * Including the values that look like absence. A stored `flood_limit: 0` is a
   * deliberate "use the default", and a stored `throttle_by_email: FALSE` is a
   * deliberate "do not count that bucket"; an update that tested truthiness
   * rather than presence would silently undo both.
   */
  public function testAdministratorValuesArePreserved(): void {
    $this->makeLegacySite();

    $this->config(FormSettings::CONFIG_NAME)
      ->set('amount', '25.500')
      ->set('currency', 'SAR')
      ->set('flood_limit', 3)
      ->set('idempotency_lifetime', 0)
      ->set('throttle_by_email', FALSE)
      ->save();

    tap_payment_custom_update_10001();

    $settings = $this->rawSettings();

    self::assertSame('25.500', $settings['amount']);
    self::assertSame('SAR', $settings['currency']);
    self::assertSame(3, $settings['flood_limit'], 'A chosen limit must survive.');
    self::assertSame(0, $settings['idempotency_lifetime'], 'A chosen zero is a choice, not an absence.');
    self::assertFalse($settings['throttle_by_email'], 'A chosen FALSE is a choice, not an absence.');

    // The keys the administrator never touched are filled in behind them.
    self::assertSame(0, $settings['flood_window']);
    self::assertTrue($settings['throttle_by_session']);
  }

  /**
   * Running the update twice changes nothing the second time.
   */
  public function testTheUpdateIsIdempotent(): void {
    $this->makeLegacySite();

    tap_payment_custom_update_10001();
    $once = $this->rawSettings();

    $message = tap_payment_custom_update_10001();
    $twice = $this->rawSettings();

    self::assertSame($once, $twice, 'A second run must not change anything.');
    self::assertStringContainsString('already complete', $message);
  }

  /**
   * A half-applied update is finished off rather than restarted.
   *
   * Each key is decided on its own, so an interrupted run leaves what it wrote
   * in place and the next run fills in only the remainder.
   */
  public function testPartiallyAppliedUpdateIsCompleted(): void {
    $this->makeLegacySite();

    // As though an earlier run got three keys in before it stopped, one of
    // them at a value the site had chosen.
    $config = $this->config(FormSettings::CONFIG_NAME);
    $config->set('flood_limit', 7)->set('flood_window', 0)->set('throttle_by_session', FALSE)->save();

    tap_payment_custom_update_10001();

    $settings = $this->rawSettings();

    self::assertSame(7, $settings['flood_limit'], 'What the interrupted run wrote must stand.');
    self::assertFalse($settings['throttle_by_session']);

    foreach (self::NEW_KEYS as $key) {
      self::assertArrayHasKey($key, $settings, sprintf('%s must be present after the second run.', $key));
    }
  }

  /**
   * The update reports what it did.
   */
  public function testTheUpdateReportsTheKeysItAdded(): void {
    $this->makeLegacySite();

    $message = tap_payment_custom_update_10001();

    foreach (self::NEW_KEYS as $key) {
      self::assertStringContainsString($key, $message);
    }
  }

  /**
   * Strips the new keys, leaving the configuration a pre-upgrade site had.
   */
  private function makeLegacySite(): void {
    $config = \Drupal::configFactory()->getEditable(FormSettings::CONFIG_NAME);

    foreach (self::NEW_KEYS as $key) {
      $config->clear($key);
    }

    $config->save();

    $stored = $this->rawSettings();

    foreach (self::NEW_KEYS as $key) {
      self::assertArrayNotHasKey($key, $stored, sprintf('%s should be gone before the update runs.', $key));
    }
  }

  /**
   * The stored configuration, exactly as it would be exported.
   *
   * @return array<string, mixed>
   *   The raw data, without any settings.php override folded in.
   */
  private function rawSettings(): array {
    return \Drupal::configFactory()
      ->getEditable(FormSettings::CONFIG_NAME)
      ->getRawData();
  }

}
