<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Compliance\Backup\BackupStore;

/**
 * Backup facts held in memory, or with a named flaw: future (success dated tomorrow), claims_without_success (stores listed although none succeeded),
 * unavailable_claims (unavailable but still lists a store), offsite_without_destination, secret_text, unstable (differs per call), throws.
 */
final class InMemoryBackupFacts implements BackupFactsProviderInterface
{
    private int $calls = 0;

    public function __construct(private ?string $flaw = null, private bool $withBackup = true)
    {
    }

    public function backupFacts(): BackupFacts
    {
        ++$this->calls;
        if ($this->flaw === 'throws') {
            throw new \RuntimeException('backup log unreadable');
        }
        $now = new \DateTimeImmutable('-2 hours');
        if ($this->flaw === 'future') {
            return new BackupFacts(true, new \DateTimeImmutable('+1 day'), [BackupStore::CONTROL_DB], ['local'], true, 30);
        }
        if ($this->flaw === 'claims_without_success') {
            return new BackupFacts(true, null, [BackupStore::CONTROL_DB], ['local'], true, 30);
        }
        if ($this->flaw === 'unavailable_claims') {
            return new BackupFacts(false, null, [BackupStore::CONTROL_DB]);
        }
        if ($this->flaw === 'offsite_without_destination') {
            return new BackupFacts(true, $now, [BackupStore::CONTROL_DB], ['local'], true, 30, $now);
        }
        if ($this->flaw === 'secret_text') {
            return new BackupFacts(true, $now, [BackupStore::CONTROL_DB], ['local'], true, 30, null, null, null, [], 'backup.sh --passphrase=correct-horse-battery');
        }
        if ($this->flaw === 'unstable') {
            return new BackupFacts(true, $now->modify('-' . $this->calls . ' minutes'), [BackupStore::CONTROL_DB], ['local'], true, 30);
        }
        if (!$this->withBackup) {
            return new BackupFacts(true);
        }

        return new BackupFacts(true, $now, [BackupStore::CONTROL_DB, BackupStore::RMM_POSTGRES], ['local', 'offsite'], true, 400, $now, 'age', null, ['audit_events'], 'deploy/backup.sh');
    }
}
