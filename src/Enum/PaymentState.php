<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Enum;

/**
 * Every state a Tap charge is documented to report.
 *
 * The cases and their spelling come from the official "Post Payment Response
 * Details" table; `IN_PROGRESS` comes from the payment-methods page, which
 * documents it for asynchronous methods. Nothing else is added: an unlisted
 * status must surface as a mapping failure, never be quietly folded into a
 * neighbouring case, because "we did not recognise this" and "the payment
 * failed" are different facts and only one of them is safe to act on.
 *
 * A boolean pair like `$paid`/`$failed` cannot express that difference, which
 * is why the module carries a state rather than flags.
 *
 * @api
 *   Public and stable since 1.0.0. The case names are part of the contract and
 *   are what gets persisted on a transaction entity.
 *
 * @see https://developers.tap.company/reference/charges
 * @see https://developers.tap.company/docs/payment-methods
 */
enum PaymentState: string {

  case Initiated = 'INITIATED';
  case InProgress = 'IN_PROGRESS';
  case Captured = 'CAPTURED';
  case Abandoned = 'ABANDONED';
  case Cancelled = 'CANCELLED';
  case Failed = 'FAILED';
  case Declined = 'DECLINED';
  case Restricted = 'RESTRICTED';
  case Void = 'VOID';
  case TimedOut = 'TIMEDOUT';
  case Unknown = 'UNKNOWN';

  /**
   * Whether the money has actually been taken.
   *
   * `CAPTURED` is the only documented success for a charge. Anything else —
   * including `UNKNOWN` — must not release goods.
   *
   * @return bool
   *   TRUE only for a captured charge.
   */
  public function isSuccessful(): bool {
    return $this === self::Captured;
  }

  /**
   * Whether Tap can still move this charge somewhere else.
   *
   * `UNKNOWN` is deliberately *not* final. The documentation groups it with the
   * failures, but the word describes the registry's certainty, not the
   * payment's outcome, so the module keeps re-reading it instead of writing
   * off a charge that may well have been captured.
   *
   * @return bool
   *   TRUE when no further state change is expected.
   */
  public function isFinal(): bool {
    return match ($this) {
      self::Initiated, self::InProgress, self::Unknown => FALSE,
      default => TRUE,
    };
  }

  /**
   * Whether the customer still has somewhere to be sent.
   *
   * @return bool
   *   TRUE while the charge is awaiting the payer.
   */
  public function isPending(): bool {
    return $this === self::Initiated || $this === self::InProgress;
  }

  /**
   * The state for a documented status string, or NULL when unrecognised.
   *
   * Callers must handle the NULL rather than defaulting: see the class
   * docblock for why guessing here is the one mistake worth avoiding.
   *
   * @param string $status
   *   A status as it appears in a Tap response.
   *
   * @return self|null
   *   The matching state, or NULL when Tap sent something undocumented.
   */
  public static function fromStatus(string $status): ?self {
    return self::tryFrom(strtoupper(trim($status)));
  }

}
