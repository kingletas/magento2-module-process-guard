<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * What this module does to a store that installs it and changes nothing.
 */
class ShippedDefaultsTest extends TestCase
{
    /**
     * The one that matters.
     */
    public function testSheddingIsOffOutOfTheBox(): void
    {
        $this->assertSame('0', $this->default('enforcement/shedding_enabled'));
    }

    public function testNoObserverIsClassifiedOutOfTheBox(): void
    {
        $this->assertSame('', $this->default('enforcement/disabled_observers'));
        $this->assertSame('', $this->default('enforcement/advisory_observers'));
        $this->assertSame('', $this->default('enforcement/critical_observers'));
    }

    /**
     * On, and safe to be on: with nothing classified, every observer runs and
     * throws as before.
     */
    public function testMeasurementIsOnOutOfTheBox(): void
    {
        $this->assertSame('1', $this->default('general/enabled'));
    }

    /**
     * Off, because on a busy storefront the per-process summaries are the bulk
     * of the log volume.
     */
    public function testSummaryReportingIsOffOutOfTheBox(): void
    {
        $this->assertSame('0', $this->default('reporting/summaries_enabled'));
    }

    /**
     * The section id is what every config path hangs off, and `bin/rebrand`
     * rewrites it.
     */
    public function testTheSectionIdMatchesTheOneDiXmlConfigures(): void
    {
        $section = $this->config()->default->children()[0]->getName();

        $di = simplexml_load_file(dirname(__DIR__, 2) . '/etc/di.xml');
        $this->assertInstanceOf(SimpleXMLElement::class, $di, 'etc/di.xml did not parse.');

        $configured = null;

        foreach ($di->type as $type) {
            foreach ($type->arguments->argument ?? [] as $argument) {
                if ((string) $argument['name'] === 'section') {
                    $configured = trim((string) $argument);
                }
            }
        }

        $this->assertNotNull($configured, 'No <argument name="section"> found in etc/di.xml.');
        $this->assertSame($configured, $section);
    }

    private function default(string $path): string
    {
        [$group, $field] = explode('/', $path);
        $section = $this->config()->default->children()[0];

        $this->assertTrue(isset($section->{$group}->{$field}), sprintf('%s is not in etc/config.xml.', $path));

        return trim((string) $section->{$group}->{$field});
    }

    private function config(): SimpleXMLElement
    {
        $config = simplexml_load_file(dirname(__DIR__, 2) . '/etc/config.xml');

        $this->assertInstanceOf(SimpleXMLElement::class, $config, 'etc/config.xml did not parse.');

        return $config;
    }
}
