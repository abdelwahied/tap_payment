<?php

declare(strict_types=1);

namespace Drupal\tap_payment_custom\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tap_payment_custom\Form\PaymentForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Puts the payment form anywhere a block can go.
 *
 * @internal
 *   A block plugin; not part of the public API.
 */
#[Block(
  id: 'tap_payment_custom_form',
  admin_label: new TranslatableMarkup('Tap payment form'),
  category: new TranslatableMarkup('Payment'),
)]
final class PaymentFormBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a PaymentFormBlock.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   Builds the payment form.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly FormBuilderInterface $formBuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('form_builder'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return $this->formBuilder->getForm(PaymentForm::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'make tap payments');
  }

}
