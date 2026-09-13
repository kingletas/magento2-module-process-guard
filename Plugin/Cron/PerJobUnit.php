<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Plugin\Cron;

use Kingletas\ProcessGuard\Api\UnitOfWorkInterface;
use Magento\Cron\Model\Schedule;

/**
 * One cron job is one unit of work.
 */
class PerJobUnit
{
    public const UNIT_PREFIX = 'cron.';

    /** `Schedule::getJobCode()` is a magic accessor for this field. */
    private const JOB_CODE = 'job_code';

    public function __construct(
        private readonly UnitOfWorkInterface $unitOfWork
    ) {
    }

    /**
     * Taking the lock is the last thing before a job runs, and it succeeds once
     * per job. Its return type is undeclared, so narrowing this one would break
     * a lock manager that answers with anything but a bool.
     */
    public function afterTryLockJob(Schedule $subject, mixed $locked): mixed
    {
        if ($locked) {
            $this->unitOfWork->begin(self::UNIT_PREFIX . (string) $subject->getData(self::JOB_CODE));
        }

        return $locked;
    }
}
