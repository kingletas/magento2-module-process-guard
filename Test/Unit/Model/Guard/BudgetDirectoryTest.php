<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Test\Unit\Model\Guard;

use Kingletas\ProcessGuard\Model\Guard\Budget;
use Kingletas\ProcessGuard\Model\Guard\BudgetDirectory;
use PHPUnit\Framework\TestCase;

class BudgetDirectoryTest extends TestCase
{
    public function testAProcessWithABudgetReturnsIt(): void
    {
        $budget = new Budget(warnMilliseconds: 100);

        $this->assertSame($budget, (new BudgetDirectory(['a' => $budget]))->get('a'));
    }

    public function testAProcessWithNoBudgetHasNoLimits(): void
    {
        $this->assertNull((new BudgetDirectory())->get('a'));
    }

    public function testAnythingThatIsNotABudgetIsIgnored(): void
    {
        $directory = new BudgetDirectory(['a' => 'not a budget']);

        $this->assertNull($directory->get('a'));
        $this->assertSame([], $directory->all());
    }

    public function testEveryBudgetIsListedForReporting(): void
    {
        $budgets = ['b' => new Budget(warnMilliseconds: 1), 'a' => new Budget(tripMilliseconds: 2)];

        $this->assertSame(['b', 'a'], array_keys((new BudgetDirectory($budgets))->all()));
    }
}
