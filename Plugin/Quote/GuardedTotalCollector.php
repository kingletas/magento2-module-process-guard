<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Plugin\Quote;

use Kingletas\ProcessGuard\Api\ClockInterface;
use Kingletas\ProcessGuard\Model\Config;
use Kingletas\ProcessGuard\Model\Journal\Observation;
use Kingletas\ProcessGuard\Model\Journal\ObservationOutcome;
use Kingletas\ProcessGuard\Model\Journal\ObservationRecorder;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\CollectorInterface;
use Throwable;

/**
 * Times each totals collector separately, so a slow collection can name the
 * part that is slow.
 */
class GuardedTotalCollector
{
    public const PROCESS_PREFIX = 'totals.';

    private ?bool $enabled = null;

    public function __construct(
        private readonly Config $config,
        private readonly ObservationRecorder $recorder,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function aroundCollect(
        CollectorInterface $subject,
        callable $proceed,
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ): mixed {
        if (!$this->isEnabled()) {
            return $proceed($quote, $shippingAssignment, $total);
        }

        $started = $this->clock->nanoTime();
        $failure = null;

        try {
            return $proceed($quote, $shippingAssignment, $total);
        } catch (Throwable $e) {
            $failure = $e;

            throw $e;
        } finally {
            $this->close($subject, $started, $quote, $failure);
        }
    }

    private function close(CollectorInterface $subject, int $started, Quote $quote, ?Throwable $failure): void
    {
        $class = $this->realClass($subject);

        // The whole class name, not an abbreviation of it: this is the string
        // an operator searches for, and two collectors here are called `Tax`.
        $this->recorder->record(new Observation(
            $failure === null ? ObservationOutcome::Completed : ObservationOutcome::Failed,
            self::PROCESS_PREFIX . $class,
            $class,
            max(0, $this->clock->nanoTime() - $started),
            ['collector' => $class, 'quote_id' => (int) $quote->getId()],
            $failure?->getMessage()
        ));
    }

    private function realClass(CollectorInterface $subject): string
    {
        $class = get_class($subject);
        $at = strpos($class, '\\Interceptor');

        return $at === false ? $class : substr($class, 0, $at);
    }

    /**
     * Read once and held: this runs for every collector of every collection.
     */
    private function isEnabled(): bool
    {
        return $this->enabled ??= $this->config->isEnabled() && $this->config->isTotalsDetailEnabled();
    }
}
