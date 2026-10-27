<?php

declare(strict_types=1);

/*
 * Promotes a design change made in an edition back into Core's shared design layer (docs/ui/DESIGN_ASSETS.md, "Changing the design").
 *
 *   php scripts/promote-ui-assets.php --css=/var/www/app/css [--js=/var/www/app/js]            # dry run: what differs
 *   php scripts/promote-ui-assets.php --css=... [--js=...] --apply                              # copy the changes into assets/ui
 *   php scripts/promote-ui-assets.php --css=... --apply --add=css/new_sheet.css                 # also add a brand-new shared file
 *
 * Run it from a rivet-core checkout (it writes assets/ui). Only files the manifest already lists are compared; the edition's own
 * sheets, including its *.edition.css overrides, are none of its business. A new shared file must be named with --add (and then
 * gets a load_order entry before itflow.bind-tabler.css that you adjust by hand). After --apply the manifest is rebuilt, the
 * result is verified, and the next steps are printed. Nothing is committed or released by this script.
 * Exit 0 on success (or nothing to promote), 1 on a refused or failed promotion, 2 on bad usage.
 */

require __DIR__ . '/ui-assets-lib.php';

$css = null;
$js = null;
$apply = false;
$add = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--css=')) {
        $css = rtrim(substr($arg, 6), '/');
    } elseif (str_starts_with($arg, '--js=')) {
        $js = rtrim(substr($arg, 5), '/');
    } elseif ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--add=')) {
        $add[] = substr($arg, 6);
    } else {
        fwrite(STDERR, "usage: promote-ui-assets.php --css=DIR [--js=DIR] [--apply] [--add=css/x.css ...]\n");
        exit(2);
    }
}
if ($css === null && $js === null) {
    fwrite(STDERR, "--css=DIR and/or --js=DIR is required (the edition's directories)\n");
    exit(2);
}
foreach (array_filter([$css, $js]) as $dir) {
    if (!is_dir($dir)) {
        fwrite(STDERR, "not a directory: $dir\n");
        exit(2);
    }
}
foreach ($add as $rel) {
    if (!ui_assets_valid_path($rel)) {
        fwrite(STDERR, "--add needs a path like css/name.css or js/name.js, got: $rel\n");
        exit(2);
    }
}

$root = ui_assets_root();
if (str_contains(str_replace('\\', '/', $root), '/vendor/')) {
    fwrite(STDERR, "refusing to run against a Core installed under vendor/: promotion writes assets/ui, run it from a rivet-core checkout\n");
    exit(2);
}
$manifestPath = $root . '/MANIFEST.json';
try {
    $manifest = ui_assets_manifest($manifestPath);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
$core = ui_assets_verify_core($manifest, $root);
if ($core !== []) {
    fwrite(STDERR, "Core's own assets do not match their manifest; fix that first:\n  - " . implode("\n  - ", $core) . "\n");
    exit(1);
}

$changes = [];
$missing = [];
foreach (array_keys($manifest['files']) as $rel) {
    $dir = str_starts_with($rel, 'css/') ? $css : $js;
    if ($dir === null) {
        continue;
    }
    $file = $dir . '/' . basename($rel);
    if (is_link($file)) {
        fwrite(STDERR, "refusing the edition's symlink $file: promotion reads regular files only\n");
        exit(2);
    }
    if (!is_file($file)) {
        $missing[] = $rel;
    } elseif (hash_file('sha256', $file) !== $manifest['files'][$rel]['sha256']) {
        $changes[$rel] = $file;
    }
}
foreach ($add as $rel) {
    $dir = str_starts_with($rel, 'css/') ? $css : $js;
    $file = ($dir ?? '') . '/' . basename($rel);
    if ($dir === null || !is_file($file) || is_link($file)) {
        fwrite(STDERR, "--add $rel: the edition has no such regular file (a symlink is refused)\n");
        exit(2);
    }
    if (isset($manifest['files'][$rel])) {
        fwrite(STDERR, "--add $rel: already shared; it is compared like any other file\n");
        exit(2);
    }
    $changes[$rel] = $file;
}

foreach ($missing as $rel) {
    fwrite(STDERR, "note: the edition has no copy of $rel (not installed, or removed); it is left as it is in Core\n");
}
if ($changes === []) {
    echo "Nothing to promote: the edition's shared files equal Core's.\n";
    exit(0);
}
foreach ($changes as $rel => $file) {
    $new = isset($manifest['files'][$rel]) ? 'changed' : 'NEW';
    printf("%-8s %-34s %7d -> %7d bytes\n", $new, $rel, isset($manifest['files'][$rel]) ? (int) $manifest['files'][$rel]['bytes'] : 0, (int) filesize($file));
}
if (!$apply) {
    echo "\nDry run. Re-run with --apply to copy these into Core's assets/ui.\n";
    exit(0);
}

foreach ($changes as $rel => $file) {
    $dest = "$root/$rel";
    $tmp = dirname($dest) . '/.' . basename($dest) . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (is_link($dest) || !copy($file, $tmp) || !rename($tmp, $dest)) {
        @unlink($tmp);
        fwrite(STDERR, "cannot write $dest\n");
        exit(1);
    }
}

$old = $manifest['files'];
$files = [];
foreach (ui_assets_scan($root) as $rel => $sha) {
    $files[$rel] = ['sha256' => $sha, 'bytes' => (int) filesize("$root/$rel"), 'origin' => $old[$rel]['origin'] ?? 'edition-promoted'];
}
// A promoted file no longer equals RivetIT's bytes by definition.
foreach (array_keys($changes) as $rel) {
    if (isset($old[$rel])) {
        $files[$rel]['origin'] = $old[$rel]['origin'] === 'rivetit-verbatim' ? 'rivetit-verbatim+promoted' : $old[$rel]['origin'];
    }
}
$manifest['files'] = $files;
foreach ($add as $rel) {
    if (str_starts_with($rel, 'css/') && !in_array($rel, array_column($manifest['load_order'], 'file'), true)) {
        $at = null;
        foreach ($manifest['load_order'] as $i => $e) {
            if ($e['file'] === 'css/itflow.bind-tabler.css') {
                $at = $i;
            }
        }
        array_splice($manifest['load_order'], $at ?? count($manifest['load_order']), 0, [['file' => $rel, 'owner' => 'core', 'note' => 'promoted from an edition; place it correctly and say which pages load it']]);
    }
}
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($manifestPath, $json) === false) {
    fwrite(STDERR, "cannot write $manifestPath\n");
    exit(1);
}
$problems = ui_assets_verify_core(ui_assets_manifest($manifestPath), $root);
if ($problems !== []) {
    fwrite(STDERR, "promoted, but Core's assets do not verify:\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}

echo "\nPromoted " . count($changes) . " file(s) into assets/ui and rebuilt MANIFEST.json.\nNext:\n";
echo "  1. git diff assets/ui          # review exactly what changed\n";
echo "  2. composer test               # UiAssetsTest etc. (the byte-identity test for a changed file needs its expected hash updated)\n";
echo "  3. other edition: run scripts/install-ui-assets.php there and look at its pages (visible change!)\n";
echo "  4. CHANGELOG entry, commit, release candidate tag; each edition then pins it and runs the installer\n";
exit(0);
