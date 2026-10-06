<?php

declare(strict_types=1);

/*
 * Runs the Core migrations that ship in a given source tree against a scratch database, as an edition's updater would.
 *   php scripts/migrate-with.php <source-root-containing-src/> <database>     (credentials: RIVETCORE_TEST_DB_HOST/USER/PASS)
 * Prints a JSON list of the migration ids this call applied. Used by scripts/verify-upgrade.php, which points it at old
 * tags extracted with `git archive`. Only the Core classes under <source-root>/src are loaded (plus this repo's test mysqli
 * adapter), so an old tag really runs its own migrations.
 */

[, $root, $database] = $argv + [null, null, null];
if (!is_string($root) || !is_string($database) || !preg_match('/scratch/i', $database)) {
    fwrite(STDERR, "usage: migrate-with.php <source-root> <scratch-database>\n");
    exit(2);
}
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'RivetCore\\Tests\\')) {
        return;
    }
    if (str_starts_with($class, 'RivetCore\\')) {
        $f = $root . '/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($f)) {
            require $f;
        }
    }
});
require __DIR__ . '/../tests/Support/MysqliDatabase.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: '127.0.0.1', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $database);
$m->set_charset('utf8mb4');
$db = new RivetCore\Tests\Support\MysqliDatabase($m);
$runner = new RivetCore\Migration\MigrationRunner($db, RivetCore\Migration\CoreMigrations::all(), new RivetCore\Support\SystemClock());
echo json_encode($runner->run()), "\n";
