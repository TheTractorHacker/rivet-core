<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionException;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresMigrator;
use RivetCore\Rmm\Store\Postgres\PostgresTimeoutException;
use RivetCore\Rmm\Store\Sizes\PostgresStoreSizes;
use RivetCore\Rmm\Support\RmmCache;

/**
 * What an edition shows about the RMM data store on its Update and Server pages, the way it shows the MariaDB version: which store is in use, and for PostgreSQL its
 * version, storage mode, schema state and size. Reached through {@see \RivetCore\Rmm\RmmModule::dataStoreInfo()}; the one-line text for a tile is {@see summaryLine()}.
 * Operator guide: docs/rmm/POSTGRES.md, "Showing the data store on an Update page".
 *
 * Guarantees:
 *  - {@see report()} NEVER throws. A failure is an answer (`reachable = false` and a short `error`).
 *  - On a MariaDB install nothing connects to PostgreSQL and no PostgreSQL query runs, unless the caller passes `$probe = true`. The only reads are the settings row and the saved
 *    connection row of the control database.
 *  - A live attempt on PostgreSQL uses its own session with a connect timeout of 2 seconds and a statement timeout of 2 seconds, behind the same shared circuit breaker as the
 *    data store (after a failure the next calls answer at once for the breaker window), so a dead server cannot stall a page.
 *  - The answer carries no user name, password, sealed value or DSN, and `error` is one of a fixed set of short sentences, never the driver's text (a server message can name the role).
 *  - When the module has a shared cache (Redis) a live answer is kept {@see CACHE_TTL_S} seconds; without one every call asks the server.
 *
 * The array {@see report()} returns (stable; keys are only ever added):
 *
 *   store        'mariadb'|'postgres'  the store the RMM reads and writes (the `rmm_data_store` setting)
 *   active       bool                  the setting is in effect (always true; present so a page can treat both stores alike)
 *   phase        ?string               a data store migration in progress ('copy:postgres', 'dual:mariadb', ...), else null
 *   configured   bool                  a PostgreSQL connection is saved or the edition supplies one
 *   probed       bool                  PostgreSQL was asked just now (always true for store 'postgres'; false for MariaDB unless $probe)
 *   saved        ?array                the saved connection without credentials: host, port, database, tested_at, test_ok, storage_mode, server_version; null when none
 *   postgres     ?array                null when PostgreSQL was not probed, otherwise:
 *     in_use              bool         the RMM data lives here (false when probing from a MariaDB install)
 *     reachable           bool
 *     host, port, database  ?string|?int  where it points (no user); null when unknown
 *     server_version      ?string      '18.6'
 *     server_version_full ?string      the server's own string
 *     version_major, version_minor  ?int
 *     storage_mode        ?string      'native'|'timescale'
 *     timescale_version   ?string      only when the extension is installed
 *     schema              ?array       applied (list), pending (list), changed (list), current (?string, the newest applied id), exists (bool), up_to_date (bool)
 *     database_size_bytes ?int
 *     tables              ?list        [{table, rows, bytes}] and total_rows / total_bytes, only when $tables = true
 *     total_rows, total_bytes  ?int
 *   checked_at   string                UTC 'Y-m-d H:i:s' of the live check (of the cached one when cached)
 *   cached       bool                  the live part came from the cache
 *   error        ?string               short, sanitised; null when nothing is wrong
 *
 * @api
 */
final class DataStoreInfo
{
    public const CACHE_TTL_S = 30;
    public const CACHE_GROUP = 'datastore';
    /** The longest a live check may spend connecting and on each statement. */
    public const PROBE_TIMEOUT_S = 2;

    /** @var \Closure(): array{0:?PostgresConnectionInterface,1:?PostgresConfig} */
    private \Closure $target;

    /**
     * @param \Closure(): array{0:?PostgresConnectionInterface,1:?PostgresConfig} $target the PostgreSQL to ask: [connection, config for display], [null, null] when none is configured; called only when PostgreSQL is probed
     * @param bool $suppliedByEdition the edition passes the connection itself (the `postgres` module option), so a store is configured even without a saved row
     * @param ?RmmCache $cache used only when it is backed by a shared cache; the database-backed variant is never used here
     */
    public function __construct(
        private readonly DataStoreSwitch $switch,
        private readonly RmmSettings $settings,
        \Closure $target,
        private readonly bool $suppliedByEdition = false,
        private readonly ?RmmCache $cache = null,
        private readonly ?\Closure $now = null,
    ) {
        $this->target = $target;
    }

