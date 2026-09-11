<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Api;

use Kingletas\ProcessGuard\Model\Journal\Observation;
use Kingletas\ProcessGuard\Model\Report\ProcessReport;

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
