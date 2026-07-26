<?php

declare(strict_types=1);

namespace Drupal\tap_payment_custom\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\tap_payment\Dto\Customer;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;
use Drupal\tap_payment\Dto\PaymentSession;
use Drupal\tap_payment\Exception\InvalidPaymentRequestException;
use Drupal\tap_payment\Exception\TapPaymentException;
use Drupal\tap_payment\Service\TapPaymentSettings;
use Drupal\tap_payment\TapPaymentInterface;
use Drupal\tap_payment_custom\FormSettings;
use Drupal\tap_payment_custom\IdempotencyKeyFactory;
use Drupal\tap_payment_custom\PaymentThrottle;
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
 * A public endpoint that spends money and calls a third-party API on request
 * needs three things this form did not originally have. It is throttled per
 * payer rather than per address, so an office behind one IP is not locked out
 * by its own first customer. It derives an idempotency key from the submission,
 * which switches on duplicate protection the core service and Tap both already
 * offered but were never given a key to use. And it takes a lock around the
 * one call that costs money, so two requests that arrive together cannot both
 * open a charge.
 *
 * @internal
 *   The form class may change shape.
 */
final class PaymentForm extends FormBase {

  /**
   * Lock namespace for one payer's in-flight submission.
   */
  private const LOCK_PREFIX = 'tap_payment_custom:pay:';

  /**
   * Seconds a submission may hold the lock.
   *
   * Long enough for a slow Tap round trip, short enough that a crashed worker
   * does not block the payer's own retry for a noticeable time.
   */
  private const LOCK_TIMEOUT = 30.0;

  /**
   * How many derived keys one payer may occupy within a single window.
   *
   * A ceiling on the loop, not a policy: the throttle refuses a payer long
   * before they can finish this many identical payments in one window.
   */
  private const MAX_KEY_GENERATIONS = 20;

  /**
   * Constructs a PaymentForm.
   *
   * @param \Drupal\tap_payment\TapPaymentInterface $payments
   *   The one service this module needs.
   * @param \Drupal\tap_payment_custom\FormSettings $settings
   *   What to charge, and how hard to throttle.
   * @param \Drupal\tap_payment\Service\TapPaymentSettings $gatewaySettings
   *   Reports whether the gateway is usable, so the form can say so.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's log channel.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   Supplies the language Tap should render its page in.
   * @param \Drupal\tap_payment_custom\PaymentThrottle $throttle
   *   Bounds how often one payer may start a payment.
   * @param \Drupal\tap_payment_custom\IdempotencyKeyFactory $keys
   *   Derives the key that makes a repeat submission harmless.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   Serialises two submissions that arrive at the same moment.
   */
  public function __construct(
    protected readonly TapPaymentInterface $payments,
    protected readonly FormSettings $settings,
    protected readonly TapPaymentSettings $gatewaySettings,
    protected readonly LoggerChannelInterface $logger,
    protected readonly LanguageManagerInterface $languageManager,
    protected readonly PaymentThrottle $throttle,
    protected readonly IdempotencyKeyFactory $keys,
    protected readonly LockBackendInterface $lock,
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
      $container->get('tap_payment_custom.throttle'),
      $container->get('tap_payment_custom.idempotency_keys'),
      $container->get('lock'),
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
      return $this->unavailable($form);
    }

