<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tap_payment\Enum\Environment;
use Drupal\tap_payment\Service\TapPaymentSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the two things Tap actually requires.
 *
 * Three fields, and that is the whole administration surface. Tap's charge API
 * needs a secret key and nothing else from a site: the environment is chosen by
 * which key is used, the API version is in the URL, and every other charge
 * parameter is a per-payment decision that belongs to the calling module, not
 * to a settings page. Timeouts, retry counts and the reconciliation window are
 * container parameters — real knobs, but ones a site tunes deliberately in
 * `services.yml`, not ones to be guessed at in a form.
 *
 * Notably absent is the public key. Tap issues one, but it is for the browser
 * SDKs; no endpoint this module calls accepts it. Offering a field for it would
 * be asking an administrator to paste a credential the module will never send.
 *
 * The stored keys are never rendered back. The fields are always empty and an
 * empty submission keeps what is stored, so the secret is written into the
 * database and never leaves it — not into an HTML response, not into a browser
 * cache, not into a screenshot in a support ticket.
 *
 * @internal
 *   The form class may change shape. What is stable is the route
 *   `tap_payment.settings` and the configuration it writes; read that through
 *   \Drupal\tap_payment\Service\TapPaymentSettings.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * Constructs a SettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\tap_payment\Service\TapPaymentSettings $settings
   *   Reports which keys are already stored, without revealing them.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected TapPaymentSettings $settings,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('tap_payment.settings'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tap_payment_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [TapPaymentSettings::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['environment'] = [
      '#type' => 'radios',
      '#title' => $this->t('Environment'),
      '#default_value' => $this->settings->environment()->value,
      '#options' => [
        Environment::Sandbox->value => $this->t('Sandbox — test keys, no money moves'),
        Environment::Production->value => $this->t('Production — live keys, real payments'),
      ],
      '#required' => TRUE,
      '#description' => $this->t('Tap has no separate sandbox address. The environment is decided entirely by which secret key requests are signed with, so switching this switches which key below is used.'),
    ];

    $form['sandbox_secret_key'] = $this->keyElement(
      Environment::Sandbox,
      $this->t('Sandbox secret key'),
      $this->t('The test key from the Tap dashboard, beginning with %prefix.', ['%prefix' => Environment::Sandbox->keyPrefix()]),
    );

    $form['live_secret_key'] = $this->keyElement(
      Environment::Production,
      $this->t('Live secret key'),
      $this->t('The production key from the Tap dashboard, beginning with %prefix.', ['%prefix' => Environment::Production->keyPrefix()]),
    );

    $form['storage_note'] = [
      '#type' => 'item',
      '#markup' => $this->t('Keys are stored in configuration. On a site that exports configuration to version control, override them per environment in <code>settings.php</code> instead, so a secret never reaches a repository.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach ([Environment::Sandbox, Environment::Production] as $environment) {
      $name = $this->settings->secretKeyName($environment);
      $value = trim((string) $form_state->getValue($name));

      if ($value !== '' && !$environment->matchesKey($value)) {
        // Catching this here is the difference between a clear message now and
        // a mystery 401 on a customer's first real payment.
        $form_state->setErrorByName($name, $this->t('A @environment key must begin with %prefix. The value entered looks like a key for the other environment.', [
          '@environment' => $environment->value,
          '%prefix' => $environment->keyPrefix(),
        ]));
      }
    }

    $chosen = Environment::tryFrom((string) $form_state->getValue('environment'));

    if ($chosen !== NULL) {
      $submitted = trim((string) $form_state->getValue($this->settings->secretKeyName($chosen)));

      if ($submitted === '' && !$this->settings->hasSecretKey($chosen)) {
        $form_state->setErrorByName($this->settings->secretKeyName($chosen), $this->t('Selecting the @environment environment requires its secret key.', [
          '@environment' => $chosen->value,
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(TapPaymentSettings::CONFIG_NAME);
    $config->set('environment', (string) $form_state->getValue('environment'));

    foreach ([Environment::Sandbox, Environment::Production] as $environment) {
      $name = $this->settings->secretKeyName($environment);
      $value = trim((string) $form_state->getValue($name));

      // An empty field means "leave it alone", because the field is always
      // empty on load. Writing the blank would silently disable payments.
      if ($value !== '') {
        $config->set($name, $value);
      }
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Builds one write-only secret key field.
   *
   * @param \Drupal\tap_payment\Enum\Environment $environment
   *   Which key this is.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The field label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   What to paste in.
   *
   * @return array<string, mixed>
   *   The form element.
   */
  private function keyElement(Environment $environment, TranslatableMarkup $title, TranslatableMarkup $description): array {
    $stored = $this->settings->hasSecretKey($environment);

    return [
      '#type' => 'password',
      '#title' => $title,
      '#description' => $stored
        ? $this->t('@description A key is currently stored; leave this blank to keep it.', ['@description' => $description])
        : $description,
      '#attributes' => ['autocomplete' => 'off'],
      '#size' => 60,
    ];
  }

}
