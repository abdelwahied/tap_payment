<?php

declare(strict_types=1);

namespace Drupal\tap_payment_custom\Controller;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tap_payment\Entity\TapTransactionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tells the payer what happened.
 *
 * The state shown here is read from the site's own ledger, which by this point
 * has been set from a verified Tap response — never from anything in the URL.
 * The transaction UUID in the query string only says *which* payment to look
 * at; it cannot say how it went.
 *
 * A payment still in a pending state is reported as pending rather than as a
 * failure. Asynchronous methods legitimately take time, and telling somebody
 * their payment failed when it has not is worse than telling them to wait.
 *
 * @internal
 *   A controller; not part of the public API.
 */
final class CompletionController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs a CompletionController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the transaction.
   */
  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * Renders the outcome of one payment.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function show(Request $request): array {
    $transaction = $this->load((string) $request->query->get('tap_transaction', ''));

    if ($transaction === NULL) {
      return $this->message($this->t('No payment was found for this address.'));
    }

    $state = $transaction->getState();

    if ($state->isSuccessful()) {
      return $this->message($this->t('Thank you. Your payment of @amount @currency was received.', [
        '@amount' => $transaction->getMoney()->amount,
        '@currency' => $transaction->getMoney()->currency,
      ]));
    }

    if ($state->isPending()) {
      return $this->message($this->t('Your payment has not been confirmed yet. This page will show the result once the bank reports it.'));
    }

    return $this->message($this->t('Your payment was not completed. Nothing has been charged.'));
  }

  /**
   * Loads a transaction by the UUID in the query string.
   *
   * @param string $uuid
   *   The UUID.
   *
   * @return \Drupal\tap_payment\Entity\TapTransactionInterface|null
   *   The transaction, or NULL.
   */
  private function load(string $uuid): ?TapTransactionInterface {
    if (!Uuid::isValid($uuid)) {
      return NULL;
    }

    $matches = $this->entityTypeManager
      ->getStorage('tap_payment_transaction')
      ->loadByProperties(['uuid' => $uuid, 'context_module' => 'tap_payment_custom']);

    $transaction = reset($matches);

    return $transaction instanceof TapTransactionInterface ? $transaction : NULL;
  }

  /**
   * Wraps one sentence in a render array that is never cached.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $text
   *   The sentence.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function message($text): array {
    return [
      '#markup' => $text,
      '#cache' => ['max-age' => 0],
    ];
  }

}
