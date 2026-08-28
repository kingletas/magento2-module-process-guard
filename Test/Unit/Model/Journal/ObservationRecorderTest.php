<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model\Journal;

use Commerce\ProcessGuard\Api\ProcessJournalInterface;
use Commerce\ProcessGuard\Api\ProcessReporterInterface;
use Commerce\ProcessGuard\Model\Journal\Observation;
use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use Commerce\ProcessGuard\Model\Journal\ObservationRecorder;
use Commerce\ProcessGuard\Model\Report\ProcessReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One rule, in one place: journal everything, report only what matters.
 */
class ObservationRecorderTest extends TestCase
{
    public function testEveryObservationIsJournalledWhateverItsOutcome(): void
    {
        $journal = $this->journal();
        $recorder = new ObservationRecorder($journal, $this->reporter());

        foreach (ObservationOutcome::cases() as $outcome) {
            $recorder->record($this->observation($outcome));
        }

        $this->assertCount(count(ObservationOutcome::cases()), $journal->recorded);
    }

    /**
     * The half that keeps the log readable.
     */
    public function testACleanCompletionIsJournalledButNotReported(): void
    {
        $journal = $this->journal();
        $reporter = $this->reporter();

        (new ObservationRecorder($journal, $reporter))
            ->record($this->observation(ObservationOutcome::Completed));

        $this->assertCount(1, $journal->recorded);
        $this->assertSame([], $reporter->observations);
    }

    #[DataProvider('noteworthyOutcomes')]
    public function testANoteworthyOutcomeIsBothJournalledAndReported(ObservationOutcome $outcome): void
    {
        $journal = $this->journal();
        $reporter = $this->reporter();

        (new ObservationRecorder($journal, $reporter))->record($this->observation($outcome));

        $this->assertCount(1, $journal->recorded);
        $this->assertCount(1, $reporter->observations);
    }

    /**
     * @return array<string, array{ObservationOutcome}>
     */
    public static function noteworthyOutcomes(): array
    {
        return array_combine(
            array_map(
                static fn (ObservationOutcome $outcome): string => $outcome->value,
                array_filter(
                    ObservationOutcome::cases(),
                    static fn (ObservationOutcome $outcome): bool => $outcome->isNoteworthy()
                )
            ),
            array_map(
                static fn (ObservationOutcome $outcome): array => [$outcome],
                array_filter(
                    ObservationOutcome::cases(),
                    static fn (ObservationOutcome $outcome): bool => $outcome->isNoteworthy()
                )
            )
        );
    }

    /**
     * The journal comes first.
     */
    public function testTheObservationIsJournalledBeforeItIsReported(): void
    {
        $order = [];
        $journal = $this->journal($order);
        $reporter = $this->reporter($order);

        (new ObservationRecorder($journal, $reporter))
            ->record($this->observation(ObservationOutcome::OverBudget));

        $this->assertSame(['journal', 'reporter'], $order);
    }

    public function testSummarisingHandsTheReportAndTheProcessNameToTheReporter(): void
    {
        $report = new ProcessReport(['checkout' => ['calls' => 1, 'elapsed' => 1, 'max' => 1, 'outcomes' => []]]);
        $reporter = $this->reporter();

        (new ObservationRecorder($this->journal(report: $report), $reporter))->summarise('checkout');

        $this->assertSame([['report' => $report, 'process' => 'checkout']], $reporter->processes);
    }

    public function testTheReportComesFromTheJournal(): void
    {
        $report = new ProcessReport();

        $this->assertSame(
            $report,
            (new ObservationRecorder($this->journal(report: $report), $this->reporter()))->getReport()
        );
    }

    private function observation(ObservationOutcome $outcome): Observation
    {
        return new Observation($outcome, 'checkout', 'checkout.observer', 1_000_000);
    }

    /**
     * @param string[] $order
     */
    private function journal(array &$order = [], ?ProcessReport $report = null): ProcessJournalInterface
    {
        return new class ($order, $report ?? new ProcessReport()) implements ProcessJournalInterface {
            /** @var Observation[] */
            public array $recorded = [];

            /**
             * @param string[] $order
             */
            public function __construct(private array &$order, private readonly ProcessReport $report)
            {
            }

            public function record(Observation $observation): void
            {
                $this->order[] = 'journal';
                $this->recorded[] = $observation;
            }

            public function getReport(): ProcessReport
            {
                return $this->report;
            }

            public function getObservations(): array
            {
                return $this->recorded;
            }

            public function clear(): void
            {
                $this->recorded = [];
            }
        };
    }

    /**
     * @param string[] $order
     */
    private function reporter(array &$order = []): ProcessReporterInterface
    {
        return new class ($order) implements ProcessReporterInterface {
            /** @var Observation[] */
            public array $observations = [];

            /** @var array<int, array{report: ProcessReport, process: string}> */
            public array $processes = [];

            /**
             * @param string[] $order
             */
            public function __construct(private array &$order)
            {
            }

            public function reportObservation(Observation $observation): void
            {
                $this->order[] = 'reporter';
                $this->observations[] = $observation;
            }

            public function reportProcess(ProcessReport $report, string $process): void
            {
                $this->processes[] = ['report' => $report, 'process' => $process];
            }
        };
    }
}
