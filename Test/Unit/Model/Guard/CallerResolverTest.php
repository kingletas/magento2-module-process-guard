<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Test\Unit\Model\Guard;

use Kingletas\ProcessGuard\Model\Guard\CallerResolver;
use PHPUnit\Framework\TestCase;

class CallerResolverTest extends TestCase
{
    public function testItNamesTheCallingClassAndMethod(): void
    {
        $caller = (new CallerResolver())->resolve();

        $this->assertStringContainsString(self::class, $caller);
        $this->assertStringEndsWith('::testItNamesTheCallingClassAndMethod', $caller);
    }

    public function testItSkipsFramesItWasToldToIgnore(): void
    {
        $caller = (new CallerResolver([self::class]))->resolve();

        $this->assertStringNotContainsString(self::class, $caller);
    }

    /**
     * A generated subclass is not a place anyone can go and look.
     */
    public function testAnInterceptorIsReportedAsTheClassItWraps(): void
    {
        $resolver = new CallerResolver();
        $reported = (new class ($resolver) {
            public function __construct(private readonly CallerResolver $resolver)
            {
            }

            public function ask(): string
            {
                return $this->resolver->resolve();
            }
        })->ask();

        $this->assertStringNotContainsString('\\Interceptor', $reported);
    }

    /**
     * A frame that is always the caller carries no information, so the list can
     * name one method rather than a whole class.
     */
    public function testOneMethodCanBeIgnoredWithoutIgnoringItsClass(): void
    {
        $ignored = self::class . '::testOneMethodCanBeIgnoredWithoutIgnoringItsClass';
        $caller = (new CallerResolver([$ignored]))->resolve();

        $this->assertNotSame($ignored, $caller);
    }

    public function testASiblingMethodIsStillReported(): void
    {
        $caller = (new CallerResolver([self::class . '::somethingElse']))->resolve();

        $this->assertStringEndsWith('::testASiblingMethodIsStillReported', $caller);
    }

    /**
     * An interceptor's generated methods sit between every caller and every
     * subject, and naming one of them answers nothing.
     */
    public function testAGeneratedInterceptorMethodIsNeverTheAnswer(): void
    {
        $reported = (new class (new CallerResolver()) {
            public function __construct(private readonly CallerResolver $resolver)
            {
            }

            public function ___callParent(): string
            {
                return $this->resolver->resolve();
            }

            public function ask(): string
            {
                return $this->___callParent();
            }
        })->ask();

        $this->assertStringNotContainsString('___callParent', $reported);
        $this->assertStringEndsWith('::ask', $reported);
    }

    /**
     * A plugin chain runs inside a closure the interceptor made, and that
     * closure is not anybody's code.
     */
    public function testAnInterceptorClosureIsNeverTheAnswer(): void
    {
        $resolver = new CallerResolver();
        $reported = (static fn (): string => $resolver->resolve())();

        $this->assertStringNotContainsString('{closure', $reported);
        $this->assertStringEndsWith('::testAnInterceptorClosureIsNeverTheAnswer', $reported);
    }

    public function testItGivesUpRatherThanGuessing(): void
    {
        $resolver = new CallerResolver([self::class, 'PHPUnit', 'ReflectionMethod', 'Kingletas'], 2);

        $this->assertSame(CallerResolver::UNKNOWN, $resolver->resolve());
    }

    public function testTheDepthIsNeverZero(): void
    {
        $this->assertNotSame('', (new CallerResolver([], 0))->resolve());
    }
}
