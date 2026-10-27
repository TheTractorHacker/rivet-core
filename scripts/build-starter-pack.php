<?php

declare(strict_types=1);

/**
 * Rewrites a starter pack's manifest.json (file checksums) and, for the bundled pack, the public catalog docs/rmm/starter-pack.manifest.json that
 * the website lists and the catalog tables of docs/rmm/STARTER_PACK.md (between its `starter:catalog` markers). Run it after changing anything under src/Rmm/Starter/data (or your own pack folder):
 *
 *   php scripts/build-starter-pack.php [pack-folder]   rewrite the files
 *   php scripts/build-starter-pack.php --check         exit 1 when they are stale (CI)
 */

require __DIR__ . '/../vendor/autoload.php';

use RivetCore\Rmm\Starter\StarterPack;

$args = array_slice($argv, 1);
$check = in_array('--check', $args, true);
$args = array_values(array_diff($args, ['--check']));
$dir = $args[0] ?? __DIR__ . '/../src/Rmm/Starter/data';
$bundled = !isset($args[0]);

$manifest = StarterPack::buildManifest($dir);
$text = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$stale = [];
if (@file_get_contents($dir . '/manifest.json') !== $text) {
    $stale[] = $dir . '/manifest.json';
    if (!$check) {
        file_put_contents($dir . '/manifest.json', $text);
    }
}
if ($bundled || is_file($dir . '/manifest.json')) {
    StarterPack::forget();
    if (!$check || $stale === []) {
        $catalog = StarterPack::fromDirectory($dir)->catalog();
        $out = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $target = $bundled ? __DIR__ . '/../docs/rmm/starter-pack.manifest.json' : null;
        if ($bundled) {
            $doc = __DIR__ . '/../docs/rmm/STARTER_PACK.md';
            $old = (string) file_get_contents($doc);
            $new = preg_replace_callback('/(<!-- starter:catalog:start -->\n)(.*?)(<!-- starter:catalog:end -->)/s', static fn (array $m): string => $m[1] . renderCatalog($catalog) . $m[3], $old);
            if ($new !== null && $new !== $old) {
                $stale[] = realpath(dirname($doc)) . '/STARTER_PACK.md';
                if (!$check) {
                    file_put_contents($doc, $new);
                }
            }
        }
        if ($target !== null && @file_get_contents($target) !== $out) {
            $stale[] = realpath(dirname($target)) . '/starter-pack.manifest.json';
            if (!$check) {
                file_put_contents($target, $out);
            }
        }
    }
}
if ($check) {
    if ($stale !== []) {
        fwrite(STDERR, "Stale: " . implode(', ', $stale) . "\nRun php scripts/build-starter-pack.php\n");
        exit(1);
    }
    echo "starter pack manifest is current\n";
    exit(0);
}
echo $stale === [] ? "nothing to do\n" : 'wrote ' . implode(', ', $stale) . "\n";

/** @param array<string,mixed> $c the pack catalog */
function renderCatalog(array $c): string
{
    $esc = static fn (string $t): string => str_replace(['|', "\n"], ['\\|', ' '], $t);
    $out = '';
    foreach (['windows' => 'Windows (PowerShell)', 'linux' => 'Linux (bash)'] as $platform => $title) {
        $out .= "\n### $title scripts\n\n| Id | Name | Risk | Approval | What it does | Parameters |\n|---|---|---|---|---|---|\n";
        foreach ($c['scripts'] as $s) {
            if ($s['platform'] !== $platform) {
                continue;
            }
            $params = [];
            foreach ($s['params'] as $p) {
                $d = array_key_exists('default', $p) ? ($p['type'] === 'bool' ? ($p['default'] ? 'true' : 'false') : (string) $p['default']) : 'required';
                $range = isset($p['min']) ? " {$p['min']}..{$p['max']}" : '';
                $params[] = "`{$p['name']}` ({$p['type']}$range, $d)";
            }
            $out .= "| `{$s['id']}` | {$esc($s['name'])} | {$s['risk']} | " . ($s['requires_approval'] ? 'yes' : 'no') . " | {$esc($s['description'])} | " . ($params === [] ? 'none' : implode('<br>', $params)) . " |\n";
        }
    }
    $out .= "\n### Check template sets\n\n| Id | Use | Checks | Parameters |\n|---|---|---|---|\n";
    foreach ($c['check_sets'] as $set) {
        $checks = array_map(static fn (array $k): string => "`{$k['key']}` ({$k['type']}, every {$k['interval_s']} s" . ($k['when'] !== null ? ", only when `{$k['when']}` is set" : '') . ')', $set['checks']);
        $params = array_map(static fn (array $p): string => "`{$p['name']}` ({$p['type']}, " . ($p['default'] === null ? 'empty' : (is_array($p['default']) ? implode(',', $p['default']) : (string) $p['default'])) . ')', $set['params']);
        $out .= "| `{$set['id']}` | {$set['use']} ({$set['platform']}) | " . implode('<br>', $checks) . ' | ' . ($params === [] ? 'none' : implode('<br>', $params)) . " |\n";
    }
    $out .= "\n### Policy drafts\n\n| Id | Name | Built from | Notes |\n|---|---|---|---|\n";
    foreach ($c['policies'] as $p) {
        $out .= "| `{$p['id']}` | {$esc($p['name'])} | " . implode(', ', array_map(static fn (string $x): string => "`$x`", $p['check_sets'])) . " | {$esc($p['description'])} |\n";
    }

    return $out . "\n";
}
