<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model\Guard;

use Commerce\ProcessGuard\Api\ProcessReporterInterface;
use Commerce\ProcessGuard\Model\Config;
use Commerce\ProcessGuard\Model\Guard\Budget;
use Commerce\ProcessGuard\Model\Guard\ProcessGuard;
use Commerce\ProcessGuard\Model\Journal\Observation;
use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use Commerce\ProcessGuard\Model\Journal\ObservationRecorder;
use Commerce\ProcessGuard\Model\Journal\RequestJournal;
use Commerce\ProcessGuard\Model\Report\ProcessReport;
use Commerce\ProcessGuard\Test\Support\FakeClock;
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
            [self::PROCESS => new Budget(warnMilliseconds: 1)]
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
            []
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

    private function guard(?Budget $budget = null): ProcessGuard
    {
        return new ProcessGuard(
            $this->clock,
            new ObservationRecorder($this->journal, $this->reporter),
            $this->config,
            $budget === null ? [] : [self::PROCESS => $budget]
        );
    }
}
