<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tap_payment\Dto\Customer;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Enum\Environment;
use Drupal\tap_payment\Exception\InvalidPaymentRequestException;
use Drupal\tap_payment\Utility\CurrencyDecimals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the value objects that refuse a request Tap would reject.
  *
  * @covers \Drupal\tap_payment\Dto\Money
  * @covers \Drupal\tap_payment\Dto\Customer
  * @covers \Drupal\tap_payment\Dto\PaymentRequest
  * @covers \Drupal\tap_payment\Utility\CurrencyDecimals
  * @covers \Drupal\tap_payment\Enum\Environment
 */
#[CoversClass(Money::class)]
#[CoversClass(Customer::class)]
#[CoversClass(PaymentRequest::class)]
#[CoversClass(CurrencyDecimals::class)]
#[CoversClass(Environment::class)]
final class ValueObjectTest extends UnitTestCase {

  /**
   * An amount survives every representation it can arrive in.
    *
    * @dataProvider amountProvider
   */
  #[DataProvider('amountProvider')]
  public function testAmountsNormalise(int|float|string $input, string $expected): void {
    $this->assertSame($expected, Money::fromNumeric($input, 'kwd')->amount);
  }

  /**
   * Amounts as callers hand them over, and their canonical form.
   *
   * @return array<string, array{int|float|string, string}>
   *   Input and expected canonical amount.
   */
  public static function amountProvider(): array {
    return [
      'integer' => [10, '10'],
      'float' => [10.5, '10.5'],
      'float with trailing zeros' => [3.000, '3'],
      'string' => ['10.500', '10.500'],
      'padded string' => ['  7.25  ', '7.25'],
    ];
  }

  /**
   * The currency code is upper-cased, and a bad one is refused.
   */
  public function testCurrencyIsNormalisedAndValidated(): void {
    $this->assertSame('KWD', Money::fromNumeric(1, 'kwd')->currency);

    $this->expectException(InvalidPaymentRequestException::class);
    new Money('1', 'KWDX');
  }

  /**
   * A zero or negative amount is not a payment.
    *
    * @dataProvider badAmountProvider
   */
  #[DataProvider('badAmountProvider')]
  public function testUnusableAmountsAreRefused(string $amount): void {
    $this->expectException(InvalidPaymentRequestException::class);
    new Money($amount, 'KWD');
  }

  /**
   * Amounts that are not a positive decimal.
   *
   * @return array<string, array{string}>
   *   One bad amount per case.
   */
  public static function badAmountProvider(): array {
    return [
      'zero' => ['0'],
      'zero with decimals' => ['0.00'],
      'negative' => ['-5'],
      'not a number' => ['ten'],
      'empty' => [''],
      'scientific notation' => ['1e3'],
    ];
  }

  /**
   * Formatting and comparison both respect the currency's precision.
   */
  public function testFormattingAndEquality(): void {
    $money = new Money('3', 'KWD');

    $this->assertSame('3.000', $money->format(3));
    $this->assertSame('3.00', $money->format(2));
    $this->assertSame(3.0, $money->toNumber(3));

    $this->assertTrue($money->equals(new Money('3.000', 'KWD'), 3));
    $this->assertTrue($money->equals(new Money('3.0004', 'KWD'), 3));
    $this->assertFalse($money->equals(new Money('3.002', 'KWD'), 3));
    $this->assertFalse($money->equals(new Money('3', 'SAR'), 2));
  }

  /**
   * The decimal map answers for what it knows and defaults to two otherwise.
   */
  public function testCurrencyDecimals(): void {
    $decimals = new CurrencyDecimals(['KWD' => 3, 'SAR' => 2]);

    $this->assertSame(3, $decimals->forCurrency('KWD'));
    $this->assertSame(3, $decimals->forCurrency(' kwd '));
    $this->assertSame(2, $decimals->forCurrency('SAR'));
    $this->assertSame(CurrencyDecimals::DEFAULT_DECIMALS, $decimals->forCurrency('NZD'));
  }

