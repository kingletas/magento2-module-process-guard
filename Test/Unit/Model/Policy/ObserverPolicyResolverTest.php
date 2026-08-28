<?php
/**
 * ObserverPolicyResolverTest.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model\Policy;

use Commerce\ProcessGuard\Api\ObserverPolicy;
use Commerce\ProcessGuard\Model\Config;
use Commerce\ProcessGuard\Model\Policy\ObserverPolicyResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ObserverPolicyResolverTest extends TestCase
{
    private const EVENT = 'sales_order_place_after';
    private const OBSERVER = 'vendor_analytics_order_ping';
    private const CLASS_NAME = 'Vendor\Analytics\Observer\OrderPing';

    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getDisabledObservers')->willReturn([]);
        $this->config->method('getAdvisoryObservers')->willReturn([]);
        $this->config->method('getCriticalObservers')->willReturn([]);
    }

    /**
     * Nothing is contained or skipped because a heuristic thought an observer
     * looked unimportant.
     */
    public function testAnythingUnclassifiedIsOnlyMeasured(): void
    {
        $resolver = $this->resolver();

        self::assertSame(
            ObserverPolicy::Measured,
            $resolver->resolve(self::EVENT, self::OBSERVER, self::CLASS_NAME)
        );
    }

    public function testTheDeclaredClassificationIsUsed(): void
    {
        $resolver = $this->resolver(classifications: [self::OBSERVER => 'advisory']);

        self::assertSame(
            ObserverPolicy::Advisory,
            $resolver->resolve(self::EVENT, self::OBSERVER, self::CLASS_NAME)
        );
    }

    public function testAnObserverCanBeClassifiedByItsClass(): void
    {
        $resolver = $this->resolver(classifications: [self::CLASS_NAME => 'critical']);

        self::assertSame(
            ObserverPolicy::Critical,
            $resolver->resolve(self::EVENT, self::OBSERVER, self::CLASS_NAME)
        );
    }

    /**
     * The kill list is what somebody reaches for at two in the morning, and it
     * has to beat whatever the code says.
     */
    public function testTheRuntimeKillListBeatsEverythingDeclared(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getDisabledObservers')->willReturn([self::OBSERVER]);
        $this->config->method('getAdvisoryObservers')->willReturn([]);
        $this->config->method('getCriticalObservers')->willReturn([]);

        $resolver = $this->resolver(classifications: [self::OBSERVER => 'critical']);

        self::assertSame(
            ObserverPolicy::Disabled,
            $resolver->resolve(self::EVENT, self::OBSERVER, self::CLASS_NAME)
        );
    }

    /**
     * Whoever is reading a stack trace has the class; whoever is reading
     * events.xml has the name.
     */
    public function testTheKillListAcceptsEitherIdentifier(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getDisabledObservers')->willReturn([' ' . self::CLASS_NAME . ' ']);
        $this->config->method('getAdvisoryObservers')->willReturn([]);
        $this->config->method('getCriticalObservers')->willReturn([]);

        self::assertSame(
            ObserverPolicy::Disabled,
            $this->resolver()->resolve(self::EVENT, self::OBSERVER, self::CLASS_NAME)
        );
    }

    /**
     * The critical list is read before the advisory one, so naming a class can
     * only do less.
     */
    public function testCriticalBeatsAdvisory(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getDisabledObservers')->willReturn([]);
        $this->config->method('getCriticalObservers')->willReturn([self::OBSERVER]);
        $this->config->method('getAdvisoryObservers')->willReturn([self::OBSERVER]);

        self::assertSame(
            ObserverPolicy::Critical,
            $this->resolver(classifications: [self::OBSERVER => 'advisory'])
                ->resolve(self::EVENT, self::OBSERVER, self::CLASS_NAME)
        );
    }

    public function testRuntimeClassificationBeatsDeclaredClassification(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getDisabledObservers')->willReturn([]);
        $this->config->method('getCriticalObservers')->willReturn([]);
        $this->config->method('getAdvisoryObservers')->willReturn([self::OBSERVER]);

        self::assertSame(
            ObserverPolicy::Advisory,
            $this->resolver()->resolve(self::EVENT, self::OBSERVER, self::CLASS_NAME)
        );
    }

    /**
     * A typo in di.xml that silently turned an observer advisory would be a way
     * to lose an order to a spelling mistake.
     */
    public function testAnUnreadablePolicyIsIgnoredRatherThanGuessedAt(): void
    {
        $resolver = $this->resolver(classifications: [self::OBSERVER => 'advisery']);

        self::assertSame(
            ObserverPolicy::Measured,
            $resolver->resolve(self::EVENT, self::OBSERVER, self::CLASS_NAME)
        );
    }

    public function testAnUnnamedObserverIsOnlyMeasured(): void
    {
        $resolver = $this->resolver(classifications: ['' => 'disabled']);

        self::assertSame(ObserverPolicy::Measured, $resolver->resolve(self::EVENT, '', ''));
    }

    public function testOnlyDeclaredEventsAreGuarded(): void
    {
        $resolver = $this->resolver(events: ['sales_order_place_after']);

        self::assertTrue($resolver->isGuardedEvent('sales_order_place_after'));
        self::assertFalse($resolver->isGuardedEvent('controller_action_predispatch'));
    }

    /**
     * Magento lower-cases event names on dispatch; a guarded list that did not
     * would be a list that silently guards nothing.
     */
    public function testEventMatchingIgnoresCaseAndSurroundingSpace(): void
    {
        $resolver = $this->resolver(events: [' Sales_Order_Place_After ']);

        self::assertTrue($resolver->isGuardedEvent('sales_order_place_after'));
        self::assertSame(['sales_order_place_after'], $resolver->getGuardedEvents());
    }

    public function testNoGuardedEventsMeansNothingIsGuarded(): void
    {
        $resolver = $this->resolver(events: []);

        self::assertFalse($resolver->isGuardedEvent(self::EVENT));
        self::assertSame([], $resolver->getGuardedEvents());
    }

    /**
     * @param string[]              $events
     * @param array<string, string> $classifications
     */
    private function resolver(array $events = [self::EVENT], array $classifications = []): ObserverPolicyResolver
    {
        return new ObserverPolicyResolver($this->config, $events, $classifications);
    }
}
