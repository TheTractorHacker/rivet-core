<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Backup;

/**
 * Decorates an edition's {@see BackupFactsProviderInterface} with evidence from the backup files themselves.
 *
 * The edition's own record says "encrypted"; this checks the newest backup's files (their header, see {@see BackupEncryptionInspector}) and corrects the facts in one
 * direction only for safety: a plaintext file turns `encrypted` into false (and the reason says so). When the files cannot be told apart
 * (unreadable, unknown format) the edition's own value is kept as it was, never upgraded. When the edition reports `encrypted = null` (unknown) and every file is
 * encrypted, it becomes true. An edition that says `false` while the files look encrypted is not overruled. Nothing else in the facts changes, and a provider that
 * says "unavailable" or has no successful backup is passed through untouched. Never throws.
 *
 * @api
 */
final class ArtifactCheckedBackupFactsProvider implements BackupFactsProviderInterface
{
    /**
     * @param \Closure():list<string> $artifactPaths files of the newest successful backup, as the edition knows them (local paths); empty = nothing to check
     */
    public function __construct(
        private readonly BackupFactsProviderInterface $inner,
        private readonly \Closure $artifactPaths,
        private readonly BackupEncryptionInspector $inspector = new BackupEncryptionInspector(),
    ) {
    }

    public function backupFacts(): BackupFacts
    {
        try {
            $facts = $this->inner->backupFacts();
            if (!$facts->available || $facts->lastSuccessAt === null) {
                return $facts;
            }
            $paths = array_values(array_filter(($this->artifactPaths)(), 'is_string'));
            if ($paths === []) {
                return $facts;
            }
            $reports = array_map($this->inspector->inspectFile(...), $paths);
            $plain = array_filter($reports, static fn (BackupEncryptionReport $r): bool => $r->state === BackupEncryptionInspector::PLAINTEXT);
            if ($plain !== []) {
                return $this->with($facts, false, null, trim($facts->reason . ' ' . count($plain) . ' backup file(s) are not encrypted (checked their headers).'));
            }
            $allEncrypted = array_filter($reports, static fn (BackupEncryptionReport $r): bool => $r->isEncrypted()) === $reports;
            if ($allEncrypted && $facts->encrypted !== false) {
                $methods = array_values(array_unique(array_map(static fn (BackupEncryptionReport $r): ?string => $r->method, $reports)));

                return $this->with($facts, true, $facts->encryptionMethod ?? (count($methods) === 1 ? $methods[0] : null), $facts->reason);
            }

            return $facts;
        } catch (\Throwable) {
            return BackupFacts::unavailable('The backup files could not be checked.');
        }
    }

    private function with(BackupFacts $f, bool $encrypted, ?string $method, string $reason): BackupFacts
    {
        return new BackupFacts($f->available, $f->lastSuccessAt, $f->includedStores, $f->destinations, $encrypted, $f->retentionDays, $f->lastOffsiteSuccessAt,
            $encrypted ? $method : null, $f->lastRestoreTest, $f->includedTables, $f->source, mb_substr($reason, 0, 200));
    }
}
