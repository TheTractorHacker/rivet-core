<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

/**
 * Creates and upgrades the schema of the PostgreSQL data store (phase 2 of #89). SEPARATE from {@see \RivetCore\Migration\CoreMigrations}, which only ever runs
 * on the control database: this one runs on the PostgreSQL database, keeps its own ledger (`rivet_core_pg_migrations`) and settings (`rivet_core_pg_meta`) and is
 * never run implicitly: an administrator or the edition's update procedure calls {@see migrate()}.
 *
 * Guarantees: idempotent (a second run changes nothing); one migration per transaction (PostgreSQL DDL is transactional, so a failed migration leaves nothing
 * behind); serialized by a session advisory lock (two updaters at once run the migrations once); versioned (the ledger records every applied id with a checksum
 * of its statements, and {@see status()} reports an applied id whose statements changed since).
 *
 * The storage mode ({@see PostgresSchema::MODE_NATIVE} or MODE_TIMESCALE) is decided when the first migration runs: `auto` picks TimescaleDB when the extension is
 * installed in the database (`SELECT extname FROM pg_extension`), otherwise native partitioning; it is recorded in `rivet_core_pg_meta` and never changes by itself.
 *
 * @api
 */
final class PostgresMigrator
{
    public const LEDGER = 'rivet_core_pg_migrations';
    public const META = 'rivet_core_pg_meta';
    public const MODE_AUTO = 'auto';
    private const LOCK_KEY = 7_201_089_001;   // an arbitrary constant: "rivet core, #89, 001"

    /** @var list<array{id:string,statements:\Closure(string):list<string>}> */
    private readonly array $migrations;

    /**
     * @param list<array{id:string,statements:\Closure(string):list<string>}>|null $migrations a seam for tests; the default is the shipped list
     */
    public function __construct(private readonly PostgresConnectionInterface $db, ?array $migrations = null)
    {
        $this->migrations = $migrations ?? [
            ['id' => '0001_hot_tables', 'statements' => static fn (string $mode): array => PostgresSchema::statements($mode)],
            // #91 phase 2: the read-only `rpt_` views over the hot tables (CREATE OR REPLACE VIEW; no table is touched)
            ['id' => '0002_reporting_views', 'statements' => static fn (string $mode): array => \RivetCore\Reporting\ReportingSchema::postgresStatements()],
        ];
    }

    /** The id of the newest migration this release ships. */
    public function latest(): string
    {
        return $this->migrations[count($this->migrations) - 1]['id'];
    }

    /**
     * Apply every migration that has not run yet.
     *
     * @param string $mode `auto`, `native` or `timescale`; honoured only when the schema does not exist yet (afterwards the recorded mode wins)
     * @return array{applied:list<string>,mode:string,timescale_available:bool}
     * @throws PostgresException
     */
    public function migrate(string $mode = self::MODE_AUTO): array
    {
        if (!in_array($mode, [self::MODE_AUTO, PostgresSchema::MODE_NATIVE, PostgresSchema::MODE_TIMESCALE], true)) {
            throw new \InvalidArgumentException('Storage mode must be auto, native or timescale.');
        }
        $this->db->value('SELECT pg_advisory_lock(?)', [self::LOCK_KEY]);
        try {
            return $this->migrateLocked($mode);
        } finally {
            try {
                $this->db->value('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
            } catch (PostgresException) {
                // the session is gone, and with it the lock
            }
        }
    }

    /**
     * What is applied and what is pending.
     *
     * @return array{exists:bool,applied:list<string>,pending:list<string>,changed:list<string>,mode:?string,timescale_available:bool,server:string}
     */
    public function status(): array
    {
        $exists = (bool) $this->db->value('SELECT to_regclass(?) IS NOT NULL', [self::LEDGER]);
        $applied = [];
        $sums = [];
        if ($exists) {
            foreach ($this->db->query('SELECT id, checksum FROM ' . self::LEDGER . ' ORDER BY id') as $r) {
                $applied[] = (string) $r['id'];
                $sums[(string) $r['id']] = (string) $r['checksum'];
            }
        }
        $mode = $exists && (bool) $this->db->value('SELECT to_regclass(?) IS NOT NULL', [self::META]) ? $this->storedMode() : null;
        $pending = [];
        $changed = [];
        foreach ($this->migrations as $m) {
            if (!in_array($m['id'], $applied, true)) {
                $pending[] = $m['id'];
            } elseif (isset($sums[$m['id']]) && $sums[$m['id']] !== self::checksum($m['statements']($mode ?? PostgresSchema::MODE_NATIVE))) {
                $changed[] = $m['id'];
            }
        }

        return ['exists' => $exists, 'applied' => $applied, 'pending' => $pending, 'changed' => $changed, 'mode' => $mode, 'timescale_available' => $this->timescaleInstalled(),
            'server' => $this->db->serverVersion()];
    }

    /** Whether the TimescaleDB extension is installed in this database. */
    public function timescaleInstalled(): bool
    {
        foreach ($this->db->query('SELECT extname FROM pg_extension') as $r) {
            if ((string) $r['extname'] === 'timescaledb') {
                return true;
            }
        }

        return false;
    }

    /** The storage mode recorded when the schema was created, or null before that. */
    public function storedMode(): ?string
    {
        $v = $this->db->value('SELECT v FROM ' . self::META . ' WHERE k = ?', ['storage_mode']);

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @return array{applied:list<string>,mode:string,timescale_available:bool}
     */
    private function migrateLocked(string $requested): array
    {
        $ts = $this->timescaleInstalled();
        $this->db->transaction(function (): void {
            $this->db->execute('CREATE TABLE IF NOT EXISTS ' . self::LEDGER . ' (id text PRIMARY KEY, checksum text NOT NULL, applied_at timestamptz NOT NULL DEFAULT now())');
            $this->db->execute('CREATE TABLE IF NOT EXISTS ' . self::META . ' (k text PRIMARY KEY, v text NOT NULL, updated_at timestamptz NOT NULL DEFAULT now())');
        });
        $mode = $this->storedMode();
        if ($mode === null) {
            $mode = $requested === self::MODE_AUTO ? ($ts ? PostgresSchema::MODE_TIMESCALE : PostgresSchema::MODE_NATIVE) : $requested;
            if ($mode === PostgresSchema::MODE_TIMESCALE && !$ts) {
                throw new PostgresQueryException('TimescaleDB mode was requested but the extension is not installed in this database (run CREATE EXTENSION timescaledb as the database owner, or use native mode).');
            }
            $this->db->execute('INSERT INTO ' . self::META . ' (k, v) VALUES (?, ?) ON CONFLICT (k) DO NOTHING', ['storage_mode', $mode]);
        }
        $done = [];
        foreach ($this->migrations as $m) {
            if ($this->db->value('SELECT 1 FROM ' . self::LEDGER . ' WHERE id = ?', [$m['id']]) !== null) {
                continue;
            }
            $statements = $m['statements']($mode);
            $this->db->transaction(function () use ($m, $statements): void {
                foreach ($statements as $sql) {
                    $this->db->execute($sql);
                }
                $this->db->execute('INSERT INTO ' . self::LEDGER . ' (id, checksum) VALUES (?, ?)', [$m['id'], self::checksum($statements)]);
            });
            $done[] = $m['id'];
        }

        return ['applied' => $done, 'mode' => $mode, 'timescale_available' => $ts];
    }

    /** @param list<string> $statements */
    private static function checksum(array $statements): string
    {
        return hash('sha256', implode("\n;\n", $statements));
    }
}
