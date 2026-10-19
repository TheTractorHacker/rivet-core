<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Change;

use RivetCore\Rmm\Contracts\ChangeWatchPolicyInterface;
use RivetCore\Rmm\Crypto\CanonicalJson;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * The change-monitoring settings (one row of endpoint_agent_change_settings): which presets are on, the custom watches and the caps. Whether monitoring is ON is the
 * `change_monitor` sub-switch; this row only says what it watches and how much it may store. It also builds the SIGNED configuration an agent receives.
 *
 * The signed configuration: the watches of one platform, the polling interval and the agent-side caps, as one object with a `version` (a hash of everything the agent is
 * told) and an Ed25519 `signature` over {@see Signer::changeConfigMessage()}. The agent verifies it against the key it pinned at enrolment before it reads a single
 * registry value or file, so a response altered on the way (or a compromised proxy) cannot make an agent read anything.
 *
 * @api
 */
final class ChangeSettings
{
    /** @var array<string,array{0:int,1:int,2:int}> limit => [min, max, default] */
    public const LIMITS = [
        'change_retention_days' => [1, 730, 90],
        'change_device_max_rows' => [100, 200000, 5000],
        'change_fleet_max_rows' => [1000, 50000000, 500000],
        'change_rate_per_min' => [10, 2000, 120],
        'change_interval_s' => [60, 86400, 300],
        'change_max_value_bytes' => [64, 512, 256],
    ];

    public const STORAGE_WARNING = 'Change monitoring stores, in this server\'s database, what changed on every monitored device: registry keys and values, the lines added to or removed from configuration files, '
        . 'local group members and scheduled task definitions, with the user and program when Windows records them. Values that look like secrets are never read, hashed or stored, and credential stores (SAM, SECURITY, '
        . 'shadow files, private keys) are refused, but what remains can still contain user names, host names, paths and program command lines. It is capped per device and for the whole fleet and old records are '
        . 'deleted; choose the presets you need, keep retention short, and watch the storage figure shown here before turning it on for a large fleet.';

    /**
     * Stored bytes of one record (row plus its two indexes), measured on MariaDB 11.8 (docs/rmm/CHANGE_MONITOR.md, "Scale"): 470 for a typical registry, file, group or task change,
     * about 2900 for a record with every text at its maximum. The caps are shown against a budget of 900 (about twice the typical figure); the worst case is shown as well.
     */
    public const BYTES_PER_ROW_TYPICAL = 470;
    public const BYTES_PER_ROW = 900;
    public const BYTES_PER_ROW_WORST = 3000;

    /** @var array<string,mixed>|null */
    private ?array $cache = null;
    /** @var array<string,list<array<string,mixed>>> */
    private array $watchCache = [];

    public function __construct(private readonly Sql $sql, private readonly RmmSettings $settings, private readonly ChangeWatchPolicyInterface $policy)
    {
    }

    public function policy(): ChangeWatchPolicyInterface
    {
        return $this->policy;
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        if ($this->cache === null) {
            $row = $this->sql->one('SELECT * FROM endpoint_agent_change_settings WHERE id = 1');
            if ($row === null) {
                $this->sql->run('INSERT IGNORE INTO endpoint_agent_change_settings (id, updated_at) VALUES (1, ?)', [$this->sql->utcNow()]);
                $row = $this->sql->one('SELECT * FROM endpoint_agent_change_settings WHERE id = 1') ?? [];
            }
            $this->cache = $row;
        }

        return $this->cache;
    }

    public function refresh(): void
    {
        $this->cache = null;
        $this->watchCache = [];
    }

    /** @return array<string,int> */
    public function limits(): array
    {
        $out = array_map(static fn (array $l): int => $l[2], self::LIMITS);
        $raw = $this->row()['limits_json'] ?? null;
        $stored = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($stored)) {
            foreach (self::LIMITS as $k => [$min, $max]) {
                if (isset($stored[$k]) && is_int($stored[$k]) && $stored[$k] >= $min && $stored[$k] <= $max) {
                    $out[$k] = $stored[$k];
                }
            }
        }

