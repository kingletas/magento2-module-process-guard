<?php
/**
 * BudgetTest.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model\Guard;

use Commerce\ProcessGuard\Model\Guard\Budget;
use PHPUnit\Framework\TestCase;

final class BudgetTest extends TestCase
{
    private const MS = 1_000_000;

    public function testMillisecondsBecomeNanoseconds(): void
    {
        $budget = new Budget(warnMilliseconds: 100, tripMilliseconds: 500);

        self::assertSame(100 * self::MS, $budget->getWarnNanoseconds());
        self::assertSame(500 * self::MS, $budget->getTripNanoseconds());
    }

    /**
     * A process nobody has budgeted is unlimited.
     */
    public function testNoLimitsIsTheDefaultAndMeansNoLimits(): void
    {
        $budget = new Budget();

        self::assertNull($budget->getWarnNanoseconds());
        self::assertNull($budget->getTripNanoseconds());
        self::assertFalse($budget->isWarned(PHP_INT_MAX));
        self::assertFalse($budget->isTripped(PHP_INT_MAX));
        self::assertFalse($budget->isCallCountExceeded(PHP_INT_MAX));
        self::assertFalse($budget->isMemoryExceeded(PHP_INT_MAX));
    }

    public function testZeroAndNegativeLimitsAreTreatedAsAbsent(): void
    {
        $budget = new Budget(warnMilliseconds: 0, tripMilliseconds: -5, maxCalls: 0, memoryBytes: -1);

        self::assertNull($budget->getWarnNanoseconds());
        self::assertNull($budget->getTripNanoseconds());
        self::assertNull($budget->getMaxCalls());
        self::assertNull($budget->getMemoryBytes());
    }

    /**
     * Nothing trips before it has been warned about.
     */
    public function testAWarningNeverSitsAboveTheTrip(): void
    {
        $budget = new Budget(warnMilliseconds: 900, tripMilliseconds: 100);

        self::assertSame(100 * self::MS, $budget->getWarnNanoseconds());
        self::assertTrue($budget->isWarned(101 * self::MS));
        self::assertTrue($budget->isTripped(101 * self::MS));
    }

    public function testThresholdsAreExclusive(): void
    {
        $budget = new Budget(warnMilliseconds: 100);

        self::assertFalse($budget->isWarned(100 * self::MS), 'Exactly at budget is within budget.');
        self::assertTrue($budget->isWarned(100 * self::MS + 1));
    }

    public function testCallCountsAreTheirOwnLimit(): void
    {
        $budget = new Budget(maxCalls: 4);

        self::assertFalse($budget->isCallCountExceeded(4));
        self::assertTrue($budget->isCallCountExceeded(5));
        self::assertFalse($budget->isWarned(PHP_INT_MAX), 'A call limit is not a time limit.');
    }

    public function testMemoryCeilingIsSeparateFromTime(): void
    {
        $budget = new Budget(memoryBytes: 1024);

        self::assertFalse($budget->isMemoryExceeded(1024));
        self::assertTrue($budget->isMemoryExceeded(1025));
    }

    public function testItDescribesItselfInMilliseconds(): void
    {
        $budget = new Budget(warnMilliseconds: 100, tripMilliseconds: 500, maxCalls: 4, memoryBytes: 2048);

        self::assertSame(
            ['warn_ms' => 100, 'trip_ms' => 500, 'max_calls' => 4, 'memory_bytes' => 2048],
            $budget->toArray()
        );
    }
}
