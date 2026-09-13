<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Test\Unit\Console\Command;

use Kingletas\ProcessGuard\Api\ObserverPolicyResolverInterface;
use Kingletas\ProcessGuard\Console\Command\CheckConfigurationCommand;
use Kingletas\ProcessGuard\Model\Config;
use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\Event\Config\Data as EventConfigData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CheckConfigurationCommandTest extends TestCase
{
    private const EVENT = 'sales_order_place_after';
    private const REACHABLE_NAME = 'analytics_ping';
    private const REACHABLE_CLASS = 'Vendor\\Analytics\\Observer\\OrderPing';

    private Config&MockObject $config;
    private ScopeInterface&MockObject $configScope;

    /** @var string[] */
    private array $scopes = [];

    protected function setUp(): void
    {
        $this->scopes = [];
        $this->config = $this->createMock(Config::class);

        $this->configScope = $this->createMock(ScopeInterface::class);
        $this->configScope->method('getCurrentScope')->willReturn('global');
        $this->configScope->method('setCurrentScope')
            ->willReturnCallback(function (string $scope): void {
                $this->scopes[] = $scope;
            });
    }

    public function testItSaysNothingWhenEveryClassifiedObserverIsWatched(): void
    {
        $this->config->method('getAdvisoryObservers')->willReturn([self::REACHABLE_CLASS]);
        $this->config->method('getCriticalObservers')->willReturn([self::REACHABLE_NAME]);
        $this->config->method('getDisabledObservers')->willReturn([]);

        $tester = $this->check();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('', trim($tester->getDisplay()));
    }

    /**
     * The failure this command exists for: a class pasted from a stack trace
     * that is on an event the guard does not watch.
     */
    public function testItFailsAndNamesAnObserverTheGuardCannotReach(): void
    {
        $this->config->method('getDisabledObservers')->willReturn(['Magento\\Csp\\Observer\\Render']);
        $this->config->method('getAdvisoryObservers')->willReturn([]);
        $this->config->method('getCriticalObservers')->willReturn([]);

        $tester = $this->check();

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Magento\\Csp\\Observer\\Render', $tester->getDisplay());
        $this->assertStringContainsString('disabled_observers', $tester->getDisplay());
    }

    public function testItListsTheEventsThatAreWatched(): void
    {
        $this->config->method('getDisabledObservers')->willReturn(['nobody']);
        $this->config->method('getAdvisoryObservers')->willReturn([]);
        $this->config->method('getCriticalObservers')->willReturn([]);

        $this->assertStringContainsString(self::EVENT, $this->check()->getDisplay());
    }

    public function testEitherIdentifierCounts(): void
    {
        $this->config->method('getDisabledObservers')->willReturn([' ' . self::REACHABLE_NAME . ' ']);
        $this->config->method('getAdvisoryObservers')->willReturn([strtoupper(self::REACHABLE_CLASS)]);
        $this->config->method('getCriticalObservers')->willReturn([]);

        $this->assertSame(Command::SUCCESS, $this->check()->getStatusCode());
    }

    public function testGuardingNothingIsItselfAFailure(): void
    {
        $this->config->method('getDisabledObservers')->willReturn([]);
        $this->config->method('getAdvisoryObservers')->willReturn([]);
        $this->config->method('getCriticalObservers')->willReturn([]);

        $tester = $this->check([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No events are guarded', $tester->getDisplay());
    }

    /**
     * An observer can be declared in any area, so every area is read.
     */
    public function testEveryAreaIsSearched(): void
    {
        $this->config->method('getDisabledObservers')->willReturn([]);
        $this->config->method('getAdvisoryObservers')->willReturn([]);
        $this->config->method('getCriticalObservers')->willReturn([]);

        $this->check();

        $this->assertContains('frontend', $this->scopes);
        $this->assertContains('adminhtml', $this->scopes);
        $this->assertContains('crontab', $this->scopes);
    }

    /**
     * The scope is global machinery: leaving it somewhere else would change
     * what every later command reads.
     */
    public function testTheScopeIsPutBackEvenWhenReadingThrows(): void
    {
        $this->config->method('getDisabledObservers')->willReturn([]);
        $this->config->method('getAdvisoryObservers')->willReturn([]);
        $this->config->method('getCriticalObservers')->willReturn([]);

        $eventConfig = $this->createMock(EventConfigData::class);
        $eventConfig->method('get')->willThrowException(new RuntimeException('unreadable'));

        try {
            $this->check(null, $eventConfig);
        } catch (RuntimeException) {
            $this->assertSame('global', end($this->scopes));

            return;
        }

        $this->fail('The read failure should have reached the caller.');
    }

    /**
     * @param string[]|null $events
     */
    private function check(?array $events = null, ?EventConfigData $eventConfig = null): CommandTester
    {
        $policyResolver = $this->createMock(ObserverPolicyResolverInterface::class);
        $policyResolver->method('getGuardedEvents')->willReturn($events ?? [self::EVENT]);

        $tester = new CommandTester(new CheckConfigurationCommand(
            $policyResolver,
            $eventConfig ?? $this->eventConfig(),
            $this->configScope,
            $this->config,
            'kingletas:process-guard:check'
        ));

        $tester->execute([]);

        return $tester;
    }

    private function eventConfig(): EventConfigData&MockObject
    {
        $eventConfig = $this->createMock(EventConfigData::class);
        $eventConfig->method('get')->willReturn([
            self::REACHABLE_NAME => [
                'name' => self::REACHABLE_NAME,
                'instance' => self::REACHABLE_CLASS,
            ],
        ]);

        return $eventConfig;
    }
}
