<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementType;

/**
 * Did a copy of the newest backup reach a place that survives the loss of this server? When the profile also sets a maximum backup interval, the off-site
 * copy must be that recent too (a local backup that is fresh while the upload has been failing for a week is not an off-site backup).
 *
 * @api
 */
final class BackupOffsiteCheck extends AbstractBackupCheck
{
    public function id(): string
    {
        return 'backup_offsite';
    }

    public function title(): string
    {
        return 'A backup copy is stored off the server';
    }

    public function category(): string
    {
        return 'Backup and recovery';
    }

    public function why(): string
    {
        return 'Backups kept only on the server they protect are lost with it (fire, theft, ransomware, a failed disk).';
    }

    public function controls(): array
    {
        return ['iso27001' => ['A.8.13'], 'soc2' => ['A1.2'], 'pci' => ['9.4.1.1'], 'hipaa' => ['164.308(a)(7)(ii)(B)'], 'nist171' => ['3.8.9']];
    }

    public function requirementTypes(): array
    {
        return [RequirementType::BACKUP_OFFSITE];
    }

    protected function judge(FrameworkProfile $profile, BackupFacts $facts): CheckResult
    {
        if ($facts->lastSuccessAt === null) {
            return $this->noBackup();
        }
        if (!$facts->hasOffsite() || $facts->lastOffsiteSuccessAt === null) {
            return CheckResult::fail('No off-site copy of the backup is on record.', 'Not met: the newest successful backup (' . self::utc($facts->lastSuccessAt) . ') was written ' . ($facts->destinations === [] ? 'nowhere that was reported' : 'only to ' . implode(', ', $facts->destinations)) . '.', $this->settingsPath ?: null, ['offsite' => false]);
        }
        $age = $this->ageSeconds($facts->lastOffsiteSuccessAt);
        $m = ['offsite' => true, 'offsite_age_hours' => round($age / 3600, 1)];
        $rpo = $profile->requirement(RequirementType::BACKUP_RPO_MAX)?->value;
        $evidence = 'the newest off-site copy arrived ' . self::utc($facts->lastOffsiteSuccessAt) . ' (' . self::span($age) . ' ago).';
        if ($rpo !== null && $age > $rpo * 3600 + max(3600, intdiv($rpo * 3600, 10))) {
            return CheckResult::fail('The newest off-site copy is ' . self::span($age) . ' old; the limit is ' . $rpo . ' hours.', 'Not met: ' . $evidence, $this->settingsPath ?: null, $m + ['max_hours' => $rpo]);
        }

        return CheckResult::pass('A copy of the backup is stored off the server.', 'Met: ' . $evidence, $m);
    }
}
