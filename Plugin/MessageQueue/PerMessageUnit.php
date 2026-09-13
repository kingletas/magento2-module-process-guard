<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Plugin\MessageQueue;

use Kingletas\ProcessGuard\Api\UnitOfWorkInterface;
use Magento\Framework\MessageQueue\CallbackInvokerInterface;
use Magento\Framework\MessageQueue\QueueInterface;

/**
 * One message is one unit of work.
 */
class PerMessageUnit
{
    public const UNIT = 'queue.message';

    public function __construct(
        private readonly UnitOfWorkInterface $unitOfWork
    ) {
    }

    /**
     * The bounded path, taken when a consumer is given a message count, with
     * every argument left at the type the framework hands over.
     *
     * @return array<int, mixed>
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function beforeInvoke(
        CallbackInvokerInterface $subject,
        QueueInterface $queue,
        mixed $maxNumberOfMessages,
        callable $callback,
        mixed $maxIdleTime = null,
        mixed $sleep = null
    ): array {
        return [$queue, $maxNumberOfMessages, $this->bounded($callback), $maxIdleTime, $sleep];
    }

    /**
     * The daemon path, taken when a consumer is given no message count. This
     * is the one that runs for hours.
     *
     * @return array<int, mixed>
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function beforeSubscribe(QueueInterface $subject, callable $callback): array
    {
        return [$this->bounded($callback)];
    }

    private function bounded(callable $callback): callable
    {
        $unitOfWork = $this->unitOfWork;

        return static function (...$arguments) use ($callback, $unitOfWork) {
            $unitOfWork->begin(self::UNIT);

            return $callback(...$arguments);
        };
    }
}
