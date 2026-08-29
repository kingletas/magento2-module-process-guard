<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Performance;

use Commerce\Foundation\Test\Support\BudgetAssertions;
use Commerce\Foundation\Test\Support\CountingScopeConfig;
use Commerce\ProcessGuard\Api\ProcessReporterInterface;
use Commerce\ProcessGuard\Model\Config;
use Commerce\ProcessGuard\Model\Journal\ObservationRecorder;
use Commerce\ProcessGuard\Model\Journal\RequestJournal;
use Commerce\ProcessGuard\Model\Policy\ObserverPolicyResolver;
use Commerce\ProcessGuard\Plugin\Event\GuardedInvoker;
use Commerce\ProcessGuard\Test\Support\FakeClock;
use Commerce\ProcessGuard\Test\Support\RecordingGuard;
use Magento\Framework\Event;
use Magento\Framework\Event\Invoker\InvokerDefault;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

/**
 * What this module costs the requests it does not act on.
 */
class DispatchCostTest extends TestCase
{
    use BudgetAssertions;

    private const SECTION = 'commerce_processguard';
    private const WATCHED = 'sales_order_place_after';
    private const UNWATCHED = 'controller_action_predispatch';

    /**
     * Every request dispatches hundreds of these.
     */
    public function testAnUnwatchedEventCostsTheSameWhateverTheObserverCount(): void
    {
        $this->assertConstantCost(
            'config reads while dispatching to observers of an unwatched event',
            fn (int $observers): int => $this->dispatch(self::UNWATCHED, $observers)->reads()
        );
    }

    /**
     * Order placement runs about fifty-six observers.
     */
    public function testWatchingAnEventCostsTheSameWhateverTheObserverCount(): void
    {
        $this->assertConstantCost(
            'config reads while dispatching to observers of a watched event',
            fn (int $observers): int => $this->dispatch(self::WATCHED, $observers)->reads()
        );
    }

    /**
     * Five settings, each read once: the master switch, the shedding switch and
     * three lists.
     */
    public function testTheWholeGuardIsDecidedByFiveConfigReads(): void
    {
        $config = $this->dispatch(self::WATCHED, 56);

        $this->assertCostAtMost(
            'settling policy for a fifty-six observer event',
            5,
            $config->reads(),
            $config->summary()
        );
    }

    /**
     * Dispatch one event to `$observers` observers, and hand back the config
     * double so the caller can ask what it was asked.
     */
    private function dispatch(string $event, int $observers): CountingScopeConfig
    {
        $scopeConfig = new CountingScopeConfig([
            self::SECTION . '/general/enabled' => '1',
            self::SECTION . '/enforcement/shedding_enabled' => '0',
            self::SECTION . '/enforcement/disabled_observers' => '',
            self::SECTION . '/enforcement/critical_observers' => '',
            self::SECTION . '/enforcement/advisory_observers' => '',
        ]);

        $config = new Config($scopeConfig, self::SECTION);
        $invoker = new GuardedInvoker(
            $config,
            new ObserverPolicyResolver($config, [self::WATCHED]),
            new RecordingGuard(),
            new ObservationRecorder(new RequestJournal(), $this->createMock(ProcessReporterInterface::class)),
            new FakeClock()
        );

        $subject = $this->createMock(InvokerDefault::class);
        $observer = new Observer();
        $observer->setEvent(new Event(['name' => $event]));

        for ($i = 0; $i < $observers; $i++) {
            $invoker->aroundDispatch(
                $subject,
                static fn () => null,
                ['name' => 'vendor_observer_' . $i, 'instance' => 'Vendor\Module\Observer\Number' . $i],
                $observer
            );
        }

        return $scopeConfig;
    }
}
