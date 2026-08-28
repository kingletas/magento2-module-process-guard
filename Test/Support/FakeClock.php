<?php
/**
 * FakeClock.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Support;

use Commerce\ProcessGuard\Api\ClockInterface;

/**
 * A clock the tests move by hand.
 */
class FakeClock implements ClockInterface
{
    private int $nanos = 0;

    private int $memory = 0;

    private ?int $limit = null;

    /** Readings alternate: one opens a timed block, the next closes it. */
    private bool $inBlock = false;

    /** @var int[] Elapsed times to hand out, one per timed block. */
    private array $durations = [];

    /**
     * How long each timed block takes, in order.
     *
     * @param int[] $millisecondDurations
     */
    public function willTake(array $millisecondDurations): self
    {
        foreach ($millisecondDurations as $ms) {
            $this->durations[] = $ms * 1_000_000;
        }

        return $this;
    }

    public function withMemory(int $bytes, ?int $limit = null): self
    {
        $this->memory = $bytes;
        $this->limit = $limit;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function nanoTime(): int
    {
        if (!$this->inBlock) {
            $this->inBlock = true;

            return $this->nanos;
        }

        $this->inBlock = false;
        $this->nanos += array_shift($this->durations) ?? 0;

        return $this->nanos;
    }

    /**
     * @inheritDoc
     */
    public function memoryUsage(): int
    {
        return $this->memory;
    }

    /**
     * @inheritDoc
     */
    public function memoryLimit(): ?int
    {
        return $this->limit;
    }
}
