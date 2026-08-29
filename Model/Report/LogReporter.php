<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Model\Report;

use Commerce\ProcessGuard\Api\ProcessReporterInterface;
use Commerce\ProcessGuard\Model\Journal\Observation;
use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use Psr\Log\LoggerInterface;

/**
 * The default destination: this module's own log channel.
 */
class LogReporter implements ProcessReporterInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function reportObservation(Observation $observation): void
    {
        $context = $observation->toArray();

        match ($observation->getOutcome()) {
            ObservationOutcome::Failed,
            ObservationOutcome::Contained => $this->logger->error($observation->getMessage(), $context),

            ObservationOutcome::OverBudget,
            ObservationOutcome::Repeated,
            ObservationOutcome::Shed,
            ObservationOutcome::MemoryCeiling => $this->logger->warning($observation->getMessage(), $context),

            ObservationOutcome::Disabled => $this->logger->info($observation->getMessage(), $context),

            // Reported by mistake rather than by rule: recording it as debug
            // keeps the caller's contract honest without adding volume.
            ObservationOutcome::Completed => $this->logger->debug($observation->getMessage(), $context),
        };
    }

    /**
     * @inheritDoc
     */
    public function reportProcess(ProcessReport $report, string $process): void
    {
        if ($report->isEmpty()) {
            return;
        }

        $this->logger->info(
            sprintf('%s finished: %s', $process, implode(' | ', $report->getLines())),
            ['process' => $process, 'report' => $report->toArray()]
        );
    }
}