  /**
   * A customer needs the two fields Tap marks required.
   */
  public function testCustomerRequiresNameAndEmail(): void {
    $customer = new Customer('Ada', 'ada@example.com');

    $this->assertFalse($customer->hasPhone());

    $this->expectException(InvalidPaymentRequestException::class);
    new Customer('   ', 'ada@example.com');
  }

  /**
   * A malformed email is caught before it reaches Tap as error 1138.
   */
  public function testCustomerEmailIsValidated(): void {
    $this->expectException(InvalidPaymentRequestException::class);
    new Customer('Ada', 'not-an-address');
  }

  /**
   * Half a phone number is not a phone number.
   */
  public function testPartialPhoneIsRefused(): void {
    $this->expectException(InvalidPaymentRequestException::class);
    new Customer('Ada', 'ada@example.com', phoneCountryCode: '966');
  }

  /**
   * The limits Tap documents behind its 11xx errors are enforced locally.
   *
   * @param array<string, mixed> $overrides
   *   Constructor arguments that push the request past a documented limit.
    *
    * @dataProvider overLongProvider
   */
  #[DataProvider('overLongProvider')]
  public function testDocumentedLimitsAreEnforced(array $overrides): void {
    $arguments = array_merge([
      'money' => new Money('1', 'KWD'),
      'customer' => new Customer('Ada', 'ada@example.com'),
      'returnUrl' => '/done',
    ], $overrides);

    $this->expectException(InvalidPaymentRequestException::class);
    new PaymentRequest(...$arguments);
  }

  /**
   * Requests that exceed a documented limit.
   *
   * @return array<string, array{array<string, mixed>}>
   *   Constructor overrides that must be refused.
   */
  public static function overLongProvider(): array {
    return [
      'description over 1000 (error 1121)' => [['description' => str_repeat('a', 1001)]],
      'transaction reference over 100 (error 1123)' => [['transactionReference' => str_repeat('a', 101)]],
      'order reference over 100 (error 1122)' => [['orderReference' => str_repeat('a', 101)]],
      'metadata key over 250 (error 1127)' => [['metadata' => [str_repeat('k', 251) => 'v']]],
      'metadata value over 1000 (error 1128)' => [['metadata' => ['k' => str_repeat('v', 1001)]]],
      // PHP casts a decimal-integer string key to an int on the way in, so a
      // caller who wrote ['12' => 'x'] — exactly what the signature asks for —
      // hands this constructor an integer key. Tap's metadata is a string map.
      'metadata key that PHP cast to an integer' => [['metadata' => ['12' => 'x']]],
      'metadata value that is not a string' => [['metadata' => ['k' => 42]]],
      'unsupported hosted page language' => [['languageCode' => 'fr']],
      'empty return url' => [['returnUrl' => '  ']],
      'empty source' => [['sourceId' => '']],
    ];
  }

  /**
   * The cancel destination falls back to the return destination.
   */
  public function testCancelUrlDefaultsToReturnUrl(): void {
    $request = new PaymentRequest(
      money: new Money('1', 'KWD'),
      customer: new Customer('Ada', 'ada@example.com'),
      returnUrl: '/done',
    );

    $this->assertSame('/done', $request->cancelUrl());
    $this->assertSame(PaymentRequest::SOURCE_ALL, $request->sourceId);
  }

  /**
   * A key is matched against the environment that issues that prefix.
   */
  public function testEnvironmentKeyPrefixes(): void {
    $this->assertSame('sk_test_', Environment::Sandbox->keyPrefix());
    $this->assertSame('sk_live_', Environment::Production->keyPrefix());

    $this->assertTrue(Environment::Sandbox->matchesKey('sk_test_abc'));
    $this->assertFalse(Environment::Sandbox->matchesKey('sk_live_abc'));
    $this->assertTrue(Environment::Production->matchesKey('  sk_live_abc  '));
  }

}
