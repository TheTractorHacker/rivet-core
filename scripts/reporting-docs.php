<?php

declare(strict_types=1);

/*
 * Regenerates the parts of the reporting docs and tests that are derived from the view catalog (src/Reporting/ReportingCatalog.php):
 *   tests/Fixtures/reporting_views.json                (the frozen column lists)
 *   docs/reporting/DATA_DICTIONARY.md                  (between the BEGIN/END GENERATED markers)
 *   docs/reporting/samples/power-query/**.pq           (one Power Query per view and source)
 * php scripts/reporting-docs.php          write them
 * php scripts/reporting-docs.php --check  exit 1 when a file differs (the tests do the same)
 * Changing tests/Fixtures/reporting_views.json is how a column is added ON PURPOSE: review the diff.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/ReportingDocs.php';

$check = in_array('--check', $argv, true);
$stale = [];
foreach (ReportingDocs::all() as $rel => $content) {
    $path = ReportingDocs::root() . '/' . $rel;
    if (is_file($path) && file_get_contents($path) === $content) {
        continue;
    }
    if ($check) {
        $stale[] = $rel;
        continue;
    }
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
        fwrite(STDERR, "cannot create " . dirname($path) . "\n");
        exit(1);
    }
    file_put_contents($path, $content);
    echo "wrote $rel\n";
}
if ($stale !== []) {
    fwrite(STDERR, "stale generated files (run php scripts/reporting-docs.php):\n  " . implode("\n  ", $stale) . "\n");
    exit(1);
}
