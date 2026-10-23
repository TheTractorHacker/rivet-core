<?php

declare(strict_types=1);

/*
 * Rewrites assets/ui/MANIFEST.json from the files on disk. Run it after changing anything below assets/ui and at release time:
 *
 *   php scripts/build-ui-manifest.php                          # refresh sha256 and size of every file
 *   php scripts/build-ui-manifest.php --core-version=1.0.0-rc.34
 *
 * The load order and each file's `origin` are maintained by hand in the manifest; this script keeps them and a new file gets origin
 * "core" until someone says otherwise. CI runs scripts/check-ui-assets.php, which fails when the manifest and the disk disagree.
 */

require __DIR__ . '/ui-assets-lib.php';

$root = ui_assets_root();
$manifestPath = "$root/MANIFEST.json";
$manifest = ui_assets_manifest($manifestPath);

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--core-version=')) {
        $manifest['core_version'] = substr($arg, strlen('--core-version='));
    } else {
        fwrite(STDERR, "usage: build-ui-manifest.php [--core-version=X]\n");
        exit(2);
    }
}

$old = $manifest['files'];
$files = [];
foreach (ui_assets_scan($root) as $rel => $sha) {
    $files[$rel] = ['sha256' => $sha, 'bytes' => (int) filesize("$root/$rel"), 'origin' => $old[$rel]['origin'] ?? 'core'];
}
$manifest['files'] = $files;

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($manifestPath, $json) === false) {
    fwrite(STDERR, "cannot write $manifestPath\n");
    exit(1);
}
printf("%s: %d files, core_version %s\n", $manifestPath, count($files), $manifest['core_version']);
