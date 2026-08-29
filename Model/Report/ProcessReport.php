<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Model\Report;

use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;

/**
 * What every named process cost, aggregated.
 */
class ProcessReport
{
    /**
     * @param array<string, array{calls: int, elapsed: int, max: int, outcomes: array<string, int>}> $processes
     * @param int $droppedDetail Observations whose detail was not kept because
     *                           the journal was full. Counts still include them.
     */
    public function __construct(
        private readonly array $processes = [],
        private readonly int $droppedDetail = 0
    ) {
    }

    /**
     * @return string[] Process names, most expensive first.
     */
    public function getProcesses(): array
    {
        $processes = $this->processes;

        uasort($processes, static fn (array $a, array $b): int => $b['elapsed'] <=> $a['elapsed']);

        return array_keys($processes);
    }

    public function getCalls(string $process): int
    {
        return $this->processes[$process]['calls'] ?? 0;
    }

    public function getElapsedNanoseconds(string $process): int
    {
        return $this->processes[$process]['elapsed'] ?? 0;
    }

    public function getElapsedMilliseconds(string $process): float
    {
        return $this->getElapsedNanoseconds($process) / 1_000_000;
    }

    public function getSlowestNanoseconds(string $process): int
    {
        return $this->processes[$process]['max'] ?? 0;
    }

    public function getOutcomeCount(string $process, ObservationOutcome $outcome): int
    {
        return $this->processes[$process]['outcomes'][$outcome->value] ?? 0;
    }

    public function getDroppedDetail(): int
    {
        return $this->droppedDetail;
    }

    public function isEmpty(): bool
    {
        return $this->processes === [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $rows = [];

        foreach ($this->getProcesses() as $process) {
            $rows[$process] = [
                'calls' => $this->getCalls($process),
                'ms' => round($this->getElapsedMilliseconds($process), 2),
                'slowest_ms' => round($this->getSlowestNanoseconds($process) / 1_000_000, 2),
                'outcomes' => array_filter($this->processes[$process]['outcomes'] ?? []),
            ];
        }

        if ($this->droppedDetail > 0) {
            // Said out loud rather than left to be inferred: the counts above
            // are complete, the per-observation detail is not.
            $rows['_truncated'] = ['observations_without_detail' => $this->droppedDetail];
        }

        return $rows;
    }

    /**
     * @return string[] One line per process, most expensive first.
     */
    public function getLines(): array
    {
        $lines = [];

        foreach ($this->getProcesses() as $process) {
            $notable = array_filter($this->processes[$process]['outcomes'] ?? []);

            unset($notable[ObservationOutcome::Completed->value]);

            $lines[] = sprintf(
                '%s: %s call(s), %sms total, slowest %sms%s',
                $process,
                $this->getCalls($process),
                round($this->getElapsedMilliseconds($process), 2),
                round($this->getSlowestNanoseconds($process) / 1_000_000, 2),
                $notable === [] ? '' : ' — ' . $this->describe($notable)
            );
        }

        return $lines;
    }

    /**
     * @param array<string, int> $outcomes
     */
    private function describe(array $outcomes): string
    {
        $parts = [];

        foreach ($outcomes as $outcome => $count) {
            $parts[] = $count . ' ' . str_replace('_', ' ', $outcome);
        }

        return implode(', ', $parts);
    }
}
