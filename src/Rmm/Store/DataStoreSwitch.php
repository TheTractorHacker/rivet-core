<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

use RivetCore\Database\DatabaseException;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Store\Migration\MigrationRuns;
use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresException;
use RivetCore\Rmm\Store\Postgres\PostgresMigrator;
use RivetCore\Rmm\Support\Sql;

/**
 * The one supported way to choose PostgreSQL as the RMM data store (phase 3 of #89, docs/design/rmm-data-store.md section 13.1; operator guide docs/rmm/POSTGRES.md).
 *
 * {@see RmmSettings::update()} keeps refusing `postgres` for direct callers: a bare setting would point the module at a database nobody tested. This service stores the
 * connection (the password SEALED with the edition's SecretBox, in its own table, never in the settings row that pages receive), TESTS it, creates or upgrades the schema,
 * and only then writes the setting, all or nothing. Switching back to MariaDB is always allowed.
 *
 * **Switching does not move rows.** History written before the switch stays in the store it was written to and is NOT shown after the switch (a read that merged two stores
 * would have to merge their paging, totals, caps and ledgers, which is exactly where such a merge goes wrong). {@see status()} reports what stayed behind so the edition can
 * show it; {@see RmmStoreMigrator} moves history, verifies it and cuts over. The password is never logged, audited, returned or put in an exception message.
 *
 * @api
 */
final class DataStoreSwitch
{
    public const MIN_POSTGRES_MAJOR = 14;

    /** What the edition must show next to the "Switch" button. */
    public const NO_DATA_MOVE_NOTICE = 'Switching the data store does not move existing history. Everything the RMM recorded so far stays in the current store and is no longer shown; '
        . 'new data is written to the new one. Use "Migrate data" to copy the history first (recommended), or accept that it stays behind. Switching back makes the old history visible again.';

    /** @var \Closure(PostgresConfig): PostgresConnectionInterface */
    private \Closure $connect;
    private readonly bool $defaultConnect;
    private readonly MigrationRuns $runs;

