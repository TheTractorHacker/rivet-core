<?php

declare(strict_types=1);

/*
 * Fails when line coverage of src/ (excluding the DOCX/PDF converters, which are covered by their own corpus tests and
 * a separate bar) drops below the given percentage.   php scripts/coverage-gate.php coverage.xml 85
 */

[$script, $file, $min] = $argv + [null, 'coverage.xml', '85'];
$xml = simplexml_load_file((string) $file);
if ($xml === false) {
    fwrite(STDERR, "cannot read $file\n");
    exit(2);
}
$total = $covered = 0;
foreach ($xml->xpath('//file') as $f) {
    $path = (string) $f['name'];
    if (str_contains($path, '/src/KB/') && preg_match('/(Docx|Pdf)Converter\.php$/', $path)) {
        continue;
    }
    foreach ($f->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }
        $total++;
        if ((int) $line['count'] > 0) {
            $covered++;
        }
    }
}
$pct = $total > 0 ? 100 * $covered / $total : 0.0;
printf("Line coverage outside the converters: %.1f%% (%d/%d), required %s%%\n", $pct, $covered, $total, $min);
exit($pct + 1e-9 >= (float) $min ? 0 : 1);
