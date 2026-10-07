<?php

declare(strict_types=1);

/**
 * Regenerates the machine-derived blocks of docs/rmm/PROTOCOL.md from the code and the recorded evidence, so the frozen protocol
 * document cannot drift from what the server does:
 *
 *   constants  every constant of RmmProtocol (reflection), grouped by the section comments of the source file
 *   observed   every (endpoint, method, status, code) the golden transcripts recorded (tests/Fixtures/rmm/golden/*.json)
 *   examples   a few complete exchanges taken from those transcripts (placeholders are the transcripts' masks)
 *   vectors    the shared signing and trailer vectors (endpoint-agent/testdata/vectors) and their SHA-256
 *
 *   php scripts/rmm-protocol-doc.php           rewrite the blocks in place
 *   php scripts/rmm-protocol-doc.php --check   exit 1 (and print the first difference) when the file is stale
 *
 * A block is the text between `<!-- BEGIN GENERATED: name -->` and `<!-- END GENERATED: name -->`.
 */

use RivetCore\Rmm\RmmProtocol;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$docPath = $root . '/docs/rmm/PROTOCOL.md';

/** @return array<string,string> */
function protocolBlocks(string $root): array
{
    return [
        'constants' => constantsBlock($root),
        'observed' => observedBlock($root),
        'examples' => examplesBlock($root),
        'vectors' => vectorsBlock($root),
    ];
}

function render(mixed $v): string
{
    if (is_string($v)) {
        return "`" . str_replace('`', "'", $v === '' ? "''" : $v) . "`";
    }
    if (is_array($v)) {
        return '`' . json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '`';
    }
    if (is_bool($v)) {
        return $v ? '`true`' : '`false`';
    }

    return '`' . var_export($v, true) . '`';
}

function esc(string $s): string
{
    return str_replace('|', '\\|', $s);
}

function constantsBlock(string $root): string
{
    $src = (string) file_get_contents($root . '/src/Rmm/RmmProtocol.php');
    $section = 'General';
    $bySection = [];
    foreach (explode("\n", $src) as $line) {
        if (preg_match('#^\s*// ---- (.+)$#', $line, $m) === 1) {
            $section = trim((string) preg_replace('/\.\s.*$/', '', $m[1]));
            $section = (string) preg_replace('/:.*$/', '', $section);
            $section = (string) preg_replace('/\s*\(.*$/', '', $section);
        } elseif (preg_match('/^\s*public const ([A-Z0-9_]+) =/', $line, $m) === 1) {
            $bySection[$section][] = $m[1];
        }
    }
    $ref = new ReflectionClass(RmmProtocol::class);
    $out = "These are the values of `RivetCore\\Rmm\\RmmProtocol`; `tests/Unit/Rmm/FrozenConstantsTest.php` pins them.\n";
    foreach ($bySection as $name => $consts) {
        $out .= "\n**" . ucfirst($name) . "**\n\n| Constant | Value |\n|---|---|\n";
        foreach ($consts as $c) {
            $out .= '| `' . $c . '` | ' . esc(render($ref->getConstant($c))) . " |\n";
        }
    }

    return rtrim($out, "\n");
}

/** @return list<array<string,mixed>> every request/response pair of the device endpoints in the transcripts */
function transcriptSteps(string $root): array
{
    $steps = [];
    foreach (glob($root . '/tests/Fixtures/rmm/golden/0*.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        foreach ($d['steps'] ?? [] as $s) {
            if (isset($s['request'], $s['response'])) {
                $s['_file'] = basename($f);
                $steps[] = $s;
            }
        }
    }

    return $steps;
}

