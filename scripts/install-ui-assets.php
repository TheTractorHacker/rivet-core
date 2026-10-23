<?php

declare(strict_types=1);

/*
 * Installs Core's shared design layer into an edition (docs/ui/DESIGN_ASSETS.md).
 *
 *   php vendor/rivet/rivet-core/scripts/install-ui-assets.php --css=/var/www/app/css [--js=/var/www/app/js] [--dry-run]
 *
 * Copies every file listed in assets/ui/MANIFEST.json into the edition, byte for byte (no header is injected, so the copy hashes
 * to the manifest value and scripts/check-ui-assets.php can prove it), and writes css/.rivet-ui-assets.json recording the Core
 * version and hashes that were installed. It touches nothing else: the edition's own sheets, including its *.edition.css
 * overrides, are left alone. Core's own files are verified first; a Core tree that does not match its manifest is refused.
 * Shared files are overwritten (that is the point); --dry-run lists what would change.
 */

require __DIR__ . '/ui-assets-lib.php';

$css = null;
$js = null;
$dry = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--css=')) {
        $css = rtrim(substr($arg, 6), '/');
    } elseif (str_starts_with($arg, '--js=')) {
        $js = rtrim(substr($arg, 5), '/');
    } elseif ($arg === '--dry-run') {
        $dry = true;
    } else {
        fwrite(STDERR, "usage: install-ui-assets.php --css=DIR [--js=DIR] [--dry-run]\n");
        exit(2);
    }
}
if ($css === null) {
    fwrite(STDERR, "--css=DIR is required (the edition's css directory; it must already exist)\n");
    exit(2);
}
foreach (array_filter([$css, $js]) as $dir) {
    if (!is_dir($dir) || !is_writable($dir)) {
        fwrite(STDERR, "not a writable directory: $dir\n");
        exit(2);
    }
}

$root = ui_assets_root();
try {
    $manifest = ui_assets_manifest("$root/MANIFEST.json");
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
$bad = ui_assets_verify_core($manifest, $root);
if ($bad !== []) {
    fwrite(STDERR, "Core's assets do not match their manifest, nothing installed:\n  - " . implode("\n  - ", $bad) . "\n");
    exit(1);
}

$changed = 0;
$installed = [];
if ($js === null) {
    $scripts = count(array_filter(array_keys($manifest['files']), static fn(string $rel): bool => str_starts_with($rel, 'js/')));
    fwrite(STDERR, "note: --js not given, the $scripts shared scripts were not installed (the picker, wizard and guide pages need them)\n");
}
foreach ($manifest['files'] as $rel => $meta) {
    $dir = str_starts_with($rel, 'css/') ? $css : $js;
    if ($dir === null) {
        continue;
    }
    $dest = $dir . '/' . basename($rel);
    $state = !is_file($dest) ? 'new' : (hash_file('sha256', $dest) === $meta['sha256'] ? 'same' : 'update');
    $installed[$rel] = $meta['sha256'];
    printf("%-7s %s\n", $state, $dest);
    if ($state === 'same') {
        continue;
    }
    $changed++;
    if ($dry) {
        continue;
    }
    $tmp = $dest . '.tmp' . getmypid();
    if (!copy("$root/$rel", $tmp) || !chmod($tmp, 0644) || !rename($tmp, $dest)) {
        @unlink($tmp);
        fwrite(STDERR, "cannot write $dest\n");
        exit(1);
    }
}

if (!$dry) {
    $receipt = json_encode(['schema' => UI_ASSETS_SCHEMA, 'core_version' => $manifest['core_version'], 'files' => $installed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents("$css/" . UI_ASSETS_RECEIPT, $receipt) === false) {
        fwrite(STDERR, "cannot write the receipt in $css\n");
        exit(1);
    }
}
printf("%s: %d of %d files %s (Core %s)\n", $dry ? 'dry run' : 'done', $changed, count($installed), $dry ? 'would change' : 'written', $manifest['core_version']);
