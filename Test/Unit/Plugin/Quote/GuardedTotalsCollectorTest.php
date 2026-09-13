<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Test\Unit\Plugin\Quote;

use Kingletas\ProcessGuard\Api\ProcessGuardInterface;
use Kingletas\ProcessGuard\Model\Config;
use Kingletas\ProcessGuard\Model\Guard\CallerResolver;
use Kingletas\ProcessGuard\Plugin\Quote\GuardedTotalsCollector;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\TotalsCollector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GuardedTotalsCollectorTest extends TestCase
{
    private ProcessGuardInterface&MockObject $guard;
    private TotalsCollector&MockObject $subject;
    private Config&MockObject $config;

    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $runs = [];

    protected function setUp(): void
    {
        $this->runs = [];
        $this->subject = $this->createMock(TotalsCollector::class);
        $this->config = $this->createMock(Config::class);

        $this->guard = $this->createMock(ProcessGuardInterface::class);
        $this->guard->method('run')
            ->willReturnCallback(function (string $process, callable $work, array $context = []): mixed {
                $this->runs[] = [$process, $context];

                return $work();
            });
    }

    public function testTotalsAreStillCollected(): void
    {
        $total = $this->createMock(Total::class);
        $plugin = $this->plugin();

        $result = $plugin->aroundCollect(
            $this->subject,
            static fn (): Total => $total,
            $this->quote(42, 3)
        );

        $this->assertSame($total, $result);
    }

    public function testBothEntryPointsCountAsTheSameProcess(): void
    {
        $plugin = $this->plugin();
        $quote = $this->quote(42, 3);

        $plugin->aroundCollect($this->subject, fn (): Total => $this->createMock(Total::class), $quote);
        $plugin->aroundCollectQuoteTotals($this->subject, static fn (): Quote => $quote, $quote);

        $this->assertSame(
            [GuardedTotalsCollector::PROCESS, GuardedTotalsCollector::PROCESS],
            array_column($this->runs, 0),
            'Collecting twice by two routes is still collecting twice — which is the defect being counted.'
        );
    }

    /**
     * A report that says "some cart was slow" is not actionable.
     */
    public function testTheReportCanNameTheCart(): void
    {
        $plugin = $this->plugin();

        $plugin->aroundCollect($this->subject, fn (): Total => $this->createMock(Total::class), $this->quote(42, 14));

        $this->assertSame(42, $this->runs[0][1]['quote_id']);
        $this->assertSame(14, $this->runs[0][1]['items']);
        $this->assertStringContainsString('collect', (string) $this->runs[0][1]['label']);
    }

    public function testAFailureIsNotSwallowed(): void
    {
        $plugin = $this->plugin();

        $this->expectException(RuntimeException::class);

        $plugin->aroundCollect(
            $this->subject,
            static function (): Total {
                throw new RuntimeException('a collector threw');
            },
            $this->quote(42, 1)
        );
    }

    /**
     * Who asked for a collection is the only thing that turns "collected five
     * times, budget allows four" into somewhere to go and look.
     */
    public function testTheCallerIsRecordedOnlyWhenDetailIsOn(): void
    {
        $this->config->method('isTotalsDetailEnabled')->willReturn(true);

        $this->plugin(new CallerResolver([], 3))
            ->aroundCollect($this->subject, fn (): Total => $this->createMock(Total::class), $this->quote(7, 1));

        $this->assertArrayHasKey('asked_by', $this->runs[0][1]);
        $this->assertNotSame('', $this->runs[0][1]['asked_by']);
    }

    public function testTheCallerCostsNothingWhenDetailIsOff(): void
    {
        $this->config->method('isTotalsDetailEnabled')->willReturn(false);

        $callers = $this->createMock(CallerResolver::class);
        $callers->expects($this->never())->method('resolve');

        $this->plugin($callers)
            ->aroundCollect($this->subject, fn (): Total => $this->createMock(Total::class), $this->quote(7, 1));

        $this->assertArrayNotHasKey('asked_by', $this->runs[0][1]);
    }

    private function plugin(?CallerResolver $callers = null): GuardedTotalsCollector
    {
        return new GuardedTotalsCollector(
            $this->guard,
            $this->config,
            $callers ?? $this->createMock(CallerResolver::class)
        );
    }

    private function quote(int $id, int $items): Quote&MockObject
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn($id);
        $quote->method('getItemsCount')->willReturn($items);

        return $quote;
    }
}
