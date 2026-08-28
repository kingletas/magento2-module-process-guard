<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model\Report;

use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use Commerce\ProcessGuard\Model\Report\ProcessReport;
use PHPUnit\Framework\TestCase;

/**
 * What a report says, and — more importantly — what it refuses to leave out.
 */
class ProcessReportTest extends TestCase
{
    public function testAnEmptyReportSaysSo(): void
    {
        $report = new ProcessReport();

        $this->assertTrue($report->isEmpty());
        $this->assertSame([], $report->getProcesses());
        $this->assertSame([], $report->getLines());
    }

    public function testTheMostExpensiveProcessComesFirst(): void
    {
        $report = new ProcessReport([
            'cheap' => $this->row(calls: 1, elapsed: 1_000_000),
            'expensive' => $this->row(calls: 1, elapsed: 90_000_000),
            'middling' => $this->row(calls: 1, elapsed: 40_000_000),
        ]);

        $this->assertSame(['expensive', 'middling', 'cheap'], $report->getProcesses());
    }

    /**
     * Total time, not call count.
     */
    public function testOrderingIsByTotalTimeRatherThanCallCount(): void
    {
        $report = new ProcessReport([
            'busy' => $this->row(calls: 200, elapsed: 2_000_000),
            'slow' => $this->row(calls: 1, elapsed: 90_000_000),
        ]);

        $this->assertSame(['slow', 'busy'], $report->getProcesses());
    }

    public function testTimingsAreReportedInBothNanosecondsAndMilliseconds(): void
    {
        $report = new ProcessReport(['checkout' => $this->row(calls: 3, elapsed: 12_500_000, max: 9_000_000)]);

        $this->assertSame(3, $report->getCalls('checkout'));
        $this->assertSame(12_500_000, $report->getElapsedNanoseconds('checkout'));
        $this->assertSame(12.5, $report->getElapsedMilliseconds('checkout'));
        $this->assertSame(9_000_000, $report->getSlowestNanoseconds('checkout'));
    }

    /**
     * A process nobody recorded is zero, not a warning and not a crash.
     */
    public function testAnUnknownProcessReadsAsZeroRatherThanFailing(): void
    {
        $report = new ProcessReport(['checkout' => $this->row()]);

        $this->assertSame(0, $report->getCalls('never-ran'));
        $this->assertSame(0, $report->getElapsedNanoseconds('never-ran'));
        $this->assertSame(0.0, $report->getElapsedMilliseconds('never-ran'));
        $this->assertSame(0, $report->getSlowestNanoseconds('never-ran'));
        $this->assertSame(0, $report->getOutcomeCount('never-ran', ObservationOutcome::Failed));
    }

    public function testOutcomeCountsAreReadableByOutcome(): void
    {
        $report = new ProcessReport([
            'checkout' => $this->row(outcomes: [
                ObservationOutcome::Completed->value => 4,
                ObservationOutcome::Contained->value => 2,
            ]),
        ]);

        $this->assertSame(4, $report->getOutcomeCount('checkout', ObservationOutcome::Completed));
        $this->assertSame(2, $report->getOutcomeCount('checkout', ObservationOutcome::Contained));
        $this->assertSame(0, $report->getOutcomeCount('checkout', ObservationOutcome::Shed));
    }

    /**
     * A line for a process where nothing went wrong carries no outcome clause.
     */
    public function testALineForACleanProcessMentionsNoOutcomes(): void
    {
        $report = new ProcessReport([
            'checkout' => $this->row(calls: 2, elapsed: 4_000_000, max: 3_000_000, outcomes: [
                ObservationOutcome::Completed->value => 2,
            ]),
        ]);

        $lines = $report->getLines();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('checkout: 2 call(s), 4ms total, slowest 3ms', $lines[0]);
        $this->assertStringNotContainsString('completed', $lines[0]);
    }

    public function testALineNamesEveryOutcomeThatIsNotACleanCompletion(): void
    {
        $report = new ProcessReport([
            'checkout' => $this->row(outcomes: [
                ObservationOutcome::Completed->value => 5,
                ObservationOutcome::Contained->value => 1,
                ObservationOutcome::OverBudget->value => 2,
            ]),
        ]);

        $line = $report->getLines()[0];

        $this->assertStringContainsString('1 contained', $line);
        $this->assertStringContainsString('2 over budget', $line);
        $this->assertStringNotContainsString('completed', $line);
    }

    /**
     * Zero-count outcomes are filtered out rather than printed as "0 shed",
     * which would put noise on every line to say nothing happened.
     */
    public function testAnOutcomeRecordedAsZeroIsNotPrinted(): void
    {
        $report = new ProcessReport([
            'checkout' => $this->row(outcomes: [
                ObservationOutcome::Shed->value => 0,
                ObservationOutcome::Failed->value => 1,
            ]),
        ]);

        $line = $report->getLines()[0];

        $this->assertStringNotContainsString('shed', $line);
        $this->assertStringContainsString('1 failed', $line);
    }

    public function testTheArrayFormCarriesTheSameNumbersAsTheLines(): void
    {
        $report = new ProcessReport([
            'checkout' => $this->row(calls: 3, elapsed: 12_500_000, max: 9_000_000, outcomes: [
                ObservationOutcome::Completed->value => 3,
            ]),
        ]);

        $this->assertSame(
            [
                'checkout' => [
                    'calls' => 3,
                    'ms' => 12.5,
                    'slowest_ms' => 9.0,
                    'outcomes' => [ObservationOutcome::Completed->value => 3],
                ],
            ],
            $report->toArray()
        );
    }

    /**
     * The property this class exists to protect.
     */
    public function testATruncatedReportDeclaresWhatItIsMissing(): void
    {
        $report = new ProcessReport(['checkout' => $this->row()], droppedDetail: 41);

        $this->assertSame(41, $report->getDroppedDetail());
        $this->assertSame(['observations_without_detail' => 41], $report->toArray()['_truncated']);
    }

    public function testACompleteReportCarriesNoTruncationRow(): void
    {
        $report = new ProcessReport(['checkout' => $this->row()]);

        $this->assertSame(0, $report->getDroppedDetail());
        $this->assertArrayNotHasKey('_truncated', $report->toArray());
    }

    /**
     * @param array<string, int> $outcomes
     *
     * @return array{calls: int, elapsed: int, max: int, outcomes: array<string, int>}
     */
    private function row(int $calls = 1, int $elapsed = 1_000_000, int $max = 1_000_000, array $outcomes = []): array
    {
        return ['calls' => $calls, 'elapsed' => $elapsed, 'max' => $max, 'outcomes' => $outcomes];
    }
}
