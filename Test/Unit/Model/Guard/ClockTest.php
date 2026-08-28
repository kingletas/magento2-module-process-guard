<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model\Guard;

use Commerce\ProcessGuard\Api\ClockInterface;
use Commerce\ProcessGuard\Model\Guard\Clock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The real clock — the one place in this module that touches PHP's own timing
 * and memory functions.
 */
final class ClockTest extends TestCase
{
    public function testItIsTheInterfaceTheGuardDependsOn(): void
    {
        self::assertInstanceOf(ClockInterface::class, new Clock());
    }

    public function testTimeMovesForwardAndIsMeasuredInNanoseconds(): void
    {
        $clock = new Clock();

        $before = $clock->nanoTime();
        // Enough work to be measurable without being slow.
        for ($i = 0; $i < 10_000; $i++) {
            $unused = $i * 2;
        }
        $after = $clock->nanoTime();

        self::assertGreaterThan($before, $after);
        // A microsecond-resolution clock returning microseconds would put this
        // difference in the tens, not the tens of thousands.
        self::assertGreaterThan(1_000, $after - $before);
    }

    /**
     * Real usage rather than the emalloc figure, because the number the memory
     * limit is actually compared against is the real one.
     */
    public function testMemoryUsageReportsRealAllocation(): void
    {
        self::assertSame(memory_get_usage(true), (new Clock())->memoryUsage());
    }

    /**
     * Every shorthand PHP accepts, including the small ones.
     */
    #[DataProvider('memoryLimits')]
    public function testEveryShorthandIsReadAsBytes(string|false $configured, ?int $expected): void
    {
        self::assertSame($expected, $this->clockReading($configured)->memoryLimit());
    }

    /**
     * @return array<string, array{string|false, int|null}>
     */
    public static function memoryLimits(): array
    {
        return [
            'gigabytes' => ['2G', 2 * 1024 * 1024 * 1024],
            'gigabytes, lower case' => ['2g', 2 * 1024 * 1024 * 1024],
            'megabytes' => ['512M', 512 * 1024 * 1024],
            'megabytes, lower case' => ['512m', 512 * 1024 * 1024],
            'kilobytes' => ['64K', 64 * 1024],
            'kilobytes, lower case' => ['64k', 64 * 1024],
            'a plain byte count' => ['1048576', 1048576],
            'unlimited' => ['-1', null],
            'unset' => ['', null],
            'unreadable' => [false, null],
        ];
    }

    /**
     * `-1` means no limit, and read as one it puts every process over its
     * ceiling.
     */
    public function testUnlimitedIsNullRatherThanNegativeOne(): void
    {
        self::assertNull($this->clockReading('-1')->memoryLimit());
        self::assertNotSame(-1, $this->clockReading('-1')->memoryLimit());
    }

    /**
     * And the real ini value still goes through the same parsing, so the seam
     * cannot drift away from what the class actually does in production.
     */
    public function testTheRealConfiguredLimitIsReadThroughTheSameParsing(): void
    {
        $configured = ini_get('memory_limit');
        $expected = $configured === false || $configured === '' || $configured === '-1'
            ? null
            : (new Clock())->memoryLimit();

        self::assertSame($expected, (new Clock())->memoryLimit());
    }

    private function clockReading(string|false $configured): Clock
    {
        return new class ($configured) extends Clock {
            public function __construct(private readonly string|false $configured)
            {
            }

            protected function rawMemoryLimit(): string|false
            {
                return $this->configured;
            }
        };
    }
}
