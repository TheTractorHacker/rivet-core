<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Compliance\Backup\BackupStore;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementsProviderInterface;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Contracts\ClockInterface;

/**
 * Does the newest successful backup contain every data store in use: the control database, and the PostgreSQL RMM store when that is the RMM data store?
 * The active store comes from `$dataStoreKind` (for example `fn () => $module->settings()->dataStore()`), read when the check runs.
 *
 * @api
 */
final class BackupCoverageCheck extends AbstractBackupCheck
{
    /** @param \Closure(): string $dataStoreKind the active RMM data store: `mariadb` or `postgres` */
    public function __construct(RequirementsProviderInterface $profiles, BackupFactsProviderInterface $facts, ClockInterface $clock, private \Closure $dataStoreKind, string $settingsPath = '')
    {
        parent::__construct($profiles, $facts, $clock, $settingsPath);
    }

    public function id(): string
    {
        return 'backup_coverage';
    }

    public function title(): string
    {
        return 'Backups cover every data store';
    }

    public function category(): string
    {
        return 'Backup and recovery';
    }

    public function why(): string
    {
        return 'A backup that leaves out a database cannot restore the system: the hot RMM history lives in PostgreSQL when that data store is chosen, apart from the control database.';
    }

    public function controls(): array
    {
        return self::BACKUP_CONTROLS;
    }

    public function requirementTypes(): array
    {
        return [RequirementType::BACKUP_COVERS_ALL_STORES];
    }

    protected function judge(FrameworkProfile $profile, BackupFacts $facts): CheckResult
    {
        if ($facts->lastSuccessAt === null) {
            return $this->noBackup();
        }
        $kind = ($this->dataStoreKind)();
        $required = BackupStore::required($kind);
        $missing = array_values(array_diff($required, $facts->includedStores));
        $lines = ['Evidence: the RMM data store is ' . ($kind === 'postgres' ? 'PostgreSQL' : 'MariaDB') . '; the newest successful backup (' . self::utc($facts->lastSuccessAt) . ') contains: ' . ($facts->includedStores === [] ? 'nothing reported' : implode(', ', array_map(static fn (string $s): string => BackupStore::LABELS[$s], $facts->includedStores))) . '.'];
        $m = ['rmm_data_store' => $kind, 'required' => implode(',', $required), 'included' => implode(',', $facts->includedStores)];
        foreach ($required as $s) {
            $lines[] = (in_array($s, $missing, true) ? 'Not met: ' : 'Met: ') . BackupStore::LABELS[$s] . (in_array($s, $missing, true) ? ' is not in the backup.' : ' is in the backup.');
        }
        if ($missing === []) {
            return CheckResult::pass('Every data store in use is in the backup.', self::lines($lines), $m);
        }

        return CheckResult::fail('The backup does not contain: ' . implode(', ', array_map(static fn (string $s): string => BackupStore::LABELS[$s], $missing)) . '.', self::lines($lines), $this->settingsPath ?: null, $m);
    }
}
