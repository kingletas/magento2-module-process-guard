<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Model;

use Commerce\ProcessGuard\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

/**
 * The settings an operator reaches for mid-incident.
 */
class ConfigTest extends TestCase
{
    private const SECTION = 'commerce_processguard';

    public function testEveryFlagIsOffWhenNothingIsConfigured(): void
    {
        $config = $this->config([]);

        $this->assertFalse($config->isEnabled());
        $this->assertFalse($config->isSheddingEnabled());
        $this->assertFalse($config->isSummaryReportingEnabled());
    }

    public function testEveryObserverListIsEmptyWhenNothingIsConfigured(): void
    {
        $config = $this->config([]);

        $this->assertSame([], $config->getDisabledObservers());
        $this->assertSame([], $config->getAdvisoryObservers());
        $this->assertSame([], $config->getCriticalObservers());
    }

    /**
     * Measurement on is not enforcement on.
     */
    public function testMeasurementAndSheddingAreIndependentFlags(): void
    {
        $config = $this->config([self::SECTION . '/general/enabled' => '1']);

        $this->assertTrue($config->isEnabled());
        $this->assertFalse($config->isSheddingEnabled(), 'Enabling measurement must not enable shedding.');
    }

    public function testSheddingReadsItsOwnFlag(): void
    {
        $config = $this->config([self::SECTION . '/enforcement/shedding_enabled' => '1']);

        $this->assertTrue($config->isSheddingEnabled());
    }

    public function testSummaryReportingReadsItsOwnFlag(): void
    {
        $config = $this->config([self::SECTION . '/reporting/summaries_enabled' => '1']);

        $this->assertTrue($config->isSummaryReportingEnabled());
    }

    /**
     * The config paths, asserted literally.
     */
    public function testTheConfigPathsAreTheOnesInTheRunbook(): void
    {
        $config = $this->config([
            self::SECTION . '/general/enabled' => '1',
            self::SECTION . '/enforcement/shedding_enabled' => '1',
            self::SECTION . '/enforcement/disabled_observers' => 'a',
            self::SECTION . '/enforcement/advisory_observers' => 'b',
            self::SECTION . '/enforcement/critical_observers' => 'c',
            self::SECTION . '/reporting/summaries_enabled' => '1',
        ]);

        $this->assertTrue($config->isEnabled());
        $this->assertTrue($config->isSheddingEnabled());
        $this->assertSame(['a'], $config->getDisabledObservers());
        $this->assertSame(['b'], $config->getAdvisoryObservers());
        $this->assertSame(['c'], $config->getCriticalObservers());
        $this->assertTrue($config->isSummaryReportingEnabled());
    }

    /**
     * The observer lists are typed into a text field during an incident, so
     * spaces round the commas are the normal case rather than the exception.
     */
    public function testAnObserverListIsSplitAndTrimmed(): void
    {
        $config = $this->config([
            self::SECTION . '/enforcement/disabled_observers' => 'slow_observer , another_one,  third ',
        ]);

        $this->assertSame(['slow_observer', 'another_one', 'third'], $config->getDisabledObservers());
    }

    public function testEmptyEntriesInAnObserverListAreDropped(): void
    {
        $config = $this->config([
            self::SECTION . '/enforcement/disabled_observers' => 'one,,  ,two,',
        ]);

        $this->assertSame(['one', 'two'], $config->getDisabledObservers());
    }

    /**
     * A store scope has to be honoured: a switch flipped on one storefront must
     * not silently apply to the rest of the estate.
     */
    public function testValuesAreReadInTheStoreScopeTheyWereAskedFor(): void
    {
        $scopes = [];
        $config = new Config($this->scopeConfig([], $scopes), self::SECTION);

        $config->isEnabled(7);

        $this->assertSame([ScopeInterface::SCOPE_STORE, 7], $scopes[0]);
    }

    public function testTheSectionIsAConstructorArgumentSoTheModuleCanBeRebranded(): void
    {
        $this->assertSame('acme_processguard', (new Config($this->scopeConfig([]), 'acme_processguard'))->getSection());
    }

    /**
     * @param array<string, string> $values
     */
    private function config(array $values): Config
    {
        return new Config($this->scopeConfig($values), self::SECTION);
    }

    /**
     * @param array<string, string>          $values
     * @param array<int, array{string, ?int}> $scopes
     */
    private function scopeConfig(array $values, array &$scopes = []): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);

        $scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scopeType = 'default', $scopeCode = null) use ($values, &$scopes) {
                $scopes[] = [$scopeType, $scopeCode];

                return $values[$path] ?? null;
            }
        );

        $scopeConfig->method('isSetFlag')->willReturnCallback(
            function (string $path, string $scopeType = 'default', $scopeCode = null) use ($values, &$scopes): bool {
                $scopes[] = [$scopeType, $scopeCode];

                return (bool) ($values[$path] ?? false);
            }
        );

        return $scopeConfig;
    }
}
