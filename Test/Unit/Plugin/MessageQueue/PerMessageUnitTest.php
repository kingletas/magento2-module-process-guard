<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Test\Unit\Plugin\MessageQueue;

use Kingletas\ProcessGuard\Api\UnitOfWorkInterface;
use Kingletas\ProcessGuard\Plugin\MessageQueue\PerMessageUnit;
use Magento\Framework\MessageQueue\CallbackInvokerInterface;
use Magento\Framework\MessageQueue\QueueInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PerMessageUnitTest extends TestCase
{
    private UnitOfWorkInterface&MockObject $unitOfWork;

    /** @var string[] */
    private array $begun = [];

    /** @var string[] */
    private array $handled = [];

    protected function setUp(): void
    {
        $this->begun = [];
        $this->handled = [];

        $this->unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $this->unitOfWork->method('begin')
            ->willReturnCallback(function (string $unit): void {
                $this->begun[] = $unit;
            });
    }

    public function testEveryMessageOnTheBoundedPathStartsItsOwnUnit(): void
    {
        $arguments = $this->plugin()->beforeInvoke(
            $this->createMock(CallbackInvokerInterface::class),
            $this->createMock(QueueInterface::class),
            3,
            $this->handler()
        );

        $this->deliver($arguments[2], 3);

        $this->assertSame([PerMessageUnit::UNIT, PerMessageUnit::UNIT, PerMessageUnit::UNIT], $this->begun);
    }

    /**
     * The path a daemon consumer takes, and the one that runs for hours.
     */
    public function testEveryMessageOnTheSubscribePathStartsItsOwnUnit(): void
    {
        $arguments = $this->plugin()->beforeSubscribe(
            $this->createMock(QueueInterface::class),
            $this->handler()
        );

        $this->deliver($arguments[0], 2);

        $this->assertSame([PerMessageUnit::UNIT, PerMessageUnit::UNIT], $this->begun);
    }

    public function testTheMessageStillReachesItsHandler(): void
    {
        $arguments = $this->plugin()->beforeSubscribe(
            $this->createMock(QueueInterface::class),
            $this->handler()
        );

        $arguments[0]('message-1');

        $this->assertSame(['message-1'], $this->handled);
    }

    public function testTheUnitBeginsBeforeTheHandlerRuns(): void
    {
        $order = [];

        $unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $unitOfWork->method('begin')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'begin';
        });

        $arguments = (new PerMessageUnit($unitOfWork))->beforeSubscribe(
            $this->createMock(QueueInterface::class),
            static function () use (&$order): void {
                $order[] = 'handle';
            }
        );

        $arguments[0]('message-1');

        $this->assertSame(['begin', 'handle'], $order);
    }

    public function testEverythingElseIsPassedThroughUntouched(): void
    {
        $queue = $this->createMock(QueueInterface::class);

        $arguments = $this->plugin()->beforeInvoke(
            $this->createMock(CallbackInvokerInterface::class),
            $queue,
            7,
            $this->handler(),
            60,
            1
        );

        $this->assertSame($queue, $arguments[0]);
        $this->assertSame(7, $arguments[1]);
        $this->assertSame(60, $arguments[3]);
        $this->assertSame(1, $arguments[4]);
    }

    private function plugin(): PerMessageUnit
    {
        return new PerMessageUnit($this->unitOfWork);
    }

    private function handler(): callable
    {
        return function (string $message): void {
            $this->handled[] = $message;
        };
    }

    private function deliver(callable $callback, int $messages): void
    {
        for ($i = 1; $i <= $messages; $i++) {
            $callback('message-' . $i);
        }
    }
}
