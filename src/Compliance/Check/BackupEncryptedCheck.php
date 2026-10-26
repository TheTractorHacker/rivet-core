<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementType;

/**
 * Was the newest successful backup encrypted before it left the server?
 *
 * @api
 */
final class BackupEncryptedCheck extends AbstractBackupCheck
{
    public function id(): string
    {
        return 'backup_encrypted';
    }

    public function title(): string
    {
        return 'Backups are encrypted';
    }

    public function category(): string
    {
        return 'Backup and recovery';
    }

    public function why(): string
    {
        return 'A backup holds everything the system holds, including credentials and personal data; unencrypted copies are the easiest way for it to leak.';
    }

    public function controls(): array
    {
        return ['iso27001' => ['A.8.13', 'A.8.24'], 'soc2' => ['CC6.1'], 'pci' => ['3.5.1'], 'hipaa' => ['164.312(a)(2)(iv)'], 'nist171' => ['3.8.9']];
    }

    public function requirementTypes(): array
    {
        return [RequirementType::BACKUP_ENCRYPTED];
    }

    protected function judge(FrameworkProfile $profile, BackupFacts $facts): CheckResult
    {
        if ($facts->lastSuccessAt === null) {
            return $this->noBackup();
        }
        $m = ['encrypted' => $facts->encrypted, 'method' => $facts->encryptionMethod];
        if ($facts->encrypted === true) {
            return CheckResult::pass('The newest backup is encrypted' . ($facts->encryptionMethod !== null ? ' (' . $facts->encryptionMethod . ')' : '') . '.', 'Met: every file of the backup finished ' . self::utc($facts->lastSuccessAt) . ' was encrypted' . ($facts->encryptionMethod !== null ? ' with ' . $facts->encryptionMethod : '') . '.', $m);
        }
        if ($facts->encrypted === false) {
            return CheckResult::fail('The newest backup is not encrypted.', 'Not met: at least one file of the backup finished ' . self::utc($facts->lastSuccessAt) . ' was written without encryption.', $this->settingsPath ?: null, $m);
        }

        return CheckResult::warn('Not available: the edition does not say whether its backup is encrypted.', 'Evidence: no encryption flag was reported for the backup finished ' . self::utc($facts->lastSuccessAt) . '.', $this->settingsPath ?: null, $m);
    }
}
