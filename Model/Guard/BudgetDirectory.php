<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Model\Guard;

/**
 * What every named process is allowed to cost, in one place.
 */
class BudgetDirectory
{
    /**
     * @param array<string, Budget> $budgets Process name => budget. A process that is
     *                                       not in here has no limits; see Budget.
     */
    public function __construct(
        private readonly array $budgets = []
    ) {
    }

    public function get(string $process): ?Budget
    {
        $budget = $this->budgets[$process] ?? null;

        return $budget instanceof Budget ? $budget : null;
    }

    /**
     * @return array<string, Budget>
     */
    public function all(): array
    {
        return array_filter($this->budgets, static fn ($budget): bool => $budget instanceof Budget);
    }
}
