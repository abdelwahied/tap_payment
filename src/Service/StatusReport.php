<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Service;

use Drupal\Component\Utility\DeprecationHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tap_payment\Api\Adapter\AdapterRegistry;
use Drupal\tap_payment\Enum\Environment;
use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Exception\ConfigurationException;

/**
 * Answers "is this module actually working?" on the status report.
 *
 * Two of the three checks are things a site only ever discovers the hard way.
 *
 * A site left on sandbox credentials takes orders that never collect a penny,
 * and every test payment succeeds, so nothing looks wrong until the accounts
 * are reconciled. Saying plainly which environment is live is the cheapest
 * possible guard against that.
 *
 * Payments stuck in a non-final state are the visible end of a webhook that
 * never arrived. One or two is ordinary — a customer closed the tab. A growing
 * number means the webhook endpoint is unreachable from Tap, which is exactly
 * the failure that is invisible from inside the site.
 *
 * The report is one service so that the Drupal 10.3 procedural hook and the
 * Drupal 11.3 attribute hook cannot drift apart: both ask this the same
 * question.
 *
 * @internal
 *   Injected as a service.
 */
final class StatusReport {

  /**
   * Constructs a StatusReport.
   *
   * @param \Drupal\tap_payment\Service\TapPaymentSettings $settings
   *   Reports the environment and whether a key is usable.
   * @param \Drupal\tap_payment\Api\Adapter\AdapterRegistry $adapters
   *   Reports whether an adapter exists for the configured API version.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Counts the payments still waiting for an outcome.
   */
  public function __construct(
    private readonly TapPaymentSettings $settings,
    private readonly AdapterRegistry $adapters,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The module's entries for the status report.
   *
   * @return array<string, array<string, mixed>>
   *   Requirements, keyed as Drupal expects.
   */
  public function requirements(): array {
    return $this->credentials() + $this->apiVersion() + $this->openPayments();
  }

  /**
   * Whether the module has usable credentials, and for which environment.
   *
   * @return array<string, array<string, mixed>>
   *   One requirement.
   */
  private function credentials(): array {
    $environment = $this->settings->environment();

    try {
      $this->settings->secretKey();
    }
    catch (ConfigurationException $e) {
      return [
        'tap_payment_credentials' => [
          'title' => new TranslatableMarkup('Tap Payment: credentials'),
          'value' => new TranslatableMarkup('Not usable'),
          'description' => new TranslatableMarkup('@reason No payment can be created until this is fixed.', [
            '@reason' => $e->getMessage(),
          ]),
          'severity' => DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '11.2.0', fn() => RequirementSeverity::Warning, fn() => REQUIREMENT_WARNING),
        ],
      ];
    }

    return [
      'tap_payment_credentials' => [
        'title' => new TranslatableMarkup('Tap Payment: credentials'),
        'value' => $environment === Environment::Production
          ? new TranslatableMarkup('Production — real payments')
          : new TranslatableMarkup('Sandbox — no money moves'),
        'description' => $environment === Environment::Production
          ? new TranslatableMarkup('Charges are created with the live secret key.')
          : new TranslatableMarkup('Charges are created with the test secret key. Every payment will appear to succeed and none will be collected — switch to production before taking real orders.'),
        // Sandbox is not an error: it is the correct state for a site being
        // built. It is only worth flagging so that nobody discovers it later.
        'severity' => $environment === Environment::Production ? DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '11.2.0', fn() => RequirementSeverity::OK, fn() => REQUIREMENT_OK) : DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '11.2.0', fn() => RequirementSeverity::Warning, fn() => REQUIREMENT_WARNING),
      ],
    ];
  }

  /**
   * Whether an adapter exists for the configured API version.
   *
   * @return array<string, array<string, mixed>>
   *   One requirement, or none when everything is in order.
   */
  private function apiVersion(): array {
    try {
      $version = $this->adapters->active()->version();
    }
    catch (ConfigurationException $e) {
      return [
        'tap_payment_api_version' => [
          'title' => new TranslatableMarkup('Tap Payment: API version'),
          'value' => new TranslatableMarkup('Unsupported'),
          'description' => new TranslatableMarkup('@reason', ['@reason' => $e->getMessage()]),
          'severity' => DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '11.2.0', fn() => RequirementSeverity::Error, fn() => REQUIREMENT_ERROR),
        ],
      ];
    }

    return [
      'tap_payment_api_version' => [
        'title' => new TranslatableMarkup('Tap Payment: API version'),
        'value' => $version,
        'severity' => DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '11.2.0', fn() => RequirementSeverity::OK, fn() => REQUIREMENT_OK),
      ],
    ];
  }

  /**
   * How many payments are still waiting for an outcome.
   *
   * @return array<string, array<string, mixed>>
   *   One requirement.
   */
  private function openPayments(): array {
    $open = array_values(array_map(
      static fn (PaymentState $state): string => $state->value,
      array_filter(PaymentState::cases(), static fn (PaymentState $state): bool => !$state->isFinal()),
    ));

    $count = (int) $this->entityTypeManager->getStorage('tap_payment_transaction')->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', $open, 'IN')
      ->count()
      ->execute();

    return [
      'tap_payment_open' => [
        'title' => new TranslatableMarkup('Tap Payment: unresolved payments'),
        'value' => new TranslatableMarkup('@count', ['@count' => $count]),
        'description' => new TranslatableMarkup('Payments Tap has not reported a final outcome for. Cron re-reads them; a number that keeps growing means Tap cannot reach this site&#039;s webhook endpoint.'),
        'severity' => DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '11.2.0', fn() => RequirementSeverity::OK, fn() => REQUIREMENT_OK),
      ],
    ];
  }

}
