<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Api;

/**
 * Declares where one piece of work ends and the next begins.
 */
interface UnitOfWorkInterface
{
    /**
     * Judge what follows on its own, not on what this process did before it.
     *
     * @param string $unit What is starting, for the report: a queue topic, a
     *                     cron job code, a request path.
     */
    public function begin(string $unit): void;
}
