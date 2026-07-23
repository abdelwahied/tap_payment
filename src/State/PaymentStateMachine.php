<?php

declare(strict_types=1);

namespace Drupal\tap_payment\State;

use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Exception\PaymentStateException;

/**
 * The only thing allowed to change a payment's state.
 *
 * Tap can deliver the same outcome more than once and out of order: the payer
 * comes back through the browser while the webhook is still in flight, or a
 * webhook is retried after the site already read the charge. Without a rule
 * about which moves are legal, whichever message arrives last wins — and a
 * stale `INITIATED` landing on top of a `CAPTURED` is a paid order that looks
 * unpaid.
 *
 * So the graph runs one way only. A final state absorbs everything: repeat
 * deliveries are refused, not applied, and a caller treats the refusal as the
 * no-op it is. That is where this module's idempotency actually lives —
 * not in a table of seen message identifiers, which would have to be pruned
 * and could be raced, but in the shape of the graph itself.
 *
 * `UNKNOWN` is the one non-final state Tap can report as an outcome. It stays
 * open so a later read can still resolve it, which is what the reconciliation
 * queue exists to do.
 *
 * @api
 *   Public and stable since 1.0.0.
 *
 * @see https://developers.tap.company/reference/charges
 */
final class PaymentStateMachine {

  /**
   * The state a payment is created in.
   *
   * @return \Drupal\tap_payment\Enum\PaymentState
   *   The initial state.
   */
  public function initialState(): PaymentState {
    return PaymentState::Initiated;
  }

  /**
   * Whether a payment may move from one state to another.
   *
   * A move to the state a payment is already in is *not* a transition: it is
   * the same fact arriving twice. Callers get FALSE and do nothing, which is
   * the correct handling of a duplicate webhook.
   *
   * @param \Drupal\tap_payment\Enum\PaymentState $from
   *   The current state.
   * @param \Drupal\tap_payment\Enum\PaymentState $to
   *   The state being reported.
   *
   * @return bool
   *   TRUE when the move is legal and changes something.
   */
  public function canTransition(PaymentState $from, PaymentState $to): bool {
    if ($from === $to) {
      return FALSE;
    }

    // Nothing leaves a final state. This single line is what makes a replayed
    // or late webhook harmless.
    if ($from->isFinal()) {
      return FALSE;
    }

    // A pending payment can reach any outcome, and INITIATED may additionally
    // hand over to the asynchronous IN_PROGRESS. Nothing ever goes back to
    // INITIATED: a charge is initiated exactly once.
    return $to !== PaymentState::Initiated
      && ($to !== PaymentState::InProgress || $from === PaymentState::Initiated);
  }

  /**
   * Asserts that a move is legal.
   *
   * @param \Drupal\tap_payment\Enum\PaymentState $from
   *   The current state.
   * @param \Drupal\tap_payment\Enum\PaymentState $to
   *   The state being reported.
   *
   * @throws \Drupal\tap_payment\Exception\PaymentStateException
   *   When the move is not allowed.
   */
  public function assertTransition(PaymentState $from, PaymentState $to): void {
    if (!$this->canTransition($from, $to)) {
      throw new PaymentStateException(sprintf(
        'A payment cannot move from %s to %s.',
        $from->value,
        $to->value,
      ));
    }
  }

  /**
   * Every state reachable in one step.
   *
   * Exposed so an integration can render a lifecycle, and so the test suite
   * can assert the whole graph rather than the handful of edges that happen to
   * be exercised.
   *
   * @param \Drupal\tap_payment\Enum\PaymentState $from
   *   The current state.
   *
   * @return array<int, \Drupal\tap_payment\Enum\PaymentState>
   *   The states that may follow.
   */
  public function allowedTransitions(PaymentState $from): array {
    return array_values(array_filter(
      PaymentState::cases(),
      fn (PaymentState $to): bool => $this->canTransition($from, $to),
    ));
  }

}
