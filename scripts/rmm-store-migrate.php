#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Move the RMM history between MariaDB and PostgreSQL, verify it, cut over, roll back, purge (phase 3 of #89; docs/rmm/POSTGRES.md section "Migrate").
 *
 *   php scripts/rmm-store-migrate.php --bootstrap=/etc/rivetit/rmm-store-bootstrap.php --from=mariadb --to=postgres --dry-run
 *   php scripts/rmm-store-migrate.php --bootstrap=... --from=mariadb --to=postgres [--batch=2000] [--pause-ms=100] [--sample=50|all|0] [--settle=5] [--verify-lag=10] [--no-cutover] [--actor=name]
 *   php scripts/rmm-store-migrate.php --bootstrap=... --to=postgres --resume              continue a stopped run (add --from as in the first call)
 *   php scripts/rmm-store-migrate.php --bootstrap=... --to=postgres --resume --cutover     finish a run that stopped after verification (--no-cutover)
 *   php scripts/rmm-store-migrate.php --bootstrap=... --rollback [--force]                 go back within the rollback period (copies back what was written since)
 *   php scripts/rmm-store-migrate.php --bootstrap=... --abort
 *   php scripts/rmm-store-migrate.php --bootstrap=... --purge-old --yes [--force]          delete the history from the store that is NOT active
 *   php scripts/rmm-store-migrate.php --bootstrap=... --status
 *
 * The bootstrap file is the edition's small PHP file (see RivetCore\Rmm\Store\Cli\CliBootstrap) that returns the control database, the clock, the SecretBox and optionally
 * the audit sink. The PostgreSQL connection is the one the data store setting STORED (password unsealed with the SecretBox) or the bootstrap's own 'postgres' entry; it is
 * never taken from the command line, so the stored password cannot be sent to a host typed here.
 *
 * Output: one JSON object per line (progress events), then one last line {"summary": ...}. Nothing secret is printed. Exit status: 0 done, 1 failed or refused, 2 usage.
 */

use RivetCore\Rmm\Store\Cli\CliBootstrap;
use RivetCore\Rmm\Store\DataStoreSwitch;
use RivetCore\Rmm\Store\Migration\MigrationException;
use RivetCore\Rmm\Store\Migration\RmmStoreMigrator;
use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Testing\InMemoryRmmBridge;
use RivetCore\Testing\InMemorySecretBox;

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $file) {
    if (is_file($file)) {
        require $file;
        break;
    }
}
if (!class_exists(RmmStoreMigrator::class)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(2);
}

$flags = ['dry-run', 'resume', 'cutover', 'no-cutover', 'rollback', 'abort', 'purge-old', 'status', 'yes', 'force'];
$values = ['bootstrap', 'from', 'to', 'batch', 'pause-ms', 'sample', 'settle', 'verify-lag', 'actor'];
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $a, $m) !== 1 || !in_array($m[1], [...$flags, ...$values], true) || (in_array($m[1], $values, true) && !isset($m[2])) || (in_array($m[1], $flags, true) && isset($m[2]))) {
        fwrite(STDERR, "Unknown or malformed argument: $a\n");
        exit(2);
    }
    $opt[$m[1]] = $m[2] ?? true;
}
if (!isset($opt['bootstrap'])) {
    fwrite(STDERR, "--bootstrap=FILE is required (see the header of this script).\n");
    exit(2);
}
$modes = array_values(array_filter(['rollback', 'abort', 'purge-old', 'status'], static fn (string $k): bool => isset($opt[$k])));
if (count($modes) > 1 || ($modes === [] && !isset($opt['to']))) {
    fwrite(STDERR, "Choose --to=mariadb|postgres (a migration) or exactly one of --rollback --abort --purge-old --status.\n");
    exit(2);
}

try {
    $boot = CliBootstrap::load((string) $opt['bootstrap']);
} catch (InvalidArgumentException|Throwable $e) {
    fwrite(STDERR, 'Bootstrap error: ' . $e->getMessage() . "\n");
    exit(2);
}
$sql = new Sql($boot->database, $boot->clock);
$box = $boot->secretBox ?? new InMemorySecretBox(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$settings = new RmmSettings($sql, $box, new InMemoryRmmBridge());
$switch = new DataStoreSwitch($sql, $settings, $box, $boot->audit);
$pg = $boot->postgres ?? static function () use ($switch, $boot): ?\RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface {
    if ($boot->secretBox === null) {
        throw new MigrationException('The bootstrap file gives no secretBox, so the stored PostgreSQL password cannot be unsealed. Add it, or add a "postgres" entry.');
    }
    $cfg = $switch->postgresConfig();

    return $cfg === null ? null : PdoPostgresConnection::fromConfig($cfg);
};
$migrator = new RmmStoreMigrator($sql, $settings, $pg, $boot->audit);
$print = static function (array $e): void {
    echo json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
};
$sample = $opt['sample'] ?? '50';
$common = ['batch' => isset($opt['batch']) ? (int) $opt['batch'] : null, 'pause_ms' => isset($opt['pause-ms']) ? (int) $opt['pause-ms'] : null, 'sample' => $sample === 'all' ? -1 : (int) $sample,
    'settle_s' => isset($opt['settle']) ? (int) $opt['settle'] : null, 'verify_lag_s' => isset($opt['verify-lag']) ? (int) $opt['verify-lag'] : null, 'actor' => (string) ($opt['actor'] ?? 'cli')];
$common = array_filter($common, static fn ($v): bool => $v !== null);

try {
    if (isset($opt['status'])) {
        $print(['summary' => ['status' => $migrator->status(), 'data_store' => $settings->dataStore()]]);
        exit(0);
    }
    if (isset($opt['abort'])) {
        $migrator->abort((string) ($opt['actor'] ?? 'cli'));
        $print(['summary' => ['aborted' => true]]);
        exit(0);
    }
    if (isset($opt['purge-old'])) {
        $print(['summary' => $migrator->purgeOld(['yes' => isset($opt['yes']), 'force' => isset($opt['force'])], $print)]);
        exit(0);
    }
    if (isset($opt['rollback'])) {
        $print(['summary' => $migrator->rollback($common + ['force' => isset($opt['force'])], $print)]);
        exit(0);
    }
    $r = $migrator->migrate($common + ['to' => (string) $opt['to'], 'from' => isset($opt['from']) ? (string) $opt['from'] : $settings->dataStore(), 'dry_run' => isset($opt['dry-run']),
        'resume' => isset($opt['resume']), 'cutover' => !isset($opt['no-cutover'])], $print);
    $print(['summary' => $r]);
    exit(($r['ok'] ?? false) ? 0 : 1);
} catch (MigrationException $e) {
    $print(['error' => $e->getMessage()]);
    exit(1);
} catch (Throwable $e) {
    $print(['error' => get_class($e) . ': ' . $e->getMessage()]);
    exit(1);
}
