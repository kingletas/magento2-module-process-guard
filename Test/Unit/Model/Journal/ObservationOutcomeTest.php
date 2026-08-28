<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model\Journal;

use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How a unit of work ended, and the two questions a report asks about it.
 */
final class ObservationOutcomeTest extends TestCase
{
    #[DataProvider('outcomesThatCostTime')]
    public function testAnOutcomeThatConsumedTimeCountsAsHavingRun(ObservationOutcome $outcome): void
    {
        self::assertTrue($outcome->ran());
    }

    /**
     * @return array<string, array{ObservationOutcome}>
     */
    public static function outcomesThatCostTime(): array
    {
        return [
            'completed' => [ObservationOutcome::Completed],
            'over budget' => [ObservationOutcome::OverBudget],
            'threw and propagated' => [ObservationOutcome::Failed],
            'threw and was contained' => [ObservationOutcome::Contained],
        ];
    }

    /**
     * A failure still costs the time it spent before throwing, so it counts as
     * having run.
     */
    #[DataProvider('outcomesThatWereSkipped')]
    public function testAnOutcomeThatNeverExecutedDoesNotCountAsHavingRun(ObservationOutcome $outcome): void
    {
        self::assertFalse($outcome->ran());
    }

    /**
     * @return array<string, array{ObservationOutcome}>
     */
    public static function outcomesThatWereSkipped(): array
    {
        return [
            'shed' => [ObservationOutcome::Shed],
            'disabled' => [ObservationOutcome::Disabled],
            'repeated' => [ObservationOutcome::Repeated],
            'memory ceiling' => [ObservationOutcome::MemoryCeiling],
        ];
    }

    public function testOnlyACleanCompletionIsUnremarkable(): void
    {
        $unremarkable = array_values(array_filter(
            ObservationOutcome::cases(),
            static fn (ObservationOutcome $outcome): bool => !$outcome->isNoteworthy()
        ));

        self::assertSame([ObservationOutcome::Completed], $unremarkable);
    }

    /**
     * The distinction the enum exists for.
     */
    public function testFailedAndContainedAreDistinctStates(): void
    {
        self::assertNotSame(ObservationOutcome::Failed, ObservationOutcome::Contained);
        self::assertTrue(ObservationOutcome::Failed->ran());
        self::assertTrue(ObservationOutcome::Contained->ran());
        self::assertTrue(ObservationOutcome::Failed->isNoteworthy());
        self::assertTrue(ObservationOutcome::Contained->isNoteworthy());
    }

    /**
     * `Repeated` is not slowness — it is the same work done six times in one
     * request, which no per-call timing shows.
     */
    public function testRepeatedIsReportedWithoutBeingCountedAsExecution(): void
    {
        self::assertFalse(ObservationOutcome::Repeated->ran());
        self::assertTrue(ObservationOutcome::Repeated->isNoteworthy());
    }

    /**
     * These values are written into log lines and report rows that people grep.
     */
    public function testTheStoredValuesAreStable(): void
    {
        self::assertSame(
            [
                'completed',
                'failed',
                'contained',
                'shed',
                'disabled',
                'over_budget',
                'repeated',
                'memory_ceiling',
            ],
            array_map(
                static fn (ObservationOutcome $outcome): string => $outcome->value,
                ObservationOutcome::cases()
            )
        );
    }
}
