<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Api;

use Commerce\ProcessGuard\Model\Journal\Observation;
use Commerce\ProcessGuard\Model\Report\ProcessReport;

/**
 * Where breaches and summaries go.
 */
interface ProcessReporterInterface
{
    public function reportObservation(Observation $observation): void;

    /**
     * A named process finished.
     */
    public function reportProcess(ProcessReport $report, string $process): void;
}
