<?php
/**
 * GuardedConsumer.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Plugin\MessageQueue;

use Commerce\ProcessGuard\Api\ProcessGuardInterface;
use Magento\Framework\MessageQueue\ConsumerInterface;

/**
 * Times a consumer batch and watches the process's memory.
 */
class GuardedConsumer
{
    public const PROCESS = 'queue.consumer';

    public function __construct(
        private readonly ProcessGuardInterface $guard
    ) {
    }

    /**
     * @param int|null $maxNumberOfMessages
     *
     * phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     */
    public function aroundProcess(
        ConsumerInterface $subject,
        callable $proceed,
        $maxNumberOfMessages = null
    ): mixed {
        $context = [
            'label' => self::PROCESS . ':' . $this->name($subject),
            'consumer' => $this->name($subject),
            'batch' => $maxNumberOfMessages === null ? null : (int) $maxNumberOfMessages,
        ];

        // Before, not after: the point is to report the climb, and a checkpoint
        // that only runs after the batch that ran out of memory never runs.
        $this->guard->checkpoint(self::PROCESS, $context);

        return $this->guard->run(
            self::PROCESS,
            static fn () => $proceed($maxNumberOfMessages),
            $context
        );
    }

    private function name(ConsumerInterface $subject): string
    {
        $class = get_class($subject);

        // Interceptors are generated subclasses; their name is noise in a log.
        return str_contains($class, '\\Interceptor')
            ? substr($class, 0, strpos($class, '\\Interceptor'))
            : $class;
    }
}
