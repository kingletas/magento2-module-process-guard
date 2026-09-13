<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Test\Unit\Plugin\Cron;

use Kingletas\ProcessGuard\Api\UnitOfWorkInterface;
use Kingletas\ProcessGuard\Plugin\Cron\PerJobUnit;
use Magento\Cron\Model\Schedule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PerJobUnitTest extends TestCase
{
    private UnitOfWorkInterface&MockObject $unitOfWork;

    /** @var string[] */
    private array $begun = [];

    protected function setUp(): void
    {
        $this->begun = [];

        $this->unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $this->unitOfWork->method('begin')
            ->willReturnCallback(function (string $unit): void {
                $this->begun[] = $unit;
            });
    }

    public function testEachJobStartsItsOwnUnitNamedAfterTheJob(): void
    {
        $this->lock('catalog_index_refresh', true);

        $this->assertSame(['cron.catalog_index_refresh'], $this->begun);
    }

    /**
     * The lock is attempted more than once when it is contended, and only the
     * attempt that wins is a job about to run.
     */
    public function testAFailedLockIsNotAUnitOfWork(): void
    {
        $this->lock('catalog_index_refresh', false);

        $this->assertSame([], $this->begun);
    }

    public function testTheAnswerIsPassedBackUnchanged(): void
    {
        $this->assertTrue($this->lock('catalog_index_refresh', true));
        $this->assertFalse($this->lock('catalog_index_refresh', false));
    }

    /**
     * The job is about to run, so the boundary has to be crossed before it
     * does rather than after.
     */
    public function testTheBoundaryIsCrossedWhileTheLockIsHeld(): void
    {
        $this->lock('catalog_index_refresh', true);

        $this->assertCount(1, $this->begun);
    }

    public function testTheHookIsNamedForAMethodThatExists(): void
    {
        $this->assertTrue((new ReflectionClass(Schedule::class))->hasMethod('tryLockJob'));
    }

    private function lock(string $code, bool $locked): mixed
    {
        $schedule = $this->createMock(Schedule::class);
        $schedule->method('getData')->willReturn($code);

        return (new PerJobUnit($this->unitOfWork))->afterTryLockJob($schedule, $locked);
    }
}