        return $out;
    }

    /** @return array{presets:array{windows:list<string>,linux:list<string>},custom:list<array<string,mixed>>} */
    private function config(): array
    {
        $raw = $this->row()['config_json'] ?? null;
        $d = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $out = ['presets' => ['windows' => [], 'linux' => []], 'custom' => []];
        if (!is_array($d)) {
            return $out;
        }
        $cat = ChangePresets::catalog();
        foreach (['windows', 'linux'] as $os) {
            foreach (is_array($d['presets'][$os] ?? null) ? $d['presets'][$os] : [] as $id) {
                if (is_string($id) && isset($cat[$id]) && $cat[$id]['platform'] === $os && !in_array($id, $out['presets'][$os], true)) {
                    $out['presets'][$os][] = $id;
                }
            }
        }
        foreach (is_array($d['custom'] ?? null) ? $d['custom'] : [] as $c) {
            if (is_array($c) && isset($c['id'], $c['platform'], $c['kind'], $c['paths']) && count($out['custom']) < ChangeProtocol::MAX_CUSTOM_WATCHES) {
                $out['custom'][] = $c;
            }
        }

        return $out;
    }

    /** @return list<string> enabled preset ids for a platform */
    public function enabledPresets(string $os): array
    {
        return $this->config()['presets'][$os === 'windows' ? 'windows' : 'linux'];
    }

    /** @return list<array<string,mixed>> */
    public function customWatches(): array
    {
        return $this->config()['custom'];
    }

    /**
     * The watches a device of this platform is told to read: the enabled presets and the custom watches that (still) pass the policy.
     *
     * @return list<array<string,mixed>>
     */
    public function watchesFor(string $os): array
    {
        $os = $os === 'windows' ? 'windows' : 'linux';
        if (isset($this->watchCache[$os])) {
            return $this->watchCache[$os];
        }
        $out = [];
        foreach (ChangePresets::watchesFor($os, $this->enabledPresets($os)) as $w) {
            $out[] = $w;
        }
        foreach ($this->customWatches() as $c) {
            if ($c['platform'] !== $os) {
                continue;
            }
            $w = ['id' => (string) $c['id'], 'kind' => (string) $c['kind'], 'paths' => $c['paths']];
            if ($this->watchAllowed($w)) {
                $out[] = $w;
            }
        }

        return $this->watchCache[$os] = $out;
    }

    /** @param array<string,mixed> $watch */
    private function watchAllowed(array $watch): bool
    {
        if (!is_array($watch['paths'] ?? null)) {
            return false;
        }
        foreach ($watch['paths'] as $p) {
            $t = is_array($p) ? ($watch['kind'] === 'registry' ? ($p['key'] ?? null) : ($p['path'] ?? null)) : null;
            if (!is_string($t) || !$this->policy->allows((string) $watch['kind'], $t, 0)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,array<string,mixed>> watch id => spec, for the ingest check */
    public function knownWatches(string $os): array
    {
        $out = [];
        foreach ($this->watchesFor($os) as $w) {
            $out[(string) $w['id']] = $w;
        }

        return $out;
    }

    /**
     * Is a reported target inside what this watch was told to read? A rogue device may only record changes below its own watched paths.
     *
     * @param array<string,mixed> $watch
     */
    public static function targetInWatch(array $watch, string $target): bool
    {
        switch ($watch['kind'] ?? '') {
            case 'group':
                return $target === 'group:' . (string) ($watch['name'] ?? '');
            case 'task':
                return $target === 'scheduled-tasks';
            case 'registry':
                $t = self::normaliseKey($target);
                foreach ($watch['paths'] ?? [] as $p) {
                    $k = self::normaliseKey((string) ($p['key'] ?? ''));
                    if ($k === '') {
                        continue;
                    }
                    $re = '/^' . str_replace('\\*', '[^\\\\]+', preg_quote($k, '/')) . '(?:\\\\.*)?$/isD';
                    if (preg_match($re, $t) === 1) {
                        return true;
                    }
                }

                return false;
            case 'file':
                $win = preg_match('/^[A-Za-z]:\\\\/', $target) === 1;
                foreach ($watch['paths'] ?? [] as $p) {
                    $f = (string) ($p['path'] ?? '');
                    $a = $win ? strtolower($target) : $target;
                    $b = $win ? strtolower($f) : $f;
                    $sep = $win ? '\\' : '/';
                    if ($b !== '' && ($a === $b || str_starts_with($a, rtrim($b, $sep) . $sep))) {
                        return !str_contains($target, $sep . '..' . $sep) && !str_ends_with($target, $sep . '..');
                    }
                }

                return false;
        }

        return false;
    }

    public static function normaliseKey(string $key): string
    {
        return (string) preg_replace(['/^HKEY_LOCAL_MACHINE\\\\/i', '/^HKEY_USERS\\\\/i'], ['HKLM\\', 'HKU\\'], $key);
    }

    /**
     * The signed `config.change` object for a platform, or null when nothing is watched there, the object would be too big, or no signing key is available.
     *
     * @return array<string,mixed>|null
     */
    public function agentConfig(string $os): ?array
    {
        $os = $os === 'windows' ? 'windows' : 'linux';
        $watches = $this->watchesFor($os);
        if ($watches === []) {
            return null;
        }
        $l = $this->limits();
        $cfg = ['v' => ChangeProtocol::CONFIG_VERSION, 'kind' => 'change_config', 'platform' => $os, 'interval_s' => $l['change_interval_s'], 'batch_max' => ChangeProtocol::BATCH_MAX,
            'rate_per_min' => $l['change_rate_per_min'], 'max_value_bytes' => $l['change_max_value_bytes'], 'watches' => $watches,
            // monotonic: the time of the last settings change. The agent keeps the highest it has seen and refuses a lower one, so an old signed configuration cannot be replayed to undo a narrowing
            'seq' => max(1, Sql::ts((string) ($this->row()['updated_at'] ?? '')))];
        $cfg['version'] = substr(hash('sha256', Signer::changeConfigMessage($cfg)), 0, 16);
        try {
            [$sec] = $this->settings->signingKey();
            $cfg['signature'] = Signer::sign(Signer::changeConfigMessage($cfg), $sec);
        } catch (\Throwable) {
            return null;   // no usable signing key: an unsigned configuration is never offered
        }
        if (strlen((string) json_encode($cfg)) > ChangeProtocol::CONFIG_MAX_BYTES) {
            error_log('rmm change monitor: the signed configuration for ' . $os . ' is over ' . ChangeProtocol::CONFIG_MAX_BYTES . ' bytes and was not offered');

            return null;
        }

        return $cfg;
    }

    // ------------------------------------------------------------------ the administrator's view

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $l = $this->limits();
        $cfg = $this->config();
        $presets = [];
        foreach (ChangePresets::catalog() as $id => $p) {
            $presets[] = ['id' => $id, 'platform' => $p['platform'], 'kind' => $p['kind'], 'category' => $p['category'], 'title' => $p['title'], 'description' => $p['description'],
                'recommended' => $p['recommended'], 'enabled' => $p['watch'] === null ? true : in_array($id, $cfg['presets'][$p['platform']], true), 'source' => $p['source'] ?? 'agent',
                'recommended_rule' => $p['rule']];
        }

        return [
            'presets' => $presets,
            'custom' => $cfg['custom'],
            'limits' => $l,
            'storage_warning' => self::STORAGE_WARNING,
            'bytes_per_record' => ['typical' => self::BYTES_PER_ROW_TYPICAL, 'budget' => self::BYTES_PER_ROW, 'worst' => self::BYTES_PER_ROW_WORST],
            'device_cap_bytes' => $l['change_device_max_rows'] * self::BYTES_PER_ROW,
            'fleet_cap_bytes' => $l['change_fleet_max_rows'] * self::BYTES_PER_ROW,
            'worst_case_bytes_per_device' => $l['change_device_max_rows'] * self::BYTES_PER_ROW_WORST,
            'fleet_worst_case_bytes' => $l['change_fleet_max_rows'] * self::BYTES_PER_ROW_WORST,
        ];
    }

    /**
     * Validate and save. Only the keys present are touched; nothing is written when any value is refused.
     *
     * @param array<string,mixed> $in presets {windows:[id], linux:[id]}, custom [watch], limits {name:int}
     * @return list<string> error messages; empty when saved
     */
    public function update(array $in, int $userId): array
    {
        $err = [];
        $cur = $this->config();
        $set = [];
        if (array_key_exists('presets', $in)) {
            $d = $in['presets'];
            $presets = $cur['presets'];
            if (!is_array($d)) {
                $err[] = 'presets must be an object with windows and linux lists of preset ids.';
            } else {
                $cat = ChangePresets::catalog();
                foreach ($d as $os => $ids) {
                    if (!in_array($os, ['windows', 'linux'], true) || !is_array($ids) || !array_is_list($ids) || count($ids) > 40) {
                        $err[] = 'presets takes windows and linux, each a list of preset ids.';
                        continue;
                    }
                    $list = [];
                    foreach ($ids as $id) {
                        if (!is_string($id) || !isset($cat[$id]) || $cat[$id]['platform'] !== $os) {
                            $err[] = 'Not a ' . $os . ' preset: ' . (is_string($id) ? mb_substr($id, 0, 60) : 'value') . '.';
                            continue;
                        }
                        if ($cat[$id]['watch'] !== null && !in_array($id, $list, true)) {
                            $list[] = $id;
                        }
                    }
                    $presets[$os] = $list;
                }
            }
            $cur['presets'] = $presets;
            $set['config'] = true;
        }
        if (array_key_exists('custom', $in)) {
            [$custom, $e] = $this->validateCustom($in['custom']);
            array_push($err, ...$e);
            $cur['custom'] = $custom;
            $set['config'] = true;
        }
        $limits = null;
        if (array_key_exists('limits', $in)) {
            $limits = $this->limits();
            $d = $in['limits'];
            if (!is_array($d)) {
                $err[] = 'limits must be an object of limit name to whole number.';
            } else {
                foreach ($d as $k => $v) {
                    $k = (string) $k;
                    if (!isset(self::LIMITS[$k])) {
                        $err[] = "Unknown limit $k.";
                        continue;
                    }
                    [$min, $max] = self::LIMITS[$k];
                    if (!is_int($v) || $v < $min || $v > $max) {
                        $err[] = "Limit $k must be a whole number from $min to $max.";
                        continue;
                    }
                    $limits[$k] = $v;
                }
            }
        }
        if ($err !== []) {
            return $err;
        }
        $sets = [];
        $params = [];
        if (isset($set['config'])) {
            $sets[] = 'config_json = ?';
            $params[] = (string) json_encode($cur, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($limits !== null) {
            $sets[] = 'limits_json = ?';
            $params[] = (string) json_encode($limits);
        }
        if ($sets !== []) {
            $this->row();
            $sets[] = 'updated_by = ?';
            $sets[] = 'updated_at = ?';
            array_push($params, $userId, $this->sql->utcNow());
            $this->sql->run('UPDATE endpoint_agent_change_settings SET ' . implode(', ', $sets) . ' WHERE id = 1', $params);
            $this->refresh();
        }

        return [];
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    private function validateCustom(mixed $d): array
    {
        $err = [];
        $out = [];
        if (!is_array($d) || !array_is_list($d) || count($d) > ChangeProtocol::MAX_CUSTOM_WATCHES) {
            return [[], ['custom must be a list of at most ' . ChangeProtocol::MAX_CUSTOM_WATCHES . ' watches.']];
        }
        $seen = [];
        foreach ($d as $i => $w) {
            if (!is_array($w) || array_is_list($w) || array_diff(array_keys($w), ['id', 'platform', 'kind', 'title', 'paths']) !== []) {
                $err[] = "Custom watch #$i: only id, platform, kind, title and paths.";
                continue;
            }
            $id = is_string($w['id'] ?? null) ? strtolower($w['id']) : '';
            if ($id !== '' && !str_starts_with($id, 'custom.')) {
                $id = 'custom.' . $id;
            }
            if (preg_match(ChangeProtocol::WATCH_ID_RE, $id) !== 1 || !str_starts_with($id, 'custom.') || isset($seen[$id])) {
                $err[] = "Custom watch #$i: the id is missing, repeated or not custom.<letters, digits, _> (at most 40).";
                continue;
            }
            $seen[$id] = true;
            $platform = $w['platform'] ?? null;
            $kind = $w['kind'] ?? null;
            if (!in_array($platform, ['windows', 'linux'], true) || !in_array($kind, ['registry', 'file'], true) || ($kind === 'registry' && $platform !== 'windows')) {
                $err[] = "Custom watch $id: platform is windows or linux; kind is registry (Windows only) or file.";
                continue;
            }
            $title = $w['title'] ?? $id;
            if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 100 || preg_match('/[\x00-\x1f\x7f]/', $title) === 1) {
                $err[] = "Custom watch $id: a title of 1 to 100 printable characters.";
                continue;
            }
            $paths = $w['paths'] ?? null;
            if (!is_array($paths) || !array_is_list($paths) || $paths === [] || count($paths) > ChangeProtocol::MAX_PATHS_PER_WATCH) {
                $err[] = "Custom watch $id: paths is a list of 1 to " . ChangeProtocol::MAX_PATHS_PER_WATCH . ' entries.';
                continue;
            }
            $clean = [];
            $bad = false;
            foreach ($paths as $p) {
                $c = $kind === 'registry' ? $this->cleanRegistryPath($p, $id, $err) : $this->cleanFilePath($p, (string) $platform, $id, $err);
                if ($c === null) {
                    $bad = true;
                    break;
                }
                $clean[] = $c;
            }
            if (!$bad) {
                $out[] = ['id' => $id, 'platform' => $platform, 'kind' => $kind, 'title' => trim($title), 'paths' => $clean];
            }
        }

        return [$out, $err];
    }

    /**
     * @param list<string> $err
     * @return array<string,mixed>|null
     */
    private function cleanRegistryPath(mixed $p, string $id, array &$err): ?array
    {
        if (!is_array($p) || array_diff(array_keys($p), ['key', 'depth', 'values']) !== [] || !is_string($p['key'] ?? null)) {
            $err[] = "Custom watch $id: a registry path is {key, depth?, values?}.";

            return null;
        }
        if (!$this->policy->allows('registry', $p['key'], 0)) {
            $err[] = "Custom watch $id: that registry key cannot be watched (credential hives, whole hives and unsupported hives are refused): " . mb_substr(ChangeProtocol::text($p['key'], 120), 0, 120) . '.';

            return null;
        }
        $depth = $p['depth'] ?? 0;
        if (!is_int($depth) || $depth < 0 || $depth > 4) {
            $err[] = "Custom watch $id: depth is a whole number from 0 to 4.";

            return null;
        }
        $out = ['key' => $p['key'], 'depth' => $depth];
        if (array_key_exists('values', $p)) {
            $v = $p['values'];
            if (!is_array($v) || !array_is_list($v) || $v === [] || count($v) > 20) {
                $err[] = "Custom watch $id: values is a list of 1 to 20 value names.";

                return null;
            }
            foreach ($v as $name) {
                if (!is_string($name) || $name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                    $err[] = "Custom watch $id: a value name is 1 to 100 printable characters.";

                    return null;
                }
            }
            $out['values'] = array_values(array_unique($v));
        }

        return $out;
    }

    /**
     * @param list<string> $err
     * @return array<string,mixed>|null
     */
    private function cleanFilePath(mixed $p, string $platform, string $id, array &$err): ?array
    {
        if (!is_array($p) || array_diff(array_keys($p), ['path', 'depth', 'match']) !== [] || !is_string($p['path'] ?? null)) {
            $err[] = "Custom watch $id: a file path is {path, depth?, match?}.";

            return null;
        }
        $win = preg_match('/^[A-Za-z]:\\\\/', $p['path']) === 1;
        if (($platform === 'windows') !== $win || !$this->policy->allows('file', $p['path'], 0)) {
            $err[] = "Custom watch $id: that path cannot be watched here (an absolute path of the right platform, below a configuration folder, and never a credential file or key): " . mb_substr(ChangeProtocol::text($p['path'], 120), 0, 120) . '.';

            return null;
        }
        $out = ['path' => $p['path']];
        if (array_key_exists('depth', $p)) {
            if (!is_int($p['depth']) || $p['depth'] < 0 || $p['depth'] > 3) {
                $err[] = "Custom watch $id: depth is a whole number from 0 to 3.";

                return null;
            }
            if ($p['depth'] > 0) {
                $out['depth'] = $p['depth'];
            }
        }
        if (array_key_exists('match', $p)) {
            if (!is_string($p['match']) || preg_match('/^[A-Za-z0-9._*?-]{1,60}$/D', $p['match']) !== 1) {
                $err[] = "Custom watch $id: match is a file name pattern of letters, digits and . _ - * ?";

                return null;
            }
            $out['match'] = $p['match'];
        }

        return $out;
    }
}
