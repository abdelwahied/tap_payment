<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Unit;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\tap_payment\Api\TapApiClient;
use Drupal\tap_payment\Exception\ApiException;
use Drupal\tap_payment\Exception\AuthenticationException;
use Drupal\tap_payment\Exception\ConfigurationException;
use Drupal\tap_payment\Exception\RateLimitException;
use Drupal\tap_payment\Logger\LogSanitizer;
use Drupal\tap_payment\Service\TapPaymentSettings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the transport, with no network involved.
  *
  * @covers \Drupal\tap_payment\Api\TapApiClient
 */
#[CoversClass(TapApiClient::class)]
final class TapApiClientTest extends UnitTestCase {

  /**
   * The requests the client actually sent.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $history = [];

  /**
   * The secret key the stub settings return.
   */
  private const SECRET = 'sk_test_XKokBfNWv6FIYuTMg5sLPjhJ';

  /**
   * Every request carries the documented bearer credentials and JSON body.
   */
  public function testRequestShape(): void {
    $client = $this->client([new Response(200, [], '{"id":"chg_1"}')]);

    $response = $client->post('charges', ['amount' => 10.5, 'currency' => 'KWD']);

    $this->assertTrue($response->isSuccessful());
    $this->assertSame(['id' => 'chg_1'], $response->data);

    $request = $this->history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('https://api.tap.company/v2/charges', (string) $request->getUri());
    $this->assertSame('Bearer ' . self::SECRET, $request->getHeaderLine('Authorization'));
    $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    $this->assertSame('{"amount":10.5,"currency":"KWD"}', (string) $request->getBody());
  }

  /**
   * A caller cannot replace the Authorization header with one of its own.
   */
  public function testCallerHeadersCannotOverrideCredentials(): void {
    $client = $this->client([new Response(200, [], '{}')]);

    $client->post('charges', [], ['Authorization' => 'Bearer sk_live_attacker', 'lang_code' => 'ar']);

    $request = $this->history[0]['request'];
    $this->assertSame('Bearer ' . self::SECRET, $request->getHeaderLine('Authorization'));
    $this->assertSame('ar', $request->getHeaderLine('lang_code'));
  }

  /**
   * Throttling is retried, and the eventual success is returned.
   */
  public function testRateLimitIsRetriedThenSucceeds(): void {
    $client = $this->client([
      new Response(429, [], '{}'),
      new Response(200, [], '{"id":"chg_1"}'),
    ]);

    $this->assertTrue($client->get('charges/chg_1')->isSuccessful());
    $this->assertCount(2, $this->history);
  }

  /**
   * Throttling that outlasts the retries raises its own exception.
   */
  public function testExhaustedRateLimitRaises(): void {
    $client = $this->client([
      new Response(429, [], '{}'),
      new Response(429, [], '{}'),
      new Response(429, [], '{}'),
    ]);

    $this->expectException(RateLimitException::class);
    $client->get('charges/chg_1');
  }

  /**
   * A server fault is retried; a rejected request never is.
   */
  public function testOnlyTransportFailuresAreRetried(): void {
    $client = $this->client([
      new Response(500, [], '{}'),
      new Response(200, [], '{"id":"chg_1"}'),
    ]);
    $client->get('charges/chg_1');
    $this->assertCount(2, $this->history);

    $this->history = [];
    $client = $this->client([
      new Response(400, [], '{"errors":[{"code":"1110","description":"Redirect URL is missing"}]}'),
    ]);

    try {
      $client->post('charges', []);
      $this->fail('A rejected charge must raise.');
    }
    catch (ApiException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertSame(['1110'], $e->getErrorCodes());
      $this->assertStringContainsString('1110', $e->getMessage());
      // One attempt only: retrying a rejected charge cannot make it succeed,
      // and retrying one that did succeed bills the customer twice.
      $this->assertCount(1, $this->history);
    }
  }

  /**
   * A refused key is reported as an authentication problem, not a generic one.
   */
  public function testUnauthorisedIsDistinguished(): void {
    $client = $this->client([new Response(401, [], '{"errors":[{"code":"1101","description":"mismatch"}]}')]);

    $this->expectException(AuthenticationException::class);
    $client->get('charges/chg_1');
  }

  /**
   * A dropped connection is retried, then reported without leaking the key.
   */
  public function testConnectionFailureIsRetriedThenReportedSafely(): void {
    $request = new Request('POST', 'https://api.tap.company/v2/charges');
    $message = 'cURL error 28 with Authorization: Bearer ' . self::SECRET;

    $client = $this->client([
      new ConnectException($message, $request),
      new ConnectException($message, $request),
      new ConnectException($message, $request),
    ]);

    try {
      $client->post('charges', []);
      $this->fail('An unreachable API must raise.');
    }
    catch (ApiException $e) {
      $this->assertSame(0, $e->getStatusCode());
      $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
      $this->assertStringContainsString(LogSanitizer::REDACTED, $e->getMessage());
    }
  }

  /**
   * A body that is not JSON still reports its status rather than exploding.
   */
  public function testNonJsonBodyIsReportedByStatus(): void {
    $client = $this->client([
      new Response(502, [], '<html>Bad gateway</html>'),
      new Response(502, [], 'x'),
      new Response(502, [], 'x'),
    ]);

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('HTTP 502');
    $client->get('charges/chg_1');
  }

  /**
   * Without a usable key nothing is sent at all.
   */
  public function testMissingCredentialsStopTheRequest(): void {
    $client = new TapApiClient(
      new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{}')]))]),
      $this->settings(''),
      $this->createMock(LoggerChannelInterface::class),
      new LogSanitizer(),
      'https://api.tap.company/v2/',
      30.0,
      10.0,
      2,
      1,
    );

    $this->expectException(ConfigurationException::class);
    $client->post('charges', []);
  }

  /**
   * Builds a client whose transport replays the given queue.
   *
   * @param array<int, mixed> $queue
   *   Responses or exceptions, in the order Guzzle should produce them.
   *
   * @return \Drupal\tap_payment\Api\TapApiClient
   *   The client under test.
   */
  private function client(array $queue): TapApiClient {
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($this->history));

    return new TapApiClient(
      new Client(['handler' => $stack]),
      $this->settings(self::SECRET),
      $this->createMock(LoggerChannelInterface::class),
      new LogSanitizer(),
      'https://api.tap.company/v2/',
      30.0,
      10.0,
      2,
      // One millisecond of backoff: the retry policy is what is under test,
      // not the wait.
      1,
    );
  }

  /**
   * Real settings over a stub config factory.
   *
   * TapPaymentSettings is final on purpose — it is the one place that decides
   * which key is in play, and a test double would be free to disagree with it.
   * Driving the real object from stub configuration keeps that decision under
   * test rather than mocked away.
   *
   * @param string $key
   *   The sandbox secret key to configure, or an empty string for none.
   *
   * @return \Drupal\tap_payment\Service\TapPaymentSettings
   *   The settings service.
   */
  private function settings(string $key): TapPaymentSettings {
    return new TapPaymentSettings($this->getConfigFactoryStub([
      TapPaymentSettings::CONFIG_NAME => [
        'environment' => 'sandbox',
        'sandbox_secret_key' => $key,
        'live_secret_key' => '',
      ],
    ]));
  }

}
