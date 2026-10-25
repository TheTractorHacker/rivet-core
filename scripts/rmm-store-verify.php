#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reconcile the control database with the hot RMM store, typically right after a restore (#89 phase 3, docs/rmm/POSTGRES.md). READ-ONLY.
 *
 *   php scripts/rmm-store-verify.php --bootstrap=/etc/rivetcore/rmm-cli.php [--json] [--gap=3600] [--examples=10]
 *
 * The bootstrap file is the edition's (see RivetCore\Rmm\Store\Cli\CliBootstrap): it returns the control database, the clock and, to read the PostgreSQL connection
 * stored by the data store setting, the SecretBox (or an explicit 'postgres' connection). Which store is checked is the one the control database names.
 *
 * Exit status: 0 nothing found, 1 findings (orphans, ledger drift, rollup contradictions, a hot store older than the control database), 2 usage or setup error.
 */

use RivetCore\Rmm\Store\Cli\CliBootstrap;
use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Store\Verify\StoreVerifier;

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $file) {
    if (is_file($file)) {
        require $file;
        break;
    }
}
if (!class_exists(StoreVerifier::class)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(2);
}

$opts = ['json' => false, 'gap' => 3600, 'examples' => 10];
$bootstrap = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--json') {
        $opts['json'] = true;
    } elseif (preg_match('/^--bootstrap=(.+)$/D', $a, $m) === 1) {
        $bootstrap = $m[1];
    } elseif (preg_match('/^--(gap|examples)=(\d{1,9})$/D', $a, $m) === 1) {
        $opts[$m[1]] = (int) $m[2];
    } else {
        fwrite(STDERR, "Unknown argument: $a\n");
        exit(2);
    }
}
if ($bootstrap === null) {
    fwrite(STDERR, "Usage: php scripts/rmm-store-verify.php --bootstrap=FILE [--json] [--gap=SECONDS] [--examples=N]\n");
    exit(2);
}

try {
    $b = CliBootstrap::load($bootstrap);
    $kind = (string) ($b->database->fetchOne('SELECT data_store FROM endpoint_agent_settings WHERE id = 1')['data_store'] ?? RmmDataStoreKind::MARIADB);
    $pg = null;
    if ($kind === RmmDataStoreKind::POSTGRES) {
        $pg = $b->postgres;
        if ($pg === null) {
            $row = $b->database->fetchOne('SELECT * FROM rmm_data_store_connection WHERE id = 1');
            if ($row === null || $b->secretBox === null) {
                throw new InvalidArgumentException('The store is "postgres": the bootstrap must give a "postgres" connection, or a "secretBox" so the stored connection can be used.');
            }
            $cfg = ['host' => (string) $row['host'], 'port' => (int) $row['port'], 'database' => (string) $row['db_name'], 'user' => (string) $row['db_user'], 'sslmode' => (string) $row['sslmode'],
                'schema' => (string) $row['schema_name'], 'password_sealed' => (string) ($row['password_sealed'] ?? '')];
            if (($row['sslrootcert'] ?? null) !== null && $row['sslrootcert'] !== '') {
                $cfg['sslrootcert'] = (string) $row['sslrootcert'];
            }
            $pg = PdoPostgresConnection::fromConfig(PostgresConfig::fromSealed($cfg, $b->secretBox));
        }
    }
    $report = (new StoreVerifier($b->database, $b->clock, $pg, $kind))->run($opts['gap'], $opts['examples']);
} catch (InvalidArgumentException|RuntimeException $e) {
    fwrite(STDERR, 'Setup error: ' . $e->getMessage() . "\n");
    exit(2);
}

if ($opts['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    printf("RMM data store: %s, checked %s UTC\n", $report['kind'], $report['checked_at']);
    foreach ($report['findings'] as $f) {
        printf("[%s] %s %s: %s\n", $f['severity'], $f['code'], $f['table'] ?? '', $f['detail']);
    }
    echo $report['clean'] ? "Clean: the control database and the hot store agree.\n" : count($report['findings']) . " finding(s).\n";
}
exit($report['clean'] ? 0 : 1);
