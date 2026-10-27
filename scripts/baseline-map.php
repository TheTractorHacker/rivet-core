<?php

declare(strict_types=1);

/**
 * Regenerates docs/security/CONTROL_MAPPING.md and the control table in docs/security/BASELINE.md from docs/security/baseline.json.
 *   php scripts/baseline-map.php          write both files
 *   php scripts/baseline-map.php --check  exit 1 when either file differs from what would be generated
 */
require __DIR__ . '/lib/BaselineLib.php';

$root = BaselineLib::root();
$b = BaselineLib::load();
$mapping = BaselineLib::renderMapping($b);
$baselinePath = $root . '/docs/security/BASELINE.md';
$baseline = BaselineLib::spliceTable((string) file_get_contents($baselinePath), BaselineLib::renderTable($b));
if ($baseline === null) {
    fwrite(STDERR, "BASELINE.md has no generated-controls markers\n");
    exit(2);
}
$mapPath = $root . '/docs/security/CONTROL_MAPPING.md';
$check = in_array('--check', $argv, true);
$stale = [];
foreach ([[$mapPath, $mapping], [$baselinePath, $baseline]] as [$path, $want]) {
    $have = is_file($path) ? (string) file_get_contents($path) : '';
    if ($have === $want) {
        continue;
    }
    if ($check) {
        $stale[] = substr($path, strlen($root) + 1);
    } else {
        file_put_contents($path, $want);
        echo 'wrote ' . substr($path, strlen($root) + 1) . "\n";
    }
}
if ($stale !== []) {
    fwrite(STDERR, 'stale, run php scripts/baseline-map.php: ' . implode(', ', $stale) . "\n");
    exit(1);
}
