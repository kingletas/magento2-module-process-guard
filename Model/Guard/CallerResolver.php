<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Model\Guard;

/**
 * Names the code that asked for a piece of work.
 */
class CallerResolver
{
    public const UNKNOWN = 'unknown';

    /**
     * Frames that belong to the machinery rather than to anybody's code:
     * Magento's generated interceptor methods, and the closures the
     * interceptor wraps a plugin chain in.
     */
    private const MACHINERY = ['___', '{closure'];

    /**
     * @param string[] $ignoredPrefixes Matched against `Class::method`, so an
     *                                  entry can name a whole namespace or one
     *                                  method. A frame that is always the
     *                                  caller carries no information.
     * @param int      $depth           How far back to look. A backtrace is not
     *                                  free, and the answer is always near the
     *                                  top.
     */
    public function __construct(
        private readonly array $ignoredPrefixes = [],
        private readonly int $depth = 12
    ) {
    }

    /**
     * The nearest frame that is somebody else's code.
     */
    public function resolve(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, max(1, $this->depth));

        foreach ($frames as $frame) {
            $class = (string) ($frame['class'] ?? '');

            if ($class === '') {
                continue;
            }

            $function = (string) ($frame['function'] ?? '');

            if ($this->machinery($function)) {
                continue;
            }

            $name = $this->strip($class) . '::' . $function;

            if (!$this->ignored($name)) {
                return $name;
            }
        }

        return self::UNKNOWN;
    }

    private function machinery(string $function): bool
    {
        foreach (self::MACHINERY as $prefix) {
            if (str_starts_with($function, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function ignored(string $name): bool
    {
        // Frame zero is always this method asking the question, whatever the
        // wiring says, and it is never the answer.
        if (str_starts_with($name, self::class)) {
            return true;
        }

        foreach ($this->ignoredPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Interceptors are generated subclasses; their name is noise in a report.
     */
    private function strip(string $class): string
    {
        $at = strpos($class, '\\Interceptor');

        return $at === false ? $class : substr($class, 0, $at);
    }
}
