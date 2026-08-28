<?php
/**
 * ClockInterface.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Api;

/**
 * Monotonic time and memory, behind a seam.
 */
interface ClockInterface
{
    public function nanoTime(): int;

    public function memoryUsage(): int;

    public function memoryLimit(): ?int;
}
