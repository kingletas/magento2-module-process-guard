<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Plugin\Event;

use Commerce\ProcessGuard\Api\ObserverPolicy;
use Commerce\ProcessGuard\Api\ObserverPolicyResolverInterface;
use Commerce\ProcessGuard\Api\ClockInterface;
use Commerce\ProcessGuard\Api\ProcessGuardInterface;
use Commerce\ProcessGuard\Model\Config;
use Commerce\ProcessGuard\Model\Journal\Observation;
use Commerce\ProcessGuard\Model\Journal\ObservationOutcome;
use Commerce\ProcessGuard\Model\Journal\ObservationRecorder;
use Magento\Framework\Event\Invoker\InvokerDefault;
use Magento\Framework\Event\Observer;
use Throwable;

/**
 * The gate itself: one call per observer per event.
 */
class GuardedInvoker
{
    /**
     * Process names are prefixed so that an event called `checkout` and a
     * process called `checkout` cannot share a budget by accident.
     */
    public const PROCESS_PREFIX = 'event.';

    private int $observerWarnNanoseconds;

    /**
     * Whether measurement is on, settled once and then held.
     *
     * @see isEnabled() for why this is read once rather than per dispatch.
     */
    private ?bool $enabled = null;

    /**
     * @param int $observerWarnMilliseconds When a single observer takes longer
     *                                      than this, it is named. The event's
     *                                      own budget is cumulative and lives
     *                                      in the guard; this is the "which one
     *                                      of the fifty-six" question.
     */
    public function __construct(
        private readonly Config $config,
        private readonly ObserverPolicyResolverInterface $policyResolver,
        private readonly ProcessGuardInterface $guard,
        private readonly ObservationRecorder $recorder,
        private readonly ClockInterface $clock,
        int $observerWarnMilliseconds = 250
    ) {
        $this->observerWarnNanoseconds = max(0, $observerWarnMilliseconds) * 1_000_000;
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function aroundDispatch(
        InvokerDefault $subject,
        callable $proceed,
        array $configuration,
        Observer $observer
    ): mixed {
        if (!$this->isEnabled()) {
            return $proceed($configuration, $observer);
        }

        $eventName = $this->eventName($observer);

        // The cheap path: one lowercase and one isset, on every observer of
        // every event.
        if ($eventName === '' || !$this->policyResolver->isGuardedEvent($eventName)) {
            return $proceed($configuration, $observer);
        }

        $observerName = (string) ($configuration['name'] ?? '');
        $instance = (string) ($configuration['instance'] ?? '');
        $process = self::PROCESS_PREFIX . $eventName;
        $label = $observerName !== '' ? $observerName : $instance;
        $context = ['event' => $eventName, 'observer' => $observerName, 'class' => $instance];

        $policy = $this->policyResolver->resolve($eventName, $observerName, $instance);

        if (!$policy->runs()) {
            $this->recorder->record(new Observation(ObservationOutcome::Disabled, $process, $label, 0, $context));

            return null;
        }

        if ($this->shouldShed($policy, $process)) {
            $this->recorder->record(new Observation(ObservationOutcome::Shed, $process, $label, 0, $context));

            return null;
        }

        return $this->invoke($proceed, $configuration, $observer, $policy, $process, $label, $context);
    }

    /**
     * @param array<string, mixed>       $configuration
     * @param array<string, scalar|null> $context
     */
    private function invoke(
        callable $proceed,
        array $configuration,
        Observer $observer,
        ObserverPolicy $policy,
        string $process,
        string $label,
        array $context
    ): mixed {
        $started = $this->clock->nanoTime();

        try {
            $result = $proceed($configuration, $observer);
        } catch (Throwable $e) {
            $this->close($process, $started, $label, $context, $policy, $e);

            // Contained only where a person has declared this observer
            // advisory.
            if (!$policy->containsFailures()) {
                throw $e;
            }

            return null;
        }

        $this->close($process, $started, $label, $context, $policy, null);

        return $result;
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function close(
        string $process,
        int $started,
        string $label,
        array $context,
        ObserverPolicy $policy,
        ?Throwable $failure
    ): void {
        $elapsed = max(0, $this->clock->nanoTime() - $started);

        // Into the event's cumulative total, which is what decides whether the
        // *next* advisory observer runs.
        $this->guard->record($process, $elapsed, $context);

        $outcome = match (true) {
            $failure !== null && $policy->containsFailures() => ObservationOutcome::Contained,
            $failure !== null => ObservationOutcome::Failed,
            $this->observerWarnNanoseconds > 0
                && $elapsed > $this->observerWarnNanoseconds => ObservationOutcome::OverBudget,
            default => ObservationOutcome::Completed,
        };

        $this->recorder->record(new Observation(
            $outcome,
            $process,
            $label,
            $elapsed,
            $context + ['policy' => $policy->value],
            $failure?->getMessage()
        ));
    }

    private function shouldShed(ObserverPolicy $policy, string $process): bool
    {
        // Three conditions: declared sheddable, shedding switched on for this
        // store, budget blown.
        return $policy->isSheddable()
            && $this->config->isSheddingEnabled()
            && $this->guard->isTripped($process);
    }

    /**
     * The master switch, read once for the life of this process.
     */
    private function isEnabled(): bool
    {
        return $this->enabled ??= $this->config->isEnabled();
    }

    private function eventName(Observer $observer): string
    {
        $event = $observer->getEvent();

        return $event === null ? '' : mb_strtolower((string) $event->getName());
    }
}
