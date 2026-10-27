<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Backup;

/**
 * One recorded restore test, as read back. Plain values.
 *
 * @api
 */
final readonly class RestoreTestRecord
{
    /**
     * @param list<string> $stores {@see BackupStore} ids that were restored
     * @param list<array{store:string, table:string, expected:int, restored:int}> $counts row counts compared after the restore
     * @param array{kind:string, checked_at:string, clean:bool, finding_codes:array<string,int>, table_counts:array<string,int>}|null $verify the reconciliation of scripts/rmm-store-verify.php
     */
    public function __construct(
        public int $id,
        public \DateTimeImmutable $testedAt,
        public \DateTimeImmutable $recordedAt,
        public string $recordedByName,
        public bool $passed,
        public array $stores,
        public string $dataStore,
        public ?\DateTimeImmutable $backupTakenAt,
        public ?int $durationS,
        public array $counts,
        public ?array $verify,
        public ?string $note,
    ) {
    }

    public function countsCompared(): bool
    {
        return $this->counts !== [];
    }

    public function countsMatch(): bool
    {
        foreach ($this->counts as $c) {
            if ($c['expected'] !== $c['restored']) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{store:string, table:string, expected:int, restored:int}> only the rows that differ */
    public function mismatches(): array
    {
        return array_values(array_filter($this->counts, static fn (array $c): bool => $c['expected'] !== $c['restored']));
    }
}
