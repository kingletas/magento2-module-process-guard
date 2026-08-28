<?php
/**
 * ObservationTest.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model\Journal;

use Commerce\ProcessGuard\Model\Journal\Observation;
use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use PHPUnit\Framework\TestCase;

final class ObservationTest extends TestCase
{
    public function testNanosecondsAreReportedInMilliseconds(): void
    {
        $observation = new Observation(ObservationOutcome::Completed, 'event.a', 'observer', 1_500_000);

        self::assertSame(1.5, $observation->getElapsedMilliseconds());
    }

    public function testNegativeTimeIsImpossible(): void
    {
        $observation = new Observation(ObservationOutcome::Completed, 'event.a', 'observer', -5);

        self::assertSame(0, $observation->getElapsedNanoseconds());
    }

    /**
     * Both mean the work threw.
     */
    public function testAContainedFailureReadsDifferentlyFromAPropagatedOne(): void
    {
        $failed = new Observation(ObservationOutcome::Failed, 'event.a', 'obs', 0, [], 'boom');
        $contained = new Observation(ObservationOutcome::Contained, 'event.a', 'obs', 0, [], 'boom');

        self::assertStringContainsString('left to propagate', $failed->getMessage());
        self::assertStringContainsString('contained', $contained->getMessage());
        self::assertStringContainsString('advisory', $contained->getMessage());
        self::assertStringContainsString('boom', $contained->getMessage());
    }

    public function testEveryOutcomeHasAMessageThatNamesTheProcessAndTheUnit(): void
    {
        foreach (ObservationOutcome::cases() as $outcome) {
            $observation = new Observation($outcome, 'event.place_order', 'vendor_ping', 1_000_000);

            self::assertStringContainsString('event.place_order', $observation->getMessage(), $outcome->value);
            self::assertStringContainsString('vendor_ping', $observation->getMessage(), $outcome->value);
        }
    }

    public function testAFailureWithNoMessageStillReadsAsASentence(): void
    {
        $observation = new Observation(ObservationOutcome::Failed, 'event.a', 'obs', 0, [], null);

        self::assertStringContainsString('no message', $observation->getMessage());
    }

    public function testContextIsCarriedIntoTheLogPayload(): void
    {
        $observation = new Observation(
            ObservationOutcome::OverBudget,
            'event.a',
            'obs',
            2_000_000,
            ['event' => 'sales_order_place_after', 'class' => 'Vendor\Observer']
        );

        $payload = $observation->toArray();

        self::assertSame('over_budget', $payload['outcome']);
        self::assertSame(2.0, $payload['ms']);
        self::assertSame('sales_order_place_after', $payload['event']);
        self::assertSame('Vendor\Observer', $payload['class']);
    }

    /**
     * Skipped work did not run, and counting it as a call would flatter the
     * average of exactly the path that is in trouble.
     */
    public function testSkippedOutcomesDoNotCountAsHavingRun(): void
    {
        self::assertFalse(ObservationOutcome::Shed->ran());
        self::assertFalse(ObservationOutcome::Disabled->ran());
        self::assertFalse(ObservationOutcome::Repeated->ran());
        self::assertFalse(ObservationOutcome::MemoryCeiling->ran());
        self::assertTrue(ObservationOutcome::Completed->ran());
        self::assertTrue(ObservationOutcome::Contained->ran());
    }

    public function testOnlyARoutineCompletionIsUnremarkable(): void
    {
        self::assertFalse(ObservationOutcome::Completed->isNoteworthy());

        foreach (ObservationOutcome::cases() as $outcome) {
            if ($outcome !== ObservationOutcome::Completed) {
                self::assertTrue($outcome->isNoteworthy(), $outcome->value);
            }
        }
    }
}
