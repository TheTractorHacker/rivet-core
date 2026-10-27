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
 *
 * Safe to run as root against a directory another user can write (1.0.0-rc.38): a destination or receipt that is a symlink is refused before anything is written,
 * the temporary file is created exclusively (O_EXCL, which never follows a symlink) under an unpredictable name, and the result replaces the destination with
 * rename(), which replaces a directory entry and never writes through one.
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

/** Write $bytes to $dest without ever following a symlink: exclusive temp file with a random name, then an atomic rename. */
function install_file(string $dest, string $bytes): bool
{
    if (is_link($dest)) {
        return false;
    }
    $tmp = dirname($dest) . '/.' . basename($dest) . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $h = @fopen($tmp, 'xb');
    if ($h === false) {
        return false;
    }
    $ok = fwrite($h, $bytes) === strlen($bytes) && fclose($h) && chmod($tmp, 0644) && rename($tmp, $dest);
    if (!$ok) {
        @unlink($tmp);
    }

    return $ok;
}

// Refuse every symlinked destination (and receipt) up front, so a run never ends half-way because of one.
$symlinks = [];
foreach ($manifest['files'] as $rel => $meta) {
    $dir = str_starts_with($rel, 'css/') ? $css : $js;
    if ($dir !== null && is_link($dir . '/' . basename($rel))) {
        $symlinks[] = $dir . '/' . basename($rel);
    }
}
if (is_link("$css/" . UI_ASSETS_RECEIPT)) {
    $symlinks[] = "$css/" . UI_ASSETS_RECEIPT;
}
if ($symlinks !== []) {
    fwrite(STDERR, "refusing to write through a symlink, nothing installed:\n  - " . implode("\n  - ", $symlinks) . "\n");
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
    $bytes = file_get_contents("$root/$rel");
    if ($bytes === false || !install_file($dest, $bytes)) {
        fwrite(STDERR, "cannot write $dest\n");
        exit(1);
    }
}

if (!$dry) {
    $receipt = json_encode(['schema' => UI_ASSETS_SCHEMA, 'core_version' => $manifest['core_version'], 'files' => $installed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (!install_file("$css/" . UI_ASSETS_RECEIPT, $receipt)) {
        fwrite(STDERR, "cannot write the receipt in $css\n");
        exit(1);
    }
}
printf("%s: %d of %d files %s (Core %s)\n", $dry ? 'dry run' : 'done', $changed, count($installed), $dry ? 'would change' : 'written', $manifest['core_version']);
