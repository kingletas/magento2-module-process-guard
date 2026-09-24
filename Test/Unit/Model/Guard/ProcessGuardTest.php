<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Test\Unit\Model\Guard;

use Kingletas\ProcessGuard\Api\ProcessReporterInterface;
use Kingletas\ProcessGuard\Model\Config;
use Kingletas\ProcessGuard\Model\Guard\Budget;
use Kingletas\ProcessGuard\Model\Guard\BudgetDirectory;
use Kingletas\ProcessGuard\Model\Guard\ProcessGuard;
use Kingletas\ProcessGuard\Model\Journal\Observation;
use Kingletas\ProcessGuard\Model\Journal\ObservationOutcome;
use Kingletas\ProcessGuard\Model\Journal\ObservationRecorder;
use Kingletas\ProcessGuard\Model\Journal\RequestJournal;
use Kingletas\ProcessGuard\Model\Report\ProcessReport;
use Kingletas\ProcessGuard\Test\Support\FakeClock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProcessGuardTest extends TestCase
{
    private const PROCESS = 'checkout.place_order';

    private Config&MockObject $config;
    private FakeClock $clock;
    private RequestJournal $journal;
    private ProcessReporterInterface&MockObject $reporter;

    /** @var Observation[] */
    private array $reported = [];

    /** @var string[] */
    private array $summarised = [];

    protected function setUp(): void
    {
        $this->reported = [];
        $this->summarised = [];

        $this->clock = new FakeClock();
        $this->journal = new RequestJournal();

        $this->reporter = $this->createMock(ProcessReporterInterface::class);
        $this->reporter->method('reportObservation')
            ->willReturnCallback(function (Observation $observation): void {
                $this->reported[] = $observation;
            });
        $this->reporter->method('reportProcess')
            ->willReturnCallback(function (ProcessReport $report, string $process): void {
                $this->summarised[] = $process;
            });

        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isSummaryReportingEnabled')->willReturn(false);
    }

    /**
     * The constructor 2.0.0 shipped: budgets as an array in fourth place.
     */
    public function testBudgetsPassedAsAnArrayAreStillJudged(): void
    {
        $guard = new ProcessGuard(
            $this->clock,
            new ObservationRecorder($this->journal, $this->reporter),
            $this->config,
            [self::PROCESS => new Budget(maxCalls: 1)]
        );

        $guard->run(self::PROCESS, static fn (): bool => true);
        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame(1, $this->countReported(ObservationOutcome::Repeated));
    }

    public function testAnArrayBudgetOverridesTheDirectorysBudgetOfTheSameName(): void
    {
        $guard = new ProcessGuard(
            $this->clock,
            new ObservationRecorder($this->journal, $this->reporter),
            $this->config,
            [self::PROCESS => new Budget(maxCalls: 9)],
            new BudgetDirectory([self::PROCESS => new Budget(maxCalls: 1), 'other' => new Budget(maxCalls: 2)])
        );

        $guard->run(self::PROCESS, static fn (): bool => true);
        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame(0, $this->countReported(ObservationOutcome::Repeated));
        $this->assertSame(9, $guard->getBudgets()->get(self::PROCESS)?->getMaxCalls());
        $this->assertSame(2, $guard->getBudgets()->get('other')?->getMaxCalls());
    }

    public function testReturnsWhateverTheWorkReturns(): void
    {
        $this->assertSame('result', $this->guard()->run(self::PROCESS, static fn (): string => 'result'));
    }

    /**
     * Switched off, this must cost one config read — not a try/finally, two
     * array writes and a journal entry on every dispatch.
     */
    public function testDoesNothingAtAllWhenDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);

        $guard = new ProcessGuard(
            $this->clock,
            new ObservationRecorder($this->journal, $this->reporter),
            $config,
            budgetDirectory: new BudgetDirectory([self::PROCESS => new Budget(warnMilliseconds: 1)])
        );

        $this->assertSame('result', $guard->run(self::PROCESS, static fn (): string => 'result'));
        $this->assertSame([], $this->journal->getObservations());
        $this->assertSame([], $this->reported);
    }

    /**
     * The wrapper never decides an exception was unimportant.
     */
    public function testAFailureIsRecordedAndThenRethrown(): void
    {
        $guard = $this->guard();

        try {
            $guard->run(self::PROCESS, static function (): void {
                throw new RuntimeException('payment gateway timed out');
            });

            $this->fail('The exception must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('payment gateway timed out', $e->getMessage());
        }

        $observation = $this->journal->getObservations()[0];

        $this->assertSame(ObservationOutcome::Failed, $observation->getOutcome());
        $this->assertSame('payment gateway timed out', $observation->getFailure());
        $this->assertCount(1, $this->reported);
    }

    public function testTimesTheWork(): void
    {
        $this->clock->willTake([250]);

        $this->guard()->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame(250.0, $this->journal->getObservations()[0]->getElapsedMilliseconds());
    }

    /**
     * Cumulative, not per call: the failure that matters is a path costing four
     * seconds, whether that was one observer or forty.
     */
    public function testTheBudgetIsCumulativeOverTheRequest(): void
    {
        $this->clock->willTake([300, 300, 300]);
        $guard = $this->guard(new Budget(warnMilliseconds: 700));

        $guard->run(self::PROCESS, static fn (): bool => true);
        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame([], $this->reported, '600ms of a 700ms budget is not a breach.');

        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertCount(1, $this->reported);
        $this->assertSame(ObservationOutcome::OverBudget, $this->reported[0]->getOutcome());
    }

    /**
     * The breach is logged once rather than on every call after it.
     */
    public function testABreachIsReportedOncePerProcessPerRequest(): void
    {
        $this->clock->willTake([1000, 1000, 1000]);
        $guard = $this->guard(new Budget(warnMilliseconds: 100));

        $guard->run(self::PROCESS, static fn (): bool => true);
        $guard->run(self::PROCESS, static fn (): bool => true);
        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertCount(1, $this->reported);
    }

    public function testIsTrippedOnlyOnceTheTripBudgetIsPassed(): void
    {
        $this->clock->willTake([2000, 3000]);
        $guard = $this->guard(new Budget(warnMilliseconds: 1000, tripMilliseconds: 4000));

        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertFalse($guard->isTripped(self::PROCESS));

        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertTrue($guard->isTripped(self::PROCESS));
    }

    /**
     * An unbudgeted process is unlimited by definition.
     */
    public function testAProcessWithNoBudgetNeverTrips(): void
    {
        $this->clock->willTake([600_000]);
        $guard = $this->guard();

        $guard->run('unbudgeted', static fn (): bool => true);

        $this->assertFalse($guard->isTripped('unbudgeted'));
        $this->assertSame([], $this->reported);
    }

    /**
     * The classic checkout defect is not a slow collector — it is the same
     * collector running six times, which no per-call timing shows.
     */
    public function testRepetitionIsItsOwnBreach(): void
    {
        $guard = $this->guard(new Budget(maxCalls: 2));

        $guard->run(self::PROCESS, static fn (): bool => true);
        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame([], $this->reported);

        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertCount(1, $this->reported);
        $this->assertSame(ObservationOutcome::Repeated, $this->reported[0]->getOutcome());
        $this->assertStringContainsString('3 calls', $this->reported[0]->getLabel());
    }

    public function testExternallyTimedWorkCountsTowardsTheSameBudget(): void
    {
        $guard = $this->guard(new Budget(warnMilliseconds: 100, tripMilliseconds: 200));

        $guard->record(self::PROCESS, 150 * 1_000_000, ['observer' => 'slow_one']);

        $this->assertCount(1, $this->reported);
        $this->assertFalse($guard->isTripped(self::PROCESS));

        $guard->record(self::PROCESS, 100 * 1_000_000, ['observer' => 'another']);

        $this->assertTrue($guard->isTripped(self::PROCESS));
    }

    public function testACheckpointReportsAMemoryCeiling(): void
    {
        $this->clock->withMemory(900 * 1024 * 1024, 1024 * 1024 * 1024);
        $guard = $this->guard(new Budget(memoryBytes: 800 * 1024 * 1024));

        $guard->checkpoint('queue.consumer');

        $this->assertSame([], $this->reported, 'The ceiling belongs to the budgeted process, not to another.');

        $guard = $this->guard(new Budget(memoryBytes: 800 * 1024 * 1024));
        $guard->checkpoint(self::PROCESS, ['consumer' => 'inventory']);

        $this->assertCount(1, $this->reported);
        $this->assertSame(ObservationOutcome::MemoryCeiling, $this->reported[0]->getOutcome());
        $this->assertSame('inventory', $this->reported[0]->getContext()['consumer']);
    }

    public function testAMemoryCeilingIsReportedOnce(): void
    {
        $this->clock->withMemory(900);
        $guard = $this->guard(new Budget(memoryBytes: 800));

        $guard->checkpoint(self::PROCESS);
        $guard->checkpoint(self::PROCESS);
        $guard->checkpoint(self::PROCESS);

        $this->assertCount(1, $this->reported);
    }

    public function testACheckpointOnAnUnbudgetedProcessDoesNothing(): void
    {
        $this->clock->withMemory(PHP_INT_MAX);

        $this->guard()->checkpoint('unbudgeted');

        $this->assertSame([], $this->reported);
    }

    public function testASummaryIsEmittedWhenTheOutermostRunCloses(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isSummaryReportingEnabled')->willReturn(true);

        $guard = new ProcessGuard(
            $this->clock,
            new ObservationRecorder($this->journal, $this->reporter),
            $config,
            budgetDirectory: new BudgetDirectory()
        );

        $guard->run(self::PROCESS, function () use ($guard): bool {
            $guard->run('inner', static fn (): bool => true);

            return true;
        });

        $this->assertSame(['inner', self::PROCESS], $this->summarised);
    }

    public function testNoSummaryWhenSummariesAreOff(): void
    {
        $this->guard()->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame([], $this->summarised);
    }

    public function testTheReportComesFromTheJournal(): void
    {
        $this->clock->willTake([40]);

        $guard = $this->guard();
        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame(1, $guard->getReport()->getCalls(self::PROCESS));
        $this->assertSame(40.0, $guard->getReport()->getElapsedMilliseconds(self::PROCESS));
    }

    /**
     * The defect this fixes: a PHP process that handles thousands of units of
     * work measured all of them against one budget, crossed it early and never
     * came back under it.
     */
    public function testTimeDoesNotCarryFromOneUnitOfWorkToTheNext(): void
    {
        $this->clock->willTake([400, 400]);
        $guard = $this->guard(new Budget(warnMilliseconds: 300, tripMilliseconds: 300));

        $guard->run(self::PROCESS, static fn (): bool => true);
        $this->assertTrue($guard->isTripped(self::PROCESS), 'One long call should be over the warning.');

        $guard->begin('queue.message');
        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame(
            400.0,
            $guard->getReport()->getElapsedMilliseconds(self::PROCESS),
            'The second unit of work is charged for itself and nothing else.'
        );
    }

    public function testWithoutABoundaryTimeStillAccumulates(): void
    {
        $this->clock->willTake([400, 400]);
        $guard = $this->guard();

        $guard->run(self::PROCESS, static fn (): bool => true);
        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame(
            800.0,
            $guard->getReport()->getElapsedMilliseconds(self::PROCESS),
            'Two calls in one unit of work are two calls, which is the behaviour a request needs.'
        );
    }

    public function testCallCountsDoNotCarryEither(): void
    {
        $this->clock->willTake([1, 1, 1, 1, 1, 1]);
        $guard = $this->guard(new Budget(maxCalls: 2));

        $guard->run(self::PROCESS, static fn (): bool => true);
        $guard->run(self::PROCESS, static fn (): bool => true);
        $guard->begin('queue.message');
        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame(
            0,
            $this->countReported(ObservationOutcome::Repeated),
            'Three calls across two units of work is not three calls in one.'
        );
    }

    /**
     * A consumer's own budget covers the consumer, not one message inside it.
     */
    public function testAProcessStillRunningSurvivesTheBoundary(): void
    {
        $this->clock->willTake([5, 5]);
        $guard = $this->guard(new Budget(maxCalls: 1));

        $guard->run(self::PROCESS, static function () use ($guard): bool {
            $guard->begin('queue.message');

            return true;
        });
        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame(
            1,
            $this->countReported(ObservationOutcome::Repeated),
            'The call made before the boundary still counts, because the process was open across it.'
        );
    }

    public function testAProcessThatHadFinishedDoesNotSurviveTheBoundary(): void
    {
        $this->clock->willTake([5, 5]);
        $guard = $this->guard(new Budget(maxCalls: 1));

        $guard->run(self::PROCESS, static fn (): bool => true);
        $guard->begin('queue.message');
        $guard->run(self::PROCESS, static fn (): bool => true);

        $this->assertSame(
            0,
            $this->countReported(ObservationOutcome::Repeated),
            'One call in each of two units of work is not two calls in one.'
        );
    }

    public function testTheUnitIsNamedByWhoeverBeganIt(): void
    {
        $guard = $this->guard();
        $guard->begin('cron.catalog_index_refresh');

        $this->assertSame('cron.catalog_index_refresh', $guard->getUnit());
    }

    public function testABoundaryDoesNothingWhileTheGuardIsOff(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);

        $guard = new ProcessGuard(
            $this->clock,
            new ObservationRecorder($this->journal, $this->reporter),
            $config,
            budgetDirectory: new BudgetDirectory([])
        );

        $guard->begin('queue.message');

        $this->assertSame('', $guard->getUnit());
    }

    private function countReported(ObservationOutcome $outcome): int
    {
        return count(array_filter(
            $this->reported,
            static fn (Observation $observation): bool => $observation->getOutcome() === $outcome
        ));
    }

    private function guard(?Budget $budget = null): ProcessGuard
    {
        return new ProcessGuard(
            $this->clock,
            new ObservationRecorder($this->journal, $this->reporter),
            $this->config,
            budgetDirectory: new BudgetDirectory($budget === null ? [] : [self::PROCESS => $budget])
        );
    }
}
