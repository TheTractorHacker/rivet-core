<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

use RivetCore\Rmm\Store\Postgres\PostgresConfig;

/**
 * What an edition's backup has to include for the RMM data store (#89, docs/rmm/POSTGRES.md). Core cannot own the edition's `deploy/backup.sh` or its in-app backup,
 * so it describes the requirement and builds the exact command; the edition runs it and wraps the output in its own encryption.
 *
 *  - MariaDB store: nothing extra. The hot tables are in the control database, which the edition already backs up.
 *  - PostgreSQL store: a second database. {@see describe()} says which one (never the password) and {@see command()} is the dump to run: it writes a `pg_dump` custom
 *    format stream to STDOUT (`scripts/rmm-postgres-backup.sh`), so `... | age -r KEY > rmm-pg.dump.age` works. The password goes to the process by `PGPASSWORD`
 *    or `PGPASSFILE` only; this class neither takes nor returns it.
 *
 * @api
 */
final class BackupHook
{
    /** The script that dumps the PostgreSQL store, relative to the package root. */
    public const DUMP_SCRIPT = 'scripts/rmm-postgres-backup.sh';
    /** The script that restores it from STDIN. */
    public const RESTORE_SCRIPT = 'scripts/rmm-postgres-restore.sh';

    /**
     * @param ?PostgresConfig $config the connection of the PostgreSQL store; required when the kind is `postgres`
     * @return array{kind:string,needs_dump:bool,connection:?array{host:string,port:int,database:string,user:string,schema:string,sslmode:string},env:array<string,string>,password_env:list<string>,notes:string}
     * @throws \InvalidArgumentException for an unknown kind, or `postgres` without a connection
     */
    public static function describe(string $kind, ?PostgresConfig $config = null): array
    {
        if (!in_array($kind, RmmDataStoreKind::ALL, true)) {
            throw new \InvalidArgumentException('Unknown RMM data store "' . $kind . '".');
        }
        if ($kind === RmmDataStoreKind::MARIADB) {
            return ['kind' => $kind, 'needs_dump' => false, 'connection' => null, 'env' => [], 'password_env' => [],
                'notes' => 'The hot RMM tables are in the control database; the existing database backup covers them.'];
        }
        if ($config === null) {
            throw new \InvalidArgumentException('The PostgreSQL connection is required to describe the backup of the "postgres" store.');
        }

        return [
            'kind' => $kind,
            'needs_dump' => true,
            'connection' => ['host' => $config->host, 'port' => $config->port, 'database' => $config->database, 'user' => $config->user, 'schema' => $config->schema, 'sslmode' => $config->sslMode],
            'env' => [
                'RIVETCORE_PG_DATABASE' => $config->database,
                'RIVETCORE_PG_USER' => $config->user,
                'RIVETCORE_PG_HOST' => $config->host,
                'RIVETCORE_PG_PORT' => (string) $config->port,
                'RIVETCORE_PG_SSLMODE' => $config->sslMode,
                'RIVETCORE_PG_SCHEMA' => $config->schema,
            ],
            'password_env' => ['PGPASSWORD', 'PGPASSFILE'],
            'notes' => 'Back up this PostgreSQL database in the same run and to the same encrypted destination as the control database. Set PGPASSWORD (or PGPASSFILE) for the '
                . 'process yourself; it is not part of this descriptor. Restore the control database first, then the RMM store, then run scripts/rmm-store-verify.php.',
        ];
    }

    /**
     * The dump command. Run it with {@see describe()}'s `env` set (plus the password variable) and read the dump from its STDOUT.
     *
     * @param string $packageRoot where rivet-core is installed (for example `vendor/rivet/rivet-core`), without a trailing slash
     * @return array{argv:list<string>,string:string,env:array<string,string>}
     * @throws \InvalidArgumentException as {@see describe()}, and for the `mariadb` store (there is nothing to dump)
     */
    public static function command(string $kind, ?PostgresConfig $config, string $packageRoot = 'vendor/rivet/rivet-core'): array
    {
        $d = self::describe($kind, $config);
        if (!$d['needs_dump']) {
            throw new \InvalidArgumentException('The "mariadb" store needs no separate dump.');
        }
        $script = rtrim($packageRoot, '/') . '/' . self::DUMP_SCRIPT;
        $env = $d['env'];
        $prefix = '';
        foreach ($env as $k => $v) {
            $prefix .= $k . '=' . escapeshellarg($v) . ' ';
        }

        return ['argv' => [$script], 'string' => $prefix . escapeshellarg($script), 'env' => $env];
    }
}
