<?php
/**
 * ShowPoliciesCommandTest.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Console\Command;

use Commerce\ProcessGuard\Api\ObserverPolicy;
use Commerce\ProcessGuard\Api\ObserverPolicyResolverInterface;
use Commerce\ProcessGuard\Console\Command\ShowPoliciesCommand;
use Commerce\ProcessGuard\Model\Config;
use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\Event\Config\Data as EventConfigData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ShowPoliciesCommandTest extends TestCase
{
    private const EVENT = 'sales_order_place_after';

    private Config&MockObject $config;
    private EventConfigData&MockObject $eventConfig;
    private ScopeInterface&MockObject $configScope;
    private ObserverPolicyResolverInterface&MockObject $policyResolver;

    /** @var string[] Scopes the event configuration was asked for. */
    private array $scopes = [];

    protected function setUp(): void
    {
        $this->scopes = [];

        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isSheddingEnabled')->willReturn(false);

        $this->configScope = $this->createMock(ScopeInterface::class);
        $this->configScope->method('setCurrentScope')
            ->willReturnCallback(function (string $scope): void {
                $this->scopes[] = $scope;
            });

        $this->eventConfig = $this->createMock(EventConfigData::class);
        $this->eventConfig->method('get')->willReturn([
            'analytics_ping' => [
                'name' => 'analytics_ping',
                'instance' => 'Vendor\Analytics\Observer\OrderPing',
            ],
            'inventory_reservation' => [
                'name' => 'inventory_reservation',
                'instance' => 'Vendor\Inventory\Observer\Reserve',
            ],
        ]);

        $this->policyResolver = $this->createMock(ObserverPolicyResolverInterface::class);
        $this->policyResolver->method('getGuardedEvents')->willReturn([self::EVENT]);
        $this->policyResolver->method('resolve')
            ->willReturnCallback(static fn (string $event, string $name): ObserverPolicy => $name === 'analytics_ping'
                ? ObserverPolicy::Advisory
                : ObserverPolicy::Critical);
    }

    /**
     * There is no way in Magento to ask which observers are on an event.
     */
    public function testItListsEveryObserverOnEveryGuardedEventWithItsPolicy(): void
    {
        $tester = $this->runCommand();
        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(self::EVENT, $output);
        self::assertStringContainsString('analytics_ping', $output);
        self::assertStringContainsString('Vendor\Inventory\Observer\Reserve', $output);
        self::assertStringContainsString('contain failures', $output);
        self::assertStringContainsString('never skip or contain', $output);
        self::assertStringContainsString('2 observer(s)', $output);
    }

    /**
     * A CLI process is in the global area, so a storefront event's observers
     * are simply absent from its configuration.
     */
    public function testTheAreaIsAskedForRatherThanAssumed(): void
    {
        $this->runCommand(['--area' => 'frontend']);

        self::assertSame(['frontend'], $this->scopes);
    }

    public function testAnUnknownAreaIsRefusedWithTheListOfRealOnes(): void
    {
        $tester = $this->runCommand(['--area' => 'storefront']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('adminhtml', $tester->getDisplay());
    }

    public function testTheDefaultAreaIsGlobal(): void
    {
        $this->runCommand();

        self::assertSame(['global'], $this->scopes);
    }

    /**
     * A policy table printed by a switched-off module describes only what would
     * happen.
     */
    public function testItSaysWhenTheGuardIsSwitchedOff(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);
        $config->method('isSheddingEnabled')->willReturn(false);

        $tester = $this->runCommand(config: $config);

        self::assertStringContainsString('switched off', $tester->getDisplay());
    }

    public function testItReportsWhenNothingIsGuarded(): void
    {
        $resolver = $this->createMock(ObserverPolicyResolverInterface::class);
        $resolver->method('getGuardedEvents')->willReturn([]);

        $tester = $this->runCommand(resolver: $resolver);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No events are guarded', $tester->getDisplay());
        self::assertSame([], $this->scopes, 'Nothing to list means nothing to read.');
    }

    public function testAnEventWithNoObserversIsStillShown(): void
    {
        $eventConfig = $this->createMock(EventConfigData::class);
        $eventConfig->method('get')->willReturn([]);

        $tester = $this->runCommand(eventConfig: $eventConfig);

        self::assertStringContainsString('0 observer(s)', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $input
     */
    private function runCommand(
        array $input = [],
        ?Config $config = null,
        ?ObserverPolicyResolverInterface $resolver = null,
        ?EventConfigData $eventConfig = null
    ): CommandTester {
        $command = new ShowPoliciesCommand(
            $resolver ?? $this->policyResolver,
            $eventConfig ?? $this->eventConfig,
            $this->configScope,
            $config ?? $this->config
        );

        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }
}
