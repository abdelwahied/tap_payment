<?php

declare(strict_types=1);

namespace Drupal\Tests\tap_payment\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tap_payment\Enum\PaymentState;
use Drupal\tap_payment\Exception\PaymentStateException;
use Drupal\tap_payment\State\PaymentStateMachine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the transition rules that make repeat notifications harmless.
  *
  * @covers \Drupal\tap_payment\State\PaymentStateMachine
  * @covers \Drupal\tap_payment\Enum\PaymentState
 */
#[CoversClass(PaymentStateMachine::class)]
#[CoversClass(PaymentState::class)]
final class PaymentStateMachineTest extends UnitTestCase {

  /**
   * The machine under test.
   */
  private PaymentStateMachine $machine;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->machine = new PaymentStateMachine();
  }

  /**
   * A payment starts as initiated.
   */
  public function testInitialState(): void {
    $this->assertSame(PaymentState::Initiated, $this->machine->initialState());
  }

  /**
   * Nothing leaves a final state — the whole idempotency guarantee in one test.
    *
    * @dataProvider finalStateProvider
   */
  #[DataProvider('finalStateProvider')]
  public function testFinalStatesAbsorbEverything(PaymentState $final): void {
    $this->assertSame([], $this->machine->allowedTransitions($final));

    foreach (PaymentState::cases() as $target) {
      $this->assertFalse(
        $this->machine->canTransition($final, $target),
        sprintf('%s must not move to %s.', $final->value, $target->value),
      );
    }
  }

  /**
   * Every state Tap documents as an outcome.
   *
   * @return array<string, array{PaymentState}>
   *   One final state per case.
   */
  public static function finalStateProvider(): array {
    $cases = [];

    foreach (PaymentState::cases() as $state) {
      if ($state->isFinal()) {
        $cases[$state->value] = [$state];
      }
    }

    return $cases;
  }

  /**
   * An initiated payment can reach every outcome.
   */
  public function testInitiatedReachesEveryOutcome(): void {
    $allowed = $this->machine->allowedTransitions(PaymentState::Initiated);

    $this->assertNotContains(PaymentState::Initiated, $allowed);
    $this->assertContains(PaymentState::InProgress, $allowed);
    $this->assertContains(PaymentState::Captured, $allowed);
    $this->assertContains(PaymentState::Failed, $allowed);
    $this->assertContains(PaymentState::Unknown, $allowed);
    $this->assertCount(count(PaymentState::cases()) - 1, $allowed);
  }

  /**
   * A payment never goes back to being initiated or in progress.
   */
  public function testNothingGoesBackwards(): void {
    $this->assertFalse($this->machine->canTransition(PaymentState::InProgress, PaymentState::Initiated));
    $this->assertFalse($this->machine->canTransition(PaymentState::InProgress, PaymentState::InProgress));
    $this->assertFalse($this->machine->canTransition(PaymentState::Unknown, PaymentState::InProgress));
    $this->assertFalse($this->machine->canTransition(PaymentState::Unknown, PaymentState::Initiated));
  }

  /**
   * An unknown outcome can still be resolved later.
   *
   * This is why UNKNOWN is not final: the reconciliation queue keeps asking.
   */
  public function testUnknownCanStillResolve(): void {
    $this->assertTrue($this->machine->canTransition(PaymentState::Unknown, PaymentState::Captured));
    $this->assertTrue($this->machine->canTransition(PaymentState::Unknown, PaymentState::Failed));
    $this->assertFalse(PaymentState::Unknown->isFinal());
    $this->assertFalse(PaymentState::Unknown->isSuccessful());
  }

  /**
   * Reporting the state a payment is already in changes nothing.
   */
  public function testSameStateIsNotTransition(): void {
    $this->assertFalse($this->machine->canTransition(PaymentState::Initiated, PaymentState::Initiated));
  }

  /**
   * An illegal move raises when asserted rather than silently succeeding.
   */
  public function testAssertingAnIllegalTransitionThrows(): void {
    $this->expectException(PaymentStateException::class);
    $this->expectExceptionMessage('cannot move from CAPTURED to FAILED');
    $this->machine->assertTransition(PaymentState::Captured, PaymentState::Failed);
  }

  /**
   * A legal move asserts without complaint.
   */
  public function testAssertingLegalTransitionPasses(): void {
    $this->machine->assertTransition(PaymentState::Initiated, PaymentState::Captured);
    $this->addToAssertionCount(1);
  }

  /**
   * Only capture means paid.
   */
  public function testOnlyCaptureIsSuccess(): void {
    foreach (PaymentState::cases() as $state) {
      $this->assertSame(
        $state === PaymentState::Captured,
        $state->isSuccessful(),
        sprintf('%s reports success only when it is CAPTURED.', $state->value),
      );
    }
  }

  /**
   * Statuses are read case-insensitively, and unknown ones return NULL.
   */
  public function testStatusParsing(): void {
    $this->assertSame(PaymentState::Captured, PaymentState::fromStatus('captured'));
    $this->assertSame(PaymentState::Captured, PaymentState::fromStatus(' CAPTURED '));
    $this->assertNull(PaymentState::fromStatus('SETTLED'));
  }

}
