<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Support;

use Commerce\ProcessGuard\Api\ProcessGuardInterface;
use Commerce\ProcessGuard\Model\Report\ProcessReport;

/**
 * A guard that runs the work for real and remembers how it was called.
 */
class RecordingGuard implements ProcessGuardInterface
{
    /** @var string[] Process names passed to run(), in order. */
    public array $processes = [];

    /** @var array<string, scalar|null> Context from the most recent run(). */
    public array $context = [];

    /** @var string[] Process names passed to checkpoint(), in order. */
    public array $checkpoints = [];

    /** @var array<int, array{process: string, elapsed: int}> */
    public array $recorded = [];

    /** @var array<string, bool> Processes to report as over budget. */
    public array $tripped = [];

    public function __construct(private readonly ProcessReport $report = new ProcessReport())
    {
    }

    /**
     * The most recent process name, which is what a single-call test asks about.
     */
    public function process(): ?string
    {
        return $this->processes === [] ? null : $this->processes[array_key_last($this->processes)];
    }

    /**
     * @inheritDoc
     */
    public function run(string $process, callable $work, array $context = []): mixed
    {
        $this->processes[] = $process;
        $this->context = $context;

        return $work();
    }

    /**
     * @inheritDoc
     */
    public function record(string $process, int $elapsedNanoseconds, array $context = []): void
    {
        $this->recorded[] = ['process' => $process, 'elapsed' => $elapsedNanoseconds];
    }

    /**
     * @inheritDoc
     */
    public function isTripped(string $process): bool
    {
        return $this->tripped[$process] ?? false;
    }

    /**
     * @inheritDoc
     */
    public function checkpoint(string $process, array $context = []): void
    {
        $this->checkpoints[] = $process;
    }

    /**
     * @inheritDoc
     */
    public function getReport(): ProcessReport
    {
        return $this->report;
    }
}
