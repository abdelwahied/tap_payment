<?php

declare(strict_types=1);

namespace Drupal\tap_payment_webform\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\tap_payment\Dto\Customer;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Exception\TapPaymentException;
use Drupal\tap_payment\TapPaymentInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Charges for a webform submission through Tap.
 *
 * The handler maps element keys to the fields a charge needs, rather than
 * dictating what a form must be called. A donation form, a booking form and a
 * membership renewal all have an amount and an email somewhere; the point of
 * the mapping is that none of them has to be rewritten to take a payment.
 *
 * The submission is saved before the payer leaves, always. A submission that
 * only existed once payment succeeded would lose every abandoned attempt —
 * along with any way of telling an abandoned attempt from a failed one. The
 * two are linked through the transaction's context id, which is the submission
 * id, so they can be reconciled afterwards whatever the payer does next and
 * without the form having to define an element to hold the reference.
 *
 * @internal
 *   A Webform plugin; not part of the public API.
 *
 * @WebformHandler(
 *   id = "tap_payment",
 *   label = @Translation("Tap payment"),
 *   category = @Translation("Payment"),
 *   description = @Translation("Charges the submitter through Tap Payments and redirects them to the hosted payment page."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */
final class TapPaymentWebformHandler extends WebformHandlerBase {

  /**
   * The Tap payment service.
   */
  private TapPaymentInterface $payments;

  /**
   * Where the payer should be sent, once a charge has been created.
   */
  private ?string $redirectUrl = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->payments = $container->get('tap_payment.payment');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'amount_element' => '',
      'fixed_amount' => '',
      'currency' => 'KWD',
      'email_element' => 'email',
      'first_name_element' => '',
      'last_name_element' => '',
      'description' => '',
      'source_id' => PaymentRequest::SOURCE_ALL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['amount_element'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount element key'),
      '#default_value' => $this->configuration['amount_element'],
      '#description' => $this->t('The element holding what to charge. Leave empty to charge a fixed amount instead.'),
    ];

    $form['fixed_amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fixed amount'),
      '#default_value' => $this->configuration['fixed_amount'],
      '#description' => $this->t('Used when no amount element is named. A positive decimal.'),
    ];

    $form['currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency'),
      '#default_value' => $this->configuration['currency'],
      '#required' => TRUE,
      '#size' => 5,
      '#maxlength' => 3,
      '#description' => $this->t('A three-letter ISO 4217 code your Tap account is enabled for.'),
    ];

    $form['email_element'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email element key'),
      '#default_value' => $this->configuration['email_element'],
      '#required' => TRUE,
      '#description' => $this->t('Tap requires an email address for every charge.'),
    ];

    $form['first_name_element'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name element key'),
      '#default_value' => $this->configuration['first_name_element'],
      '#description' => $this->t('Tap requires a first name. When this is empty or the element has no value, the submission number is sent instead.'),
    ];

    $form['last_name_element'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name element key'),
      '#default_value' => $this->configuration['last_name_element'],
    ];

    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $this->configuration['description'],
      '#maxlength' => 1000,
      '#description' => $this->t('Shown to the payer on the Tap page.'),
    ];

    $form['source_id'] = [
      '#type' => 'radios',
      '#title' => $this->t('Payment methods'),
      '#default_value' => $this->configuration['source_id'],
      '#options' => [
        PaymentRequest::SOURCE_ALL => $this->t('Every method enabled on the Tap account'),
        PaymentRequest::SOURCE_CARD => $this->t('Cards only'),
      ],
      '#required' => TRUE,
    ];

    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE): void {
    // Only when a submission is first created. A resubmission is a new
    // submission with a new id, and so a new payment; re-saving an existing one
    // is not. The submission is not modified here — the link between the two is
    // kept on the transaction, whose context id is this submission's id, so no
    // extra webform element has to be defined to hold it.
    if ($update) {
      return;
    }

    try {
      $session = $this->payments->createPayment(new PaymentRequest(
        money: $this->money($webform_submission),
        customer: $this->customer($webform_submission),
        returnUrl: $this->returnUrl($webform_submission),
        description: $this->configuration['description'] ?: NULL,
        // Keyed on the submission UUID, so a submission saved twice — which
        // Webform does during its own flow — cannot create two charges.
        idempotencyKey: 'webform-' . $webform_submission->uuid(),
        orderReference: (string) $webform_submission->id(),
        sourceId: (string) $this->configuration['source_id'],
        contextModule: 'tap_payment_webform',
        contextId: (string) $webform_submission->id(),
      ));
    }
    catch (TapPaymentException $e) {
      Error::logException($this->getLogger(), $e);
      $this->messenger()->addError($this->t('The payment could not be started. Your submission has been saved.'));

      return;
    }

    $this->redirectUrl = $session->redirectUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function confirmForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission): void {
    if ($this->redirectUrl === NULL) {
      return;
    }

    // Tap's hosted page is off this site by definition, and this is the only
    // URL trusted here — it came straight back from the charge that was just
    // created, not from anything a submitter typed.
    $form_state->setResponse(new TrustedRedirectResponse($this->redirectUrl));
  }

  /**
   * What to charge for a submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission.
   *
   * @return \Drupal\tap_payment\Dto\Money
   *   The amount and currency.
   *
   * @throws \Drupal\tap_payment\Exception\InvalidPaymentRequestException
   *   When neither the element nor the fixed amount yields a usable value.
   */
  private function money(WebformSubmissionInterface $submission): Money {
    $key = trim((string) $this->configuration['amount_element']);
    $amount = $key === ''
      ? (string) $this->configuration['fixed_amount']
      : (string) $submission->getElementData($key);

    return Money::fromNumeric(trim($amount), (string) $this->configuration['currency']);
  }

  /**
   * Who is paying, from the elements the form was told to read.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission.
   *
   * @return \Drupal\tap_payment\Dto\Customer
   *   The payer.
   *
   * @throws \Drupal\tap_payment\Exception\InvalidPaymentRequestException
   *   When the email element holds nothing usable.
   */
  private function customer(WebformSubmissionInterface $submission): Customer {
    $first = trim((string) $this->element($submission, 'first_name_element'));

    return new Customer(
      firstName: $first !== '' ? $first : (string) $this->t('Submission @id', ['@id' => $submission->id()]),
      email: trim((string) $this->element($submission, 'email_element')),
      lastName: trim((string) $this->element($submission, 'last_name_element')) ?: NULL,
    );
  }

  /**
   * Reads one configured element from a submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission.
   * @param string $setting
   *   The configuration key naming the element.
   *
   * @return mixed
   *   The value, or NULL when no element is configured.
   */
  private function element(WebformSubmissionInterface $submission, string $setting): mixed {
    $key = trim((string) $this->configuration[$setting]);

    return $key === '' ? NULL : $submission->getElementData($key);
  }

  /**
   * Where the payer lands once the module has verified the outcome.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission.
   *
   * @return string
   *   The webform's own confirmation page.
   */
  private function returnUrl(WebformSubmissionInterface $submission): string {
    return Url::fromRoute(
      'entity.webform.confirmation',
      ['webform' => $submission->getWebform()->id()],
      ['query' => ['token' => $submission->getToken()]],
    )->toString();
  }

}
