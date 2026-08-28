<?php
/**
 * ObserverPolicyResolverInterface.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Api;

/**
 * Decides what may be done to a given observer on a given event.
 */
interface ObserverPolicyResolverInterface
{
    /**
     * @param string $eventName    Dispatched event, lower case as Magento
     *                             normalises it.
     * @param string $observerName The name from `events.xml`, which is what an
     *                             operator has to type into the kill list.
     * @param string $instance     Observer class, for the report.
     */
    public function resolve(string $eventName, string $observerName, string $instance): ObserverPolicy;

    /**
     * Is this event watched at all?
     */
    public function isGuardedEvent(string $eventName): bool;

    /**
     * The events this module is watching.
     *
     * @return string[]
     */
    public function getGuardedEvents(): array;
}
