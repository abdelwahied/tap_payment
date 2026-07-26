<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that the README still describes the module that is actually shipped.
 *
 * Documentation rots silently. A default changed in a container parameter, a
 * configuration key renamed, a message reworded — none of it breaks a test, and
 * all of it leaves an administrator configuring something the module no longer
 * does. The numbers and the promises the README makes are load-bearing enough
 * to be worth asserting, so they fail here rather than in someone's live site.
 *
 * @group tap_payment
 */
#[Group('tap_payment')]
final class ReadmeAccuracyTest extends UnitTestCase {

  /**
   * The module root. Not `$root`, which UnitTestCase already declares.
   */
  private string $moduleRoot;

  /**
   * The README, as text.
   */
  private string $readme;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->moduleRoot = dirname(__DIR__, 3);
    $readme = file_get_contents($this->moduleRoot . '/README.md');

    self::assertIsString($readme, 'The README is readable.');
    $this->readme = $readme;
  }

  /**
   * Every throttling default in the README is the default the module ships.
   */
  public function testDocumentedDefaultsMatchTheContainerParameters(): void {
    $parameters = $this->parameters();
    $documented = $this->documentedDefaults();

    $expected = [
      'flood_limit' => (string) $parameters['tap_payment.pay_flood_limit'],
      'flood_window' => (string) $parameters['tap_payment.pay_flood_window'],
      'flood_ip_multiplier' => (string) $parameters['tap_payment.pay_flood_ip_multiplier'],
      'throttle_by_session' => 'true',
      'throttle_by_email' => 'true',
    ];

    foreach ($expected as $key => $value) {
      self::assertArrayHasKey($key, $documented, sprintf('The README documents a default for %s.', $key));
      self::assertSame($value, $documented[$key], sprintf(
        'The README says %s defaults to %s, but the module ships %s.',
        $key,
        $documented[$key],
        $value,
      ));
    }
  }

  /**
   * The idempotency window quoted in prose is the one the module ships.
   */
  public function testDocumentedIdempotencyWindowMatches(): void {
    $lifetime = $this->parameters()['tap_payment.pay_idempotency_lifetime'];

    self::assertStringContainsString(
      sprintf('(%d seconds by default)', $lifetime),
      $this->readme,
      'The README quotes an idempotency window that is no longer the shipped default.',
    );
  }

  /**
   * Every setting the README names is a real configuration key.
   */
  public function testDocumentedSettingsExistInTheSchema(): void {
    $schema = Yaml::parseFile(
      $this->moduleRoot . '/modules/tap_payment_custom/config/schema/tap_payment_custom.schema.yml',
    );
    $mapping = $schema['tap_payment_custom.settings']['mapping'];

    foreach (array_keys($this->documentedDefaults()) as $key) {
      self::assertArrayHasKey($key, $mapping, sprintf(
        'The README documents %s, which no longer exists in the configuration schema.',
        $key,
      ));
    }
  }

  /**
   * The README's claim that 0 means "keep the default" is what ships.
   */
  public function testShippedConfigurationLeavesTheNumbersAtZero(): void {
    $installed = Yaml::parseFile(
      $this->moduleRoot . '/modules/tap_payment_custom/config/install/tap_payment_custom.settings.yml',
    );

    self::assertStringContainsString(
      'Leave a number at 0 to keep the module',
      $this->readme . $this->settingsFormSource(),
      'The "0 means keep the default" promise is no longer made anywhere.',
    );

    foreach (['flood_limit', 'flood_window', 'flood_ip_multiplier', 'idempotency_lifetime'] as $key) {
      self::assertSame(0, $installed[$key], sprintf(
        'The README promises %s ships at 0 meaning "use the default".',
        $key,
      ));
    }
  }

  /**
   * Each outcome the README promises a message for actually has one.
   *
   * The table exists because the four cases used to share one sentence. If a
   * branch is ever collapsed back into another, this fails.
   */
  public function testEveryDocumentedOutcomeHasMessage(): void {
    $form = file_get_contents(
      $this->moduleRoot . '/modules/tap_payment_custom/src/Form/PaymentForm.php',
    );

    self::assertIsString($form, 'The payment form is readable.');

    $outcomes = [
      'already been completed',
      'already being processed',
      'was not completed',
      'could not be started',
    ];

    foreach ($outcomes as $outcome) {
      self::assertStringContainsString($outcome, $form, sprintf(
        'The README documents the "%s" outcome, but the form no longer says it.',
        $outcome,
      ));
      self::assertStringContainsString($outcome, $this->readme, sprintf(
        'The form says "%s", but the README no longer documents that outcome.',
        $outcome,
      ));
    }
  }

  /**
   * The README does not overstate what the idempotency key identifies.
   *
   * It used to promise that a browser identity was part of the key and that
   * this made it impossible for two people to derive the same one. An anonymous
   * visitor with no session contributes nothing to the key, so the promise was
   * stronger than the code — and a security note that overstates its own
   * guarantee is worse than none.
   */
  public function testTheKeyIsNotDescribedAsBrowserIdentity(): void {
    self::assertStringNotContainsString(
      'The browser identity is part of the key',
      $this->readme,
      'The overstated browser-identity claim is back in the README.',
    );

    self::assertMatchesRegularExpression(
      '/when (one is available|there is one)|if one has already been started/i',
      $this->readme,
      'The README should say the session contributes only when one exists.',
    );
  }

  /**
   * The defaults the README documents, keyed by setting name.
   *
   * @return array<string, string>
   *   The documented default for each setting named in the settings table.
   */
  private function documentedDefaults(): array {
    preg_match_all(
      '/^\|\s*`([a-z_]+)`\s*\|\s*([^|\s]+)\s*\|/m',
      $this->readme,
      $matches,
      PREG_SET_ORDER,
    );

    $defaults = [];

    foreach ($matches as $match) {
      $defaults[$match[1]] = $match[2];
    }

    self::assertNotEmpty($defaults, 'The README still has a settings table to check.');

    return $defaults;
  }

  /**
   * The module's container parameters.
   *
   * @return array<string, mixed>
   *   Every parameter the module declares.
   */
  private function parameters(): array {
    // PARSE_CUSTOM_TAGS because the service file uses Symfony's own
    // `!tagged_iterator`, which the plain parser refuses rather than ignores.
    $services = Yaml::parseFile(
      $this->moduleRoot . '/tap_payment.services.yml',
      Yaml::PARSE_CUSTOM_TAGS,
    );

    return $services['parameters'];
  }

  /**
   * The standalone form's settings form, as text.
   *
   * @return string
   *   The source.
   */
  private function settingsFormSource(): string {
    $source = file_get_contents(
      $this->moduleRoot . '/modules/tap_payment_custom/src/Form/SettingsForm.php',
    );

    self::assertIsString($source, 'The settings form is readable.');

    return $source;
  }

}
