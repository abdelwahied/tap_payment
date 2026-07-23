<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\tap_payment\Service\StatusReport;

/**
 * The status-report entry, as Drupal 11.3 and later want it declared.
 *
 * The module still ships the procedural hook_requirements() in its .install
 * file, because it supports Drupal 10.3 where this class is never discovered.
 * That function carries #[LegacyRequirementsHook] so that on 11.3 and later
 * only this implementation runs and the check is never performed twice. Both
 * ask the same service the same question, so the two can never disagree.
 *
 * @internal
 *   A hook implementation.
 */
final class TapPaymentRequirements {

  /**
   * Constructs a TapPaymentRequirements.
   *
   * @param \Drupal\tap_payment\Service\StatusReport $statusReport
   *   Builds the requirements.
   */
  public function __construct(private readonly StatusReport $statusReport) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, mixed>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    return $this->statusReport->requirements();
  }

}
