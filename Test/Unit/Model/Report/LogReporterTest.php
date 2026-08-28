<?php
/**
 * LogReporterTest.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model\Report;

use Commerce\ProcessGuard\Model\Journal\Observation;
use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use Commerce\ProcessGuard\Model\Report\LogReporter;
use Commerce\ProcessGuard\Model\Report\ProcessReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LogReporterTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    /** @var array<int, array{0: string, 1: string}> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->lines = [];
        $this->logger = $this->createMock(LoggerInterface::class);

        foreach (['error', 'warning', 'info', 'debug'] as $level) {
            $this->logger->method($level)
                ->willReturnCallback(function (string $message, array $context = []) use ($level): void {
                    $this->lines[] = [$level, $message];
                });
        }
    }

    /**
     * The levels are the contract an alert rule is written against, so they are
     * asserted rather than left to whoever edits the class next.
     */
    public function testAFailureIsAnErrorWhetherOrNotItWasContained(): void
    {
        $this->report(ObservationOutcome::Failed);
        $this->report(ObservationOutcome::Contained);

        self::assertSame(['error', 'error'], array_column($this->lines, 0));
    }

    public function testABudgetBreachIsAWarning(): void
    {
        $this->report(ObservationOutcome::OverBudget);
        $this->report(ObservationOutcome::Repeated);
        $this->report(ObservationOutcome::Shed);
        $this->report(ObservationOutcome::MemoryCeiling);

        self::assertSame(['warning', 'warning', 'warning', 'warning'], array_column($this->lines, 0));
    }

    public function testAnObserverStayingSwitchedOffIsMerelyInformation(): void
    {
        $this->report(ObservationOutcome::Disabled);

        self::assertSame('info', $this->lines[0][0]);
    }

    /**
     * A completion arriving here is a mistake, recorded at debug rather than
     * swallowed.
     */
    public function testARoutineCompletionIsDebug(): void
    {
        $this->report(ObservationOutcome::Completed);

        self::assertSame('debug', $this->lines[0][0]);
    }

    public function testASummaryNamesTheProcessAndItsLines(): void
    {
        $reporter = new LogReporter($this->logger);

        $report = new ProcessReport(
            ['event.a' => ['calls' => 2, 'elapsed' => 3_000_000, 'max' => 2_000_000, 'outcomes' => []]]
        );

        $reporter->reportProcess($report, 'event.a');

        self::assertSame('info', $this->lines[0][0]);
        self::assertStringContainsString('event.a finished', $this->lines[0][1]);
        self::assertStringContainsString('3ms', $this->lines[0][1]);
    }

    public function testAnEmptyReportIsNotLogged(): void
    {
        (new LogReporter($this->logger))->reportProcess(new ProcessReport(), 'event.a');

        self::assertSame([], $this->lines);
    }

    private function report(ObservationOutcome $outcome): void
    {
        (new LogReporter($this->logger))->reportObservation(
            new Observation($outcome, 'event.a', 'observer', 1_000_000, [], 'boom')
        );
    }
}
