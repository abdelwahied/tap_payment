<?php

declare(strict_types=1);

namespace Drupal\tap_payment_custom\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Exception\InvalidPaymentRequestException;
use Drupal\tap_payment_custom\FormSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * Constructs a SettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\tap_payment_custom\FormSettings $settings
   *   Resolves what each limit is currently worth, so the form can show the
   *   effective value next to a field left at "use the default".
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected readonly FormSettings $settings,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('tap_payment_custom.settings'),
    );
  }

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

    $form['abuse'] = [
      '#type' => 'details',
      '#title' => $this->t('Throttling and duplicate protection'),
      '#description' => $this->t('The payment form is public and every submission costs an outbound API call, so it is bounded. Leave a number at 0 to keep the module’s own default.'),
    ];

    $form['abuse']['flood_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Payment starts per payer'),
      '#default_value' => (int) ($config->get('flood_limit') ?? 0),
      '#min' => 0,
      '#max' => 1000,
      '#description' => $this->t('How many payments one payer may start within the window below. Currently @value.', [
        '@value' => $this->settings->floodLimit(),
      ]),
    ];

    $form['abuse']['flood_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Window'),
      '#field_suffix' => $this->t('seconds'),
      '#default_value' => (int) ($config->get('flood_window') ?? 0),
      '#min' => 0,
      '#max' => 86400,
      '#description' => $this->t('Currently @value seconds.', ['@value' => $this->settings->floodWindow()]),
    ];

    $form['abuse']['throttle_by_session'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Count attempts per session or account'),
      '#default_value' => (bool) ($config->get('throttle_by_session') ?? TRUE),
      '#description' => $this->t('The most accurate bucket: one browser, one counter. It only applies where a session exists, which is always the case for a signed-in payer.'),
    ];

    $form['abuse']['throttle_by_email'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Count attempts per payer email'),
      '#default_value' => (bool) ($config->get('throttle_by_email') ?? TRUE),
      '#description' => $this->t('Carries the weight for anonymous payers with no session. The address is hashed before it is counted, so it is never stored in the flood table.'),
    ];

    $form['abuse']['flood_ip_multiplier'] = [
      '#type' => 'number',
      '#title' => $this->t('Per-address allowance multiplier'),
      '#default_value' => (int) ($config->get('flood_ip_multiplier') ?? 0),
      '#min' => 0,
      '#max' => 1000,
      '#description' => $this->t('One public address may carry this many payers’ worth of attempts. Behind NAT, an office or a mobile carrier, hundreds of legitimate payers share one address — a limit tight enough to stop abuse there is tight enough to lock all of them out. Currently @value.', [
        '@value' => $this->settings->floodIpMultiplier(),
      ]),
    ];

    $form['abuse']['idempotency_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Duplicate submission window'),
      '#field_suffix' => $this->t('seconds'),
      '#default_value' => (int) ($config->get('idempotency_lifetime') ?? 0),
      '#min' => 0,
      '#max' => 86400,
      '#description' => $this->t('Within this window an identical submission rejoins the payment it already started instead of opening a second charge. After it, the same payer buying the same thing again gets a new one. Currently @value seconds.', [
        '@value' => $this->settings->idempotencyLifetime(),
      ]),
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
      ->set('flood_limit', (int) $form_state->getValue('flood_limit'))
      ->set('flood_window', (int) $form_state->getValue('flood_window'))
      ->set('flood_ip_multiplier', (int) $form_state->getValue('flood_ip_multiplier'))
      ->set('throttle_by_session', (bool) $form_state->getValue('throttle_by_session'))
      ->set('throttle_by_email', (bool) $form_state->getValue('throttle_by_email'))
      ->set('idempotency_lifetime', (int) $form_state->getValue('idempotency_lifetime'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
