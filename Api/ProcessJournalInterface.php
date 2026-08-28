<?php
/**
 * ProcessJournalInterface.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Api;

use Commerce\ProcessGuard\Model\Journal\Observation;
use Commerce\ProcessGuard\Model\Report\ProcessReport;

/**
 * Where observations accumulate for the length of one request.
 */
interface ProcessJournalInterface
{
    public function record(Observation $observation): void;

    public function getReport(): ProcessReport;

    /**
     * @return Observation[] The detail kept, oldest first.
     */
    public function getObservations(): array;

    public function clear(): void;
}
