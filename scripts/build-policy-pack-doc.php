<?php

declare(strict_types=1);

/**
 * Regenerates the item tables of docs/rmm/POLICY_STARTER_PACK.md (between its `policypack:items` markers) from the manifest in src/Rmm/Policy/StarterPack/data/pack.php.
 *
 *   php scripts/build-policy-pack-doc.php           rewrite
 *   php scripts/build-policy-pack-doc.php --check   exit 1 when stale (the test suite runs the same comparison)
 */

require __DIR__ . '/../vendor/autoload.php';

use RivetCore\Rmm\Policy\StarterPack\PolicyPack;

$file = __DIR__ . '/../docs/rmm/POLICY_STARTER_PACK.md';
$new = PolicyPackDoc::inject((string) file_get_contents($file), PolicyPackDoc::render(PolicyPack::bundled()));
if (in_array('--check', $argv, true)) {
    if ($new !== file_get_contents($file)) {
        fwrite(STDERR, "docs/rmm/POLICY_STARTER_PACK.md is stale: run php scripts/build-policy-pack-doc.php\n");
        exit(1);
    }
    exit(0);
}
file_put_contents($file, $new);
echo "ok\n";

/** @internal */
final class PolicyPackDoc
{
    public static function render(PolicyPack $p): string
    {
        $cell = static fn (string $s): string => str_replace(['|', "\n"], ['\\|', ' '], $s);
        $out = '';
        $group = '';
        foreach ($p->items() as $i) {
            if ($i['group'] !== $group) {
                $group = $i['group'];
                $out .= "\n### $group\n";
            }
            $out .= "\n#### {$i['name']}\n\n`{$i['id']}` · {$i['kind']} · platform: {$i['platform']} · revision {$i['rev']}\n\n";
            $out .= "**What it does.** {$i['summary']}\n\n**Why.** {$i['why']}\n\n";
            foreach ($i as $k => $v) {
                if (str_starts_with((string) $k, 'note_')) {
                    $out .= "**Note.** $v\n\n";
                }
            }
            if (!empty($i['params'])) {
                $out .= 'Install parameters: ' . implode('; ', array_map(static fn (string $k, array $d): string => "`$k` (default `{$d['default']}`): {$d['label']}", array_keys($i['params']), $i['params'])) . "\n\n";
            }
            $out .= "| Setting | Value | Why |\n|---|---|---|\n";
            $resolved = PolicyPack::resolve($i)['content'];
            $rows = match ($i['kind']) {
                'maintenance_window' => $resolved['window'],
                'change_presets' => ['presets' => $resolved['presets']],
                'patch_policy' => $resolved['settings'],
                default => array_map(static fn (array $e): mixed => $e['value'], $resolved['settings']),
            };
            foreach ($rows as $k => $v) {
                if (in_array($k, ['name', 'timezone', 'enabled', 'note', 'starts_at', 'ends_at', 'recur_from', 'recur_until'], true) && $i['kind'] === 'maintenance_window') {
                    continue;
                }
                if ($i['kind'] === 'maintenance_window' && !isset($i['why_settings'][$k])) {
                    continue;
                }
                $val = is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES) : json_encode($v);
                if (strlen((string) $val) > 150) {
                    $val = substr((string) $val, 0, 120) . ' ...(script)';
                }
                $out .= '| `' . $k . '` | `' . $cell((string) $val) . '` | ' . $cell((string) ($i['why_settings'][$k] ?? '')) . " |\n";
            }
        }

        return $out;
    }

    public static function inject(string $doc, string $body): string
    {
        $a = '<!-- policypack:items:start -->';
        $b = '<!-- policypack:items:end -->';
        $s = strpos($doc, $a);
        $e = strpos($doc, $b);
        if ($s === false || $e === false) {
            throw new RuntimeException('markers missing');
        }

        return substr($doc, 0, $s + strlen($a)) . "\n" . $body . "\n" . substr($doc, $e);
    }
}
