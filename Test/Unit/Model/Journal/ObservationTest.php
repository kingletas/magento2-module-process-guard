<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model\Journal;

use Commerce\ProcessGuard\Model\Journal\Observation;
use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use PHPUnit\Framework\TestCase;

class ObservationTest extends TestCase
{
    public function testNanosecondsAreReportedInMilliseconds(): void
    {
        $observation = new Observation(ObservationOutcome::Completed, 'event.a', 'observer', 1_500_000);

        $this->assertSame(1.5, $observation->getElapsedMilliseconds());
    }

    public function testNegativeTimeIsImpossible(): void
    {
        $observation = new Observation(ObservationOutcome::Completed, 'event.a', 'observer', -5);

        $this->assertSame(0, $observation->getElapsedNanoseconds());
    }

    /**
     * Both mean the work threw.
     */
    public function testAContainedFailureReadsDifferentlyFromAPropagatedOne(): void
    {
        $failed = new Observation(ObservationOutcome::Failed, 'event.a', 'obs', 0, [], 'boom');
        $contained = new Observation(ObservationOutcome::Contained, 'event.a', 'obs', 0, [], 'boom');

        $this->assertStringContainsString('left to propagate', $failed->getMessage());
        $this->assertStringContainsString('contained', $contained->getMessage());
        $this->assertStringContainsString('advisory', $contained->getMessage());
        $this->assertStringContainsString('boom', $contained->getMessage());
    }

    public function testEveryOutcomeHasAMessageThatNamesTheProcessAndTheUnit(): void
    {
        foreach (ObservationOutcome::cases() as $outcome) {
            $observation = new Observation($outcome, 'event.place_order', 'vendor_ping', 1_000_000);

            $this->assertStringContainsString('event.place_order', $observation->getMessage(), $outcome->value);
            $this->assertStringContainsString('vendor_ping', $observation->getMessage(), $outcome->value);
        }
    }

    public function testAFailureWithNoMessageStillReadsAsASentence(): void
    {
        $observation = new Observation(ObservationOutcome::Failed, 'event.a', 'obs', 0, [], null);

        $this->assertStringContainsString('no message', $observation->getMessage());
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

        $this->assertSame('over_budget', $payload['outcome']);
        $this->assertSame(2.0, $payload['ms']);
        $this->assertSame('sales_order_place_after', $payload['event']);
        $this->assertSame('Vendor\Observer', $payload['class']);
    }

    /**
     * Skipped work did not run, and counting it as a call would flatter the
     * average of exactly the path that is in trouble.
     */
    public function testSkippedOutcomesDoNotCountAsHavingRun(): void
    {
        $this->assertFalse(ObservationOutcome::Shed->ran());
        $this->assertFalse(ObservationOutcome::Disabled->ran());
        $this->assertFalse(ObservationOutcome::Repeated->ran());
        $this->assertFalse(ObservationOutcome::MemoryCeiling->ran());
        $this->assertTrue(ObservationOutcome::Completed->ran());
        $this->assertTrue(ObservationOutcome::Contained->ran());
    }

    public function testOnlyARoutineCompletionIsUnremarkable(): void
    {
        $this->assertFalse(ObservationOutcome::Completed->isNoteworthy());

        foreach (ObservationOutcome::cases() as $outcome) {
            if ($outcome !== ObservationOutcome::Completed) {
                $this->assertTrue($outcome->isNoteworthy(), $outcome->value);
            }
        }
    }
}
