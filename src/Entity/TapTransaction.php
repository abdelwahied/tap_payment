<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Entity\Handler\TapTransactionAccessControlHandler;
use Drupal\tap_payment\Entity\Handler\TapTransactionListBuilder;
use Drupal\tap_payment\Entity\Handler\TapTransactionStorageSchema;
use Drupal\tap_payment\Enum\PaymentState;

/**
 * Defines the Tap payment transaction content entity.
 *
 * Two schema choices carry the module's guarantees:
 *
 * - `idempotency_key` is unique **at the database level**, not merely checked
 *   before insert. Two concurrent submissions of the same order can both pass a
 *   "does this exist yet" query; only one can win a unique index. The loser
 *   gets an exception, reloads, and reuses the charge the winner created.
 * - The amount is a string column, not a float or a decimal read back as one.
 *   Tap's webhook signature is computed over the amount rendered to the
 *   currency's decimals, so the value has to survive storage byte for byte.
 *
 * There is no `uid` and no customer field. Payments are attributable through
 * the owning module's context id; a payer's identity is Tap's to hold.
 *
 * The `@ContentEntityType` annotation below mirrors the `#[ContentEntityType]`
 * attribute so the entity is discovered on both Drupal 10.3 — whose
 * EntityTypeManager uses annotation-only discovery — and Drupal 11, whose
 * attribute discovery finds the attribute and never reads the annotation, so no
 * deprecation is emitted. The two definitions must stay byte-for-byte in sync.
 *
 * @ContentEntityType(
 *   id = "tap_payment_transaction",
 *   label = @Translation("Tap payment transaction"),
 *   label_collection = @Translation("Tap payment transactions"),
 *   label_singular = @Translation("Tap payment transaction"),
 *   label_plural = @Translation("Tap payment transactions"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Tap payment transaction",
 *     plural = "@count Tap payment transactions"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "storage_schema" = "Drupal\tap_payment\Entity\Handler\TapTransactionStorageSchema",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\tap_payment\Entity\Handler\TapTransactionListBuilder",
 *     "access" = "Drupal\tap_payment\Entity\Handler\TapTransactionAccessControlHandler"
 *   },
 *   base_table = "tap_payment_transaction",
 *   admin_permission = "administer tap payment",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "idempotency_key"
 *   },
 *   links = {
 *     "collection" = "/admin/config/services/tap-payment/transactions"
 *   }
 * )
 *
 * @internal
 *   The implementation; type-hint TapTransactionInterface.
 */
#[ContentEntityType(
  id: 'tap_payment_transaction',
  label: new TranslatableMarkup('Tap payment transaction'),
  label_collection: new TranslatableMarkup('Tap payment transactions'),
  label_singular: new TranslatableMarkup('Tap payment transaction'),
  label_plural: new TranslatableMarkup('Tap payment transactions'),
  label_count: [
    'singular' => '@count Tap payment transaction',
    'plural' => '@count Tap payment transactions',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'storage_schema' => TapTransactionStorageSchema::class,
    'view_builder' => EntityViewBuilder::class,
    'list_builder' => TapTransactionListBuilder::class,
    'access' => TapTransactionAccessControlHandler::class,
  ],
  base_table: 'tap_payment_transaction',
  admin_permission: 'administer tap payment',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'idempotency_key',
  ],
  links: [
    'collection' => '/admin/config/services/tap-payment/transactions',
  ],
)]
final class TapTransaction extends ContentEntityBase implements TapTransactionInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function getChargeId(): ?string {
    $value = trim((string) $this->get('charge_id')->value);

