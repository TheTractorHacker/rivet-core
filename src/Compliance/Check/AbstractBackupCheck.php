<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementsProviderInterface;
use RivetCore\Contracts\ClockInterface;

/**
 * A profile-driven check that judges the facts the edition reports about its backup ({@see BackupFactsProviderInterface}). Facts that cannot be read, or an
 * edition that does not report them, are "not available" (a warning, never a pass); a backup that never succeeded is judged by the subclass as a failure.
 *
 * @internal
 */
abstract class AbstractBackupCheck extends AbstractProfileCheck
{
    public function __construct(RequirementsProviderInterface $profiles, protected BackupFactsProviderInterface $facts, ClockInterface $clock, string $settingsPath = '')
    {
        parent::__construct($profiles, $clock, $settingsPath);
    }

    abstract protected function judge(FrameworkProfile $profile, BackupFacts $facts): CheckResult;

    final protected function evaluate(FrameworkProfile $profile): CheckResult
    {
        try {
            $facts = $this->facts->backupFacts();
        } catch (\Throwable) {
            return CheckResult::warn('Not available: the backup information could not be read.', null, $this->settingsPath ?: null);
        }
        if (!$facts->available) {
            return CheckResult::warn('Not available: ' . ($facts->reason !== '' ? $facts->reason : 'the backup information is not reported.'), 'Evidence: this edition did not report how its backup ran, so the requirement cannot be verified.', $this->settingsPath ?: null);
        }

        return $this->judge($profile, $facts);
    }

    protected function noBackup(): CheckResult
    {
        return CheckResult::fail('No successful backup is on record.', 'Not met: the backup has never completed successfully.', $this->settingsPath ?: null, ['last_success' => null]);
    }
}
