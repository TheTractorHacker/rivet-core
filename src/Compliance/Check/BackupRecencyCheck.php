<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementType;

/**
 * Is the newest successful backup no older than the profile's maximum interval (the RPO, in hours)? A backup that is late by less than 10% of the interval
 * (at least one hour) is a warning, so a nightly job does not flash red in the minutes before its run finishes.
 *
 * @api
 */
final class BackupRecencyCheck extends AbstractBackupCheck
{
    public function id(): string
    {
        return 'backup_recency';
    }

    public function title(): string
    {
        return 'Backups are recent enough (RPO)';
    }

    public function category(): string
    {
        return 'Backup and recovery';
    }

    public function why(): string
    {
        return 'The age of the newest backup is the data you would lose in a disaster. The framework sets how old it may be.';
    }

    public function controls(): array
    {
        return self::BACKUP_CONTROLS;
    }

    public function requirementTypes(): array
    {
        return [RequirementType::BACKUP_RPO_MAX];
    }

    protected function judge(FrameworkProfile $profile, BackupFacts $facts): CheckResult
    {
        if ($facts->lastSuccessAt === null) {
            return $this->noBackup();
        }
        $hours = $profile->requirement(RequirementType::BACKUP_RPO_MAX)->value ?? 24;
        $limit = $hours * 3600;
        $age = $this->ageSeconds($facts->lastSuccessAt);
        $m = ['age_hours' => round($age / 3600, 1), 'max_hours' => $hours];
        $evidence = 'Evidence: the newest successful backup finished ' . self::utc($facts->lastSuccessAt) . ' (' . self::span($age) . ' ago); the framework allows ' . $hours . ' hours.';
        if ($age <= $limit) {
            return CheckResult::pass('The newest backup is ' . self::span($age) . ' old (limit ' . $hours . ' hours).', 'Met: ' . substr($evidence, 10), $m);
        }
        if ($age <= $limit + max(3600, intdiv($limit, 10))) {
            return CheckResult::warn('The newest backup is ' . self::span($age) . ' old, just past the ' . $hours . ' hour limit.', "Not met yet: the next backup is overdue.\n" . $evidence, $this->settingsPath ?: null, $m);
        }

        return CheckResult::fail('The newest backup is ' . self::span($age) . ' old; the limit is ' . $hours . ' hours.', "Not met: the backup is too old.\n" . $evidence, $this->settingsPath ?: null, $m);
    }
}