function observedBlock(string $root): string
{
    $rows = [];
    foreach (transcriptSteps($root) as $s) {
        if (preg_match('#^/api/v1/(agent_[a-z]+)#', (string) $s['request']['path'], $m) !== 1) {
            continue;
        }
        $body = $s['response']['body'] ?? null;
        $code = is_array($body) && isset($body['code']) ? (string) $body['code'] : '';
        $msg = is_array($body) && isset($body['error']) ? (string) $body['error'] : '';
        if ($code === '' && $s['response']['status'] < 300) {
            $msg = isset($s['response']['body_binary']) ? 'binary download' : '';
        }
        $rows[$m[1]][$s['request']['method']][$s['response']['status']][$code . "\0" . $msg] = true;
    }
    ksort($rows);
    $out = "Recorded from the golden transcripts (`tests/Fixtures/rmm/golden`): every status the server answered, with the error `code` and message.\nThe disabled-module answer (503 `module_disabled`) is not in the transcripts; it is specified in the section above.\n\n| Endpoint | Method | Status | `code` | Message |\n|---|---|---|---|---|\n";
    foreach ($rows as $ep => $methods) {
        ksort($methods);
        foreach ($methods as $method => $statuses) {
            ksort($statuses);
            foreach ($statuses as $status => $variants) {
                ksort($variants);
                foreach (array_keys($variants) as $v) {
                    [$code, $msg] = explode("\0", $v);
                    $out .= '| `' . $ep . '` | ' . $method . ' | ' . $status . ' | ' . ($code === '' ? '' : '`' . $code . '`') . ' | ' . esc($msg) . " |\n";
                }
            }
        }
    }

    return rtrim($out, "\n");
}

function shorten(mixed $v): mixed
{
    if (is_string($v) && strlen($v) > 120) {
        return substr($v, 0, 100) . '...(' . strlen($v) . ' characters)';
    }
    if (is_array($v)) {
        $o = [];
        foreach ($v as $k => $x) {
            $o[$k] = shorten($x);
        }

        return $o;
    }

    return $v;
}

