<?php

declare(strict_types=1);

namespace Drupal\tap_payment_custom\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Exception\InvalidPaymentRequestException;
use Drupal\tap_payment_custom\FormSettings;

/**
 * Sets what the standalone payment form charges.
 *
 * These are the site's own choices, not Tap's — which is why they live in this
 * submodule and not on the gateway's settings page. The core module stays free
 * of anything a particular site happens to sell.
 *
 * @internal
 *   The form class may change shape.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tap_payment_custom_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [FormSettings::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(FormSettings::CONFIG_NAME);

    $form['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount'),
      '#default_value' => (string) $config->get('amount'),
      '#required' => TRUE,
      '#description' => $this->t('A positive decimal, written with the number of places the currency uses — for example 3.000 for KWD and 2.00 for SAR.'),
    ];

    $form['currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency'),
      '#default_value' => (string) $config->get('currency'),
      '#required' => TRUE,
      '#size' => 5,
      '#maxlength' => 3,
      '#description' => $this->t('A three-letter ISO 4217 code your Tap account is enabled for.'),
    ];

    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => (string) $config->get('description'),
      '#maxlength' => 1000,
      '#description' => $this->t('Shown to the payer on the Tap page.'),
    ];

    $form['source_id'] = [
      '#type' => 'radios',
      '#title' => $this->t('Payment methods'),
      '#default_value' => (string) ($config->get('source_id') ?: PaymentRequest::SOURCE_ALL),
      '#options' => [
        PaymentRequest::SOURCE_ALL => $this->t('Every method enabled on the Tap account'),
        PaymentRequest::SOURCE_CARD => $this->t('Cards only'),
      ],
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    try {
      // Validated through the same value object the payment itself uses, so
      // the form can never store an amount a charge would refuse.
      Money::fromNumeric(
        trim((string) $form_state->getValue('amount')),
        trim((string) $form_state->getValue('currency')),
      );
    }
    catch (InvalidPaymentRequestException $e) {
      $form_state->setErrorByName('amount', $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $money = Money::fromNumeric(
      trim((string) $form_state->getValue('amount')),
      trim((string) $form_state->getValue('currency')),
    );

    $this->config(FormSettings::CONFIG_NAME)
      ->set('amount', $money->amount)
      ->set('currency', $money->currency)
      ->set('description', trim((string) $form_state->getValue('description')))
      ->set('source_id', (string) $form_state->getValue('source_id'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
