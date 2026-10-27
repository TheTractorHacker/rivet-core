<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Backup;

/**
 * A restore test the edition ran itself (a scheduled test restore). Tests a person records in the compliance module are read from
 * {@see RestoreTestStore}; the edition can also pass its own through {@see BackupFacts::$lastRestoreTest}, and the check uses the newest of both.
 *
 * @api
 */
final readonly class RestoreTestFact
{
    /**
     * @param list<string> $stores {@see BackupStore} ids that were restored
     * @param bool|null $rowCountsMatch true when row counts of the restored copy equal those of the source, false when they differ, null when none were compared
     */
    public function __construct(public \DateTimeImmutable $testedAt, public bool $passed, public array $stores = [], public ?bool $rowCountsMatch = null)
    {
        foreach ($stores as $s) {
            if (!BackupStore::isValid($s)) {
                throw new \InvalidArgumentException('Unknown backup store in a restore test fact.');
            }
        }
        if ($passed && $rowCountsMatch === false) {
            throw new \InvalidArgumentException('A restore test cannot pass when its row counts differ.');
        }
    }
}