    /**
     * A connection for a status check: the data store's own database and role, but a short connect timeout, a short statement timeout and no retries, behind the circuit
     * breaker the data store shares for the same server (so a recorded outage is believed at once).
     */
    public static function connectionFor(PostgresConfig $c): PostgresConnectionInterface
    {
        $quick = new PostgresConfig($c->database, $c->user, $c->password(), $c->host, $c->port, $c->sslMode, $c->sslRootCert, $c->schema, min($c->connectTimeoutS, self::PROBE_TIMEOUT_S),
            self::PROBE_TIMEOUT_S * 1000, 1000, 'rivetcore-rmm-info', 0, $c->breakerOpenS, min($c->socketTimeoutS === 0 ? 5 : $c->socketTimeoutS, 5));

        return PdoPostgresConnection::fromConfig($quick);
    }

    /**
     * @param bool $probe ask PostgreSQL even though the RMM runs on MariaDB (an administrator looking at a saved connection); ignored for store 'postgres', which is always asked
     * @param bool $tables include the hot tables' rows and bytes (a dozen catalog reads: for a Server page, not for every Update page load)
     * @return array<string,mixed> see the class comment
     */
    public function report(bool $probe = false, bool $tables = false): array
    {
        $now = gmdate('Y-m-d H:i:s', $this->time());
        $out = ['store' => RmmDataStoreKind::MARIADB, 'active' => true, 'phase' => null, 'configured' => false, 'probed' => false, 'saved' => null, 'postgres' => null,
            'checked_at' => $now, 'cached' => false, 'error' => null];
        try {
            $store = $this->settings->dataStore();
            $out['store'] = $store;
            $phase = $this->settings->get()['data_store_phase'] ?? null;
            $out['phase'] = is_string($phase) && $phase !== '' ? mb_substr($phase, 0, 40) : null;
        } catch (\Throwable) {
            $out['error'] = 'the RMM settings could not be read';

            return $out;
        }
        try {
            $saved = $this->switch->connectionSettings();
            if ($saved !== null) {
                $out['saved'] = ['host' => $saved['host'], 'port' => $saved['port'], 'database' => $saved['database'], 'tested_at' => $saved['tested_at'], 'test_ok' => $saved['test_ok'],
                    'storage_mode' => $saved['storage_mode'], 'server_version' => $saved['server_version']];
            }
            $out['configured'] = $saved !== null || $this->suppliedByEdition;
        } catch (\Throwable) {
            // the saved row is informational
        }
        if ($out['store'] !== RmmDataStoreKind::POSTGRES && !$probe) {
            return $out;
        }
        $out['probed'] = true;
        $inUse = $out['store'] === RmmDataStoreKind::POSTGRES;
        try {
            [$conn, $cfg] = ($this->target)();
        } catch (\Throwable $e) {
            $conn = $cfg = null;
        }
        $live = $conn === null ? null : $this->liveOrCached($conn, $cfg, $inUse, $tables);
        if ($live === null) {
            $live = ['postgres' => $this->blank($cfg, $inUse), 'checked_at' => $now, 'cached' => false, 'error' => 'no PostgreSQL connection is configured'];
            if ($cfg === null && $out['saved'] !== null) {
                $live['postgres']['host'] = $out['saved']['host'];
                $live['postgres']['port'] = $out['saved']['port'];
                $live['postgres']['database'] = $out['saved']['database'];
            }
            if ($out['configured']) {
                $live['error'] = 'the saved PostgreSQL connection cannot be used';
            }
        }
        $out['postgres'] = $live['postgres'];
        $out['checked_at'] = $live['checked_at'];
        $out['cached'] = $live['cached'];
        $out['error'] = $live['error'];

        return $out;
    }

