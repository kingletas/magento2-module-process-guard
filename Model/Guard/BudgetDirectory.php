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

    /**
     * A directory with these budgets laid over this one, each replacing the
     * entry of the same name. Anything that is not a budget is left out, so a
     * mistyped override keeps the budget it meant to replace.
     *
     * @param array<string, mixed> $budgets Process name => budget.
     */
    public function withOverrides(array $budgets): self
    {
        return new self(array_replace(
            $this->all(),
            array_filter($budgets, static fn ($budget): bool => $budget instanceof Budget)
        ));
    }
}
