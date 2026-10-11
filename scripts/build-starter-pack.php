<?php

declare(strict_types=1);

/**
 * Rewrites a starter pack's manifest.json (file checksums) and, for the bundled pack, the public catalog docs/rmm/starter-pack.manifest.json that
 * the website lists. Run it after changing anything under src/Rmm/Starter/data (or your own pack folder):
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