    /**
     * @param ?\Closure(PostgresConfig): PostgresConnectionInterface $connect how a connection is opened (default: the PDO adapter without the circuit breaker, so a test says what is wrong now)
     */
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly SecretBoxInterface $box,
        private readonly ?RmmAuditInterface $audit = null,
        ?\Closure $connect = null,
    ) {
        $this->defaultConnect = $connect === null;
        $this->connect = $connect ?? static fn (PostgresConfig $c): PostgresConnectionInterface => new PdoPostgresConnection($c);
        $this->runs = new MigrationRuns($sql);
    }

    // ------------------------------------------------------------------ the stored connection

    /**
     * The stored connection without its password (`password_set` says whether there is one), or null when none was saved.
     *
     * @return array{host:string,port:int,database:string,user:string,sslmode:string,sslrootcert:?string,schema:string,password_set:bool,storage_mode:?string,server_version:?string,tested_at:?string,test_ok:bool}|null
     */
    public function connectionSettings(): ?array
    {
        $r = $this->row();
        if ($r === null) {
            return null;
        }

        return ['host' => (string) $r['host'], 'port' => (int) $r['port'], 'database' => (string) $r['db_name'], 'user' => (string) $r['db_user'], 'sslmode' => (string) $r['sslmode'],
            'sslrootcert' => $r['sslrootcert'] === null ? null : (string) $r['sslrootcert'], 'schema' => (string) $r['schema_name'], 'password_set' => (string) ($r['password_sealed'] ?? '') !== '',
            'storage_mode' => $r['storage_mode'] === null ? null : (string) $r['storage_mode'], 'server_version' => $r['server_version'] === null ? null : (string) $r['server_version'],
            'tested_at' => $r['tested_at'] === null ? null : (string) $r['tested_at'], 'test_ok' => (int) $r['test_ok'] === 1];
    }

    /**
     * The stored connection as a config (the password unsealed), for the module and the migration. Null when none is stored or the sealed password cannot be read
     * (damaged value or another key): never an empty password in its place.
     */
    public function postgresConfig(): ?PostgresConfig
    {
        $r = $this->row();
        if ($r === null || (string) $r['db_name'] === '') {
            return null;
        }
        try {
            return PostgresConfig::fromSealed($this->configArray($r) + ['password_sealed' => (string) ($r['password_sealed'] ?? '')], $this->box);
        } catch (\Throwable $e) {
            error_log('rmm data store: the stored PostgreSQL connection cannot be used: ' . $this->clean($e->getMessage()));

            return null;
        }
    }

    /**
     * Validate and store a connection; the password is sealed. A blank password keeps the stored one (so the form can be saved without retyping it). Saving never switches the
     * data store and resets the "tested" mark.
     *
     * @param array<string,mixed> $in host, port, database, user, password, sslmode, sslrootcert, schema
     * @return list<string> errors; empty when saved
     */
    public function saveConnection(#[\SensitiveParameter] array $in): array
    {
        try {
            [$cfg, $sealed] = $this->prepare($in);
        } catch (\InvalidArgumentException $e) {
            return [$this->clean($e->getMessage())];
        }
        try {
            $this->store($cfg, $sealed);
        } catch (DatabaseException $e) {
            return ['The connection could not be saved: the database is missing the data store tables (run the Core migrations up to 0044).'];
        }
        $this->note('rmm.data_store.connection', 'PostgreSQL connection settings saved (' . $cfg['host'] . ':' . $cfg['port'] . '/' . $cfg['database'] . ' as ' . $cfg['user'] . ').');

        return [];
    }

    // ------------------------------------------------------------------ the test

    /**
     * Test a connection without changing anything but the "tested" mark of the stored one: connect, server version (14 or newer), UTC, the role can create objects in its schema
     * (tried inside a transaction that is rolled back), TimescaleDB, what the schema looks like. Unsaved values (`$in`) are tested as they are and are NOT stored.
     *
     * @param ?array<string,mixed> $in null = the stored connection
     * @return array{ok:bool,checks:list<array{name:string,ok:bool,message:string}>,warnings:list<string>,server_version:?string,timescale:bool,storage_mode:?string,schema:array{exists:bool,pending:list<string>}}
     */
    public function testConnection(#[\SensitiveParameter] ?array $in = null): array
    {
        $out = ['ok' => false, 'checks' => [], 'warnings' => [], 'server_version' => null, 'timescale' => false, 'storage_mode' => null, 'schema' => ['exists' => false, 'pending' => []]];
        $fail = function (string $name, string $msg) use (&$out): array {
            $out['checks'][] = ['name' => $name, 'ok' => false, 'message' => $this->clean($msg)];

            return $out;
        };
        try {
            if ($in === null) {
                $config = $this->postgresConfig() ?? throw new \InvalidArgumentException('No PostgreSQL connection is saved yet.');
            } else {
                [$arr, $sealed] = $this->prepare($in);
                $config = PostgresConfig::fromSealed($arr + ['password_sealed' => $sealed], $this->box);
            }
        } catch (\InvalidArgumentException $e) {
            return $fail('settings', $e->getMessage());
        }
        if (!extension_loaded('pdo_pgsql') && $this->defaultConnect) {
            return $fail('extension', 'The PHP extension pdo_pgsql is not installed for the PHP that runs this page. Install php-pgsql for the web server PHP and for the command line PHP, then reload PHP-FPM.');
        }
        try {
            $db = ($this->connect)($config);
            $db->value('SELECT 1');
            $out['checks'][] = ['name' => 'connect', 'ok' => true, 'message' => 'Connected to ' . $config->host . ':' . $config->port . ' as ' . $config->user . '.'];
            $version = $db->serverVersion();
            $out['server_version'] = $version;
            $major = (int) $version;
            if ($major < self::MIN_POSTGRES_MAJOR) {
                return $fail('version', 'PostgreSQL ' . $version . ' is too old; ' . self::MIN_POSTGRES_MAJOR . ' or newer is required.') + $out;
            }
            $out['checks'][] = ['name' => 'version', 'ok' => true, 'message' => 'PostgreSQL ' . $version . '.'];
            $tz = (string) $db->value("SELECT current_setting('TimeZone')");
            $off = (string) $db->value("SELECT (EXTRACT(TIMEZONE FROM now()))::int");
            if (!in_array(strtoupper($tz), ['UTC', 'ETC/UTC'], true) || $off !== '0') {
                return $fail('timezone', 'The session is not in UTC (' . $tz . '). The connection sets UTC itself; a pooler in transaction mode or a role setting may be overriding it.') + $out;
            }
            $out['checks'][] = ['name' => 'utc', 'ok' => true, 'message' => 'The session runs in UTC.'];
            $enc = (string) $db->value('SHOW server_encoding');
            if (strtoupper($enc) !== 'UTF8') {
                $out['warnings'][] = 'The database encoding is ' . $enc . ', not UTF8: text outside it cannot be stored.';
            }
            $schema = $config->schema;
            if ($db->value('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$schema]) === null) {
                return $fail('schema', 'The schema "' . $schema . '" does not exist in this database.') + $out;
            }
            $probe = 'rivet_core_probe_' . bin2hex(random_bytes(4));
            try {
                $db->transaction(static function () use ($db, $schema, $probe): void {
                    $db->execute('CREATE TABLE "' . $schema . '"."' . $probe . '" (i integer)');
                    throw new ProbeRollback();
                });
            } catch (ProbeRollback) {
                // the table never existed: the transaction was rolled back, which is the point
            }
            $out['checks'][] = ['name' => 'create', 'ok' => true, 'message' => 'The role can create tables in the schema "' . $schema . '".'];
            if (in_array($db->value('SELECT rolsuper FROM pg_roles WHERE rolname = current_user'), [true, 't', 'true', 1, '1'], true)) {
                $out['warnings'][] = 'The role is a superuser. Use a dedicated role that owns only this database; the RMM needs no superuser rights.';
            }
            $migrator = new PostgresMigrator($db);
            $out['timescale'] = $migrator->timescaleInstalled();
            $st = $migrator->status();
            $out['schema'] = ['exists' => (bool) $st['exists'], 'pending' => array_values($st['pending'])];
            $out['storage_mode'] = $st['mode'] ?? ($out['timescale'] ? 'timescale' : 'native');
            $out['checks'][] = ['name' => 'schema', 'ok' => true, 'message' => $st['exists'] ? ($st['pending'] === [] ? 'The schema is up to date.' : 'The schema exists; ' . count($st['pending']) . ' migration(s) will be applied on switching.')
                : 'The schema will be created on switching (' . ($out['timescale'] ? 'TimescaleDB found: hypertables' : 'native partitioning') . ').'];
            $out['ok'] = true;
        } catch (PostgresException|\InvalidArgumentException|\RuntimeException $e) {
            $out = $fail('connect', $e->getMessage()) + $out;
            $out['ok'] = false;
        }
        if ($in === null) {
            $this->mark($out);
        }

        return $out;
    }

    // ------------------------------------------------------------------ switching

    /**
     * Choose the data store. For `postgres`: test the connection, create or upgrade the schema, then write the setting together with the connection, or change nothing.
     *
     * @param array{acknowledge_no_data_move?:bool,connection?:array<string,mixed>,actor?:string} $options `acknowledge_no_data_move` must be true to switch to `postgres` (the edition's checkbox next to {@see NO_DATA_MOVE_NOTICE}); `connection` is saved together with the switch
     * @return array{ok:bool,errors:list<string>,notice:?string,test:?array<string,mixed>}
     */
    public function switchTo(string $kind, #[\SensitiveParameter] array $options = []): array
    {
        $res = ['ok' => false, 'errors' => [], 'notice' => null, 'test' => null];
        if (!in_array($kind, RmmDataStoreKind::ALL, true)) {
            $res['errors'][] = 'Unknown RMM data store "' . $kind . '".';

            return $res;
        }
        if (!array_key_exists('data_store', $this->settings->get(true))) {
            $res['errors'][] = 'The database is missing the data store column: run the Core migrations (0042 to 0044) first.';

            return $res;
        }
        $current = $this->settings->dataStore();
        $actor = (string) ($options['actor'] ?? '');
        try {
            $active = $this->runs->active();
        } catch (DatabaseException) {
            $res['errors'][] = 'The database is missing the data store tables: run the Core migrations (0042 to 0044) first.';

            return $res;
        }
        if ($kind === RmmDataStoreKind::MARIADB) {
            if ($active !== null) {
                $this->runs->update((int) $active['run_id'], ['state' => MigrationRuns::ABORTED, 'dual_write' => 0, 'last_error' => 'Aborted: the data store was switched back to MariaDB.']);
            }
            if ($current !== RmmDataStoreKind::MARIADB || $active !== null) {
                $this->settings->set(['data_store' => RmmDataStoreKind::MARIADB, 'data_store_phase' => null]);
                $this->runs->create($current, RmmDataStoreKind::MARIADB, 'none', MigrationRuns::SWITCHED_ONLY, $actor);
                $this->note('rmm.data_store.switch', 'RMM data store switched to MariaDB. History written to PostgreSQL meanwhile stays there and is not shown.');
            }

            return ['ok' => true, 'errors' => [], 'notice' => $current === RmmDataStoreKind::POSTGRES ? 'The RMM history is read from MariaDB again. What was recorded in PostgreSQL meanwhile stays there until you migrate it.' : null, 'test' => null];
        }
        if ($current === RmmDataStoreKind::POSTGRES && $active === null) {
            return ['ok' => true, 'errors' => [], 'notice' => null, 'test' => null];
        }
        if ($active !== null) {
            $res['errors'][] = 'A data store migration is in progress (run ' . $active['run_id'] . ', ' . $active['state'] . '). Finish it, or abort it with the migration command, first.';

            return $res;
        }
        if (empty($options['acknowledge_no_data_move'])) {
            $res['errors'][] = self::NO_DATA_MOVE_NOTICE . ' Confirm that you understand this to switch.';

            return $res;
        }
        // 1. validate and test (nothing is stored yet when a connection is passed)
        $given = isset($options['connection']) && is_array($options['connection']) ? $options['connection'] : null;
        $test = $this->testConnection($given);
        $res['test'] = $test;
        if (!$test['ok']) {
            $failed = array_values(array_filter($test['checks'], static fn (array $c): bool => !$c['ok']));
            $res['errors'][] = 'The connection test failed' . ($failed === [] ? '.' : ': ' . $failed[0]['message']);

            return $res;
        }
        // 2. schema (idempotent)
        try {
            if ($given !== null) {
                [$arr, $sealed] = $this->prepare($given);
                $config = PostgresConfig::fromSealed($arr + ['password_sealed' => $sealed], $this->box);
            } else {
                $config = $this->postgresConfig() ?? throw new \InvalidArgumentException('No PostgreSQL connection is saved yet.');
            }
            (new PostgresMigrator(($this->connect)($config)))->migrate(PostgresMigrator::MODE_AUTO);
        } catch (\Throwable $e) {
            $res['errors'][] = 'The PostgreSQL schema could not be created: ' . $this->clean($e->getMessage());

            return $res;
        }
        // 3. the setting, with the connection, in one transaction on the control database
        try {
            $this->sql->database()->transaction(function () use ($given, $test, $actor, $current): void {
                if ($given !== null) {
                    [$arr, $sealed] = $this->prepare($given);
                    $this->store($arr, $sealed);
                }
                $this->mark($test);
                $this->settings->set(['data_store' => RmmDataStoreKind::POSTGRES, 'data_store_phase' => null]);
                $this->runs->create($current, RmmDataStoreKind::POSTGRES, 'none', MigrationRuns::SWITCHED_ONLY, $actor);
            });
        } catch (\Throwable $e) {
            $res['errors'][] = 'The setting could not be saved: ' . $this->clean($e->getMessage());

            return $res;
        }
        $this->note('rmm.data_store.switch', 'RMM data store switched to PostgreSQL (' . ($test['storage_mode'] ?? '?') . ' mode, server ' . ($test['server_version'] ?? '?') . '). Existing history was not moved.');

        return ['ok' => true, 'errors' => [], 'notice' => self::NO_DATA_MOVE_NOTICE, 'test' => $test];
    }

    /**
     * Everything the settings card shows: the active store, the connection (no password), the last test, the newest migration run and what stayed behind.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $out = ['data_store' => $this->settings->dataStore(), 'connection' => null, 'migration' => null, 'history' => [], 'left_behind' => null, 'notice' => self::NO_DATA_MOVE_NOTICE, 'phase' => $this->settings->get()['data_store_phase'] ?? null];
        try {
            $out['connection'] = $this->connectionSettings();
            $cur = $this->runs->current();
            if ($cur !== null) {
                unset($cur['checkpoints']);
                $out['migration'] = $cur;
            }
            $out['history'] = $this->runs->history(10);
            $lb = $this->runs->leftBehind();
            if ($lb !== null) {
                $out['left_behind'] = $lb + ['message' => 'History recorded in ' . self::label($lb['from']) . ' before ' . $lb['at'] . ' UTC stayed there and is not shown. Run the migration to bring it over.'];
            }
        } catch (DatabaseException) {
            // migration 0044 not applied yet: the card shows the bare setting
        }

        return $out;
    }

    public static function label(string $kind): string
    {
        return $kind === RmmDataStoreKind::POSTGRES ? 'PostgreSQL' : 'MariaDB';
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed>|null */
    private function row(): ?array
    {
        try {
            return $this->sql->one('SELECT * FROM rmm_data_store_connection WHERE id = 1');
        } catch (DatabaseException) {
            return null;   // migration 0044 not applied yet
        }
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function configArray(array $r): array
    {
        $c = ['database' => (string) $r['db_name'], 'user' => (string) $r['db_user'], 'host' => (string) $r['host'], 'port' => (int) $r['port'], 'sslmode' => (string) $r['sslmode'], 'schema' => (string) $r['schema_name']];
        if ($r['sslrootcert'] !== null && (string) $r['sslrootcert'] !== '') {
            $c['sslrootcert'] = (string) $r['sslrootcert'];
        }

        return $c;
    }

    /**
     * Validate a form and seal its password.
     *
     * @param array<string,mixed> $in
     * @return array{0:array<string,mixed>,1:string} [the config without a password, the sealed password]
     * @throws \InvalidArgumentException
     */
    private function prepare(#[\SensitiveParameter] array $in): array
    {
        $known = ['host', 'port', 'database', 'user', 'password', 'sslmode', 'sslrootcert', 'schema'];
        $unknown = array_diff(array_keys($in), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown connection setting(s): ' . implode(', ', array_map('strval', $unknown)) . '.');
        }
        $stored = $this->row();
        $pick = static fn (string $k, string $col, mixed $default) => array_key_exists($k, $in) ? $in[$k] : ($stored !== null ? $stored[$col] : $default);
        $cfg = [
            'host' => trim((string) $pick('host', 'host', '127.0.0.1')),
            'port' => (int) $pick('port', 'port', 5432),
            'database' => trim((string) $pick('database', 'db_name', '')),
            'user' => trim((string) $pick('user', 'db_user', '')),
            'sslmode' => trim((string) $pick('sslmode', 'sslmode', 'prefer')),
            'schema' => trim((string) $pick('schema', 'schema_name', 'public')),
        ];
        $root = $pick('sslrootcert', 'sslrootcert', null);
        if ($root !== null && trim((string) $root) !== '') {
            $cfg['sslrootcert'] = trim((string) $root);
        }
        $password = array_key_exists('password', $in) ? (string) $in['password'] : '';
        PostgresConfig::fromArray($cfg + ['password' => $password]);   // validates every field; throws with a message that names the field
        if ($password !== '') {
            return [$cfg, $this->box->encrypt($password)];
        }
        $reuse = $stored !== null ? (string) ($stored['password_sealed'] ?? '') : '';
        if ($reuse !== '') {
            // The stored password may only ever be sent to the target it was saved for. A form that changes where the connection goes must bring its own password, otherwise
            // "Test connection" (or a save) would hand the stored secret to whatever host was typed in.
            if ($cfg['host'] !== (string) $stored['host'] || $cfg['port'] !== (int) $stored['port'] || $cfg['user'] !== (string) $stored['db_user'] || $cfg['database'] !== (string) $stored['db_name']) {
                throw new \InvalidArgumentException('Enter the password again when changing the host, port, user or database.');
            }
            if (!self::isLocalHost($cfg['host']) && !in_array($cfg['sslmode'], ['verify-full', 'verify-ca'], true)) {
                throw new \InvalidArgumentException('The stored password is only sent to a remote host over a verified TLS connection: set sslmode to verify-full (or verify-ca), or enter the password again.');
            }
        }

        return [$cfg, $reuse];
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function store(array $cfg, string $sealed): void
    {
        $now = gmdate('Y-m-d H:i:s', $this->sql->clock()->now()->getTimestamp());
        $this->sql->run('INSERT INTO rmm_data_store_connection (id, host, port, db_name, db_user, password_sealed, sslmode, sslrootcert, schema_name, test_ok, updated_at) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE host = VALUES(host), port = VALUES(port), db_name = VALUES(db_name), db_user = VALUES(db_user), password_sealed = VALUES(password_sealed), sslmode = VALUES(sslmode),
            sslrootcert = VALUES(sslrootcert), schema_name = VALUES(schema_name), test_ok = 0, tested_at = NULL, updated_at = VALUES(updated_at)',
            [$cfg['host'], $cfg['port'], $cfg['database'], $cfg['user'], $sealed === '' ? null : $sealed, $cfg['sslmode'], $cfg['sslrootcert'] ?? null, $cfg['schema'], $now]);
    }

    /** @param array<string,mixed> $test */
    private function mark(array $test): void
    {
        try {
            $this->sql->run('UPDATE rmm_data_store_connection SET tested_at = ?, test_ok = ?, storage_mode = ?, server_version = ? WHERE id = 1',
                [gmdate('Y-m-d H:i:s', $this->sql->clock()->now()->getTimestamp()), $test['ok'] ? 1 : 0, $test['storage_mode'] ?? null, $test['server_version'] ?? null]);
        } catch (DatabaseException) {
            // the mark is informational
        }
    }

    private function note(string $action, string $text): void
    {
        try {
            $this->audit?->record($action, $text, 0, 0);
        } catch (\Throwable) {
            // an audit failure never undoes the switch
        }
    }

    private static function isLocalHost(string $host): bool
    {
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $host === '[::1]' || str_starts_with($host, '/');
    }

    /** Messages come from the driver (the server's primary message only) and from our own validation; never let a password ride along in one. */
    private function clean(string $m): string
    {
        $m = preg_replace('/password=\S+/i', 'password=***', $m) ?? $m;

        return mb_substr($m, 0, 400);
    }
}
