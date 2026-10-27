<?php

declare(strict_types=1);

/*
 * Maintainer tool for the shared design layer (docs/ui/DESIGN_ASSETS.md). Not shipped to editions.
 *
 *   php scripts/ui-css-split.php split  --src=<edition css dir> --shared=<out dir> --edition=<out dir>
 *   php scripts/ui-css-split.php verify --src=<edition css dir> --shared=assets/ui/css --edition=<dir with the *.edition.css files>
 *
 * split:  copies the files in VERBATIM verbatim, and cuts each file in SPLIT into the part Core keeps and the part that stays in
 *         the edition (rules whose selectors match the edition pattern). Chunks are never edited, only routed.
 * verify: proves the result against the edition's original files:
 *           1. verbatim files are byte-identical (sha256);
 *           2. for split files, the shared chunks and the edition chunks are two subsequences of the original chunk sequence that
 *              together cover it exactly, and merging them back reproduces the original file byte for byte;
 *           3. moving the edition chunks to a later file cannot change which declaration wins (CssCascade): any rule pair whose
 *              order could matter is listed, and the command fails if there is one.
 */

require __DIR__ . '/lib/CssChunker.php';
require __DIR__ . '/lib/CssCascade.php';

/** Files copied to Core unchanged. */
const VERBATIM = [
    'itflow.shim-bs4.css', 'itflow.shim-adminlte.css', 'itflow_bs5_bridge.css', 'itflow.compat-color.css', 'itflow.bind-tabler.css',
    'itflow_motion.css', 'itflow_metrics.css', 'file_upload.css',
    'itflow_rmm.css', 'itflow_rmm_ui.css', 'itflow_rmm_automation.css', 'itflow_rmm_logs.css', 'itflow_rmm_patches.css',
    'itflow_rmm_remote.css', 'itflow_rmm_scale.css', 'itflow_rmm_software.css',
];

/**
 * Files cut in two. A rule goes to the edition file when one of its selectors matches `pattern`; a rule where only some of its
 * selectors match is an error (split the rule by hand), so nothing is ever half-moved.
 */
const SPLIT = [
    'itflow_custom.css' => [
        'edition' => 'itflow_custom.edition.css',
        // The client-portal chrome and the KB article utility classes the portal renders.
        'pattern' => '/(?<![\w-])(\.client-portal-nav|\.portal-[\w-]+|\.kb-[\w-]+)/',
    ],
    'itflow_design.css' => [
        'edition' => 'itflow_design.edition.css',
        // The company-logo chip on the portal navbar and the login page: tenant branding.
        'pattern' => '/(?<![\w-])(\.client-portal-nav|\.login-box-branding)/',
    ],
];

/**
 * Deliberate, reviewed deviations from the edition's bytes (docs/ui/DESIGN_ASSETS.md, "Deviations from RivetIT"). Key: file; value: list of
 * [text in Core, text in the edition]. `split` writes the Core text, `verify` puts the edition text back before it compares, so the
 * proof still ends in the edition's exact bytes. Each pair must occur exactly once.
 */
const DEVIATIONS = [
    // Blake, 2026-10-23: WCAG AA muted text (4.19:1 on --if-bg is below 4.5:1). RivetMSP already ships #4b5d64.
    'itflow_design.css' => [['  --if-muted: #4b5d64;', '  --if-muted: #5d6f76;']],
    // The two page names the webhook wizard linked to (Internal network access, the edit page) are now read from optional
    // data-wz-networks / data-wz-edit attributes of [data-wz]. Without them the script uses the same literals as before, so RivetIT, which
    // sets neither attribute, behaves identically.
    'webhook_wizard.js' => [
        [
            "  var webhookId = root.getAttribute('data-wz-id') || '';\n  var networksUrl = root.getAttribute('data-wz-networks') || 'settings_webhooks.php#internal-networks';\n  var editUrl = root.getAttribute('data-wz-edit') || 'webhook_edit.php';\n",
            "  var webhookId = root.getAttribute('data-wz-id') || '';\n",
        ],
        ["a.href = networksUrl;", "a.href = 'settings_webhooks.php#internal-networks';"],
        ["dl.href = editUrl + '?id=' +", "dl.href = 'webhook_edit.php?id=' +"],
        ["'', editUrl + '?id=' +", "'', 'webhook_edit.php?id=' +"],
    ],
];

