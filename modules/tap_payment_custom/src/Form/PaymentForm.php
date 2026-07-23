<?php

declare(strict_types=1);

namespace Drupal\tap_payment_custom\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\tap_payment\Dto\Customer;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Exception\TapPaymentException;
use Drupal\tap_payment\Service\TapPaymentSettings;
use Drupal\tap_payment\TapPaymentInterface;
use Drupal\tap_payment_custom\FormSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Collects a payer's details and sends them to Tap.
 *
 * This module exists to prove a claim made in the core module's
 * documentation: that any Drupal module can take a Tap payment through the
 * public API alone. So look at what is *not* here. No HTTP client, no API key,
 * no charge fields, no signature, no webhook, no state machine, no knowledge
 * that Tap exists beyond the service name. One call to createPayment(), one
 * redirect. Everything else is the core module's problem.
 *
 * The form asks only for what Tap marks required on the customer object — a
 * first name and an email — plus an optional phone number. Collecting more
 * would be collecting it for nothing.
 *
 * @internal
 *   The form class may change shape.
 */
final class PaymentForm extends FormBase {

  /**
   * Constructs a PaymentForm.
   *
   * @param \Drupal\tap_payment\TapPaymentInterface $payments
   *   The one service this module needs.
   * @param \Drupal\tap_payment_custom\FormSettings $settings
   *   What to charge.
   * @param \Drupal\tap_payment\Service\TapPaymentSettings $gatewaySettings
   *   Reports whether the gateway is usable, so the form can say so.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's log channel.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   Supplies the language Tap should render its page in.
   */
  public function __construct(
    private readonly TapPaymentInterface $payments,
    private readonly FormSettings $settings,
    private readonly TapPaymentSettings $gatewaySettings,
    private readonly LoggerChannelInterface $logger,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tap_payment.payment'),
      $container->get('tap_payment_custom.settings'),
      $container->get('tap_payment.settings'),
      $container->get('logger.channel.tap_payment'),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tap_payment_custom_payment';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if (!$this->gatewaySettings->isConfigured()) {
      // Said plainly rather than by letting the submission fail: a payer who
      // fills in a form and then gets an error learns nothing useful.
      $form['unavailable'] = [
        '#type' => 'item',
        '#markup' => $this->t('Payments are not available at the moment. Please try again later.'),
      ];

      return $form;
    }

    $money = $this->settings->money();

    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Amount'),
      '#markup' => $this->t('@amount @currency', [
        '@amount' => $money->amount,
        '@currency' => $money->currency,
      ]),
    ];

    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#required' => TRUE,
      '#maxlength' => 150,
    ];

    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name'),
      '#maxlength' => 150,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
    ];

    $form['phone_country_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phone country code'),
      '#size' => 6,
      '#maxlength' => 4,
      '#description' => $this->t('Without the leading plus, for example 966.'),
    ];

    $form['phone_number'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone number'),
      '#maxlength' => 20,
      '#description' => $this->t('Without the country code.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Pay'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $code = trim((string) $form_state->getValue('phone_country_code'));
    $number = trim((string) $form_state->getValue('phone_number'));

    if (($code === '') !== ($number === '')) {
      $form_state->setErrorByName('phone_number', $this->t('A phone number needs both a country code and a number, or neither.'));
    }

    if ($code !== '' && preg_match('/^\d{1,4}$/', $code) !== 1) {
      $form_state->setErrorByName('phone_country_code', $this->t('The country code must be digits only.'));
    }

    if ($number !== '' && preg_match('/^\d{4,15}$/', $number) !== 1) {
      $form_state->setErrorByName('phone_number', $this->t('The phone number must be digits only.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $code = trim((string) $form_state->getValue('phone_country_code'));
    $number = trim((string) $form_state->getValue('phone_number'));

    try {
      $session = $this->payments->createPayment(new PaymentRequest(
        money: $this->settings->money(),
        customer: new Customer(
          firstName: trim((string) $form_state->getValue('first_name')),
          email: trim((string) $form_state->getValue('email')),
          lastName: trim((string) $form_state->getValue('last_name')) ?: NULL,
          phoneCountryCode: $code === '' ? NULL : $code,
          phoneNumber: $number === '' ? NULL : $number,
        ),
        returnUrl: Url::fromRoute('tap_payment_custom.complete')->toString(),
        description: $this->settings->description(),
        sourceId: $this->settings->sourceId(),
        languageCode: $this->languageCode(),
        contextModule: 'tap_payment_custom',
      ));
    }
    catch (TapPaymentException $e) {
      // The payer is told that it failed; the reason is for an administrator,
      // because an API error message is not something to show the public.
      Error::logException($this->logger, $e);
      $this->messenger()->addError($this->t('The payment could not be started. Please try again.'));

      return;
    }

    $url = $session->redirectUrl();

    if ($url === NULL) {
      $this->messenger()->addError($this->t('The payment could not be started. Please try again.'));

      return;
    }

    // Tap's hosted page is by definition off this site, so this is the one
    // redirect in the module that is deliberately external — and it goes only
    // to a URL Tap itself just returned.
    $form_state->setResponse(new TrustedRedirectResponse($url));
  }

  /**
   * The language Tap should render its hosted page in.
   *
   * @return string|null
   *   `ar`, `en`, or NULL to let Tap decide.
   */
  private function languageCode(): ?string {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    return in_array($langcode, ['ar', 'en'], TRUE) ? $langcode : NULL;
  }

}
