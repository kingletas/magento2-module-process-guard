<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Test\Unit\Plugin\Quote;

use Kingletas\ProcessGuard\Api\ProcessReporterInterface;
use Kingletas\ProcessGuard\Model\Config;
use Kingletas\ProcessGuard\Model\Journal\Observation;
use Kingletas\ProcessGuard\Model\Journal\ObservationOutcome;
use Kingletas\ProcessGuard\Model\Journal\ObservationRecorder;
use Kingletas\ProcessGuard\Model\Journal\RequestJournal;
use Kingletas\ProcessGuard\Plugin\Quote\GuardedTotalCollector;
use Kingletas\ProcessGuard\Test\Support\FakeClock;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\CollectorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GuardedTotalCollectorTest extends TestCase
{
    private FakeClock $clock;
    private RequestJournal $journal;
    private ObservationRecorder $recorder;

    /** @var Observation[] */
    private array $reported = [];

    protected function setUp(): void
    {
        $this->reported = [];
        $this->clock = new FakeClock();
        $this->journal = new RequestJournal();

        $reporter = $this->createMock(ProcessReporterInterface::class);
        $reporter->method('reportObservation')
            ->willReturnCallback(function (Observation $observation): void {
                $this->reported[] = $observation;
            });

        $this->recorder = new ObservationRecorder($this->journal, $reporter);
    }

    public function testEachCollectorGetsItsOwnProcess(): void
    {
        $plugin = $this->plugin(true, [300, 75]);

        $this->collect($plugin, $this->collector('VendorA_TaxCollector'));
        $this->collect($plugin, $this->collector('VendorB_DiscountCollector'));

        $this->assertSame(
            ['totals.VendorA_TaxCollector', 'totals.VendorB_DiscountCollector'],
            $this->journal->getReport()->getProcesses()
        );
    }

    /**
     * Two of the collectors on a real store are both called `Tax`, so the
     * process name carries the whole class.
     */
    public function testTheProcessNameIsTheWholeClass(): void
    {
        $this->collect($this->plugin(true, [300]), $this->collector('VendorC_Sales_Total_Quote_Tax'));

        $this->assertSame(
            ['totals.VendorC_Sales_Total_Quote_Tax'],
            $this->journal->getReport()->getProcesses()
        );
    }

    public function testTheMostExpensiveCollectorIsReportedFirst(): void
    {
        $plugin = $this->plugin(true, [75, 300]);

        $this->collect($plugin, $this->collector('VendorD_CheapCollector'));
        $this->collect($plugin, $this->collector('VendorD_ExpensiveCollector'));

        $this->assertSame('totals.VendorD_ExpensiveCollector', $this->journal->getReport()->getProcesses()[0]);
    }

    public function testNothingIsRecordedWhileTheBreakdownIsOff(): void
    {
        $this->collect($this->plugin(false, [75]), $this->collector('VendorE_DiscountCollector'));

        $this->assertTrue($this->journal->getReport()->isEmpty());
    }

    public function testCollectionStillHappensWhileTheBreakdownIsOff(): void
    {
        $collector = $this->collector('VendorF_DiscountCollector');
        $ran = false;

        $this->plugin(false, [])->aroundCollect(
            $collector,
            static function () use ($collector, &$ran) {
                $ran = true;

                return $collector;
            },
            $this->createMock(Quote::class),
            $this->createMock(ShippingAssignmentInterface::class),
            $this->createMock(Total::class)
        );

        $this->assertTrue($ran);
    }

    public function testAFailingCollectorIsRecordedAndRethrown(): void
    {
        $collector = $this->collector('VendorG_DiscountCollector');

        try {
            $this->plugin(true, [10])->aroundCollect(
                $collector,
                static function (): void {
                    throw new RuntimeException('a collector threw');
                },
                $this->createMock(Quote::class),
                $this->createMock(ShippingAssignmentInterface::class),
                $this->createMock(Total::class)
            );

            $this->fail('The failure should have reached the caller.');
        } catch (RuntimeException) {
            $this->assertSame(
                ObservationOutcome::Failed,
                $this->journal->getObservations()[0]->getOutcome()
            );
        }
    }

    /**
     * @param int[] $durations
     */
    private function plugin(bool $detail, array $durations): GuardedTotalCollector
    {
        $this->clock->willTake($durations);

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isTotalsDetailEnabled')->willReturn($detail);

        return new GuardedTotalCollector($config, $this->recorder, $this->clock);
    }

    private function collect(GuardedTotalCollector $plugin, CollectorInterface $collector): void
    {
        $plugin->aroundCollect(
            $collector,
            static fn (): CollectorInterface => $collector,
            $this->createMock(Quote::class),
            $this->createMock(ShippingAssignmentInterface::class),
            $this->createMock(Total::class)
        );
    }

    private function collector(string $class): CollectorInterface&MockObject
    {
        return $this->getMockBuilder(CollectorInterface::class)
            ->setMockClassName($class)
            ->getMock();
    }
}
