<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment_webform\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\tap_payment\Traits\TapFixtureTrait;
use Drupal\tap_payment\Service\TapPaymentSettings;
use Drupal\tap_payment_test\StubApiClient;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that submitting a webform starts a Tap payment through the public API.
 */
#[CoversClass(\Drupal\tap_payment_webform\Plugin\WebformHandler\TapPaymentWebformHandler::class)]
#[RunTestsInSeparateProcesses]
final class TapPaymentWebformHandlerTest extends KernelTestBase {

  use TapFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'webform',
    'tap_payment',
    'tap_payment_test',
    'tap_payment_webform',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('webform', ['webform']);
    $this->installEntitySchema('webform_submission');
    $this->installEntitySchema('tap_payment_transaction');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['webform', 'tap_payment']);

    $this->config(TapPaymentSettings::CONFIG_NAME)
      ->set('environment', 'sandbox')
      ->set('sandbox_secret_key', 'sk_test_XKokBfNWv6FIYuTMg5sLPjhJ')
      ->save();

    // Route generation and the open-redirect guard both need a request; a
    // kernel test has none by default.
    $request = Request::create('https://example.com/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $this->container->get('router.request_context')->fromRequest($request);

    StubApiClient::reset($this->container->get('state'));
  }

  /**
   * A submission maps its elements onto a charge and records the transaction.
   */
  public function testSubmissionStartsPayment(): void {
    StubApiClient::queue($this->container->get('state'), $this->fixture('charge_initiated_response'));
    $webform = $this->createPaymentWebform();

    $submission = WebformSubmission::create([
      'webform_id' => $webform->id(),
      'data' => [
        'amount' => '2.500',
        'email' => 'ada@example.com',
        'donor_name' => 'Ada',
      ],
    ]);
    $submission->save();

    $requests = StubApiClient::requests($this->container->get('state'));
    $this->assertCount(1, $requests);

    $body = $requests[0]['body'];
    $this->assertSame('charges', $requests[0]['path']);
    $this->assertSame(2.5, $body['amount']);
    $this->assertSame('KWD', $body['currency']);
    $this->assertSame('Ada', $body['customer']['first_name']);
    $this->assertSame('ada@example.com', $body['customer']['email']);

    // The submission is saved before the redirect, and the transaction records
    // the submission id as its context, so an abandoned payment can still be
    // reconciled to its submission afterwards.
    $transactions = $this->container->get('tap_payment.payment')
      ->loadByContext('tap_payment_webform', (string) $submission->id());
    $this->assertCount(1, $transactions);
    $this->assertSame('webform-' . $submission->uuid(), $transactions[0]->getIdempotencyKey());
  }

  /**
   * Re-saving a submission does not start a second payment.
   */
  public function testResavingDoesNotChargeAgain(): void {
    StubApiClient::queue($this->container->get('state'), $this->fixture('charge_initiated_response'));
    $webform = $this->createPaymentWebform();

    $submission = WebformSubmission::create([
      'webform_id' => $webform->id(),
      'data' => ['amount' => '2.500', 'email' => 'ada@example.com', 'donor_name' => 'Ada'],
    ]);
    $submission->save();
    $submission->resave();

    $this->assertCount(1, StubApiClient::requests($this->container->get('state')));
  }

  /**
   * Builds a webform with the Tap payment handler attached.
   *
   * @return \Drupal\webform\Entity\Webform
   *   The webform.
   */
  private function createPaymentWebform(): Webform {
    $webform = Webform::create([
      'id' => 'donate',
      'title' => 'Donate',
      'elements' => $this->elements(),
    ]);
    $webform->save();

    $webform->addWebformHandler($this->container->get('plugin.manager.webform.handler')->createInstance('tap_payment', [
      'id' => 'tap_payment',
      'handler_id' => 'tap_payment',
      'label' => 'Tap payment',
      'status' => TRUE,
      'weight' => 0,
      'settings' => [
        'amount_element' => 'amount',
        'currency' => 'KWD',
        'email_element' => 'email',
        'first_name_element' => 'donor_name',
        'description' => 'Donation',
        'source_id' => 'src_all',
      ],
    ]));
    $webform->save();

    return $webform;
  }

  /**
   * The webform's element definitions, as YAML.
   *
   * @return string
   *   The elements.
   */
  private function elements(): string {
    return <<<YAML
amount:
  '#type': textfield
  '#title': Amount
email:
  '#type': email
  '#title': Email
donor_name:
  '#type': textfield
  '#title': Name
YAML;
  }

}
