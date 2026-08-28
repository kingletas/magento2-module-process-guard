<?php
/**
 * IncidentTest.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Behaviour;

use Commerce\Foundation\Test\Support\CountingScopeConfig;
use Commerce\ProcessGuard\Api\ProcessReporterInterface;
use Commerce\ProcessGuard\Model\Config;
use Commerce\ProcessGuard\Model\Guard\Budget;
use Commerce\ProcessGuard\Model\Guard\ProcessGuard;
use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use Commerce\ProcessGuard\Model\Journal\ObservationRecorder;
use Commerce\ProcessGuard\Model\Journal\RequestJournal;
use Commerce\ProcessGuard\Model\Policy\ObserverPolicyResolver;
use Commerce\ProcessGuard\Plugin\Event\GuardedInvoker;
use Commerce\ProcessGuard\Test\Unit\Stub\FakeClock;
use Magento\Framework\Event;
use Magento\Framework\Event\Invoker\InvokerDefault;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An order being placed while one observer is misbehaving.
 */
class IncidentTest extends TestCase
{
    private const SECTION = 'commerce_processguard';
    private const EVENT = 'sales_model_service_quote_submit_before';
    private const PROCESS = 'event.sales_model_service_quote_submit_before';

    /** @var array<string, string> The store's configuration, as typed. */
    private array $settings = [];

    /** @var string[] Observers that actually ran, in order. */
    private array $ran = [];

    /** @var array<string, int> Observer name => milliseconds it takes. */
    private array $durations = [];

    /** @var array<string, string> Observer name => the exception it throws. */
    private array $failures = [];

    private RequestJournal $journal;

    protected function setUp(): void
    {
        $this->ran = [];
        $this->durations = [];
        $this->failures = [];
        $this->journal = new RequestJournal();
        $this->settings = [
            self::SECTION . '/general/enabled' => '1',
            self::SECTION . '/enforcement/shedding_enabled' => '0',
        ];
    }

    /**
     * Installing a module must not be the thing that changes behaviour.
     */
    public function testWithMeasurementOffNothingIsWatchedAndNothingIsChanged(): void
    {
        $this->settings[self::SECTION . '/general/enabled'] = '0';

        $this->placeOrder(['vendor_analytics_ping', 'payment_capture']);

        $this->assertSame(['vendor_analytics_ping', 'payment_capture'], $this->ran);
        $this->assertSame([], $this->journal->getObservations());
    }

    /**
     * Containment and shedding apply only to observers somebody has named.
     */
    public function testSwitchedOnWithNothingClassifiedEveryObserverStillRuns(): void
    {
        $this->placeOrder(['vendor_analytics_ping', 'payment_capture']);

        $this->assertSame(['vendor_analytics_ping', 'payment_capture'], $this->ran);
        $this->assertCount(2, $this->journal->getObservations());
        $this->assertSame(2, $this->journal->getReport()->getCalls(self::PROCESS));
    }

    /**
     * "Checkout is slow" is not actionable.
     */
    public function testTheSlowObserverIsNamedRatherThanTheEventBeingCalledSlow(): void
    {
        $this->durations = ['vendor_analytics_ping' => 400, 'payment_capture' => 20];

        $this->placeOrder(['vendor_analytics_ping', 'payment_capture']);

        $overBudget = $this->observationsWith(ObservationOutcome::OverBudget);

        $this->assertSame(['vendor_analytics_ping'], $overBudget);
    }

    /**
     * No deploy, no patched vendor package.
     */
    public function testAnObserverNamedInTheDisabledListStopsRunning(): void
    {
        $this->settings[self::SECTION . '/enforcement/disabled_observers'] = 'vendor_broken_observer';

        $this->placeOrder(['vendor_broken_observer', 'payment_capture']);

        $this->assertSame(['payment_capture'], $this->ran);
        $this->assertSame(['vendor_broken_observer'], $this->observationsWith(ObservationOutcome::Disabled));
    }

    /**
     * An observer can be named by its class instead, because whoever is reading
     * a stack trace has the class and not the `events.xml` name.
     */
    public function testAnObserverCanBeNamedByItsClassInstead(): void
    {
        $this->settings[self::SECTION . '/enforcement/disabled_observers']
            = 'Vendor\Analytics\Observer\OrderPing';

        $this->placeOrder(['vendor_analytics_ping', 'payment_capture']);

        $this->assertSame(['payment_capture'], $this->ran);
    }

    /**
     * An advisory observer throwing inside the order transaction must not fail
     * the order.
     */
    public function testAnAdvisoryObserverThatThrowsDoesNotTakeTheOrderWithIt(): void
    {
        $this->settings[self::SECTION . '/enforcement/advisory_observers'] = 'vendor_analytics_ping';
        $this->failures = ['vendor_analytics_ping' => 'the analytics API is down'];

        $this->placeOrder(['vendor_analytics_ping', 'payment_capture']);

        // The observer ran and failed - containment is about what happens to
        // the failure, not about the observer being skipped.
        $this->assertSame(['vendor_analytics_ping', 'payment_capture'], $this->ran);
        $this->assertSame(['vendor_analytics_ping'], $this->observationsWith(ObservationOutcome::Contained));
        $this->assertSame(
            'the analytics API is down',
            $this->journal->getObservations()[0]->getFailure(),
            'A contained failure is still a recorded failure - silence here would be worse than the crash.'
        );
    }