/** Scripts copied to Core unchanged (js/). webhook_wizard.js is the only one with a deviation. */
const JS_VERBATIM = ['chart_theme.js', 'icon_picker.js', 'date_range_picker.js', 'event_picker.js', 'webhook_guides.js'];
const JS_DEVIATING = ['webhook_wizard.js'];

/** Applies DEVIATIONS for $file: $toCore true = edition bytes to Core bytes, false = the way back. */
function deviate(string $file, string $text, bool $toCore): string
{
    foreach (DEVIATIONS[$file] ?? [] as [$core, $ed]) {
        [$from, $to] = $toCore ? [$ed, $core] : [$core, $ed];
        if (substr_count($text, $from) !== 1) {
            fwrite(STDERR, "deviation for $file must match exactly once: " . trim($from) . "\n");
            exit(1);
        }
        $text = str_replace($from, $to, $text);
    }
    return $text;
}

/**
 * Order-sensitive pairs a human looked at and cleared. Key: "<moved selector> | <later selector>"; value: why the order cannot matter.
 */
const REVIEWED = [
    '.portal-preview-toast | [class*="table-responsive-"]' =>
        'js/portal_preview_readonly.js creates the toast with className "portal-preview-toast" only, so the attribute selector never matches it.',
];

function opt(string $name): string
{
    foreach ($GLOBALS['argv'] as $a) {
        if (str_starts_with($a, "--$name=")) {
            return rtrim(substr($a, strlen($name) + 3), '/');
        }
    }
    fwrite(STDERR, "missing --$name=...\n");
    exit(2);
}

/** @return array{shared:list<array<string,mixed>>, edition:list<array<string,mixed>>} chunks routed by pattern, with their original index */
function route(string $css, string $pattern): array
{
    $out = ['shared' => [], 'edition' => []];
    foreach (CssChunker::chunks($css) as $i => $c) {
        $c['index'] = $i;
        $sels = [];
        if ($c['selector'] !== '' && $c['selector'][0] === '@') {
            if (preg_match('/^@(media|supports)\b/i', $c['selector'])) {
                foreach (CssChunker::chunks($c['body']) as $inner) {
                    $sels = array_merge($sels, CssCascade::members($inner['selector']));
                }
            }
        } elseif ($c['selector'] !== '') {
            $sels = CssCascade::members($c['selector']);
        }
        $hits = count(array_filter($sels, static fn(string $s): bool => preg_match($pattern, $s) === 1));
        if ($hits > 0 && $hits < count($sels)) {
            fwrite(STDERR, "chunk at line {$c['start_line']} has edition and shared selectors mixed: {$c['selector']}\n");
            exit(1);
        }
        $out[$hits > 0 ? 'edition' : 'shared'][] = $c;
    }
    return $out;
}

function join_chunks(array $chunks): string
{
    return implode('', array_column($chunks, 'text'));
}

$cmd = $argv[1] ?? '';
if (!in_array($cmd, ['split', 'verify'], true)) {
    fwrite(STDERR, "usage: ui-css-split.php split|verify --src=DIR --shared=DIR --edition=DIR\n");
    exit(2);
}
$src = opt('src');
$shared = opt('shared');
$edition = opt('edition');
$fail = 0;
// Optional: also prove the scripts. --js-src=<edition js dir> --js=assets/ui/js
$jsSrc = null;
$jsDir = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--js-src=')) {
        $jsSrc = rtrim(substr($a, 9), '/');
    } elseif (str_starts_with($a, '--js=')) {
        $jsDir = rtrim(substr($a, 5), '/');
    }
}

if ($cmd === 'split') {
    foreach ([$shared, $edition] as $d) {
        if (!is_dir($d) && !mkdir($d, 0775, true)) {
            fwrite(STDERR, "cannot create $d\n");
            exit(2);
        }
    }
    foreach (VERBATIM as $f) {
        copy("$src/$f", "$shared/$f");
    }
    foreach (SPLIT as $f => $cfg) {
        $parts = route(deviate($f, (string) file_get_contents("$src/$f"), true), $cfg['pattern']);
        file_put_contents("$shared/$f", join_chunks($parts['shared']));
        file_put_contents("$edition/{$cfg['edition']}", join_chunks($parts['edition']));
        printf("%s: %d chunks stay in Core, %d move to %s\n", $f, count($parts['shared']), count($parts['edition']), $cfg['edition']);
    }
    exit(0);
}

