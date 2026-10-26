<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\DataClass;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementType;

/**
 * Does the edition keep backup sets at least as long as the profile's minimum retention for backups?
 *
 * @api
 */
final class BackupRetentionCheck extends AbstractBackupCheck
{
    public function id(): string
    {
        return 'backup_retention';
    }

    public function title(): string
    {
        return 'Backups are kept long enough';
    }

    public function category(): string
    {
        return 'Backup and recovery';
    }

    public function why(): string
    {
        return 'A contract or standard that requires records for years is not met if the backups that hold them are deleted after weeks.';
    }

    public function controls(): array
    {
        return ['iso27001' => ['A.8.13'], 'hipaa' => ['164.316(b)(2)(i)']];
    }

    public function requirementTypes(): array
    {
        return [RequirementType::RETENTION_MIN];
    }

    public function appliesTo(FrameworkProfile $profile): bool
    {
        return $profile->requirement(RequirementType::RETENTION_MIN, DataClass::BACKUPS) !== null;
    }

    protected function judge(FrameworkProfile $profile, BackupFacts $facts): CheckResult
    {
        $need = $profile->retentionMin(DataClass::BACKUPS);
        $m = ['required_days' => $need, 'retention_days' => $facts->retentionDays];
        if ($facts->retentionDays === null) {
            return CheckResult::warn('Not available: the edition does not say how long it keeps backups.', 'Evidence: no backup retention was reported; the framework requires ' . $need . ' days.', $this->settingsPath ?: null, $m);
        }
        if ($facts->retentionDays === 0) {
            return CheckResult::pass('Backups are kept without a time limit.', 'Met: no deletion of old backups is configured (requirement ' . $need . ' days).', $m);
        }
        if ($facts->retentionDays >= $need) {
            return CheckResult::pass('Backups are kept ' . $facts->retentionDays . ' days (required ' . $need . ').', 'Met: backup sets are kept ' . $facts->retentionDays . ' days.', $m);
        }

        return CheckResult::fail('Backups are kept ' . $facts->retentionDays . ' days; the framework requires ' . $need . '.', 'Not met: backup sets older than ' . $facts->retentionDays . ' days are deleted.', $this->settingsPath ?: null, $m);
    }
}
