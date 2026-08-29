<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Model\Guard;

/**
 * What one named process is allowed to cost.
 */
class Budget
{
    private ?int $warnNanoseconds;

    private ?int $tripNanoseconds;

    private ?int $maxCalls;

    private ?int $memoryBytes;

    public function __construct(
        ?int $warnMilliseconds = null,
        ?int $tripMilliseconds = null,
        ?int $maxCalls = null,
        ?int $memoryBytes = null
    ) {
        $this->warnNanoseconds = $this->positiveOrNull($warnMilliseconds) === null
            ? null
            : $warnMilliseconds * 1_000_000;
        $this->tripNanoseconds = $this->positiveOrNull($tripMilliseconds) === null
            ? null
            : $tripMilliseconds * 1_000_000;
        $this->maxCalls = $this->positiveOrNull($maxCalls);
        $this->memoryBytes = $this->positiveOrNull($memoryBytes);

        // The stricter of the two wins, so nothing trips before it has been
        // warned about.
        if ($this->warnNanoseconds !== null && $this->tripNanoseconds !== null) {
            $this->warnNanoseconds = min($this->warnNanoseconds, $this->tripNanoseconds);
        }
    }

    public function getWarnNanoseconds(): ?int
    {
        return $this->warnNanoseconds;
    }

    public function getTripNanoseconds(): ?int
    {
        return $this->tripNanoseconds;
    }

    public function getMaxCalls(): ?int
    {
        return $this->maxCalls;
    }

    public function getMemoryBytes(): ?int
    {
        return $this->memoryBytes;
    }

    public function isWarned(int $elapsedNanoseconds): bool
    {
        return $this->warnNanoseconds !== null && $elapsedNanoseconds > $this->warnNanoseconds;
    }

    public function isTripped(int $elapsedNanoseconds): bool
    {
        return $this->tripNanoseconds !== null && $elapsedNanoseconds > $this->tripNanoseconds;
    }

    public function isCallCountExceeded(int $calls): bool
    {
        return $this->maxCalls !== null && $calls > $this->maxCalls;
    }

    public function isMemoryExceeded(int $bytes): bool
    {
        return $this->memoryBytes !== null && $bytes > $this->memoryBytes;
    }

    /**
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'warn_ms' => $this->warnNanoseconds === null ? null : (int) ($this->warnNanoseconds / 1_000_000),
            'trip_ms' => $this->tripNanoseconds === null ? null : (int) ($this->tripNanoseconds / 1_000_000),
            'max_calls' => $this->maxCalls,
            'memory_bytes' => $this->memoryBytes,
        ];
    }

    private function positiveOrNull(?int $value): ?int
    {
        return $value === null || $value <= 0 ? null : $value;
    }
}
