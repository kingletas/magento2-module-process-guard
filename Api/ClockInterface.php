<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Api;

/**
 * Monotonic time and memory, behind a seam.
 */
interface ClockInterface
{
    public function nanoTime(): int;

    public function memoryUsage(): int;

    public function memoryLimit(): ?int;
}
