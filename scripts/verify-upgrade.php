<?php

declare(strict_types=1);

/*
 * Proves the upgrade path (issue #43). For each old tag: extract that tag's source with `git archive`, run ITS migration
 * runner into an empty scratch database (what an edition on that tag has), insert an audit row, then run THIS tree's runner
 * and check (1) it applied exactly the migrations the tag did not have, (2) the schema equals a fresh install, (3) the data
 * survived, (4) a second run applies nothing.
 *
 *   RIVETCORE_TEST_DB_USER=.. RIVETCORE_TEST_DB_PASS=.. php scripts/verify-upgrade.php <db-base> [tag ...]
 *
 * RIVETCORE_TAG_DIR=<dir> uses <dir>/<tag>/src instead of `git archive` (for a container without the git history).
 * <db-base> is a prefix containing "scratch"; the databases <base>_up and <base>_fresh are DROPPED AND RECREATED, so the
 * user needs CREATE/DROP on them and nothing else on the server may matter. With no tags, every tag is tried.
 */

$base = $argv[1] ?? '';
if (!preg_match('/scratch/i', $base)) {
    fwrite(STDERR, "usage: verify-upgrade.php <scratch-db-base> [tag ...]\n");
    exit(2);
}
$root = dirname(__DIR__);
$tags = array_slice($argv, 2);
if ($tags === []) {
    $tags = array_values(array_filter(explode("\n", (string) shell_exec('git -C ' . escapeshellarg($root) . " tag -l 'v[0-9]*' | sort -V"))));
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: '127.0.0.1', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '');
$recreate = static function (string $name) use ($admin): void {
    $admin->query("DROP DATABASE IF EXISTS `$name`");
    $admin->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
};
$run = static function (string $cmd): string {
    $out = shell_exec($cmd . ' 2>&1');

    return trim((string) $out);
};
$php = escapeshellarg(PHP_BINARY);
$migrate = fn (string $src, string $db): array => (array) json_decode(explode("\n", $run("$php " . escapeshellarg(__DIR__ . '/migrate-with.php') . ' ' . escapeshellarg($src) . ' ' . escapeshellarg($db)))[0] ?: 'null', true);
$fingerprint = fn (string $db): array => (array) json_decode($run("$php " . escapeshellarg(__DIR__ . '/schema-fingerprint.php') . ' ' . escapeshellarg($db)), true);

$fresh = $base . '_fresh';
$up = $base . '_up';
$recreate($fresh);
$headIds = $migrate($root, $fresh);
$freshFp = $fingerprint($fresh);
printf("HEAD fresh install: %d migrations, %d tables\n\n", count($headIds), count($freshFp['tables'] ?? []));
printf("%-9s %-28s %-9s %-7s %-7s %-9s %s\n", 'from', 'applied on upgrade', 'schema', 'data', 'rerun', 'old code', 'notes');

$tmp = sys_get_temp_dir() . '/rc-upgrade-' . getmypid();
mkdir($tmp, 0700, true);
$failed = 0;
foreach ($tags as $tag) {
    $pre = (string) getenv('RIVETCORE_TAG_DIR');   // trees already extracted (no git history available, e.g. inside a container)
    if ($pre !== '' && is_dir($pre . '/' . $tag . '/src')) {
        $src = $pre . '/' . $tag;
    } else {
        $src = $tmp . '/' . $tag;
        mkdir($src, 0700, true);
        $run('git -C ' . escapeshellarg($root) . ' archive ' . escapeshellarg($tag) . ' src | tar -x -C ' . escapeshellarg($src));
    }
    $recreate($up);
    $old = $migrate($src, $up);
    $notes = [];
    $conn = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: '127.0.0.1', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $up);
    $conn->query("INSERT INTO audit_events (event_type, action, summary) VALUES ('user.login', 'login', 'row written on $tag')");
    $applied = $migrate($root, $up);
    $expected = array_values(array_diff($headIds, $old));
    $idsOk = $applied === $expected;
    $fp = $fingerprint($up);
    $schemaOk = ($fp['tables'] ?? null) === $freshFp['tables'] && ($fp['columns'] ?? null) === $freshFp['columns'] && ($fp['indexes'] ?? null) === $freshFp['indexes'];
    if (!$schemaOk) {
        foreach (['tables', 'columns', 'indexes'] as $k) {
            $a = $fp[$k] ?? [];
            $b = $freshFp[$k];
            foreach (array_keys($a + $b) as $key) {
                if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                    $notes[] = "$k:$key upgraded=" . ($a[$key] ?? 'missing') . ' fresh=' . ($b[$key] ?? 'missing');
                }
            }
        }
    }
    $orderDiff = array_keys(array_filter($freshFp['order'], static fn ($cols, $t) => ($fp['order'][$t] ?? null) !== $cols, ARRAY_FILTER_USE_BOTH));
    if ($orderDiff) {
        $notes[] = 'column order differs (cosmetic) in ' . implode(',', $orderDiff);
    }
    $dataOk = (int) $conn->query("SELECT COUNT(*) FROM audit_events WHERE summary = 'row written on $tag'")->fetch_row()[0] === 1;
    $again = $migrate($root, $up);
    $rerunOk = $again === [];
    // Rollback safety of the runner: the OLD code pointed at the already-upgraded schema must apply nothing and not fail.
    $oldAgain = $migrate($src, $up);
    $rollbackOk = $oldAgain === [];
    if (!$idsOk) {
        $notes[] = 'applied ' . json_encode($applied) . ' expected ' . json_encode($expected);
    }
    $failed += (int) !($idsOk && $schemaOk && $dataOk && $rerunOk && $rollbackOk);
    printf("%-9s %-28s %-9s %-7s %-7s %-9s %s\n", $tag, count($applied) . ' (' . ($applied ? preg_replace('/_.*/', '', $applied[0]) . '..' . preg_replace('/_.*/', '', end($applied)) : 'none') . ')', $schemaOk ? 'equal' : 'DIFFERS', $dataOk ? 'kept' : 'LOST', $rerunOk ? 'no-op' : 'APPLIED', $rollbackOk ? 'old-ok' : 'OLD-FAILS', implode('; ', $notes));
}
$admin->query("DROP DATABASE IF EXISTS `$up`");
$admin->query("DROP DATABASE IF EXISTS `$fresh`");
$run('rm -rf ' . escapeshellarg($tmp));
exit($failed === 0 ? 0 : 1);
