<?php
/**
 * RequestJournal.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Model\Journal;

use Commerce\ProcessGuard\Api\ProcessJournalInterface;
use Commerce\ProcessGuard\Model\Report\ProcessReport;

/**
 * Aggregates always, keeps detail up to a bound.
 *
 * @see ProcessJournalInterface for why the bound applies to detail and not to
 *      counts.
 */
class RequestJournal implements ProcessJournalInterface
{
    /** @var array<string, array{calls: int, elapsed: int, max: int, outcomes: array<string, int>}> */
    private array $processes = [];

    /** @var Observation[] */
    private array $observations = [];

    private int $droppedDetail = 0;

    private int $detailLimit;

    /**
     * @param int $detailLimit How many individual observations to keep. A
     *                         request dispatches thousands of observers; a
     *                         consumer running for an hour dispatches
     *                         millions, and journalling all of them is how a
     *                         monitoring tool becomes the outage.
     */
    public function __construct(int $detailLimit = 500)
    {
        $this->detailLimit = max(1, $detailLimit);
    }

    /**
     * @inheritDoc
     */
    public function record(Observation $observation): void
    {
        $process = $observation->getProcess();
        $elapsed = $observation->getElapsedNanoseconds();

        $bucket = $this->processes[$process] ?? ['calls' => 0, 'elapsed' => 0, 'max' => 0, 'outcomes' => []];

        if ($observation->getOutcome()->ran()) {
            $bucket['calls']++;
            $bucket['elapsed'] += $elapsed;
            $bucket['max'] = max($bucket['max'], $elapsed);
        }

        $outcome = $observation->getOutcome()->value;
        $bucket['outcomes'][$outcome] = ($bucket['outcomes'][$outcome] ?? 0) + 1;

        $this->processes[$process] = $bucket;

        if (count($this->observations) >= $this->detailLimit) {
            // Detail stops, counting does not.
            if ($observation->getOutcome()->isNoteworthy() && $this->dropOneUninteresting()) {
                $this->observations[] = $observation;

                return;
            }

            $this->droppedDetail++;

            return;
        }

        $this->observations[] = $observation;
    }

    /**
     * @inheritDoc
     */
    public function getReport(): ProcessReport
    {
        return new ProcessReport($this->processes, $this->droppedDetail);
    }

    /**
     * @inheritDoc
     */
    public function getObservations(): array
    {
        return $this->observations;
    }

    /**
     * @inheritDoc
     */
    public function clear(): void
    {
        $this->processes = [];
        $this->observations = [];
        $this->droppedDetail = 0;
    }

    private function dropOneUninteresting(): bool
    {
        foreach ($this->observations as $index => $observation) {
            if (!$observation->getOutcome()->isNoteworthy()) {
                unset($this->observations[$index]);

                $this->observations = array_values($this->observations);
                $this->droppedDetail++;

                return true;
            }
        }

        return false;
    }
}
