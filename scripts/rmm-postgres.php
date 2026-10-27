#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Create, upgrade and check the PostgreSQL schema of the RMM data store (phase 2 of #89, docs/rmm/POSTGRES.md). It runs against the PostgreSQL database only; it never
 * touches the MariaDB control database and it never decides which store an installation uses.
 *
 *   php scripts/rmm-postgres.php status  --config=/etc/rivetcore/rmm-postgres.json
 *   php scripts/rmm-postgres.php migrate --config=/etc/rivetcore/rmm-postgres.json [--mode=auto|native|timescale]
 *
 * The connection comes from a JSON file with the keys of PostgresConfig::fromArray() (database, user, password, host, port, sslmode, ...) that only the service account
 * can read, or from the environment (RIVETCORE_PG_DATABASE, _USER, _PASSWORD, _HOST, _PORT, _SSLMODE, _SCHEMA). The password is never taken from the command line,
 * where every user of the machine could read it, and is never printed.
 *
 * Exit status: 0 done / nothing to do, 1 migrations pending (status) or a failure, 2 usage or configuration error.
 */

use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Rmm\Store\Postgres\PostgresException;
use RivetCore\Rmm\Store\Postgres\PostgresMigrator;

$autoload = [__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'];
foreach ($autoload as $file) {
    if (is_file($file)) {
        require $file;
        break;
    }
}
if (!class_exists(PostgresConfig::class)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(2);
}

$cmd = $argv[1] ?? '';
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/D', $a, $m) === 1) {
        $opts[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "Unknown argument: $a\n");
        exit(2);
    }
}
if (!in_array($cmd, ['status', 'migrate'], true) || array_diff(array_keys($opts), ['config', 'mode']) !== []) {
    fwrite(STDERR, "Usage: php scripts/rmm-postgres.php status|migrate [--config=FILE] [--mode=auto|native|timescale]\n");
    exit(2);
}
try {
    if (isset($opts['config'])) {
        $raw = is_file($opts['config']) ? file_get_contents($opts['config']) : false;
        $cfg = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($cfg)) {
            throw new InvalidArgumentException('The config file is missing or is not a JSON object.');
        }
    } else {
        $cfg = [];
        foreach (['database', 'user', 'password', 'host', 'port', 'sslmode', 'schema'] as $k) {
            $v = getenv('RIVETCORE_PG_' . strtoupper($k));
            if (is_string($v) && $v !== '') {
                $cfg[$k] = $v;
            }
        }
    }
    $config = PostgresConfig::fromArray($cfg + ['sslmode' => 'prefer']);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'Configuration error: ' . $e->getMessage() . "\n");
    exit(2);
}

$db = new PdoPostgresConnection($config);
$migrator = new PostgresMigrator($db);
try {
    if ($cmd === 'status') {
        $s = $migrator->status();
        printf("server: %s\nschema: %s\nstorage mode: %s\nTimescaleDB installed: %s\napplied: %s\npending: %s\n", $s['server'], $s['exists'] ? 'present' : 'not created', $s['mode'] ?? '-', $s['timescale_available'] ? 'yes' : 'no',
            implode(', ', $s['applied']) ?: '-', implode(', ', $s['pending']) ?: '-');
        if ($s['changed'] !== []) {
            printf("WARNING: applied migrations whose statements changed since: %s\n", implode(', ', $s['changed']));
        }
        exit($s['pending'] === [] && $s['exists'] ? 0 : 1);
    }
    $r = $migrator->migrate($opts['mode'] ?? PostgresMigrator::MODE_AUTO);
    printf("storage mode: %s\napplied now: %s\n", $r['mode'], implode(', ', $r['applied']) ?: 'nothing, the schema is up to date');
    exit(0);
} catch (PostgresException|InvalidArgumentException $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
