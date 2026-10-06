<?php

declare(strict_types=1);

/*
 * Executes the PHP code samples in the docs so they cannot rot.
 *
 *   php scripts/check-doc-samples.php [file.md ...]      (default: README.md and docs/**)
 *
 * A fenced ```php block is run when the line above it (ignoring blank lines) is `<!-- run -->`; a block that is only a fragment
 * says `<!-- skip: why -->` instead. A php block with neither marker is an error, so every sample is a deliberate decision.
 * Each sample runs in its own PHP process with this prelude already in scope:
 *
 *   $mysqli, $db   a scratch database (RIVETCORE_TEST_DB_*) with every Core migration applied and the Core tables emptied
 *   $clock         a SystemClock
 *   $redis         a RedisClientProviderInterface: the throwaway Redis in RIVETCORE_TEST_REDIS_PORT, or a provider that is "down"
 *
 * Without a scratch database the samples that need $db are reported as SKIPPED (exit status stays 0 only when --allow-skip is given).
 */

$root = dirname(__DIR__);
$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$allowSkip = in_array('--allow-skip', $argv, true);
$files = $args;
if ($files === []) {
    $files = [$root . '/README.md'];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/docs', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'md') {
            $files[] = $f->getPathname();
        }
    }
    sort($files);
}

$haveDb = (string) getenv('RIVETCORE_TEST_DB_NAME') !== '';
$prelude = <<<'PHP'
<?php
declare(strict_types=1);
require '%ROOT%/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = null; $db = null;
if (getenv('RIVETCORE_TEST_DB_NAME')) {
    $mysqli = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: '127.0.0.1', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', getenv('RIVETCORE_TEST_DB_NAME'));
    $mysqli->set_charset('utf8mb4');
    $db = new RivetCore\Tests\Support\MysqliDatabase($mysqli);
    $clock0 = new RivetCore\Support\SystemClock();
    (new RivetCore\Migration\MigrationRunner($db, RivetCore\Migration\CoreMigrations::all(), $clock0))->run();
    foreach (['audit_events','integration_jobs','mcp_unlinked_identities','problems','changes','webhook_deliveries','automation_rules','workflow_run_tasks','workflow_runs','workflow_template_tasks','workflow_templates','compliance_attestations','compliance_snapshots','compliance_subjects','compliance_shared_report','compliance_responsibilities'] as $t) { $mysqli->query("TRUNCATE TABLE `$t`"); }
}
$clock = new RivetCore\Support\SystemClock();
$redis = new class implements RivetCore\Redis\RedisClientProviderInterface {
    public function client(): ?\Predis\Client { $p = (int) getenv('RIVETCORE_TEST_REDIS_PORT'); return $p ? new \Predis\Client(['host' => '127.0.0.1', 'port' => $p, 'timeout' => 0.5]) : null; }
};
// ---- sample starts on the next line ----
PHP;
$prelude = str_replace('%ROOT%', $root, $prelude);

$ran = $skipped = $failed = 0;
foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        if (!preg_match('/^```php\s*$/', $lines[$i])) {
            continue;
        }
        $j = $i - 1;
        while ($j >= 0 && trim($lines[$j]) === '') {
            $j--;
        }
        $marker = $j >= 0 ? trim($lines[$j]) : '';
        $body = [];
        $k = $i + 1;
        while ($k < $n && !preg_match('/^```\s*$/', $lines[$k])) {
            $body[] = $lines[$k];
            $k++;
        }
        $where = str_replace($root . '/', '', $file) . ':' . ($i + 1);
        if (str_starts_with($marker, '<!-- skip:')) {
            $skipped++;
            $i = $k;
            continue;
        }
        if ($marker !== '<!-- run -->') {
            fwrite(STDERR, "ERROR $where: a php block needs <!-- run --> or <!-- skip: reason --> on the line above it\n");
            $failed++;
            $i = $k;
            continue;
        }
        $code = implode("\n", $body);
        $needsDb = str_contains($code, '$db') || str_contains($code, '$mysqli');
        if ($needsDb && !$haveDb) {
            fwrite(STDERR, "SKIPPED $where: needs a scratch database\n");
            $skipped++;
            $i = $k;
            continue;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rcdoc') . '.php';
        file_put_contents($tmp, $prelude . "\n" . $code . "\n");
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        unlink($tmp);
        $text = implode("\n", $out);
        if ($rc !== 0 || preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error)/', $text)) {
            fwrite(STDERR, "FAILED  $where (exit $rc)\n" . preg_replace('/^/m', '    ', $text) . "\n");
            $failed++;
        } else {
            echo "ok      $where -> " . trim(substr($text, 0, 110)) . "\n";
            $ran++;
        }
        $i = $k;
    }
}
echo "\n$ran sample(s) ran, $skipped skipped, $failed failed\n";
exit($failed > 0 || ($skipped > 0 && !$allowSkip && !$haveDb) ? 1 : 0);
