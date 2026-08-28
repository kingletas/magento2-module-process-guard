<?php
/**
 * GuardedConsumerTest.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Plugin\MessageQueue;

use Commerce\ProcessGuard\Api\ProcessGuardInterface;
use Commerce\ProcessGuard\Plugin\MessageQueue\GuardedConsumer;
use Commerce\ProcessGuard\Test\Support\FakeConsumer\Interceptor;
use Magento\Framework\MessageQueue\ConsumerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GuardedConsumerTest extends TestCase
{
    private ProcessGuardInterface&MockObject $guard;

    /** @var string[] Ordered log of guard calls. */
    private array $calls = [];

    /** @var array<string, mixed> */
    private array $lastContext = [];

    protected function setUp(): void
    {
        $this->calls = [];
        $this->lastContext = [];

        $this->guard = $this->createMock(ProcessGuardInterface::class);
        $this->guard->method('checkpoint')
            ->willReturnCallback(function (string $process, array $context = []): void {
                $this->calls[] = 'checkpoint';
                $this->lastContext = $context;
            });
        $this->guard->method('run')
            ->willReturnCallback(function (string $process, callable $work, array $context = []): mixed {
                $this->calls[] = 'run';
                $this->lastContext = $context;

                return $work();
            });
    }

    public function testTheConsumerStillProcesses(): void
    {
        $processed = false;
        $plugin = new GuardedConsumer($this->guard);

        $plugin->aroundProcess(
            $this->createMock(ConsumerInterface::class),
            static function () use (&$processed): void {
                $processed = true;
            },
            100
        );

        $this->assertTrue($processed);
    }

    /**
     * A checkpoint that only runs after the batch that ran out of memory never
     * runs.
     */
    public function testTheMemoryCheckpointHappensBeforeTheBatch(): void
    {
        $plugin = new GuardedConsumer($this->guard);

        $plugin->aroundProcess(
            $this->createMock(ConsumerInterface::class),
            static fn (): bool => true,
            100
        );

        $this->assertSame(['checkpoint', 'run'], $this->calls);
    }

    public function testTheBatchSizeIsInTheReport(): void
    {
        $plugin = new GuardedConsumer($this->guard);

        $plugin->aroundProcess($this->createMock(ConsumerInterface::class), static fn (): bool => true, 250);

        $this->assertSame(250, $this->lastContext['batch']);
    }

    public function testAnUnboundedRunIsReportedAsSuch(): void
    {
        $plugin = new GuardedConsumer($this->guard);

        $plugin->aroundProcess($this->createMock(ConsumerInterface::class), static fn (): bool => true);

        $this->assertNull($this->lastContext['batch']);
    }

    /**
     * Every consumer in a running installation is an interceptor subclass, and
     * the suffix is dropped.
     */
    public function testTheInterceptorSuffixIsNotInTheReport(): void
    {
        $plugin = new GuardedConsumer($this->guard);
        $plugin->aroundProcess(new Interceptor(), static fn (): bool => true);

        $this->assertStringNotContainsString('Interceptor', (string) $this->lastContext['consumer']);
        $this->assertStringEndsWith('FakeConsumer', (string) $this->lastContext['consumer']);
    }
}
