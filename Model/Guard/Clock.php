<?php
/**
 * Clock.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Model\Guard;

use Commerce\ProcessGuard\Api\ClockInterface;

/**
 * `hrtime()` and `memory_get_usage()`, behind the interface so the tests can
 * decide what time it is.
 */
class Clock implements ClockInterface
{
    /**
     * @inheritDoc
     */
    public function nanoTime(): int
    {
        return hrtime(true);
    }

    /**
     * @inheritDoc
     */
    public function memoryUsage(): int
    {
        // Real usage, not the emalloc figure: the number that matters is the
        // one the memory limit is compared against.
        return memory_get_usage(true);
    }

    /**
     * @inheritDoc
     */
    public function memoryLimit(): ?int
    {
        return $this->parseMemoryLimit($this->rawMemoryLimit());
    }

    /**
     * The configured value, unparsed.
     */
    protected function rawMemoryLimit(): string|false
    {
        return ini_get('memory_limit');
    }

    /**
     * `memory_limit` is a shorthand string: `512M`, `2G`, `-1`, or a plain byte
     * count.
     */
    protected function parseMemoryLimit(string|false $limit): ?int
    {
        if ($limit === false || $limit === '' || $limit === '-1') {
            return null;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
