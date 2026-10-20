<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Policy\StarterPack;

use RivetCore\Rmm\Alerting\MaintenanceSchedule;
use RivetCore\Rmm\Change\ChangePresets;
use RivetCore\Rmm\Checks\CheckCatalog;
use RivetCore\Rmm\Patch\PatchPolicySettings;
use RivetCore\Rmm\Policy\PolicySettings;

/**
 * The policy starter pack: a versioned manifest (data/pack.php) of policies, alerting presets, patch policies, maintenance window templates and
 * change-monitor presets, loaded and validated with the very validators the UI and REST API use. {@see self::bundled()} throws on the first
 * invalid item, so an invalid pack can never be offered or installed.
 *
 * Identity needs no table: a pack policy carries the marker ` [pp|<item id>|<rev>|<hash>]` at the end of its description (a maintenance window in its
 * note). The hash is over what the pack owns (name, description, settings), so an edit by an administrator is detected by comparing.
 *
 * @api
 */
final class PolicyPack
{
    public const KINDS = ['check_policy', 'alert_preset', 'patch_policy', 'maintenance_window', 'change_presets'];
    /** The patch rings a patch policy of the pack points at (the defaults every install has). */
    public const RINGS = ['pilot', 'broad', 'last'];
    public const MAX_SUMMARY = 240;
    public const MARKER_RE = '/ \[pp\|([a-z0-9-]{3,60})\|(\d{1,4})\|([0-9a-f]{12})\]$/D';

    /** @var list<array<string,mixed>> */
    private array $items = [];

