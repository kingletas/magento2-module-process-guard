<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Model\Guard;

use Kingletas\ProcessGuard\Api\ClockInterface;
use Kingletas\ProcessGuard\Api\ProcessGuardInterface;
use Kingletas\ProcessGuard\Api\UnitOfWorkInterface;
use Kingletas\ProcessGuard\Model\Config;
use Kingletas\ProcessGuard\Model\Journal\Observation;
use Kingletas\ProcessGuard\Model\Journal\ObservationOutcome;
use Kingletas\ProcessGuard\Model\Journal\ObservationRecorder;
use Kingletas\ProcessGuard\Model\Report\ProcessReport;
use Throwable;

/**
 * Accounting for named processes, cumulative over one request.
 *
 * @see ProcessGuardInterface, and in particular what it says about not being
 *      able to interrupt work that has already started.
 */
class ProcessGuard implements ProcessGuardInterface, UnitOfWorkInterface
{
    /** @var array<string, int> Cumulative nanoseconds per process. */
    private array $elapsed = [];

    /** @var array<string, int> */
    private array $calls = [];

    /** @var array<string, int> Nesting depth, so a summary is emitted once. */
    private array $depth = [];

    /** @var array<string, bool> Breaches already reported. */
    private array $reported = [];

    /** What is being accounted for right now, named by whoever began it. */
    private string $unit = '';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ObservationRecorder $recorder,
        private readonly Config $config,
        private readonly ?BudgetDirectory $budgets = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(string $process, callable $work, array $context = []): mixed
    {
        if (!$this->config->isEnabled()) {
            // Straight through.
            return $work();
        }

        $this->countCall($process, $context);

        $this->depth[$process] = ($this->depth[$process] ?? 0) + 1;
        $started = $this->clock->nanoTime();
        $failure = null;

        try {
            return $work();
        } catch (Throwable $e) {
            // Recorded, and then rethrown untouched.
            $failure = $e;

            throw $e;
        } finally {
            $this->close($process, $started, $failure, $context);
        }
    }

    /**
     * @inheritDoc
     */
    public function record(string $process, int $elapsedNanoseconds, array $context = []): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $this->accumulate($process, $elapsedNanoseconds);
        $this->reportTimeBreach($process, $context);
    }

    /**
     * @inheritDoc
     */
    public function isTripped(string $process): bool
    {
        $budget = $this->budgetFor($process);

        // A factual question, deliberately: whether anything may be *done*
        // about it is the caller's to ask, and depends on what the work is.
        return $budget !== null && $budget->isTripped($this->elapsed[$process] ?? 0);
    }

    /**
     * @inheritDoc
     */
    public function checkpoint(string $process, array $context = []): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $budget = $this->budgetFor($process);

        if ($budget === null) {
            return;
        }

        $this->reportTimeBreach($process, $context);

        $memory = $this->clock->memoryUsage();

        if (!$budget->isMemoryExceeded($memory) || $this->alreadyReported($process, 'memory')) {
            return;
        }

        $this->recorder->record(new Observation(
            ObservationOutcome::MemoryCeiling,
            $process,
            sprintf('%sMB in use', round($memory / 1048576, 1)),
            0,
            $context + [
                'memory_bytes' => $memory,
                'ceiling_bytes' => $budget->getMemoryBytes(),
                'memory_limit_bytes' => $this->clock->memoryLimit(),
            ]
        ));
    }

    /**
     * @inheritDoc
     */
    public function getReport(): ProcessReport
    {
        return $this->recorder->getReport();
    }

    /**
     * @inheritDoc
     */
    public function begin(string $unit): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // A process that is still running spans the boundary by definition: a
        // consumer's own budget covers the consumer, not one message inside it.
        $open = array_filter($this->depth, static fn (int $depth): bool => $depth > 0);

        $this->elapsed = array_intersect_key($this->elapsed, $open);
        $this->calls = array_intersect_key($this->calls, $open);
        $this->reported = $this->reportedForOpen($open);
        $this->depth = $open;
        $this->unit = $unit;

        $this->recorder->clear();
    }

    /**
     * The unit being accounted for right now, for a report to name.
     */
    public function getUnit(): string
    {
        return $this->unit;
    }

    /**
     * @param array<string, int>  $open
     * @return array<string, bool>
     */
    private function reportedForOpen(array $open): array
    {
        $kept = [];

        foreach ($this->reported as $key => $yes) {
            $process = strstr($key, "\0", true);

            if ($process !== false && isset($open[$process])) {
                $kept[$key] = $yes;
            }
        }

        return $kept;
    }

    /**
     * Close one run: account for it, journal it, and summarise if it was the
     * outermost one.
     *
     * @param array<string, scalar|null> $context
     */
    private function close(string $process, int $started, ?Throwable $failure, array $context): void
    {
        $elapsed = max(0, $this->clock->nanoTime() - $started);

        $this->depth[$process] = max(0, ($this->depth[$process] ?? 1) - 1);
        $this->accumulate($process, $elapsed);

        $outcome = ObservationOutcome::Completed;

        if ($failure !== null) {
            $outcome = ObservationOutcome::Failed;
        } elseif ($this->isOverTime($process) && !$this->alreadyReported($process, 'time')) {
            $outcome = ObservationOutcome::OverBudget;
        }

        $this->recorder->record(new Observation(
            $outcome,
            $process,
            (string) ($context['label'] ?? $process),
            $elapsed,
            $context,
            $failure?->getMessage()
        ));

        if ($this->depth[$process] === 0 && $this->config->isSummaryReportingEnabled()) {
            $this->recorder->summarise($process);
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function countCall(string $process, array $context): void
    {
        $calls = $this->calls[$process] = ($this->calls[$process] ?? 0) + 1;
        $budget = $this->budgetFor($process);

        if ($budget === null
            || !$budget->isCallCountExceeded($calls)
            || $this->alreadyReported($process, 'calls')
        ) {
            return;
        }

        $this->recorder->record(new Observation(
            ObservationOutcome::Repeated,
            $process,
            sprintf('%s calls, budget allows %s', $calls, $budget->getMaxCalls()),
            0,
            $context + ['calls' => $calls, 'max_calls' => $budget->getMaxCalls()]
        ));
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function reportTimeBreach(string $process, array $context): void
    {
        if (!$this->isOverTime($process) || $this->alreadyReported($process, 'time')) {
            return;
        }

        $budget = $this->budgetFor($process);
        $elapsed = $this->elapsed[$process] ?? 0;

        $this->recorder->record(new Observation(
            ObservationOutcome::OverBudget,
            $process,
            (string) ($context['label'] ?? $process),
            $elapsed,
            $context + [
                'cumulative_ms' => round($elapsed / 1_000_000, 2),
                'budget' => $budget?->toArray()['warn_ms'],
            ]
        ));
    }

    private function isOverTime(string $process): bool
    {
        $budget = $this->budgetFor($process);

        return $budget !== null && $budget->isWarned($this->elapsed[$process] ?? 0);
    }

    private function accumulate(string $process, int $elapsedNanoseconds): void
    {
        $this->elapsed[$process] = ($this->elapsed[$process] ?? 0) + max(0, $elapsedNanoseconds);
    }

    private function alreadyReported(string $process, string $kind): bool
    {
        $key = $process . "\0" . $kind;

        if (isset($this->reported[$key])) {
            return true;
        }

        $this->reported[$key] = true;

        return false;
    }

    private function budgetFor(string $process): ?Budget
    {
        return $this->budgets?->get($process);
    }
}
