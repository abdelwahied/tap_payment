<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tap_payment\Service\InternalUrlValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the open-redirect guard on payment return destinations.
  *
  * @covers \Drupal\tap_payment\Service\InternalUrlValidator
 */
#[CoversClass(InternalUrlValidator::class)]
final class InternalUrlValidatorTest extends UnitTestCase {

  /**
   * The validator under test.
   */
  private InternalUrlValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $stack = new RequestStack();
    $stack->push(Request::create('https://shop.example.com/checkout'));

    $this->validator = new InternalUrlValidator($stack);
  }

  /**
   * URLs the payer may be sent to, and ones they may not.
    *
    * @dataProvider urlProvider
   */
  #[DataProvider('urlProvider')]
  public function testInternalUrlsAreRecognised(string $url, bool $expected): void {
    $this->assertSame($expected, $this->validator->isInternal($url));
  }

  /**
   * Destinations and whether they stay on this site.
   *
   * @return array<string, array{string, bool}>
   *   URL and expectation.
   */
  public static function urlProvider(): array {
    return [
      'rooted path' => ['/thank-you', TRUE],
      'rooted path with query' => ['/thank-you?order=7', TRUE],
      'absolute url on this host' => ['https://shop.example.com/thank-you', TRUE],

      'another site' => ['https://evil.example/thank-you', FALSE],
      // The classic bypass: it is not "external" to a naive string check, but
      // a browser reads it as //host.
      'protocol relative' => ['//evil.example/thank-you', FALSE],
      'javascript scheme' => ['javascript:alert(1)', FALSE],
      'data scheme' => ['data:text/html,<script>alert(1)</script>', FALSE],
      'relative path with no root' => ['thank-you', FALSE],
      'traversal without a root' => ['../admin', FALSE],
      'empty' => ['', FALSE],
      'whitespace' => ['   ', FALSE],
      'host that merely starts the same' => ['https://shop.example.com.evil.test/x', FALSE],
    ];
  }

  /**
   * With no request to compare against, nothing absolute is accepted.
   */
  public function testWithoutRequestAbsoluteUrlsAreRefused(): void {
    $validator = new InternalUrlValidator(new RequestStack());

    $this->assertFalse($validator->isInternal('https://shop.example.com/thank-you'));
    $this->assertTrue($validator->isInternal('/thank-you'));
  }

}