    /**
     * @param array<string,mixed> $manifest
     * @throws \InvalidArgumentException naming the item and the problem
     */
    public function __construct(private readonly array $manifest)
    {
        if (($manifest['format'] ?? null) !== 1 || !is_string($manifest['id'] ?? null) || preg_match('/^[a-z0-9-]{3,40}$/D', $manifest['id']) !== 1
            || !is_string($manifest['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/D', $manifest['version']) !== 1 || !is_array($manifest['items'] ?? null)) {
            throw new \InvalidArgumentException('Policy pack: bad header.');
        }
        $seen = [];
        foreach ($manifest['items'] as $raw) {
            $item = self::validateItem((array) $raw);
            if (isset($seen[$item['id']]) || isset($seen['n:' . $item['name']])) {
                throw new \InvalidArgumentException("Policy pack: duplicate id or name {$item['id']}.");
            }
            $seen[$item['id']] = $seen['n:' . $item['name']] = true;
            $this->items[] = $item;
        }
    }

    public static function bundled(): self
    {
        /** @var array<string,mixed> $m */
        $m = require __DIR__ . '/data/pack.php';

        return new self($m);
    }

    public function id(): string
    {
        return (string) $this->manifest['id'];
    }

    public function name(): string
    {
        return (string) $this->manifest['name'];
    }

    public function version(): string
    {
        return (string) $this->manifest['version'];
    }

    /** @return list<array<string,mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return array<string,mixed>|null */
    public function item(string $id): ?array
    {
        foreach ($this->items as $i) {
            if ($i['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The item with its install-time parameters applied and its data normalised by the real validators: what is written, and what the hash covers.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $params
     * @return array{name:string,description:string,content:array<string,mixed>}
     * @throws \InvalidArgumentException
     */
    public static function resolve(array $item, array $params = []): array
    {
        $values = [];
        foreach ((array) ($item['params'] ?? []) as $k => $def) {
            $v = $params[$k] ?? $def['default'];
            if (!is_string($v) || preg_match('/^[A-Za-z0-9_.@:\\/-]{1,64}$/D', $v) !== 1) {
                throw new \InvalidArgumentException("Parameter $k must be 1 to 64 letters, digits or . _ @ : / -.");
            }
            $values[$k] = $v;
        }
        foreach (array_keys($params) as $k) {
            if (!array_key_exists((string) $k, (array) ($item['params'] ?? []))) {
                throw new \InvalidArgumentException("Unknown parameter $k for {$item['id']}.");
            }
        }
        $body = self::subst($item['body'], $values);
        $kind = (string) $item['kind'];
        $content = ['name' => $item['name'], 'description' => $item['summary']];
        if ($kind === 'check_policy' || $kind === 'alert_preset') {
            [$n, $err] = PolicySettings::normalize($body['settings']);
            if ($err !== null || $n === null) {
                throw new \InvalidArgumentException($item['id'] . ': ' . ($err ?? 'invalid'));
            }
            $content['settings'] = $n;
        } elseif ($kind === 'patch_policy') {
            [$n, $err] = PatchPolicySettings::normalize($body['settings'], self::RINGS);
            if ($err !== null || $n === null) {
                throw new \InvalidArgumentException($item['id'] . ': ' . ($err ?? 'invalid'));
            }
            $content['settings'] = array_map(static fn (array $e): mixed => $e['value'], $n);
        } elseif ($kind === 'maintenance_window') {
            [$row, $err] = MaintenanceSchedule::validate($body['window'] + ['name' => $item['name'], 'enabled' => false, 'timezone' => $values['timezone'] ?? 'UTC', 'note' => $item['summary']]);
            if ($row === null) {
                throw new \InvalidArgumentException($item['id'] . ': ' . ($err ?? 'invalid'));
            }
            $content['window'] = $row;
        } else {
            foreach ($body['presets'] as $os => $ids) {
                foreach ($ids as $pid) {
                    $c = ChangePresets::catalog()[$pid] ?? null;
                    if ($c === null || $c['platform'] !== $os || $c['watch'] === null) {
                        throw new \InvalidArgumentException("{$item['id']}: $pid is not a $os watch preset.");
                    }
                }
            }
            $content['presets'] = $body['presets'];
        }

        return ['name' => (string) $item['name'], 'description' => (string) $item['summary'], 'content' => $content];
    }

    /**
     * The hash of what the pack owns of an entity: name, description (without the marker) and the kind's data. Short (48 bits) because it is not a security
     * boundary, only an edit detector.
     *
     * @param array<string,mixed> $content
     */
    public static function hash(array $content): string
    {
        return substr(hash('sha256', self::canonical($content)), 0, 12);
    }

    public static function marker(string $itemId, int $rev, string $hash): string
    {
        return " [pp|$itemId|$rev|$hash]";
    }

    /** @return array{0:string,1:int,2:string}|null [item id, rev, hash] */
    public static function parseMarker(string $text): ?array
    {
        return preg_match(self::MARKER_RE, $text, $m) === 1 ? [$m[1], (int) $m[2], $m[3]] : null;
    }

    public static function stripMarker(string $text): string
    {
        return (string) preg_replace(self::MARKER_RE, '', $text);
    }

    /** @param mixed $v */
    public static function canonical(mixed $v): string
    {
        $norm = static function (mixed $x) use (&$norm): mixed {
            if (!is_array($x)) {
                return $x;
            }
            $o = array_map($norm, $x);
            if (!array_is_list($o)) {
                ksort($o, SORT_STRING);
            }

            return $o;
        };

        return (string) json_encode($norm($v), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param mixed $v
     * @param array<string,string> $values
     */
    private static function subst(mixed $v, array $values): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (count($v) === 1 && isset($v['$param']) && is_string($v['$param'])) {
            if (!isset($values[$v['$param']])) {
                throw new \InvalidArgumentException('Unknown parameter ' . $v['$param'] . '.');
            }

            return $values[$v['$param']];
        }

        return array_map(static fn (mixed $x): mixed => self::subst($x, $values), $v);
    }

    /**
     * @param array<string,mixed> $i
     * @return array<string,mixed>
     */
    private static function validateItem(array $i): array
    {
        $id = (string) ($i['id'] ?? '');
        if (preg_match('/^[a-z0-9-]{3,60}$/D', $id) !== 1) {
            throw new \InvalidArgumentException("Policy pack: bad item id \"$id\".");
        }
        $bad = static fn (string $m): \InvalidArgumentException => new \InvalidArgumentException("Policy pack item $id: $m");
        if (!in_array($i['kind'] ?? null, self::KINDS, true)) {
            throw $bad('unknown kind.');
        }
        if (!is_int($i['rev'] ?? null) || $i['rev'] < 1 || $i['rev'] > 9999) {
            throw $bad('rev must be a positive whole number.');
        }
        if (!in_array($i['platform'] ?? null, ['windows', 'linux', 'any'], true)) {
            throw $bad('platform must be windows, linux or any.');
        }
        foreach (['name' => 100, 'summary' => self::MAX_SUMMARY, 'why' => 600, 'group' => 60] as $f => $max) {
            if (!is_string($i[$f] ?? null) || trim($i[$f]) === '' || mb_strlen($i[$f]) > $max || preg_match('/[\x00-\x1f]/', $i[$f]) === 1) {
                throw $bad("$f must be 1 to $max characters of plain text.");
            }
        }
        if (!is_array($i['body'] ?? null) || !is_array($i['why_settings'] ?? null)) {
            throw $bad('body and why_settings are required.');
        }
        foreach (array_keys($i) as $k) {
            if (!in_array($k, ['id', 'kind', 'rev', 'name', 'platform', 'group', 'summary', 'why', 'body', 'why_settings', 'params'], true) && !str_starts_with((string) $k, 'note_')) {
                throw $bad("unknown member $k.");
            }
        }
        $params = [];
        foreach ((array) ($i['params'] ?? []) as $k => $def) {
            if (!is_string($k) || preg_match('/^[a-z][a-z0-9_]{0,30}$/D', $k) !== 1 || !is_string($def['default'] ?? null) || !is_string($def['label'] ?? null)) {
                throw $bad('bad parameter definition.');
            }
            $params[$k] = $def['default'];
        }
        $r = self::resolve($i, []);   // the defaults must produce a valid item (throws)
        // every setting has a rationale, and none is left over
        $keys = match ($i['kind']) {
            'maintenance_window' => array_keys($i['body']['window']),
            'change_presets' => ['presets'],
            default => array_keys($i['body']['settings']),
        };
        foreach ($keys as $k) {
            if (!is_string($i['why_settings'][$k] ?? null) || trim($i['why_settings'][$k]) === '') {
                throw $bad("setting $k has no rationale.");
            }
        }
        // A pack item never targets the wrong OS: every check of a windows item says windows, a linux item says linux, and an any item
        // may only hold checks that run on both, or names its OS.
        if ($i['kind'] === 'check_policy' || $i['kind'] === 'alert_preset') {
            foreach ((array) $r['content']['settings'] as $key => $e) {
                if (!str_starts_with((string) $key, 'check.') || ($e['mode'] ?? '') !== 'override') {
                    continue;
                }
                $v = $e['value'];
                $os = $v['os'] ?? null;
                if ($v['type'] === 'disk') {   // a legacy type: the platform validators do not look at its params, so the pack does
                    foreach ($v['params'] as $pk => $pv) {
                        $max = str_ends_with((string) $pk, '_pct') ? 100 : 1000000;
                        if (!in_array($pk, ['warn_free_pct', 'fail_free_pct', 'warn_free_gb', 'fail_free_gb'], true) || !is_int($pv) || $pv < 0 || $pv > $max) {
                            throw $bad("check $key: bad disk parameter $pk.");
                        }
                    }
                    foreach (['pct', 'gb'] as $u) {
                        if (isset($v['params']["warn_free_$u"], $v['params']["fail_free_$u"]) && $v['params']["fail_free_$u"] > $v['params']["warn_free_$u"]) {
                            throw $bad("check $key: the fail limit must not be above the warn limit.");
                        }
                    }
                }
                $plats = CheckCatalog::types()[$v['type']]['platforms'] ?? ['windows', 'linux'];
                if ($i['platform'] !== 'any' && $os !== $i['platform']) {
                    throw $bad("check $key must say os={$i['platform']}.");
                }
                if ($os === null && count($plats) < 2) {
                    throw $bad("check $key runs on {$plats[0]} only and must say so with os.");
                }
                if ($os !== null && !in_array($os, $plats, true)) {
                    throw $bad("check $key of type {$v['type']} does not run on $os.");
                }
            }
        }
        if ($i['kind'] === 'change_presets') {
            foreach ($i['body']['presets'] as $os => $ids) {
                if ($i['platform'] !== 'any' && $os !== $i['platform']) {
                    throw $bad('presets for the wrong platform.');
                }
            }
        }
        if (mb_strlen($r['description']) + 40 > 300) {
            throw $bad('summary too long for a stored description with its marker.');
        }

        return $i;
    }
}
