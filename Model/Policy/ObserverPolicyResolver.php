<?php
/**
 * ObserverPolicyResolver.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Model\Policy;

use Commerce\ProcessGuard\Api\ObserverPolicy;
use Commerce\ProcessGuard\Api\ObserverPolicyResolverInterface;
use Commerce\ProcessGuard\Model\Config;

/**
 * Configuration first, declaration second, `Measured` for everything else.
 *
 * @see ObserverPolicyResolverInterface for why that is the order.
 */
class ObserverPolicyResolver implements ObserverPolicyResolverInterface
{
    /** @var array<string, bool>|null Lower-cased guarded event names. */
    private ?array $guarded = null;

    /** @var array<string, ObserverPolicy>|null */
    private ?array $declared = null;

    /**
     * The three runtime override lists, parsed once.
     *
     * @var array<string, string[]>|null
     */
    private ?array $overrides = null;

    /**
     * @param string[]              $guardedEvents   Events to watch. Empty
     *                                               guards nothing at all —
     *                                               an unguarded event costs
     *                                               one array lookup, and
     *                                               `controller_action_predispatch`
     *                                               fires on every request.
     * @param array<string, string> $classifications Observer name => policy
     *                                               value, from `di.xml`.
     *                                               Reviewed in a pull request,
     *                                               unlike the runtime lists.
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $guardedEvents = [],
        private readonly array $classifications = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $eventName, string $observerName, string $instance): ObserverPolicy
    {
        $observerName = trim($observerName);

        if ($observerName === '') {
            return ObserverPolicy::Measured;
        }

        // 1.
        $overrides = $this->overrides();

        if ($this->listed($overrides['disabled'], $observerName, $instance)) {
            return ObserverPolicy::Disabled;
        }

        if ($this->listed($overrides['critical'], $observerName, $instance)) {
            return ObserverPolicy::Critical;
        }

        if ($this->listed($overrides['advisory'], $observerName, $instance)) {
            return ObserverPolicy::Advisory;
        }

        // 2. What the code says.
        return $this->declared()[$observerName]
            ?? $this->declared()[$instance]
            // 3. Nothing has been said about it, so nothing is done to it.
            ?? ObserverPolicy::Measured;
    }

    /**
     * @inheritDoc
     */
    public function isGuardedEvent(string $eventName): bool
    {
        return isset($this->guarded()[mb_strtolower(trim($eventName))]);
    }

    /**
     * @inheritDoc
     */
    public function getGuardedEvents(): array
    {
        return array_keys($this->guarded());
    }

    /**
     * @param string[] $list
     */
    private function listed(array $list, string $observerName, string $instance): bool
    {
        foreach ($list as $entry) {
            $entry = trim($entry);

            // Either identifier: an operator reading a stack trace has the
            // class, and one reading events.xml has the name.
            if ($entry !== '' && (strcasecmp($entry, $observerName) === 0 || strcasecmp($entry, $instance) === 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The incident lists, read and split once for the life of this resolver.
     *
     * @return array<string, string[]>
     */
    private function overrides(): array
    {
        return $this->overrides ??= [
            'disabled' => $this->config->getDisabledObservers(),
            'critical' => $this->config->getCriticalObservers(),
            'advisory' => $this->config->getAdvisoryObservers(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function guarded(): array
    {
        if ($this->guarded !== null) {
            return $this->guarded;
        }

        $guarded = [];

        foreach ($this->guardedEvents as $event) {
            $event = mb_strtolower(trim((string) $event));

            if ($event !== '') {
                $guarded[$event] = true;
            }
        }

        return $this->guarded = $guarded;
    }

    /**
     * @return array<string, ObserverPolicy>
     */
    private function declared(): array
    {
        if ($this->declared !== null) {
            return $this->declared;
        }

        $declared = [];

        foreach ($this->classifications as $name => $policy) {
            $resolved = ObserverPolicy::tryFrom(strtolower(trim((string) $policy)));

            // An unreadable policy is ignored rather than guessed at.
            if ($resolved !== null) {
                $declared[(string) $name] = $resolved;
            }
        }

        return $this->declared = $declared;
    }
}
