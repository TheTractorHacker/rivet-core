<?php

declare(strict_types=1);

/*
 * Shared helpers for scripts/install-ui-assets.php, scripts/check-ui-assets.php and scripts/build-ui-manifest.php.
 * Plain PHP with no Composer autoload, so an edition can run the scripts straight out of vendor/rivet/rivet-core.
 * Design: docs/ui/DESIGN_ASSETS.md.
 */

const UI_ASSETS_SCHEMA = 1;
const UI_ASSETS_RECEIPT = '.rivet-ui-assets.json';

/** Directory that holds css/, js/ and MANIFEST.json. */
function ui_assets_root(): string
{
    return dirname(__DIR__) . '/assets/ui';
}

/** A manifest key is "css/<name>" or "js/<name>": no traversal, no nesting, no odd characters. */
function ui_assets_valid_path(string $path): bool
{
    return preg_match('#^(css|js)/[A-Za-z0-9][A-Za-z0-9._-]*\.(css|js)$#', $path) === 1;
}

/**
 * @return array{schema:int, core_version:string, files:array<string, array{sha256:string, bytes:int, origin:string}>, load_order:list<array{file:string, owner:string, note?:string}>}
 */
function ui_assets_manifest(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("cannot read manifest $path");
    }
    $m = json_decode($raw, true);
    if (!is_array($m) || ($m['schema'] ?? null) !== UI_ASSETS_SCHEMA || !is_string($m['core_version'] ?? null) || !is_array($m['files'] ?? null) || !is_array($m['load_order'] ?? null)) {
        throw new RuntimeException("$path is not a schema " . UI_ASSETS_SCHEMA . ' UI asset manifest');
    }
    foreach ($m['files'] as $rel => $meta) {
        if (!is_string($rel) || !ui_assets_valid_path($rel) || !is_array($meta) || preg_match('/^[0-9a-f]{64}$/', (string) ($meta['sha256'] ?? '')) !== 1) {
            throw new RuntimeException("manifest entry is malformed: " . json_encode($rel));
        }
    }
    /** @var array{schema:int, core_version:string, files:array<string, array{sha256:string, bytes:int, origin:string}>, load_order:list<array{file:string, owner:string, note?:string}>} $m */
    return $m;
}

/** @return array<string, string> "css/x.css" => sha256 of every .css / .js file below $root/css and $root/js, sorted by path */
function ui_assets_scan(string $root): array
{
    $found = [];
    foreach (['css', 'js'] as $dir) {
        foreach (glob("$root/$dir/*") ?: [] as $file) {
            if (is_file($file)) {
                $found["$dir/" . basename($file)] = (string) hash_file('sha256', $file);
            }
        }
    }
    ksort($found, SORT_STRING);
    return $found;
}

/**
 * Self-consistency of Core's own asset directory: what the manifest lists is exactly what is on disk, with the recorded hashes and
 * sizes, and the documented load order names every shared stylesheet once.
 *
 * @param array<string, mixed> $manifest
 * @return list<string> problems, empty when consistent
 */
function ui_assets_verify_core(array $manifest, string $root): array
{
    $problems = [];
    $files = $manifest['files'];
    $onDisk = ui_assets_scan($root);
    foreach ($files as $rel => $meta) {
        if (!isset($onDisk[$rel])) {
            $problems[] = "listed in the manifest but missing on disk: $rel";
        } elseif ($onDisk[$rel] !== $meta['sha256']) {
            $problems[] = "content differs from the manifest: $rel (manifest " . substr($meta['sha256'], 0, 12) . ', disk ' . substr($onDisk[$rel], 0, 12) . ')';
        } elseif ((int) ($meta['bytes'] ?? -1) !== filesize("$root/$rel")) {
            $problems[] = "size differs from the manifest: $rel";
        }
    }
    foreach ($onDisk as $rel => $sha) {
        if (!isset($files[$rel])) {
            $problems[] = "on disk but not in the manifest: $rel (run scripts/build-ui-manifest.php)";
        }
    }
    $ordered = [];
    foreach ($manifest['load_order'] as $entry) {
        if (($entry['owner'] ?? '') === 'core') {
            $ordered[] = $entry['file'];
        }
    }
    foreach (array_keys($files) as $rel) {
        if (str_starts_with($rel, 'css/') && count(array_keys($ordered, $rel, true)) !== 1) {
            $problems[] = "stylesheet must appear exactly once in load_order as a core entry: $rel";
        }
    }
    foreach ($ordered as $rel) {
        if (!isset($files[$rel])) {
            $problems[] = "load_order names a core file that is not in the manifest: $rel";
        }
    }
    return $problems;
}

/**
 * Compares an edition's installed copies with the manifest. Files the manifest does not list (the edition's own sheets, including
 * the *.edition.css overrides) are none of its business.
 *
 * @param array<string, mixed> $manifest
 * @return list<string> problems, empty when the edition is in step with Core
 */
function ui_assets_verify_edition(array $manifest, ?string $cssDir, ?string $jsDir): array
{
    $problems = [];
    foreach ($manifest['files'] as $rel => $meta) {
        $dir = str_starts_with($rel, 'css/') ? $cssDir : $jsDir;
        if ($dir === null) {
            continue;
        }
        $file = $dir . '/' . basename($rel);
        if (!is_file($file)) {
            $problems[] = "missing: $rel (expected at $file)";
        } elseif (hash_file('sha256', $file) !== $meta['sha256']) {
            $problems[] = "modified or from another Core version: $rel (expected " . substr($meta['sha256'], 0, 12) . ', found ' . substr((string) hash_file('sha256', $file), 0, 12) . ')';
        }
    }
    return $problems;
}
