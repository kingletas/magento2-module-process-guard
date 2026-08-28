<?php
/**
 * ObservationRecorder.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Model\Journal;

use Commerce\ProcessGuard\Api\ProcessJournalInterface;
use Commerce\ProcessGuard\Api\ProcessReporterInterface;
use Commerce\ProcessGuard\Model\Report\ProcessReport;

/**
 * Write it down, and say something if it matters - the rule the guard and the
 * event gate share.
 */
class ObservationRecorder
{
    public function __construct(
        private readonly ProcessJournalInterface $journal,
        private readonly ProcessReporterInterface $reporter
    ) {
    }

    public function record(Observation $observation): void
    {
        $this->journal->record($observation);

        if ($observation->getOutcome()->isNoteworthy()) {
            $this->reporter->reportObservation($observation);
        }
    }

    public function summarise(string $process): void
    {
        $this->reporter->reportProcess($this->journal->getReport(), $process);
    }

    public function getReport(): ProcessReport
    {
        return $this->journal->getReport();
    }
}
