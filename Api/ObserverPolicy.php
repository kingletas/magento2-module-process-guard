<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Api;

/**
 * What this module is allowed to do to one observer.
 */
enum ObserverPolicy: string
{
    /**
     * Timed and reported.
     */
    case Measured = 'measured';

    /**
     * Declared non-essential by a human: its failure is logged instead of
     * thrown, and it is skipped when its event has already blown its budget.
     */
    case Advisory = 'advisory';

    /**
     * Declared essential: never contained, never skipped, and slowness reported
     * at higher severity.
     */
    case Critical = 'critical';

    /**
     * Never runs.
     */
    case Disabled = 'disabled';

    public function containsFailures(): bool
    {
        return $this === self::Advisory;
    }

    public function isSheddable(): bool
    {
        return $this === self::Advisory;
    }

    public function runs(): bool
    {
        return $this !== self::Disabled;
    }
}