function pretty(mixed $v): string
{
    return (string) json_encode(shorten($v), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

function examplesBlock(string $root): string
{
    $pick = [
        ['04-enroll-flows.json', 'new-device-linked-by-serial', 'Enrollment (the device matched an asset by serial number)'],
        ['05-checkin.json', 'first-checkin-with-inventory', 'Check-in (inventory, metrics, checks; the response carries signed checks and a signed update manifest)'],
        ['06-jobs.json', 'offer-signed-job', 'Job offer (GET agent_jobs): a signed job'],
        ['06-jobs.json', 'report-running', 'Job report: the agent started the job'],
        ['06-jobs.json', 'report-succeeded-with-secrets', 'Job report: the result (the server redacts secrets in the stored output)'],
        ['07-update.json', 'download-offered-release', 'Hosted update download (the unstamped executable)'],
        ['08-installer.json', 'download-json-body', 'Installer download (the stamped executable; the transcript records the parsed trailer)'],
        ['03-enroll-errors.json', 'token-expired', 'An error response'],
        ['09-rate-limits.json', 'enroll-eleventh-is-rate-limited', 'A rate-limited response'],
    ];
    $byKey = [];
    foreach (transcriptSteps($root) as $s) {
        $byKey[$s['_file'] . '#' . $s['id']] = $s;
    }
    $skipHeaders = ['access-control-allow-headers', 'access-control-allow-methods', 'access-control-allow-origin', 'connection', 'date', 'host', 'x-powered-by', 'content-length'];
    $out = "Placeholders such as `<TOKEN#5>`, `<DEVICE:dev2>` and `<TS>` are the transcripts' masks for values that differ on every run (see `scripts/rmm-golden/README.md`); a `<SIG:ok:job>` is an Ed25519 signature that was verified against the test key.\n";
    foreach ($pick as [$file, $id, $title]) {
        $s = $byKey[$file . '#' . $id] ?? null;
        if ($s === null) {
            throw new RuntimeException("transcript step $file#$id not found");
        }
        $out .= "\n#### $title\n\n```http\n" . $s['request']['method'] . ' ' . $s['request']['path'] . "\n";
        foreach ($s['request']['headers'] ?? [] as $h => $v) {
            $out .= "$h: $v\n";
        }
        if (isset($s['request']['body']['json'])) {
            $out .= "\n" . pretty($s['request']['body']['json']) . "\n";
        }
        $out .= "\nHTTP " . $s['response']['status'] . "\n";
        foreach ($s['response']['headers'] as $h) {
            if (!in_array(strtolower(explode(':', $h)[0]), $skipHeaders, true)) {
                $out .= $h . "\n";
            }
        }
        if (isset($s['response']['body'])) {
            $out .= "\n" . pretty($s['response']['body']) . "\n";
        } elseif (isset($s['response']['body_binary'])) {
            $out .= "\n(binary body) " . pretty($s['response']['body_binary']) . "\n";
        }
        $out .= "```\n";
    }

    return rtrim($out, "\n");
}

function vectorsBlock(string $root): string
{
    $dir = $root . '/endpoint-agent/testdata/vectors';
    $j = json_decode((string) file_get_contents($dir . '/agent_job_signing_vectors.json'), true);
    $t = json_decode((string) file_get_contents($dir . '/agent_installer_trailer_vectors.json'), true);
    $out = "Both files live in `endpoint-agent/testdata/vectors/` and are read by the Go agent and by the PHP tests; `php scripts/endpoint-vectors.php --check` regenerates them and fails on any byte difference.\n\n";
    $out .= "| File | SHA-256 |\n|---|---|\n";
    foreach (['agent_job_signing_vectors.json', 'agent_installer_trailer_vectors.json'] as $f) {
        $out .= '| `' . $f . '` | `' . hash_file('sha256', $dir . '/' . $f) . "` |\n";
    }
    $out .= "\n**Job signing** (`agent_job_signing_vectors.json`). Test key: " . trim((string) preg_replace('/\s+/', ' ', $j['test_key']['seed_derivation'])) . '; public key `' . $j['test_key']['public_key_base64'] . "`.\n\n";
    $out .= 'Signed jobs (' . count($j['jobs']) . "):\n\n";
    foreach ($j['jobs'] as $v) {
        $out .= '- ' . $v['name'] . "\n";
    }
    $out .= "\nCanonical-form-only cases (" . count($j['canonical_only']) . '): ' . implode('; ', array_column($j['canonical_only'], 'name')) . ".\n";
    $out .= "\nAlso: one update-manifest signature (" . $j['update_manifest']['description'] . ') and one check-definition signature (' . $j['check_definition']['description'] . ").\n";
    $out .= "\n**Installer trailer** (`agent_installer_trailer_vectors.json`): footer " . $t['footer_length'] . ' bytes, payload at most ' . $t['max_payload'] . " bytes.\n\n";
    $out .= 'Stamped vectors (' . count($t['vectors']) . '): ' . implode('; ', array_column($t['vectors'], 'name')) . ".\n\n";
    $out .= 'Negative cases, every one of which must be rejected (' . count($t['negative']) . "):\n\n";
    foreach ($t['negative'] as $n) {
        $out .= '- ' . $n['name'] . ': ' . $n['reason'] . "\n";
    }

    return rtrim($out, "\n");
}

/** @param array<string,string> $blocks */
function applyBlocks(string $doc, array $blocks): string
{
    foreach ($blocks as $name => $text) {
        $re = '/(<!-- BEGIN GENERATED: ' . preg_quote($name, '/') . " -->\n).*?(<!-- END GENERATED: " . preg_quote($name, '/') . ' -->)/s';
        $count = 0;
        $doc = (string) preg_replace_callback($re, static fn (array $m): string => $m[1] . $text . "\n" . $m[2], $doc, 1, $count);
        if ($count !== 1) {
            throw new RuntimeException("marker pair for block '$name' not found in PROTOCOL.md");
        }
    }

    return $doc;
}

if (!is_file($docPath)) {
    fwrite(STDERR, "missing $docPath\n");
    exit(2);
}
$current = (string) file_get_contents($docPath);
$next = applyBlocks($current, protocolBlocks($root));
if (in_array('--check', array_slice($argv, 1), true)) {
    if ($next !== $current) {
        $a = explode("\n", $current);
        $b = explode("\n", $next);
        foreach ($b as $i => $line) {
            if (($a[$i] ?? null) !== $line) {
                fwrite(STDERR, 'docs/rmm/PROTOCOL.md is stale at line ' . ($i + 1) . ": run php scripts/rmm-protocol-doc.php\n  have: " . ($a[$i] ?? '(end)') . "\n  want: $line\n");
                break;
            }
        }
        exit(1);
    }
    echo "PROTOCOL.md is current\n";
    exit(0);
}
file_put_contents($docPath, $next);
echo "PROTOCOL.md updated\n";
