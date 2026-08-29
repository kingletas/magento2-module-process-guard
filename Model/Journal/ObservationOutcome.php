<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Model\Journal;

/**
 * How one unit of work ended.
 */
enum ObservationOutcome: string
{
    case Completed = 'completed';

    /** Threw, and the exception was rethrown as it would have been anyway. */
    case Failed = 'failed';

    /** Threw, and the exception was logged instead — an advisory observer. */
    case Contained = 'contained';

    /** Skipped because its path was already over budget. */
    case Shed = 'shed';

    /** Skipped because it is switched off in configuration. */
    case Disabled = 'disabled';

    /** Ran, and took longer than its budget allowed. */
    case OverBudget = 'over_budget';

    /**
     * Not slow — repeated.
     */
    case Repeated = 'repeated';

    /** A long-running process crossed its memory ceiling at a checkpoint. */
    case MemoryCeiling = 'memory_ceiling';

    public function ran(): bool
    {
        return $this === self::Completed || $this === self::OverBudget
            || $this === self::Failed || $this === self::Contained;
    }

    public function isNoteworthy(): bool
    {
        return $this !== self::Completed;
    }
}
