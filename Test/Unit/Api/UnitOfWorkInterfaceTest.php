<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Test\Unit\Api;

use Kingletas\ProcessGuard\Api\UnitOfWorkInterface;
use Kingletas\ProcessGuard\Model\Guard\ProcessGuard;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class UnitOfWorkInterfaceTest extends TestCase
{
    public function testTheGuardIsTheImplementation(): void
    {
        self::assertTrue(
            (new ReflectionClass(ProcessGuard::class))->implementsInterface(UnitOfWorkInterface::class)
        );
    }

    public function testItAsksForOneThingOnly(): void
    {
        $methods = (new ReflectionClass(UnitOfWorkInterface::class))->getMethods();

        self::assertCount(1, $methods);
        self::assertSame('begin', $methods[0]->getName());
    }
}
