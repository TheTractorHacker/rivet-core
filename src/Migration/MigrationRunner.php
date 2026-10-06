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
        /** Seconds a second runner waits for the first to finish before giving up. */
        private int $lockWaitSeconds = 60,
    ) {
        $ids = array_map(static fn (MigrationInterface $m): string => $m->id(), $migrations);
        if (count($ids) !== count(array_unique($ids))) {
            throw new \InvalidArgumentException('Duplicate Core migration id.');
        }
    }

    /** Name of the server-side lock that makes two simultaneous runs (two web requests, or web plus CLI) take turns. */
    public const LOCK_NAME = 'rivet_core_migrations';


    /**
     * @return list<string> ids applied by this call (empty when already current)
     * @throws \RuntimeException when another runner holds the lock for longer than the wait
     */
    public function run(): array
    {
        $locked = $this->acquireLock();
        try {
            return $this->apply();
        } finally {
            if ($locked) {
                $this->releaseLock();
            }
        }
    }

    /**
     * Every known migration with the time it was applied (null when pending), in id order. Read-only.
     *
     * @return list<array{id:string, applied_at:?string}>
     */
    public function status(): array
    {
        try {
            $rows = $this->database->fetchAll('SELECT migration_id, applied_at FROM ' . self::TABLE);
        } catch (\RivetCore\Database\DatabaseException) {
            $rows = [];
        }
        $applied = [];
        foreach ($rows as $row) {
            $applied[(string) $row['migration_id']] = (string) $row['applied_at'];
        }
        $ids = array_map(static fn (MigrationInterface $m): string => $m->id(), $this->migrations);
        sort($ids);

        return array_map(static fn (string $id): array => ['id' => $id, 'applied_at' => $applied[$id] ?? null], $ids);
    }

    /** True when the lock is held; false when the database cannot provide one (the migrations are idempotent, so we go on). */
    private function acquireLock(): bool
    {
        try {
            $row = $this->database->fetchOne('SELECT GET_LOCK(?, ?) AS got', [self::LOCK_NAME, $this->lockWaitSeconds]);
        } catch (\RivetCore\Database\DatabaseException) {
            return false;
        }
        if ($row === null) {
            return false;
        }
        if ((int) ($row['got'] ?? 0) !== 1) {
            throw new \RuntimeException('Another Core migration run is in progress; try again in a moment.');
        }

        return true;
    }

    private function releaseLock(): void
    {
        try {
            $this->database->fetchOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        } catch (\RivetCore\Database\DatabaseException) {
            // The lock dies with the connection anyway.
        }
    }

    /** @return list<string> */
    private function apply(): array
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
