<?php

declare(strict_types=1);

namespace RivetCore\Migration;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;

/**
 * Applies pending Core migrations. Its state lives in `rivet_core_migrations`,
 * independent of RivetIT / RivetMSP database versions. Editions call run()
 * from their own updater after their own migrations. Safe to run repeatedly.
 */
final class MigrationRunner
{
    public const TABLE = 'rivet_core_migrations';

    /** @param list<MigrationInterface> $migrations */
    public function __construct(
        private DatabaseInterface $database,
        private array $migrations,
        private ClockInterface $clock,
    ) {
        $ids = array_map(static fn (MigrationInterface $m): string => $m->id(), $migrations);
        if (count($ids) !== count(array_unique($ids))) {
            throw new \InvalidArgumentException('Duplicate Core migration id.');
        }
    }

    /** @return list<string> ids applied by this call (empty when already current) */
    public function run(): array
    {
        $this->database->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                migration_id VARCHAR(100) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (migration_id)
            )'
        );

        $done = [];
        foreach ($this->database->fetchAll('SELECT migration_id FROM ' . self::TABLE) as $row) {
            $done[(string) $row['migration_id']] = true;
        }

        $pending = array_values(array_filter(
            $this->migrations,
            static fn (MigrationInterface $m): bool => !isset($done[$m->id()])
        ));
        usort($pending, static fn (MigrationInterface $a, MigrationInterface $b): int => strcmp($a->id(), $b->id()));

        $applied = [];
        foreach ($pending as $migration) {
            // DDL auto-commits on MySQL, so no transaction here; every step is idempotent instead.
            $migration->up($this->database);
            $this->database->execute(
                'INSERT INTO ' . self::TABLE . ' (migration_id, applied_at) VALUES (?, ?)',
                [$migration->id(), $this->clock->now()->format('Y-m-d H:i:s')]
            );
            $applied[] = $migration->id();
        }

        return $applied;
    }

    /** @return list<string> ids not yet applied */
    public function pending(): array
    {
        try {
            $rows = $this->database->fetchAll('SELECT migration_id FROM ' . self::TABLE);
        } catch (\RivetCore\Database\DatabaseException) {
            $rows = [];
        }
        $done = array_column($rows, 'migration_id');
        $ids = array_map(static fn (MigrationInterface $m): string => $m->id(), $this->migrations);
        sort($ids);

        return array_values(array_diff($ids, $done));
    }
}
