<?php
/**
 * GuardedInvokerTest.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Plugin\Event;

use Commerce\ProcessGuard\Api\ObserverPolicy;
use Commerce\ProcessGuard\Api\ObserverPolicyResolverInterface;
use Commerce\ProcessGuard\Api\ProcessGuardInterface;
use Commerce\ProcessGuard\Api\ProcessReporterInterface;
use Commerce\ProcessGuard\Model\Config;
use Commerce\ProcessGuard\Model\Journal\Observation;
use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use Commerce\ProcessGuard\Model\Journal\ObservationRecorder;
use Commerce\ProcessGuard\Model\Journal\RequestJournal;
use Commerce\ProcessGuard\Plugin\Event\GuardedInvoker;
use Commerce\ProcessGuard\Test\Support\FakeClock;
use Magento\Framework\Event;
use Magento\Framework\Event\Invoker\InvokerDefault;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GuardedInvokerTest extends TestCase
{
    private const EVENT = 'sales_order_place_after';
    private const OBSERVER = 'vendor_analytics_order_ping';
    private const CLASS_NAME = 'Vendor\Analytics\Observer\OrderPing';
    private const PROCESS = 'event.sales_order_place_after';

    private Config&MockObject $config;
    private ObserverPolicyResolverInterface&MockObject $policyResolver;
    private ProcessGuardInterface&MockObject $guard;
    private InvokerDefault&MockObject $subject;
    private RequestJournal $journal;
    private FakeClock $clock;

    private bool $observerRan = false;
    private bool $tripped = false;
    private bool $sheddingEnabled = false;

    /** @var array<int, array{0: string, 1: int}> */
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->observerRan = false;
        $this->tripped = false;
        $this->sheddingEnabled = false;
        $this->recorded = [];

        $this->clock = new FakeClock();
        $this->journal = new RequestJournal();
        $this->subject = $this->createMock(InvokerDefault::class);

        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isSheddingEnabled')->willReturnCallback(fn (): bool => $this->sheddingEnabled);

        $this->policyResolver = $this->createMock(ObserverPolicyResolverInterface::class);
        $this->policyResolver->method('isGuardedEvent')
            ->willReturnCallback(static fn (string $event): bool => $event === self::EVENT);
        $this->policyResolver->method('resolve')->willReturn(ObserverPolicy::Measured);

        $this->guard = $this->createMock(ProcessGuardInterface::class);
        $this->guard->method('isTripped')->willReturnCallback(fn (): bool => $this->tripped);
        $this->guard->method('record')
            ->willReturnCallback(function (string $process, int $nanos): void {
                $this->recorded[] = [$process, $nanos];
            });
    }

    public function testAnObserverOnAGuardedEventStillRuns(): void
    {
        $this->invoke();

        $this->assertTrue($this->observerRan);
    }

    /**
     * This method runs for every observer of every event in every request.
     */
    public function testAnUnguardedEventIsHandedStraightBack(): void
    {
        $this->invoke(event: 'controller_action_predispatch');

        $this->assertTrue($this->observerRan);
        $this->assertSame([], $this->journal->getObservations());
        $this->assertSame([], $this->recorded);
    }

    public function testDisabledMeasurementIsHandedStraightBack(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);

        $this->invoke(config: $config);

        $this->assertTrue($this->observerRan);
        $this->assertSame([], $this->journal->getObservations());
    }

    public function testTheObserverIsTimedIntoTheEventsBudget(): void
    {
        $this->clock->willTake([120]);

        $this->invoke();

        $this->assertSame([[self::PROCESS, 120 * 1_000_000]], $this->recorded);
        $this->assertSame(120.0, $this->journal->getObservations()[0]->getElapsedMilliseconds());
    }

    public function testASlowObserverIsNamed(): void
    {
        $this->clock->willTake([400]);

        $this->invoke(observerWarnMilliseconds: 250);

        $observation = $this->journal->getObservations()[0];

        $this->assertSame(ObservationOutcome::OverBudget, $observation->getOutcome());
        $this->assertSame(self::OBSERVER, $observation->getLabel());
        $this->assertSame(self::CLASS_NAME, $observation->getContext()['class']);
    }

    public function testAnObserverInsideItsOwnBudgetIsJustRecorded(): void
    {
        $this->clock->willTake([100]);

        $this->invoke(observerWarnMilliseconds: 250);

        $this->assertSame(ObservationOutcome::Completed, $this->journal->getObservations()[0]->getOutcome());
    }

    /**
     * The incident switch: a vendor observer out of the path from
     * configuration, without a patch and without a deploy.
     */
    public function testADisabledObserverNeverRuns(): void
    {
        $this->invoke(policy: ObserverPolicy::Disabled);

        $this->assertFalse($this->observerRan);
        $this->assertSame(ObservationOutcome::Disabled, $this->journal->getObservations()[0]->getOutcome());
    }

    /**
     * The whole safety argument: an unclassified observer behaves exactly as it
     * would without this module.
     */
    public function testAMeasuredObserversFailurePropagates(): void
    {
        $this->expectException(RuntimeException::class);

        $this->invoke(failure: new RuntimeException('inventory reservation failed'));
    }

    public function testACriticalObserversFailurePropagates(): void
    {
        $this->expectException(RuntimeException::class);

        $this->invoke(policy: ObserverPolicy::Critical, failure: new RuntimeException('payment capture failed'));
    }

    public function testAMeasuredFailureIsRecordedBeforeItPropagates(): void
    {
        try {
            $this->invoke(failure: new RuntimeException('boom'));
        } catch (RuntimeException) {
            // Expected — the assertion is about what was written down first.
        }

        $observation = $this->journal->getObservations()[0];

        $this->assertSame(ObservationOutcome::Failed, $observation->getOutcome());
        $this->assertSame('boom', $observation->getFailure());
    }

    /**
     * Containment happens only where a person declared the observer
     * non-essential.
     */
    public function testAnAdvisoryObserversFailureIsContained(): void
    {
        $this->invoke(policy: ObserverPolicy::Advisory, failure: new RuntimeException('analytics endpoint down'));

        $observation = $this->journal->getObservations()[0];

        $this->assertSame(ObservationOutcome::Contained, $observation->getOutcome());
        $this->assertSame('analytics endpoint down', $observation->getFailure());
    }

    public function testAContainedFailureIsStillReportedAsAnError(): void
    {
        $reported = [];
        $reporter = $this->createMock(ProcessReporterInterface::class);
        $reporter->method('reportObservation')
            ->willReturnCallback(static function (Observation $observation) use (&$reported): void {
                $reported[] = $observation->getOutcome();
            });

        $this->invoke(
            policy: ObserverPolicy::Advisory,
            failure: new RuntimeException('down'),
            reporter: $reporter
        );

        $this->assertSame([ObservationOutcome::Contained], $reported);
    }

    public function testAdvisoryObserversAreShedOnlyWhenAllThreeConditionsHold(): void
    {
        // Over budget, but shedding is off.
        $this->tripped = true;
        $this->sheddingEnabled = false;

        $this->invoke(policy: ObserverPolicy::Advisory);

        $this->assertTrue($this->observerRan, 'Shedding off means it still runs.');

        // Shedding on, but the path is inside its budget.
        $this->observerRan = false;
        $this->tripped = false;
        $this->sheddingEnabled = true;

        $this->invoke(policy: ObserverPolicy::Advisory);

        $this->assertTrue($this->observerRan, 'Within budget means it still runs.');

        // Both, and the observer is sheddable.
        $this->observerRan = false;
        $this->tripped = true;

        $this->invoke(policy: ObserverPolicy::Advisory);

        $this->assertFalse($this->observerRan);
    }

    public function testAMeasuredObserverIsNeverShed(): void
    {
        $this->tripped = true;
        $this->sheddingEnabled = true;

        $this->invoke(policy: ObserverPolicy::Measured);

        $this->assertTrue($this->observerRan);
    }

    public function testACriticalObserverIsNeverShed(): void
    {
        $this->tripped = true;
        $this->sheddingEnabled = true;

        $this->invoke(policy: ObserverPolicy::Critical);

        $this->assertTrue($this->observerRan);
    }

    public function testAShedObserverIsRecordedAsShed(): void
    {
        $this->tripped = true;
        $this->sheddingEnabled = true;

        $this->invoke(policy: ObserverPolicy::Advisory);

        $this->assertSame(ObservationOutcome::Shed, $this->journal->getObservations()[0]->getOutcome());
    }

    public function testAnObserverWithNoEventIsHandedStraightBack(): void
    {
        $observer = new Observer();

        $this->plugin()->aroundDispatch(
            $this->subject,
            function (): void {
                $this->observerRan = true;
            },
            ['name' => self::OBSERVER, 'instance' => self::CLASS_NAME],
            $observer
        );

        $this->assertTrue($this->observerRan);
        $this->assertSame([], $this->journal->getObservations());
    }

    public function testAnObserverWithNoNameIsLabelledByItsClass(): void
    {
        $this->invoke(configuration: ['instance' => self::CLASS_NAME]);

        $this->assertSame(self::CLASS_NAME, $this->journal->getObservations()[0]->getLabel());
    }

    /**
     * @param array<string, mixed>|null $configuration
     */
    private function invoke(
        string $event = self::EVENT,
        ObserverPolicy $policy = ObserverPolicy::Measured,
        ?RuntimeException $failure = null,
        ?Config $config = null,
        ?ProcessReporterInterface $reporter = null,
        ?array $configuration = null,
        int $observerWarnMilliseconds = 250
    ): void {
        $resolver = $this->createMock(ObserverPolicyResolverInterface::class);
        $resolver->method('isGuardedEvent')
            ->willReturnCallback(static fn (string $name): bool => $name === self::EVENT);
        $resolver->method('resolve')->willReturn($policy);

        $plugin = $this->plugin($config, $resolver, $reporter, $observerWarnMilliseconds);

        $magentoEvent = new Event();
        $magentoEvent->setName($event);

        $observer = new Observer();
        $observer->setEvent($magentoEvent);

        $plugin->aroundDispatch(
            $this->subject,
            function () use ($failure): void {
                $this->observerRan = true;

                if ($failure !== null) {
                    throw $failure;
                }
            },
            $configuration ?? ['name' => self::OBSERVER, 'instance' => self::CLASS_NAME],
            $observer
        );
    }

    private function plugin(
        ?Config $config = null,
        ?ObserverPolicyResolverInterface $resolver = null,
        ?ProcessReporterInterface $reporter = null,
        int $observerWarnMilliseconds = 250
    ): GuardedInvoker {
        return new GuardedInvoker(
            $config ?? $this->config,
            $resolver ?? $this->policyResolver,
            $this->guard,
            new ObservationRecorder(
                $this->journal,
                $reporter ?? $this->createMock(ProcessReporterInterface::class)
            ),
            $this->clock,
            $observerWarnMilliseconds
        );
    }
}
