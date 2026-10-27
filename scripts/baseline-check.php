<?php

declare(strict_types=1);

/**
 * Fails (exit 1) when docs/security/baseline.json is not trustworthy:
 *  - schema: required fields, unique ids, known status, known SOC 2 / ISO ids, every control mapped
 *  - every referenced file exists; every test exists (`path`, `path::method` which must be a method of that file, or `ci:job` which must be a job in ci.yml)
 *  - an 'enforced' control has at least one test; a 'gap' control has none that claims to prove it (checked loosely: no tests listed)
 *  - every GAP-nn mentioned anywhere is defined
 *  - docs/security/CONTROL_MAPPING.md and the table in docs/security/BASELINE.md match what baseline-map.php generates
 * Controls that are 'partial' without any test are reported as warnings.
 */
require __DIR__ . '/lib/BaselineLib.php';

$root = BaselineLib::root();
$errors = [];
$warnings = [];
$b = BaselineLib::load();
$ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
$seen = [];
$gapIds = array_column($b['gaps'] ?? [], 'id');

foreach ($b['controls'] ?? [] as $c) {
    $id = $c['id'] ?? '(no id)';
    foreach (['id', 'area', 'title', 'asvs', 'requirement', 'status', 'how', 'files', 'tests', 'soc2', 'iso27001', 'note'] as $f) {
        if (!array_key_exists($f, $c)) {
            $errors[] = "{$id}: missing field {$f}";
        }
    }
    if (isset($seen[$id])) {
        $errors[] = "{$id}: duplicate id";
    }
    $seen[$id] = true;
    if (!in_array($c['status'] ?? '', BaselineLib::STATUSES, true)) {
        $errors[] = "{$id}: unknown status " . json_encode($c['status'] ?? null);
        continue;
    }
    if (($c['asvs'] ?? []) === []) {
        $errors[] = "{$id}: no ASVS reference";
    }
    if (($c['soc2'] ?? []) === [] || ($c['iso27001'] ?? []) === []) {
        $errors[] = "{$id}: must map to at least one SOC 2 criterion and one ISO 27001 control";
    }
    foreach ($c['soc2'] ?? [] as $r) {
        isset(BaselineLib::SOC2[$r]) || $errors[] = "{$id}: unknown SOC 2 criterion {$r}";
    }
    foreach ($c['iso27001'] ?? [] as $r) {
        isset(BaselineLib::ISO[$r]) || $errors[] = "{$id}: unknown ISO 27001 control {$r}";
    }
    foreach ($c['files'] ?? [] as $f) {
        file_exists($root . '/' . $f) || $errors[] = "{$id}: referenced file does not exist: {$f}";
    }
    foreach ($c['tests'] ?? [] as $t) {
        if (str_starts_with($t, 'ci:')) {
            preg_match('/^  ' . preg_quote(substr($t, 3), '/') . ':\s*$/m', $ci) === 1 || $errors[] = "{$id}: CI job not found in ci.yml: {$t}";
            continue;
        }
        [$path, $method] = array_pad(explode('::', $t, 2), 2, null);
        $full = $root . '/' . $path;
        if (!is_file($full)) {
            $errors[] = "{$id}: test file does not exist: {$path}";
        } elseif ($method !== null && preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', (string) file_get_contents($full)) !== 1) {
            $errors[] = "{$id}: test method not found: {$t}";
        }
    }
    $status = $c['status'];
    if ($status === 'enforced' && ($c['tests'] ?? []) === []) {
        $errors[] = "{$id}: status enforced but no test proves it (downgrade it or add a test)";
    }
    if ($status === 'gap' && ($c['tests'] ?? []) !== []) {
        $errors[] = "{$id}: status gap but lists tests (then it is at least partial)";
    }
    if ($status === 'partial' && ($c['tests'] ?? []) === []) {
        $warnings[] = "{$id}: partial with no test";
    }
    if (preg_match_all('/GAP-\d+/', ($c['note'] ?? '') . ' ' . ($c['how'] ?? ''), $m)) {
        foreach ($m[0] as $g) {
            in_array($g, $gapIds, true) || $errors[] = "{$id}: mentions undefined {$g}";
        }
    }
}
foreach ($b['gaps'] ?? [] as $g) {
    (isset($g['id'], $g['title'], $g['detail'])) || $errors[] = 'a gap entry lacks id/title/detail';
}
foreach (['docs/security/BASELINE.md', 'docs/security/CLAIMS.md', 'docs/security/CONTROL_MAPPING.md', 'SECURITY.md'] as $f) {
    is_file($root . '/' . $f) || $errors[] = "missing document {$f}";
}

if ($errors === []) {
    $mapping = BaselineLib::renderMapping($b);
    if ((string) @file_get_contents($root . '/docs/security/CONTROL_MAPPING.md') !== $mapping) {
        $errors[] = 'docs/security/CONTROL_MAPPING.md is stale: run php scripts/baseline-map.php';
    }
    $doc = (string) @file_get_contents($root . '/docs/security/BASELINE.md');
    if (BaselineLib::spliceTable($doc, BaselineLib::renderTable($b)) !== $doc) {
        $errors[] = 'docs/security/BASELINE.md control table is stale: run php scripts/baseline-map.php';
    }
}

foreach ($warnings as $w) {
    fwrite(STDERR, "warning: {$w}\n");
}
if ($errors !== []) {
    foreach ($errors as $e) {
        fwrite(STDERR, "FAIL: {$e}\n");
    }
    exit(1);
}
$n = BaselineLib::counts($b);
echo 'baseline ok: ' . count($b['controls']) . ' controls (' . implode(', ', array_map(static fn ($s) => "{$n[$s]} {$s}", BaselineLib::STATUSES)) . '), ' . count($b['gaps']) . " gaps, " . count($warnings) . " warnings\n";
