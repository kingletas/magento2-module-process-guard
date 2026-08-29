<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Model;

use Commerce\Foundation\Model\Config\ModuleConfig;

/**
 * Typed access to this module's settings.
 */
class Config extends ModuleConfig
{
    /**
     * Measure and report.
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('general/enabled', $storeId);
    }

    /**
     * Whether an over-budget process may skip its advisory observers.
     */
    public function isSheddingEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('enforcement/shedding_enabled', $storeId);
    }

    /**
     * Observers switched off entirely, by their `events.xml` name.
     *
     * @return string[]
     */
    public function getDisabledObservers(?int $storeId = null): array
    {
        return $this->getList('enforcement/disabled_observers', $storeId);
    }

    /**
     * Observers declared non-essential at runtime, in addition to `di.xml`.
     *
     * @return string[]
     */
    public function getAdvisoryObservers(?int $storeId = null): array
    {
        return $this->getList('enforcement/advisory_observers', $storeId);
    }

    /**
     * Observers declared essential at runtime — never contained, never shed.
     *
     * @return string[]
     */
    public function getCriticalObservers(?int $storeId = null): array
    {
        return $this->getList('enforcement/critical_observers', $storeId);
    }

    /**
     * Whether a completed process emits a summary line, or only breaches do.
     */
    public function isSummaryReportingEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('reporting/summaries_enabled', $storeId);
    }
}
