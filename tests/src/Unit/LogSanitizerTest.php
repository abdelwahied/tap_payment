<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tap_payment\Logger\LogSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests that nothing sensitive can reach a log through this module.
  *
  * @covers \Drupal\tap_payment\Logger\LogSanitizer
 */
#[CoversClass(LogSanitizer::class)]
final class LogSanitizerTest extends UnitTestCase {

  /**
   * The sanitizer under test.
   */
  private LogSanitizer $sanitizer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->sanitizer = new LogSanitizer();
  }

  /**
   * Secrets are removed from a message wherever they appear.
    *
    * @dataProvider secretMessageProvider
   */
  #[DataProvider('secretMessageProvider')]
  public function testSecretsAreRemovedFromMessages(string $message, string $mustNotContain): void {
    $clean = $this->sanitizer->sanitizeMessage($message);

    $this->assertStringNotContainsString($mustNotContain, $clean);
    $this->assertStringContainsString(LogSanitizer::REDACTED, $clean);
  }

  /**
   * Messages that would leak something, and the substring that must not remain.
   *
   * @return array<string, array{string, string}>
   *   Message and forbidden substring.
   */
  public static function secretMessageProvider(): array {
    return [
      'test secret key' => [
        'cURL error on POST with Authorization: Bearer sk_test_XKokBfNWv6FIYuTMg5sLPjhJ',
        'sk_test_XKokBfNWv6FIYuTMg5sLPjhJ',
      ],
      'live secret key' => [
        'Failed using sk_live_abc123DEF456',
        'sk_live_abc123DEF456',
      ],
      'public key' => [
        'pk_test_abcdef123456 was sent',
        'pk_test_abcdef123456',
      ],
      'card token' => [
        'Charged with tok_nLKq4223436fVYL27Nj9P855',
        'tok_nLKq4223436fVYL27Nj9P855',
      ],
      'saved card id' => [
        'Saved as card_IIGi4523416sFHe27jJ9E589',
        'card_IIGi4523416sFHe27jJ9E589',
      ],
      'payer email' => [
        'Charge for ada.lovelace@example.com failed',
        'ada.lovelace@example.com',
      ],
      'card number' => [
        'Declined for 4111 1111 1111 1111',
        '4111 1111 1111 1111',
      ],
      'authorization header' => [
        'headers: {authorization: Bearer abcdef}',
        'abcdef',
      ],
    ];
  }

  /**
   * A message with nothing sensitive comes back unchanged.
   */
  public function testHarmlessMessagesAreLeftAlone(): void {
    $message = 'Tap charge chg_TS012520220955Rr950709475 moved from INITIATED to CAPTURED.';

    $this->assertSame($message, $this->sanitizer->sanitizeMessage($message));
  }

  /**
   * Sensitive keys are dropped whole, whatever their value looks like.
   */
  public function testSensitiveKeysAreDropped(): void {
    $clean = $this->sanitizer->sanitize([
      'id' => 'chg_1',
      'card' => ['first_six' => '446404', 'last_four' => '0007'],
      'customer' => [
        'first_name' => 'Ada',
        'email' => 'ada@example.com',
        'phone' => ['country_code' => '966', 'number' => '512345678'],
      ],
      'source' => ['id' => 'tok_abc123'],
    ]);

    $this->assertSame('chg_1', $clean['id']);
    $this->assertSame(LogSanitizer::REDACTED, $clean['card']);
    $this->assertSame(LogSanitizer::REDACTED, $clean['customer']['email']);
    $this->assertSame(LogSanitizer::REDACTED, $clean['customer']['phone']);
    $this->assertSame('Ada', $clean['customer']['first_name']);
    // The value is still cleaned even where the key is innocent.
    $this->assertSame(LogSanitizer::REDACTED, $clean['source']['id']);
  }

  /**
   * An object has no safe general inspection, so it is dropped.
   */
  public function testObjectsAreDropped(): void {
    $this->assertSame(LogSanitizer::REDACTED, $this->sanitizer->sanitize(new \stdClass()));
  }

  /**
   * Non-string scalars pass through untouched.
   */
  public function testScalarsSurvive(): void {
    $this->assertSame(42, $this->sanitizer->sanitize(42));
    $this->assertTrue($this->sanitizer->sanitize(TRUE));
    $this->assertNull($this->sanitizer->sanitize(NULL));
  }

}
