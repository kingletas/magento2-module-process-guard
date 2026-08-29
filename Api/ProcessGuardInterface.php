<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Api;

use Commerce\ProcessGuard\Model\Report\ProcessReport;

/**
 * A named process, its budget, and what it actually cost.
 */
interface ProcessGuardInterface
{
    /**
     * Run the callable as a named process and account for what it cost.
     *
     * @param array<string, scalar|null> $context Extra detail for the report —
     *        a quote id, a SKU, a topic name.
     * @return mixed Whatever the callable returns.
     */
    public function run(string $process, callable $work, array $context = []): mixed;

    /**
     * Account for work this guard did not run — an observer timed by the event
     * gate, a batch measured by a consumer plugin.
     *
     * @param int $elapsedNanoseconds Monotonic elapsed time, not wall clock.
     * @param array<string, scalar|null> $context
     */
    public function record(string $process, int $elapsedNanoseconds, array $context = []): void;

    /**
     * Has this process exceeded the budget that allows work to be shed?
     */
    public function isTripped(string $process): bool;

    /**
     * Note that a long-running process is still going, and check it against its
     * ceilings.
     *
     * @param array<string, scalar|null> $context
     */
    public function checkpoint(string $process, array $context = []): void;

    public function getReport(): ProcessReport;
}