    /**
     * Containment is something a person asks for.
     */
    public function testAnUnclassifiedObserverThatThrowsStillStopsTheOrder(): void
    {
        $this->failures = ['inventory_reservation' => 'the stock service is down'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the stock service is down');

        $this->placeOrder(['inventory_reservation', 'payment_capture']);
    }

    /**
     * The three shedding conditions are ANDed rather than ORed.
     */
    public function testOverBudgetTheAdvisoryObserverIsShedAndTheCriticalOneIsNot(): void
    {
        $this->settings[self::SECTION . '/enforcement/shedding_enabled'] = '1';
        $this->settings[self::SECTION . '/enforcement/advisory_observers'] = 'vendor_marketing_sync';
        $this->settings[self::SECTION . '/enforcement/critical_observers'] = 'payment_capture';

        // The first observer alone blows the four-second trip budget.
        $this->durations = ['slow_first_observer' => 5000];

        $this->placeOrder(['slow_first_observer', 'vendor_marketing_sync', 'payment_capture']);

        $this->assertSame(['slow_first_observer', 'payment_capture'], $this->ran);
        $this->assertSame(['vendor_marketing_sync'], $this->observationsWith(ObservationOutcome::Shed));
    }

    /**
     * Measurement and enforcement are separate flags on purpose: you watch
     * first, read a report, and only then decide what may be dropped.
     */
    public function testBlowingTheBudgetWithSheddingOffChangesNothing(): void
    {
        $this->settings[self::SECTION . '/enforcement/advisory_observers'] = 'vendor_marketing_sync';
        $this->durations = ['slow_first_observer' => 5000];

        $this->placeOrder(['slow_first_observer', 'vendor_marketing_sync', 'payment_capture']);

        $this->assertSame(
            ['slow_first_observer', 'vendor_marketing_sync', 'payment_capture'],
            $this->ran
        );
    }

    /**
     * Critical is read before advisory, so a name typed into both lists during
     * an incident cannot make this module do more - only less.
     */
    public function testTheCriticalListBeatsTheAdvisoryListRatherThanRacingIt(): void
    {
        $this->settings[self::SECTION . '/enforcement/shedding_enabled'] = '1';
        $this->settings[self::SECTION . '/enforcement/advisory_observers'] = 'payment_capture';
        $this->settings[self::SECTION . '/enforcement/critical_observers'] = 'payment_capture';
        $this->failures = ['payment_capture' => 'the gateway timed out'];
        $this->durations = ['slow_first_observer' => 5000];

        $this->expectException(RuntimeException::class);

        $this->placeOrder(['slow_first_observer', 'payment_capture']);
    }

    /**
     * One line afterwards says where the time went and how many observers ran.
     */
    public function testTheRequestEndsWithOneReadableSummary(): void
    {
        $this->settings[self::SECTION . '/enforcement/advisory_observers'] = 'vendor_analytics_ping';
        $this->durations = ['vendor_analytics_ping' => 400, 'payment_capture' => 120];
        $this->failures = ['vendor_analytics_ping' => 'the analytics API is down'];

        $this->placeOrder(['vendor_analytics_ping', 'payment_capture']);

        $report = $this->journal->getReport();

        $this->assertSame([self::PROCESS], $report->getProcesses());
        $this->assertSame(2, $report->getCalls(self::PROCESS));
        $this->assertSame(520.0, $report->getElapsedMilliseconds(self::PROCESS));
        $this->assertSame(1, $report->getOutcomeCount(self::PROCESS, ObservationOutcome::Contained));
    }

    /**
     * Dispatch one event to a list of observers, the way Magento does.
     *
     * @param string[] $observers Observer names, in `events.xml` order.
     */
    private function placeOrder(array $observers): void
    {
        $scopeConfig = new CountingScopeConfig($this->settings);
        $config = new Config($scopeConfig, self::SECTION);
        $clock = new FakeClock();

        $recorder = new ObservationRecorder($this->journal, $this->createMock(ProcessReporterInterface::class));
        $guard = new ProcessGuard(
            $clock,
            $recorder,
            $config,
            [self::PROCESS => new Budget(warnMilliseconds: 1000, tripMilliseconds: 4000)]
        );

        $invoker = new GuardedInvoker(
            $config,
            new ObserverPolicyResolver($config, [self::EVENT]),
            $guard,
            $recorder,
            $clock,
            250
        );

        // The clock hands out one duration per timed block, in order, so the
        // scripted times have to be queued in the order the observers run.
        $clock->willTake(array_map(fn (string $name): int => $this->durations[$name] ?? 10, $observers));

        $subject = $this->createMock(InvokerDefault::class);
        $observer = new Observer();
        $observer->setEvent(new Event(['name' => self::EVENT]));

        foreach ($observers as $name) {
            $invoker->aroundDispatch(
                $subject,
                function () use ($name) {
                    $this->ran[] = $name;

                    if (isset($this->failures[$name])) {
                        throw new RuntimeException($this->failures[$name]);
                    }

                    return null;
                },
                ['name' => $name, 'instance' => $this->classFor($name)],
                $observer
            );
        }
    }

    /**
     * @return string[] Observer labels recorded with the given outcome.
     */
    private function observationsWith(ObservationOutcome $outcome): array
    {
        $labels = [];

        foreach ($this->journal->getObservations() as $observation) {
            if ($observation->getOutcome() === $outcome) {
                $labels[] = $observation->getLabel();
            }
        }

        return $labels;
    }

    private function classFor(string $observerName): string
    {
        return match ($observerName) {
            'vendor_analytics_ping' => 'Vendor\Analytics\Observer\OrderPing',
            'payment_capture' => 'Vendor\Payment\Observer\Capture',
            default => 'Vendor\Module\Observer\\' . str_replace('_', '', ucwords($observerName, '_')),
        };
    }
}