    try {
      $money = $this->settings->money();
    }
    catch (InvalidPaymentRequestException $e) {
      // A misconfigured amount or currency is the site's mistake, and the payer
      // is not the person who can fix it. Without this the page died with an
      // exception, which told the payer nothing and the administrator no more.
      Error::logException($this->logger, $e);

      return $this->unavailable($form);
    }

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
   * The form reduced to a single "not right now" line.
   *
   * Built by adding to the array Drupal handed in rather than by returning a
   * fresh one. FormBuilder::retrieveForm() puts the form's own CSS classes on
   * `$form` *before* calling this method, so a replacement array silently drops
   * them and any theme or script hooked to the form wrapper stops matching.
   *
   * @param array<string, mixed> $form
   *   The form array as Drupal built it so far.
   *
   * @return array<string, mixed>
   *   The same array, carrying the message instead of the fields.
   */
  private function unavailable(array $form): array {
    // Said plainly rather than by letting the submission fail: a payer who
    // fills in a form and then gets an error learns nothing useful.
    $form['unavailable'] = [
      '#type' => 'item',
      '#markup' => $this->t('Payments are not available at the moment. Please try again later.'),
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

    $email = trim((string) $form_state->getValue('email'));

    // The email element already rejects a malformed address; this bounds the
    // length Tap documents, so an over-long one fails here rather than costing
    // a round trip to be told 1124.
    if (mb_strlen($email) > 254) {
      $form_state->setErrorByName('email', $this->t('The email address is too long.'));
    }

    // Everything above is the payer's fault and is worth telling them about.
    // What follows is the site's: a misconfigured amount or currency must not
    // reach Tap, and the payer is not the person who can fix it.
    try {
      $this->settings->money();
    }
    catch (InvalidPaymentRequestException $e) {
      Error::logException($this->logger, $e);
      $form_state->setErrorByName('', $this->t('Payments are not available at the moment. Please try again later.'));

      return;
    }

    // Checked here, not in submitForm(), so a throttled payer is told before
    // the form is processed — and so the check itself costs nothing when the
    // submission was going to fail validation anyway.
    if ($email !== '' && !$this->throttle->isAllowed($email)) {
      $this->logger->warning('A payment attempt was throttled on the @bucket bucket.', [
        '@bucket' => $this->throttle->exceededBucket($email),
      ]);

      $form_state->setErrorByName('', $this->t('Too many payment attempts. Please wait a few minutes and try again.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $code = trim((string) $form_state->getValue('phone_country_code'));
    $number = trim((string) $form_state->getValue('phone_number'));
    $email = trim((string) $form_state->getValue('email'));

    $money = $this->settings->money();
    $customer = new Customer(
      firstName: trim((string) $form_state->getValue('first_name')),
      email: $email,
      lastName: trim((string) $form_state->getValue('last_name')) ?: NULL,
      phoneCountryCode: $code === '' ? NULL : $code,
      phoneNumber: $number === '' ? NULL : $number,
    );

    // Counted here and nowhere else. Registering on build would let a crawler
    // that never submits anything exhaust a real payer's allowance.
    $this->throttle->register($email);

    // `reused` is the difference between "this payer pressed Pay twice" and
    // "this payer is starting something new", and nothing downstream can work
    // it out afterwards: by then the ledger row looks the same either way.
    ['key' => $key, 'reused' => $reused] = $this->resolveKey(
      $money,
      $this->keyMaterial($customer),
      $this->throttle->owner(),
      $this->settings->idempotencyLifetime(),
    );

    // Named after the key, so two requests that resolved to the same key
    // queue behind one another and two that did not never block each other.
    $lockId = self::LOCK_PREFIX . $key;
    $acquired = $this->lock->acquire($lockId, self::LOCK_TIMEOUT);

    if (!$acquired) {
      // The other request is already calling Tap for this exact submission.
      // Wait for it rather than opening a second charge alongside it; the
      // shared key then rejoins whatever it created.
      $this->logger->info('A concurrent payment submission was joined to the one already in flight.');
      $this->lock->wait($lockId, (int) self::LOCK_TIMEOUT);
    }

    try {
      $session = $this->payments->createPayment(new PaymentRequest(
        money: $money,
        customer: $customer,
        returnUrl: Url::fromRoute('tap_payment_custom.complete')->toString(),
        description: $this->settings->description(),
        sourceId: $this->settings->sourceId(),
        idempotencyKey: $key,
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
    finally {
      // Released only by the request that took it, and released on every exit
      // from the try — the return above, the catch, and any throwable the
      // service lets through.
      if ($acquired) {
        $this->lock->release($lockId);
      }
    }

    $url = $session->redirectUrl();

    if ($url === NULL) {
      $this->reportMissingRedirect($session, $reused);

      return;
    }

    $this->logger->info('Started Tap payment @charge for transaction @id.', [
      '@charge' => $session->payment->chargeId,
      '@id' => $session->transaction->id(),
    ]);

    // Tap's hosted page is by definition off this site, so this is the one
    // redirect in the module that is deliberately external — and it goes only
    // to a URL Tap itself just returned.
    $form_state->setResponse(new TrustedRedirectResponse($url));
  }

  /**
   * Tells the payer what happened when there is no hosted page to send them to.
   *
   * There is no single reason for a missing redirect URL, and the four reasons
   * do not deserve the same sentence. `PaymentSession::redirectUrl()` returns
   * NULL whenever the charge is not both pending and holding a hosted page, so
   * it covers a payment that has already finished *and* a brand-new charge Tap
   * refused on the spot — the adapter maps `DECLINED` and `FAILED` to states
   * rather than throwing, so a refusal arrives here as an ordinary answer.
   *
   * Telling the second payer that their payment "is already being processed"
   * sends them away to wait for a confirmation email that will never arrive,
   * which is worse than telling them nothing. So the reassuring message is
   * spent only where it is true: on a submission that genuinely rejoined a
   * payment already under way.
   *
   * @param \Drupal\tap_payment\Dto\PaymentSession $session
   *   What the service answered with.
   * @param bool $reused
   *   Whether this submission rejoined a payment that already existed.
   */
  private function reportMissingRedirect(PaymentSession $session, bool $reused): void {
    $recorded = $session->transaction->getState();
    // Tap's answer to *this* call. It can differ from the ledger, which the
    // one-way state machine will not move backwards, so a failure reported by
    // either is a failure.
    $reported = $session->payment->state;

    $context = [
      '@id' => $session->transaction->id(),
      '@state' => $recorded->value,
      '@reported' => $reported->value,
    ];

    if ($recorded->isSuccessful() || $reported->isSuccessful()) {
      $this->logger->info('A submission rejoined transaction @id, which is already paid (@state).', $context);
      $this->messenger()->addStatus($this->t('This payment has already been completed. Please check your email for confirmation.'));

      return;
    }

    if ($recorded->isFinal() || $reported->isFinal()) {
      // The charge is over and was not captured: declined, failed, cancelled,
      // timed out. The payer has not paid and nothing is on its way to them.
      $this->logger->warning('Tap ended transaction @id without a hosted page; recorded @state, reported @reported.', $context);
      $this->messenger()->addError($this->t('The payment was not completed. Your card was not charged — please try again, or use a different payment method.'));

      return;
    }

    if ($reused) {
      // Still open, and this submission is the second one on it: the payer
      // pressed Pay twice on something already under way.
      $this->logger->info('A repeat submission rejoined transaction @id, which is still @state.', $context);
      $this->messenger()->addStatus($this->t('Your payment is already being processed. Please check your email for confirmation.'));

      return;
    }

    // A new charge, still open, but with nowhere to send the payer. Nothing is
    // in flight on their behalf, so this is the same dead end as a refused
    // call — and it keeps the message this branch has always shown.
    $this->logger->error('Tap accepted transaction @id but returned no hosted page; recorded @state, reported @reported.', $context);
    $this->messenger()->addError($this->t('The payment could not be started. Please try again.'));
  }

  /**
   * The idempotency key this submission should be made under.
   *
   * The whole duplicate story is one decision, made here and then handed to the
   * core service, which already knows what to do with a key it has seen: it
   * rejoins the stored row and re-posts to Tap with the same
   * `reference.idempotent`, so Tap returns the original charge — hosted URL
   * included — rather than opening a second one.
   *
   * @param \Drupal\tap_payment\Dto\Money $money
   *   What is being charged.
   * @param array<string, string|null> $material
   *   The submitted payer details.
   * @param string|null $owner
   *   A stable identifier for the browser or account, when there is one.
   * @param int $lifetime
   *   How long one key stays in use, in seconds.
   *
   * @return array{key: string, reused: bool}
   *   The key to create or rejoin under, and whether it already belonged to a
   *   payment. The key is never NULL: a caller left without a derived key would
   *   have to let the service mint a random one, and two requests doing that at
   *   once is exactly the double charge this whole mechanism exists to prevent.
   *   `reused` is recorded here because this is the only place that can still
   *   tell the two cases apart.
   */
  private function resolveKey(Money $money, array $material, ?string $owner, int $lifetime): array {
    // A live payment in the previous bucket means this is a retry that happened
    // to straddle a boundary. Rejoin it before considering anything new.
    $previous = $this->keys->previous($money, $material, $owner, $lifetime);
    $transaction = $this->payments->loadByIdempotencyKey($previous);

    if ($transaction !== NULL && !$transaction->getState()->isFinal()) {
      $this->logger->info('Reused idempotency key for transaction @id, which is still @state.', [
        '@id' => $transaction->id(),
        '@state' => $transaction->getState()->value,
      ]);

      return ['key' => $previous, 'reused' => TRUE];
    }

    for ($generation = 0; $generation < self::MAX_KEY_GENERATIONS; $generation++) {
      $key = $this->keys->forGeneration($money, $material, $owner, $lifetime, $generation);
      $transaction = $this->payments->loadByIdempotencyKey($key);

      // Free: this is where a new payment belongs.
      if ($transaction === NULL) {
        return ['key' => $key, 'reused' => FALSE];
      }

      // Still open: the payer is repeating a submission, not making a new one.
      if (!$transaction->getState()->isFinal()) {
        $this->logger->info('Reused idempotency key for transaction @id, which is still @state.', [
          '@id' => $transaction->id(),
          '@state' => $transaction->getState()->value,
        ]);

        return ['key' => $key, 'reused' => TRUE];
      }

      // Finished. The payer has paid — or failed — and wants another identical
      // payment, so step to the next generation rather than handing back a
      // charge that is over.
    }

    // Every derived key in this window belongs to a finished payment. The
    // throttle bites long before this in any real configuration; falling back
    // to a unique key here means a payer is never blocked outright, at the cost
    // of duplicate protection for this one submission.
    $this->logger->warning('Exhausted @count derived idempotency keys in one window; falling back to a unique key.', [
      '@count' => self::MAX_KEY_GENERATIONS,
    ]);

    return ['key' => $this->keys->unique(), 'reused' => FALSE];
  }

  /**
   * The payer details an idempotency key is derived from.
   *
   * @param \Drupal\tap_payment\Dto\Customer $customer
   *   The submitted payer.
   *
   * @return array<string, string|null>
   *   The material, keyed by field name.
   */
  private function keyMaterial(Customer $customer): array {
    return [
      'first_name' => $customer->firstName,
      'last_name' => $customer->lastName,
      'email' => $customer->email,
      'phone_country_code' => $customer->phoneCountryCode,
      'phone_number' => $customer->phoneNumber,
    ];
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
