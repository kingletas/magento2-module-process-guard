<?php
/**
 * GuardedTotalsCollectorTest.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Plugin\Quote;

use Commerce\ProcessGuard\Api\ProcessGuardInterface;
use Commerce\ProcessGuard\Plugin\Quote\GuardedTotalsCollector;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\TotalsCollector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GuardedTotalsCollectorTest extends TestCase
{
    private ProcessGuardInterface&MockObject $guard;
    private TotalsCollector&MockObject $subject;

    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $runs = [];

    protected function setUp(): void
    {
        $this->runs = [];
        $this->subject = $this->createMock(TotalsCollector::class);

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
        $plugin = new GuardedTotalsCollector($this->guard);

        $result = $plugin->aroundCollect(
            $this->subject,
            static fn (): Total => $total,
            $this->quote(42, 3)
        );

        self::assertSame($total, $result);
    }

    public function testBothEntryPointsCountAsTheSameProcess(): void
    {
        $plugin = new GuardedTotalsCollector($this->guard);
        $quote = $this->quote(42, 3);

        $plugin->aroundCollect($this->subject, fn (): Total => $this->createMock(Total::class), $quote);
        $plugin->aroundCollectQuoteTotals($this->subject, static fn (): Quote => $quote, $quote);

        self::assertSame(
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
        $plugin = new GuardedTotalsCollector($this->guard);

        $plugin->aroundCollect($this->subject, fn (): Total => $this->createMock(Total::class), $this->quote(42, 14));

        self::assertSame(42, $this->runs[0][1]['quote_id']);
        self::assertSame(14, $this->runs[0][1]['items']);
        self::assertStringContainsString('collect', (string) $this->runs[0][1]['label']);
    }

    public function testAFailureIsNotSwallowed(): void
    {
        $plugin = new GuardedTotalsCollector($this->guard);

        $this->expectException(RuntimeException::class);

        $plugin->aroundCollect(
            $this->subject,
            static function (): Total {
                throw new RuntimeException('a collector threw');
            },
            $this->quote(42, 1)
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
