<?php
/**
 * Observation.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Model\Journal;

/**
 * One unit of work: what it was, how it ended, what it cost.
 */
class Observation
{
    private string $message;

    /**
     * @param string                     $process  Named process, e.g.
     *                                             `event.sales_order_place_after`.
     * @param string                     $label    The unit inside it — an
     *                                             observer name, a topic, a SKU.
     * @param int                        $elapsedNanoseconds Zero for work that
     *                                             never ran.
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        private readonly ObservationOutcome $outcome,
        private readonly string $process,
        private readonly string $label,
        private readonly int $elapsedNanoseconds = 0,
        private readonly array $context = [],
        private readonly ?string $failure = null
    ) {
        $this->message = $this->buildMessage();
    }

    public function getOutcome(): ObservationOutcome
    {
        return $this->outcome;
    }

    public function getProcess(): string
    {
        return $this->process;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getElapsedNanoseconds(): int
    {
        return max(0, $this->elapsedNanoseconds);
    }

    public function getElapsedMilliseconds(): float
    {
        return $this->getElapsedNanoseconds() / 1_000_000;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getFailure(): ?string
    {
        return $this->failure;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'process' => $this->process,
            'label' => $this->label,
            'ms' => round($this->getElapsedMilliseconds(), 2),
            'failure' => $this->failure,
        ] + $this->context;
    }

    private function buildMessage(): string
    {
        $ms = round($this->getElapsedMilliseconds(), 2);

        return match ($this->outcome) {
            ObservationOutcome::Completed => sprintf('%s: %s took %sms', $this->process, $this->label, $ms),
            ObservationOutcome::OverBudget => sprintf(
                '%s: %s took %sms, over budget',
                $this->process,
                $this->label,
                $ms
            ),
            ObservationOutcome::Failed => sprintf(
                '%s: %s failed after %sms and the failure was left to propagate: %s',
                $this->process,
                $this->label,
                $ms,
                $this->failure ?? 'no message'
            ),
            ObservationOutcome::Contained => sprintf(
                '%s: %s failed after %sms and was contained because it is declared advisory: %s',
                $this->process,
                $this->label,
                $ms,
                $this->failure ?? 'no message'
            ),
            ObservationOutcome::Shed => sprintf(
                '%s: %s was skipped, the process is already over budget',
                $this->process,
                $this->label
            ),
            ObservationOutcome::Disabled => sprintf(
                '%s: %s was skipped, it is switched off in configuration',
                $this->process,
                $this->label
            ),
            ObservationOutcome::Repeated => sprintf(
                '%s: %s — repeated more often in one request than its budget allows',
                $this->process,
                $this->label
            ),
            ObservationOutcome::MemoryCeiling => sprintf(
                '%s: %s — memory ceiling crossed, this process is heading for the memory limit',
                $this->process,
                $this->label
            ),
        };
    }
}
