<?php
/**
 * Interceptor.php
 *
 * @package     Commerce_ProcessGuard
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Support\FakeConsumer;

use Magento\Framework\MessageQueue\ConsumerInterface;

/**
 * Named and placed the way Magento's generated interceptors are, which is what
 * is recognised.
 */
class Interceptor implements ConsumerInterface
{
    /**
     * @inheritDoc
     */
    public function process($maxNumberOfMessages = null)
    {
    }
}
