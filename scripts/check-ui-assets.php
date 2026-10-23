<?php

declare(strict_types=1);

/*
 * Drift check for the shared design layer (docs/ui/DESIGN_ASSETS.md).
 *
 *   php scripts/check-ui-assets.php                                   # Core against its own manifest (CI)
 *   php scripts/check-ui-assets.php --css=/var/www/app/css [--js=/var/www/app/js]
 *                                                                     # an edition's copies against Core's manifest
 *   --manifest=PATH   check against another manifest (default: assets/ui/MANIFEST.json next to this script)
 *   --expect-version=X  also fail unless the manifest's core_version is X (release step: the tag without its leading v)
 *
 * Exit 0 when everything matches, 1 on any difference (each is printed), 2 on bad usage.
 * Without --css/--js it checks that the manifest and assets/ui agree, so a file cannot be edited, added or removed without the
 * manifest being regenerated (scripts/build-ui-manifest.php).
 */

require __DIR__ . '/ui-assets-lib.php';

$css = null;
$js = null;
$manifestPath = ui_assets_root() . '/MANIFEST.json';
$expectVersion = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--css=')) {
        $css = rtrim(substr($arg, 6), '/');
    } elseif (str_starts_with($arg, '--js=')) {
        $js = rtrim(substr($arg, 5), '/');
    } elseif (str_starts_with($arg, '--manifest=')) {
        $manifestPath = substr($arg, 11);
    } elseif (str_starts_with($arg, '--expect-version=')) {
        $expectVersion = substr($arg, 17);
    } else {
        fwrite(STDERR, "usage: check-ui-assets.php [--css=DIR] [--js=DIR] [--manifest=PATH] [--expect-version=X]\n");
        exit(2);
    }
}

try {
    $manifest = ui_assets_manifest($manifestPath);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

if ($css === null && $js === null) {
    $problems = ui_assets_verify_core($manifest, dirname($manifestPath));
    $what = 'Core assets/ui against ' . basename($manifestPath);
} else {
    foreach (array_filter([$css, $js]) as $dir) {
        if (!is_dir($dir)) {
            fwrite(STDERR, "not a directory: $dir\n");
            exit(2);
        }
    }
    $problems = ui_assets_verify_edition($manifest, $css, $js);
    if ($css === null || $js === null) {
        fwrite(STDERR, 'note: only ' . ($css === null ? '--js' : '--css') . " was checked; pass both to cover every shared file\n");
    }
    $what = 'edition copies against Core ' . $manifest['core_version'];
    $receipt = ($css ?? $js) . '/' . UI_ASSETS_RECEIPT;
    if (is_file($receipt)) {
        $r = json_decode((string) file_get_contents($receipt), true);
        if (is_array($r) && ($r['core_version'] ?? '') !== $manifest['core_version']) {
            fwrite(STDERR, "note: installed from Core {$r['core_version']}, this manifest is {$manifest['core_version']}\n");
        }
    }
}

if ($expectVersion !== null && $manifest['core_version'] !== $expectVersion) {
    $problems[] = "manifest core_version is {$manifest['core_version']}, expected $expectVersion (php scripts/build-ui-manifest.php --core-version=$expectVersion)";
}

if ($problems !== []) {
    fwrite(STDERR, "FAIL  $what\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  - $p\n");
    }
    exit(1);
}
printf("OK    %s (%d files)\n", $what, count($manifest['files']));
