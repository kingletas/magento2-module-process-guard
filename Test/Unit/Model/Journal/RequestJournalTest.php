<?php
/**
 * RequestJournalTest.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model\Journal;

use Commerce\ProcessGuard\Model\Journal\Observation;
use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use Commerce\ProcessGuard\Model\Journal\RequestJournal;
use PHPUnit\Framework\TestCase;

final class RequestJournalTest extends TestCase
{
    private const MS = 1_000_000;

    public function testAggregatesCallsAndTimePerProcess(): void
    {
        $journal = new RequestJournal();

        $journal->record($this->completed('event.a', 'one', 10));
        $journal->record($this->completed('event.a', 'two', 30));
        $journal->record($this->completed('event.b', 'three', 5));

        $report = $journal->getReport();

        self::assertSame(2, $report->getCalls('event.a'));
        self::assertSame(40 * self::MS, $report->getElapsedNanoseconds('event.a'));
        self::assertSame(30 * self::MS, $report->getSlowestNanoseconds('event.a'));
        self::assertSame(1, $report->getCalls('event.b'));
    }

    public function testTheMostExpensiveProcessIsFirst(): void
    {
        $journal = new RequestJournal();

        $journal->record($this->completed('cheap', 'x', 1));
        $journal->record($this->completed('expensive', 'y', 100));
        $journal->record($this->completed('middling', 'z', 50));

        self::assertSame(['expensive', 'middling', 'cheap'], $journal->getReport()->getProcesses());
    }

    /**
     * Work that never ran did not take any time, and counting it as a call
     * would make the average of a shed path look better than the path.
     */
    public function testSkippedWorkIsCountedButNotTimed(): void
    {
        $journal = new RequestJournal();

        $journal->record(new Observation(ObservationOutcome::Shed, 'event.a', 'skipped'));
        $journal->record(new Observation(ObservationOutcome::Disabled, 'event.a', 'off'));

        $report = $journal->getReport();

        self::assertSame(0, $report->getCalls('event.a'));
        self::assertSame(1, $report->getOutcomeCount('event.a', ObservationOutcome::Shed));
        self::assertSame(1, $report->getOutcomeCount('event.a', ObservationOutcome::Disabled));
    }

    /**
     * A consumer runs for hours in one process.
     */
    public function testDetailIsBoundedButCountsAreNot(): void
    {
        $journal = new RequestJournal(detailLimit: 10);

        foreach (range(1, 1000) as $i) {
            $journal->record($this->completed('event.a', 'observer-' . $i, 1));
        }

        self::assertCount(10, $journal->getObservations());
        self::assertSame(1000, $journal->getReport()->getCalls('event.a'), 'Counting must not stop when detail does.');
        self::assertSame(990, $journal->getReport()->getDroppedDetail());
    }

    /**
     * The interesting few observations survive a request that made ten thousand
     * boring ones.
     */
    public function testANoteworthyObservationDisplacesARoutineOne(): void
    {
        $journal = new RequestJournal(detailLimit: 3);

        $journal->record($this->completed('event.a', 'routine-1', 1));
        $journal->record($this->completed('event.a', 'routine-2', 1));
        $journal->record($this->completed('event.a', 'routine-3', 1));
        $journal->record(new Observation(ObservationOutcome::Failed, 'event.a', 'the-one-that-broke', 0, [], 'boom'));

        $labels = array_map(
            static fn (Observation $observation): string => $observation->getLabel(),
            $journal->getObservations()
        );

        self::assertContains('the-one-that-broke', $labels);
        self::assertCount(3, $labels);
        self::assertNotContains('routine-1', $labels, 'The oldest routine one makes room.');
    }

    public function testAFullJournalOfNoteworthyObservationsStopsGrowing(): void
    {
        $journal = new RequestJournal(detailLimit: 2);

        foreach (range(1, 5) as $i) {
            $journal->record(new Observation(ObservationOutcome::Failed, 'event.a', 'broken-' . $i, 0, [], 'boom'));
        }

        self::assertCount(2, $journal->getObservations());
        self::assertSame(5, $journal->getReport()->getOutcomeCount('event.a', ObservationOutcome::Failed));
    }

    /**
     * Truncation is stated rather than left to be inferred: a report that
     * quietly stopped counting is worse than no report.
     */
    public function testTheReportSaysWhenDetailWasTruncated(): void
    {
        $journal = new RequestJournal(detailLimit: 1);

        $journal->record($this->completed('event.a', 'one', 1));
        $journal->record($this->completed('event.a', 'two', 1));

        self::assertArrayHasKey('_truncated', $journal->getReport()->toArray());
    }

    public function testClearEmptiesEverything(): void
    {
        $journal = new RequestJournal();

        $journal->record($this->completed('event.a', 'one', 1));
        $journal->clear();

        self::assertSame([], $journal->getObservations());
        self::assertTrue($journal->getReport()->isEmpty());
    }

    public function testReportLinesNameTheProcessAndItsNumbers(): void
    {
        $journal = new RequestJournal();

        $journal->record($this->completed('event.a', 'one', 10));
        $journal->record(new Observation(ObservationOutcome::Shed, 'event.a', 'two'));

        $lines = $journal->getReport()->getLines();

        self::assertStringContainsString('event.a', $lines[0]);
        self::assertStringContainsString('10ms', $lines[0]);
        self::assertStringContainsString('1 shed', $lines[0]);
    }

    private function completed(string $process, string $label, int $milliseconds): Observation
    {
        return new Observation(ObservationOutcome::Completed, $process, $label, $milliseconds * self::MS);
    }
}
