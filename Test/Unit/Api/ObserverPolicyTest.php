<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Api;

use Commerce\ProcessGuard\Api\ObserverPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The four states, and what each one permits.
 */
final class ObserverPolicyTest extends TestCase
{
    /**
     * The safe default.
     */
    public function testTheDefaultStateContainsNothingAndSkipsNothing(): void
    {
        $measured = ObserverPolicy::Measured;

        self::assertFalse($measured->containsFailures());
        self::assertFalse($measured->isSheddable());
        self::assertTrue($measured->runs());
    }

    public function testOnlyAdvisoryAllowsAFailureToBeContained(): void
    {
        $containing = array_values(array_filter(
            ObserverPolicy::cases(),
            static fn (ObserverPolicy $policy): bool => $policy->containsFailures()
        ));

        self::assertSame([ObserverPolicy::Advisory], $containing);
    }

    public function testOnlyAdvisoryAllowsAnObserverToBeShed(): void
    {
        $sheddable = array_values(array_filter(
            ObserverPolicy::cases(),
            static fn (ObserverPolicy $policy): bool => $policy->isSheddable()
        ));

        self::assertSame([ObserverPolicy::Advisory], $sheddable);
    }

    /**
     * Critical is not "advisory with a louder log".
     */
    public function testCriticalIsNeverContainedAndNeverShed(): void
    {
        $critical = ObserverPolicy::Critical;

        self::assertFalse($critical->containsFailures());
        self::assertFalse($critical->isSheddable());
        self::assertTrue($critical->runs());
    }

    public function testOnlyDisabledStopsAnObserverRunning(): void
    {
        $notRunning = array_values(array_filter(
            ObserverPolicy::cases(),
            static fn (ObserverPolicy $policy): bool => !$policy->runs()
        ));

        self::assertSame([ObserverPolicy::Disabled], $notRunning);
    }

    /**
     * The values are configuration strings, so renaming one re-defaults every
     * observer naming it.
     */
    #[DataProvider('configurationValues')]
    public function testTheConfigurationValueIsStable(string $value, ObserverPolicy $expected): void
    {
        self::assertSame($expected, ObserverPolicy::from($value));
    }

    /**
     * @return array<string, array{string, ObserverPolicy}>
     */
    public static function configurationValues(): array
    {
        return [
            'measured' => ['measured', ObserverPolicy::Measured],
            'advisory' => ['advisory', ObserverPolicy::Advisory],
            'critical' => ['critical', ObserverPolicy::Critical],
            'disabled' => ['disabled', ObserverPolicy::Disabled],
        ];
    }

    public function testThereAreExactlyFourStates(): void
    {
        self::assertCount(
            4,
            ObserverPolicy::cases(),
            'A fifth state needs a decision about containment and shedding, so it needs a test here too.'
        );
    }
}