    /**
     * The one-line text for a tile. Pure: it reads only the array {@see report()} returned and tolerates a missing key. The text is plain (not HTML): escape it when printing.
     *
     * @param array<string,mixed> $info
     */
    public static function summaryLine(array $info): string
    {
        $store = $info['store'] ?? RmmDataStoreKind::MARIADB;
        $pg = is_array($info['postgres'] ?? null) ? $info['postgres'] : null;
        if ($store !== RmmDataStoreKind::POSTGRES) {
            $configured = !empty($info['configured']);
            $line = 'MariaDB (PostgreSQL ' . ($configured ? 'configured but ' : '') . 'not in use)';
            if ($pg !== null && $configured) {
                $line = 'MariaDB (PostgreSQL configured but not in use; ' . self::pgFacts($pg, $info) . ')';
            }

            return $line;
        }
        if ($pg === null || empty($pg['reachable'])) {
            $why = isset($info['error']) && is_string($info['error']) && $info['error'] !== '' ? $info['error'] : 'no answer';

            return 'PostgreSQL unreachable: ' . $why;
        }

        return 'PostgreSQL ' . self::pgFacts($pg, $info, true);
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string,mixed> $pg */
    private static function pgFacts(array $pg, array $info, bool $withVersion = true): string
    {
        if (empty($pg['reachable'])) {
            return 'unreachable: ' . (is_string($info['error'] ?? null) && $info['error'] !== '' ? $info['error'] : 'no answer');
        }
        $version = is_string($pg['server_version'] ?? null) && $pg['server_version'] !== '' ? $pg['server_version'] : '?';
        $mode = is_string($pg['storage_mode'] ?? null) && $pg['storage_mode'] !== '' ? $pg['storage_mode'] : 'native';
        if ($mode === 'timescale' && is_string($pg['timescale_version'] ?? null) && $pg['timescale_version'] !== '') {
            $mode .= ' ' . $pg['timescale_version'];
        }
        $schema = is_array($pg['schema'] ?? null) ? $pg['schema'] : null;
        if ($schema === null) {
            $state = 'schema state unknown';
        } elseif (empty($schema['exists'])) {
            $state = 'schema not created';
        } else {
            $pending = is_array($schema['pending'] ?? null) ? count($schema['pending']) : 0;
            $changed = is_array($schema['changed'] ?? null) ? count($schema['changed']) : 0;
            $current = is_string($schema['current'] ?? null) ? $schema['current'] : '?';
            $state = 'schema ' . $current . ', ' . ($pending > 0 ? $pending . ' pending' : ($changed > 0 ? $changed . ' changed' : 'up to date'));
        }

        return ($withVersion ? $version . ' ' : '') . '(' . $mode . ') - ' . $state;
    }

    private function time(): int
    {
        return $this->now !== null ? (int) ($this->now)() : time();
    }

    /**
     * @return array{postgres:array<string,mixed>,checked_at:string,cached:bool,error:?string}|null
     */
    private function liveOrCached(PostgresConnectionInterface $conn, ?PostgresConfig $cfg, bool $inUse, bool $tables): ?array
    {
        $compute = function () use ($conn, $cfg, $inUse, $tables): array {
            return $this->live($conn, $cfg, $inUse, $tables);
        };
        $cache = $this->cache !== null && $this->cache->shared() ? $this->cache : null;
        if ($cache === null) {
            return $compute();
        }
        $key = 'info:' . substr(hash('sha256', ($cfg?->identity() ?? 'supplied') . '|' . ($inUse ? '1' : '0') . '|' . ($tables ? '1' : '0')), 0, 32);
        try {
            $before = $cache->stats()['hit'];
            $v = $cache->remember(self::CACHE_GROUP, $key, self::CACHE_TTL_S, 0, $compute);
            if (is_array($v) && isset($v['postgres']) && is_array($v['postgres'])) {
                $v['cached'] = $cache->stats()['hit'] > $before;

                return $v;
            }
        } catch (\Throwable) {
            // a cache problem never hides the answer
        }

        return $compute();
    }

    /**
     * @return array{postgres:array<string,mixed>,checked_at:string,cached:bool,error:?string}
     */
    private function live(PostgresConnectionInterface $db, ?PostgresConfig $cfg, bool $inUse, bool $tables): array
    {
        $pg = $this->blank($cfg, $inUse);
        $error = null;
        $checkedAt = gmdate('Y-m-d H:i:s', $this->time());
        try {
            $full = $db->serverVersion();
            $pg['reachable'] = true;
            $pg['server_version_full'] = mb_substr($full, 0, 120);
            if (preg_match('/^(\d+)(?:\.(\d+))?/', $full, $m) === 1) {
                $pg['version_major'] = (int) $m[1];
                $pg['version_minor'] = isset($m[2]) ? (int) $m[2] : null;
                $pg['server_version'] = $m[1] . (isset($m[2]) ? '.' . $m[2] : '');
            }
            if ($pg['database'] === null) {
                $name = $this->guarded(static fn () => $db->value('SELECT current_database()'));
                $pg['database'] = is_string($name) ? mb_substr($name, 0, 63) : null;
            }
            $ts = $this->guarded(static fn () => $db->value("SELECT extversion FROM pg_extension WHERE extname = 'timescaledb'"));
            $pg['timescale_version'] = is_string($ts) && $ts !== '' ? mb_substr($ts, 0, 20) : null;
            $migrator = new PostgresMigrator($db);
            $st = $this->guarded(static fn () => $migrator->status());
            if (is_array($st)) {
                $applied = $st['applied'];
                $pg['schema'] = ['exists' => $st['exists'], 'applied' => $applied, 'pending' => $st['pending'], 'changed' => $st['changed'], 'current' => $applied === [] ? null : $applied[count($applied) - 1],
                    'up_to_date' => $st['exists'] && $st['pending'] === [] && $st['changed'] === []];
                $pg['storage_mode'] = $st['mode'] ?? ($pg['timescale_version'] !== null ? 'timescale' : 'native');
            } else {
                $pg['storage_mode'] = $pg['timescale_version'] !== null ? 'timescale' : 'native';
            }
            $size = $this->guarded(static fn () => $db->value('SELECT pg_database_size(current_database())'));
            $pg['database_size_bytes'] = is_numeric($size) ? max(0, (int) $size) : null;
            if ($tables) {
                $report = (new PostgresStoreSizes($db))->report();
                if (!empty($report['available'])) {
                    $pg['tables'] = $report['tables'];
                    $pg['total_rows'] = $report['total_rows'];
                    $pg['total_bytes'] = $report['total_bytes'];
                }
            }
        } catch (\Throwable $e) {
            $error = self::describe($e);
            if (!$pg['reachable']) {
                $pg['server_version'] = $pg['server_version_full'] = null;
            }
        }
        try {
            $db->close();
        } catch (\Throwable) {
            // nothing to close
        }

        return ['postgres' => $pg, 'checked_at' => $checkedAt, 'cached' => false, 'error' => $error];
    }

    /**
     * A read that is allowed to fail on its own (a role that cannot read a catalog) without making the server look down. A lost connection still propagates.
     *
     * @template T
     * @param \Closure(): T $read
     * @return T|null
     */
    private function guarded(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (PostgresConnectionException $e) {
            throw $e;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function blank(?PostgresConfig $cfg, bool $inUse): array
    {
        return ['in_use' => $inUse, 'reachable' => false, 'host' => $cfg?->host, 'port' => $cfg?->port, 'database' => $cfg?->database, 'server_version' => null, 'server_version_full' => null,
            'version_major' => null, 'version_minor' => null, 'storage_mode' => null, 'timescale_version' => null, 'schema' => null, 'database_size_bytes' => null];
    }

    /** One of a fixed set of sentences: what the driver or the server said can name the role or the host, so none of it is passed on. */
    private static function describe(\Throwable $e): string
    {
        $m = strtolower($e->getMessage());
        if ($e instanceof PostgresTimeoutException) {
            return 'the server did not answer in time';
        }
        if ($e instanceof PostgresConnectionException || $e instanceof Postgres\PostgresException) {
            return match (true) {
                str_contains($m, 'not trying again') => 'connection attempts are paused after a recent failure'
                    . (preg_match('/(\d+) more seconds/', $m, $x) === 1 ? ' (retry in ' . $x[1] . ' s)' : ''),
                str_contains($m, 'extension') && str_contains($m, 'not installed') => 'the PHP PostgreSQL driver is not installed',
                str_contains($m, 'was configured') || str_contains($m, 'could not be turned into a connection') => 'no PostgreSQL connection is configured',
                str_contains($m, 'refused') => 'connection refused',
                str_contains($m, 'timeout') || str_contains($m, 'timed out') => 'connection timed out',
                str_contains($m, 'translate host') || str_contains($m, 'name or service') || str_contains($m, 'resolve') => 'host name not found',
                str_contains($m, 'authentication') || str_contains($m, 'password') || str_contains($m, 'pg_hba') => 'authentication failed',
                str_contains($m, 'does not exist') => 'database does not exist',
                str_contains($m, 'ssl') || str_contains($m, 'tls') => 'TLS negotiation failed',
                $e instanceof PostgresConnectionException => 'connection failed',
                default => 'the server rejected the request',
            };
        }

        return 'the data store could not be read';
    }
}