foreach (VERBATIM as $f) {
    $a = hash_file('sha256', "$src/$f");
    $b = is_file("$shared/$f") ? hash_file('sha256', "$shared/$f") : 'missing';
    printf("%-6s %-28s sha256 %s\n", $a === $b ? 'SAME' : 'DIFF', $f, substr((string) $a, 0, 16));
    $fail += $a === $b ? 0 : 1;
}

if ($jsSrc !== null && $jsDir !== null) {
    foreach (array_merge(JS_VERBATIM, JS_DEVIATING) as $f) {
        $a = hash_file('sha256', "$jsSrc/$f");
        $core = is_file("$jsDir/$f") ? (string) file_get_contents("$jsDir/$f") : null;
        $b = $core === null ? 'missing' : hash('sha256', deviate($f, $core, false));
        $kind = in_array($f, JS_DEVIATING, true) ? 'with the reviewed deviation reversed' : 'verbatim';
        printf("%-6s js/%-24s sha256 %s (%s)\n", $a === $b ? 'SAME' : 'DIFF', $f, substr((string) $a, 0, 16), $kind);
        $fail += $a === $b ? 0 : 1;
    }
}

foreach (SPLIT as $f => $cfg) {
    $orig = (string) file_get_contents("$src/$f");
    $origChunks = CssChunker::chunks($orig);
    $sh = CssChunker::chunks(deviate($f, (string) file_get_contents("$shared/$f"), false));
    $ed = CssChunker::chunks((string) file_get_contents("$edition/{$cfg['edition']}"));

    // Merge: walk the original; every original chunk must be the next unread chunk of exactly one part.
    $si = $ei = 0;
    $merged = '';
    $movedLater = [];
    foreach ($origChunks as $i => $c) {
        if (isset($sh[$si]) && $sh[$si]['text'] === $c['text']) {
            $merged .= $sh[$si++]['text'];
        } elseif (isset($ed[$ei]) && $ed[$ei]['text'] === $c['text']) {
            $merged .= $ed[$ei++]['text'];
            $movedLater[] = $i;
        } else {
            printf("FAIL   %s: original chunk %d (line %d) is in neither part, or out of order\n", $f, $i, $c['start_line']);
            $fail++;
            break;
        }
    }
    $okMerge = $merged === $orig && $si === count($sh) && $ei === count($ed);
    printf("%-6s %-28s %d chunks = %d shared + %d edition, merge reproduces the original byte for byte: %s\n",
        $okMerge ? 'SPLIT' : 'FAIL', $f, count($origChunks), count($sh), count($ed), $okMerge ? 'yes' : 'NO');
    $fail += $okMerge ? 0 : 1;
    if (!$okMerge) {
        continue;
    }

    // Cascade safety: a moved chunk now comes after every shared chunk of this file. Only shared chunks that used to come AFTER it matter.
    $movedSet = array_flip($movedLater);
    $conflicts = [];
    foreach ($movedLater as $mi) {
        $moved = CssCascade::rules($origChunks[$mi]);
        $later = [];
        foreach ($origChunks as $j => $c) {
            if ($j > $mi && !isset($movedSet[$j])) {
                foreach (CssCascade::rules($c) as $r) {
                    $r['line'] = $c['start_line'];
                    $later[] = $r;
                }
            }
        }
        foreach (CssCascade::conflicts($moved, $later) as $msg) {
            $pair = trim(explode('  vs later  ', $msg)[0]) . ' | ' . trim(explode(' [', explode('  vs later  ', $msg)[1])[0]);
            if (isset(REVIEWED[$pair])) {
                continue;
            }
            $conflicts[] = "line {$origChunks[$mi]['start_line']}: $msg";
        }
        // Names that live in a global namespace (@keyframes, @font-face families) would also be order sensitive.
        if (preg_match('/^@(keyframes|font-face)/i', $origChunks[$mi]['selector'])) {
            $conflicts[] = "line {$origChunks[$mi]['start_line']}: {$origChunks[$mi]['selector']} moved (global name)";
        }
    }
    printf("%-6s %-28s cascade: %d moved chunks, %d order-sensitive pairs\n", $conflicts === [] ? 'SAFE' : 'CHECK', $f, count($movedLater), count($conflicts));
    foreach ($conflicts as $c) {
        echo "         $c\n";
    }
    $fail += count($conflicts);
}
exit($fail === 0 ? 0 : 1);