    return $value === '' ? NULL : $value;
  }

  /**
   * {@inheritdoc}
   */
  public function setChargeId(string $chargeId): static {
    $this->set('charge_id', $chargeId);

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getIdempotencyKey(): string {
    return (string) $this->get('idempotency_key')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getState(): PaymentState {
    $value = (string) $this->get('state')->value;

    // A row whose state cannot be read is treated as still open rather than as
    // an outcome: it will be re-read from Tap, which is always safe, whereas
    // inventing a final state is not.
    return PaymentState::tryFrom($value) ?? PaymentState::Unknown;
  }

  /**
   * {@inheritdoc}
   */
  public function setState(PaymentState $state): static {
    $this->set('state', $state->value);

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getMoney(): Money {
    return new Money(
      (string) $this->get('amount')->value,
      (string) $this->get('currency')->value,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isLiveMode(): bool {
    return (bool) $this->get('live_mode')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getGatewayId(): string {
    return (string) $this->get('gateway')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextModule(): ?string {
    $value = trim((string) $this->get('context_module')->value);

    return $value === '' ? NULL : $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextId(): ?string {
    $value = trim((string) $this->get('context_id')->value);

    return $value === '' ? NULL : $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getReturnUrl(): string {
    return (string) $this->get('return_url')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): string {
    $value = trim((string) $this->get('cancel_url')->value);

    return $value === '' ? $this->getReturnUrl() : $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getResponseCode(): ?string {
    $value = trim((string) $this->get('response_code')->value);

    return $value === '' ? NULL : $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getResponseMessage(): ?string {
    $value = trim((string) $this->get('response_message')->value);

    return $value === '' ? NULL : $value;
  }

  /**
   * {@inheritdoc}
   */
  public function isPaid(): bool {
    return $this->getState()->isSuccessful();
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['idempotency_key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Idempotency key'))
      ->setDescription(new TranslatableMarkup('The key sent to Tap so a repeated submission returns the original charge.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['charge_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Charge ID'))
      ->setDescription(new TranslatableMarkup('The identifier Tap issued for this charge.'))
      ->setSetting('max_length', 128);

    $fields['state'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('State'))
      ->setDescription(new TranslatableMarkup('Where the payment stands, as reported by Tap.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['amount'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Amount'))
      ->setDescription(new TranslatableMarkup('The amount to collect, stored as a decimal string so it survives the webhook signature unchanged.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['currency'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Currency'))
      ->setDescription(new TranslatableMarkup('The ISO 4217 code the amount is denominated in.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 3);

    $fields['gateway'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Gateway'))
      ->setDescription(new TranslatableMarkup('The payment gateway plugin that created this transaction.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['live_mode'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Live mode'))
      ->setDescription(new TranslatableMarkup('Whether the charge was created against production credentials.'))
      ->setDefaultValue(FALSE);

    $fields['context_module'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Context module'))
      ->setDescription(new TranslatableMarkup('The module that asked for this payment.'))
      ->setSetting('max_length', 64);

    $fields['context_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Context ID'))
      ->setDescription(new TranslatableMarkup("The owning module's identifier for whatever is being paid for."))
      ->setSetting('max_length', 128);

    $fields['return_url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Return URL'))
      ->setDescription(new TranslatableMarkup('Where the payer goes once the outcome has been verified.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 2048);

    $fields['cancel_url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Cancel URL'))
      ->setDescription(new TranslatableMarkup('Where a payer who did not complete goes.'))
      ->setSetting('max_length', 2048);

    $fields['response_code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Response code'))
      ->setDescription(new TranslatableMarkup("Tap's own code for the last outcome seen."))
      ->setSetting('max_length', 8);

    $fields['response_message'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Response message'))
      ->setDescription(new TranslatableMarkup("Tap's wording for the last response code."))
      ->setSetting('max_length', 255);

    $fields['gateway_reference'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Gateway reference'))
      ->setDescription(new TranslatableMarkup("The acquirer reference Tap reports; one of the webhook signature's inputs."))
      ->setSetting('max_length', 128);

    $fields['payment_reference'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Payment reference'))
      ->setDescription(new TranslatableMarkup("Tap's payment reference; one of the webhook signature's inputs."))
      ->setSetting('max_length', 128);

    $fields['remote_created'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Remote created'))
      ->setDescription(new TranslatableMarkup('The transaction timestamp Tap reports, in milliseconds, kept verbatim.'))
      ->setSetting('max_length', 32);

    $fields['tap_customer_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tap customer ID'))
      ->setDescription(new TranslatableMarkup('The pseudonymous customer identifier Tap issues. No other payer detail is stored.'))
      ->setSetting('max_length', 128);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('When the payment attempt started.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('When the transaction was last updated.'));

    return $fields;
  }

}
