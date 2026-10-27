<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Change\ChangeProtocol;
use RivetCore\Rmm\Logs\SecurityLogPresets;
use RivetCore\Rmm\Support\Sql;

/**
 * The remote-tools and log-collection settings (one row of endpoint_agent_remote_settings): the folders file transfer may touch, what the
 * log collectors read, and the caps on both. Kept apart from the module's settings row, which is close to its size limit and mirrored into the
 * state file. Whether a feature is ON is the sub-switch (`remote_tools`, `filetransfer`, `logs`); this row only says how it behaves.
 *
 * @api
 */
final class RemoteSettings
{
    /** @var array<string,array{0:int,1:int,2:int}> limit => [min, max, default] */
    public const LIMITS = [
        'transfer_max_bytes' => [1024, RemoteProtocol::TRANSFER_HARD_MAX_BYTES, 52428800],
        'transfer_ttl_s' => [600, 604800, 86400],
        'transfer_store_max_bytes' => [1048576, 107374182400, 1073741824],
        'logs_retention_days' => [1, 90, 7],
        'logs_device_max_rows' => [100, 1000000, 20000],
        'logs_fleet_max_rows' => [1000, 200000000, 2000000],
        'logs_rate_per_min' => [10, 6000, 600],
        'logs_interval_s' => [30, 3600, 60],
    ];

    public const FILE_TRANSFER_RISK = 'File transfer lets technicians copy files off managed devices and lets administrators put files onto them. '
        . 'Anything inside an allowed folder can leave the device, including documents that hold customer or personal data, and a file sent to a device can be a program that runs later. '
        . 'Password databases, private keys, system credential stores and the agent\'s own files are never transferable, and every transfer is written to the audit log, '
        . 'but a copied file cannot be un-copied. Allow only the folders you need, keep the size limit low, and turn this on only if your security policy permits it.';

    public const LOGS_STORAGE_WARNING = 'Log collection stores the text of event-log and system-log entries from every device that sends them, in this server\'s database. '
        . 'It is the heaviest optional part of the RMM: it multiplies database size and write load by the number of devices, and log text can contain user names, addresses and other personal data. '
        . 'Collection is capped per device and for the whole fleet and old entries are deleted, but choose narrow sources (warnings and errors from a few channels or services), '
        . 'keep retention short and watch the storage figure shown here before you turn it on for a large fleet.';

    private const CHANNEL_RE = '/^[A-Za-z0-9][A-Za-z0-9 _.\/%-]{0,99}$/';
    private const UNIT_RE = '/^[A-Za-z0-9][A-Za-z0-9:_.@-]{0,199}$/';

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Sql $sql)
    {
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        if ($this->cache === null) {
            $row = $this->sql->one('SELECT * FROM endpoint_agent_remote_settings WHERE id = 1');
            if ($row === null) {
                $this->sql->run('INSERT IGNORE INTO endpoint_agent_remote_settings (id, updated_at) VALUES (1, ?)', [$this->sql->utcNow()]);
                $row = $this->sql->one('SELECT * FROM endpoint_agent_remote_settings WHERE id = 1') ?? [];
            }
            $this->cache = $row;
        }

        return $this->cache;
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    private ?\RivetCore\Rmm\Settings\RetentionFloorsInterface $floors = null;

    /**
     * Minimum retention imposed from outside (the selected compliance framework): {@see update()} refuses a log retention below it and {@see limits()}
     * never answers less. Null (the default) changes nothing.
     */
    public function setRetentionFloors(?\RivetCore\Rmm\Settings\RetentionFloorsInterface $floors): void
    {
        $this->floors = $floors;
    }

    /** @return array<string,int> */
    public function limits(): array
    {
        return \RivetCore\Rmm\Settings\RetentionClasses::raise($this->storedLimits(), \RivetCore\Rmm\Settings\RetentionClasses::SOURCE_REMOTE, $this->floors);
    }

    /** @return array<string,int> the limits as stored, without any floor (what {@see update()} merges into) */
    private function storedLimits(): array
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

    /** @return array{linux:list<string>,windows:list<string>} */
    public function allowRoots(): array
    {
        $raw = $this->row()['transfer_allow_json'] ?? null;
        $d = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $out = ['linux' => [], 'windows' => []];
        if (is_array($d)) {
            foreach (['linux', 'windows'] as $os) {
                foreach (is_array($d[$os] ?? null) ? $d[$os] : [] as $r) {
                    if (is_string($r) && PathPolicy::validRoot($r, $os)) {
                        $out[$os][] = $r;
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function allowRootsFor(string $os): array
    {
        return $this->allowRoots()[$os === 'windows' ? 'windows' : 'linux'];
    }

    /**
     * The log sources for one platform, in the shape the agent receives them (see docs/rmm/PROTOCOL.md 3.6).
     *
     * @return list<array<string,mixed>>
     */
    public function logSources(string $os): array
    {
        $raw = $this->row()['logs_collect_json'] ?? null;
        $d = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $list = is_array($d) && is_array($d[$os === 'windows' ? 'windows' : 'linux'] ?? null) ? $d[$os === 'windows' ? 'windows' : 'linux'] : [];

        /** @var list<array<string,mixed>> $list */
        return $list;
    }

    /**
     * The named security presets switched on for the Windows event-log collector ({@see \RivetCore\Rmm\Logs\SecurityLogPresets}).
     *
     * @return list<string>
     */
    public function logPresets(): array
    {
        $raw = $this->row()['logs_collect_json'] ?? null;
        $d = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $out = [];
        foreach (is_array($d) && is_array($d['presets'] ?? null) ? $d['presets'] : [] as $id) {
            if (is_string($id) && isset(SecurityLogPresets::catalog()[$id]) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * The `config.logs` object for a platform. The version changes exactly when something the agent is told changes.
     *
     * @param list<string>|null $caps what the device announced: the aggregated security presets are added only for an agent that announced `logs:presets`
     * @return array<string,mixed>|null null when no source is configured for the platform
     */
    public function agentLogConfig(string $os, ?array $caps = null): ?array
    {
        $sources = $this->logSources($os);
        if ($os === 'windows' && ($presets = $this->logPresets()) !== []) {
            $sources = [...$sources, ...SecurityLogPresets::sourcesFor($presets, in_array(ChangeProtocol::CAP_LOG_PRESETS, $caps ?? [], true))];
        }
        if ($sources === []) {
            return null;
        }
        $l = $this->limits();
        $cfg = ['interval_s' => $l['logs_interval_s'], 'batch_max' => RemoteProtocol::LOG_BATCH_MAX, 'max_message_bytes' => RemoteProtocol::LOG_MESSAGE_MAX,
            'rate_per_min' => $l['logs_rate_per_min'], 'sources' => $sources];

        return ['version' => substr(hash('sha256', (string) json_encode($cfg)), 0, 16)] + $cfg;
    }

    /**
     * What an administrator sees on the settings page.
     *
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $l = $this->limits();
        $perRow = 600;

        return [
            'transfer_allow' => $this->allowRoots(),
            'logs_collect' => ['linux' => $this->logSources('linux'), 'windows' => $this->logSources('windows'), 'presets' => $this->logPresets()],
            'log_presets' => array_map(fn (string $id, array $p): array => ['id' => $id, 'title' => $p['title'], 'description' => $p['description'], 'platform' => $p['platform'],
                'aggregated' => $p['aggregate'], 'enabled' => in_array($id, $this->logPresets(), true)], array_keys(SecurityLogPresets::catalog()), SecurityLogPresets::catalog()),
            'limits' => $l,
            'file_transfer_risk' => self::FILE_TRANSFER_RISK,
            'logs_storage_warning' => self::LOGS_STORAGE_WARNING,
            'logs_worst_case_bytes_per_device' => $l['logs_device_max_rows'] * $perRow,
            'logs_fleet_cap_bytes' => $l['logs_fleet_max_rows'] * $perRow,
        ];
    }

    /**
     * Validate and save. Only the keys present are touched; nothing is written when any value is refused.
     *
     * @param array<string,mixed> $in transfer_allow {linux:[..],windows:[..]}, logs_collect {linux:[..],windows:[..]}, limits {name:int}
     * @return list<string> error messages; empty when saved
     */
    public function update(array $in, int $userId): array
    {
        $err = [];
        $set = [];
        if (array_key_exists('transfer_allow', $in)) {
            $allow = ['linux' => [], 'windows' => []];
            $d = $in['transfer_allow'];
            if (!is_array($d)) {
                $err[] = 'transfer_allow must be an object with linux and windows folder lists.';
            } else {
                $total = ['linux' => 0, 'windows' => 0];
                foreach ($d as $os => $roots) {
                    if (!in_array($os, ['linux', 'windows'], true) || !is_array($roots) || !array_is_list($roots) || count($roots) > 10) {
                        $err[] = 'transfer_allow takes linux and windows, each a list of at most 10 folders.';
                        continue;
                    }
                    foreach ($roots as $r) {
                        if (!is_string($r) || !PathPolicy::validRoot($r, $os)) {
                            $err[] = 'Not an allowed folder: ' . (is_string($r) ? mb_substr($r, 0, 80) : 'value')
                                . '. Use a full path at least two levels deep (for example /srv/shared or C:\\Data); never a whole drive, /etc, /root or a folder that holds credentials.';
                            continue;
                        }
                        $total[$os] += strlen($r) + 1;
                        $allow[$os][] = $r;
                    }
                    $allow[$os] = array_values(array_unique($allow[$os]));
                    if ($total[$os] > RemoteProtocol::TRANSFER_ALLOW_MAX) {
                        $err[] = 'The allowed folders for ' . $os . ' are too long in total (at most ' . RemoteProtocol::TRANSFER_ALLOW_MAX . ' characters).';
                    }
                }
                $set['transfer_allow_json'] = (string) json_encode($allow);
            }
        }
        if (array_key_exists('logs_collect', $in)) {
            [$json, $e] = $this->validateSources($in['logs_collect']);
            if ($e !== []) {
                array_push($err, ...$e);
            } else {
                $set['logs_collect_json'] = $json;
            }
        }
        if (array_key_exists('limits', $in)) {
            $cur = $this->storedLimits();
            $d = $in['limits'];
            if (!is_array($d)) {
                $err[] = 'limits must be an object of limit name to whole number.';
            } else {
                array_push($err, ...\RivetCore\Rmm\Settings\RetentionClasses::violations($d, \RivetCore\Rmm\Settings\RetentionClasses::SOURCE_REMOTE, $this->floors));
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
                    $cur[$k] = $v;
                }
                $set['limits_json'] = (string) json_encode($cur);
            }
        }
        if ($err !== []) {
            return $err;
        }
        if ($set !== []) {
            $this->row();
            $sets = [];
            $params = [];
            foreach ($set as $k => $v) {
                $sets[] = "`$k` = ?";
                $params[] = $v;
            }
            $sets[] = 'updated_by = ?';
            $sets[] = 'updated_at = ?';
            array_push($params, $userId, $this->sql->utcNow());
            $this->sql->run('UPDATE endpoint_agent_remote_settings SET ' . implode(', ', $sets) . ' WHERE id = 1', $params);
            $this->cache = null;
        }

        return [];
    }

    /**
     * @return array{0:string,1:list<string>} [normalised JSON, errors]
     */
    private function validateSources(mixed $d): array
    {
        $out = ['linux' => [], 'windows' => []];
        $err = [];
        if (!is_array($d)) {
            return ['', ['logs_collect must be an object with linux and windows source lists.']];
        }
        // Only the presets sent: keep the sources that are stored. The presets absent from a request keep their stored value too.
        if (array_keys($d) === ['presets']) {
            $out = ['linux' => $this->logSources('linux'), 'windows' => $this->logSources('windows')];
        }
        $presets = array_key_exists('presets', $d) ? [] : $this->logPresets();
        if (array_key_exists('presets', $d)) {
            if (!is_array($d['presets']) || !array_is_list($d['presets']) || count($d['presets']) > 20) {
                $err[] = 'logs_collect presets is a list of preset ids.';
            } else {
                foreach ($d['presets'] as $id) {
                    if (!is_string($id) || !isset(SecurityLogPresets::catalog()[$id])) {
                        $err[] = 'Unknown log preset ' . (is_string($id) ? mb_substr($id, 0, 60) : 'value') . '.';
                        continue;
                    }
                    if (!in_array($id, $presets, true)) {
                        $presets[] = $id;
                    }
                }
            }
            unset($d['presets']);
        }
        foreach ($d as $os => $list) {
            if (!in_array($os, ['linux', 'windows'], true) || !is_array($list) || !array_is_list($list) || count($list) > 20) {
                $err[] = 'logs_collect takes linux and windows, each a list of at most 20 sources.';
                continue;
            }
            foreach ($list as $i => $src) {
                if (!is_array($src)) {
                    $err[] = "Source #$i is not an object.";
                    continue;
                }
                $clean = $os === 'windows' ? $this->windowsSource($src, $err, (int) $i) : $this->linuxSource($src, $err, (int) $i);
                if ($clean !== null) {
                    $out[$os][] = $clean;
                }
            }
        }
        if ($presets !== []) {
            $out['presets'] = $presets;
        }

        return [(string) json_encode($out), $err];
    }

    /**
     * @param array<mixed> $s
     * @param list<string> $err
     * @return array<string,mixed>|null
     */
    private function windowsSource(array $s, array &$err, int $i): ?array
    {
        if (array_diff(array_keys($s), ['kind', 'channel', 'levels', 'event_ids', 'contains']) !== [] || ($s['kind'] ?? 'eventlog') !== 'eventlog') {
            $err[] = "Windows source #$i: only kind eventlog with channel, levels, event_ids and contains.";

            return null;
        }
        $ch = $s['channel'] ?? null;
        if (!is_string($ch) || preg_match(self::CHANNEL_RE, $ch) !== 1) {
            $err[] = "Windows source #$i: channel is missing or has characters that are not allowed.";

            return null;
        }
        $levels = $this->levels($s['levels'] ?? ['critical', 'error', 'warning'], $err, "Windows source #$i");
        $ids = $s['event_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 50) {
            $err[] = "Windows source #$i: event_ids is a list of at most 50 numbers.";

            return null;
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 0 || $id > 65535) {
                $err[] = "Windows source #$i: an event id must be a whole number from 0 to 65535.";

                return null;
            }
        }
        $contains = $this->contains($s['contains'] ?? [], $err, "Windows source #$i");

        return $levels === null || $contains === null ? null : ['kind' => 'eventlog', 'channel' => $ch, 'levels' => $levels, 'event_ids' => array_values(array_unique($ids)), 'contains' => $contains];
    }

    /**
     * @param array<mixed> $s
     * @param list<string> $err
     * @return array<string,mixed>|null
     */
    private function linuxSource(array $s, array &$err, int $i): ?array
    {
        if (array_diff(array_keys($s), ['kind', 'units', 'identifiers', 'min_level', 'contains']) !== [] || ($s['kind'] ?? 'journal') !== 'journal') {
            $err[] = "Linux source #$i: only kind journal with units, identifiers, min_level and contains.";

            return null;
        }
        $lists = [];
        foreach (['units', 'identifiers'] as $k) {
            $v = $s[$k] ?? [];
            if (!is_array($v) || !array_is_list($v) || count($v) > 20) {
                $err[] = "Linux source #$i: $k is a list of at most 20 names.";

                return null;
            }
            foreach ($v as $name) {
                if (!is_string($name) || preg_match(self::UNIT_RE, $name) !== 1) {
                    $err[] = "Linux source #$i: $k holds a name with characters that are not allowed (letters, digits and : _ . @ - only).";

                    return null;
                }
            }
            $lists[$k] = array_values(array_unique($v));
        }
        $min = $s['min_level'] ?? 'warning';
        if (!is_string($min) || !in_array($min, RemoteProtocol::LOG_LEVELS, true)) {
            $err[] = "Linux source #$i: min_level must be one of " . implode(', ', RemoteProtocol::LOG_LEVELS) . '.';

            return null;
        }
        $contains = $this->contains($s['contains'] ?? [], $err, "Linux source #$i");

        return $contains === null ? null : ['kind' => 'journal', 'units' => $lists['units'], 'identifiers' => $lists['identifiers'], 'min_level' => $min, 'contains' => $contains];
    }

    /**
     * @param list<string> $err
     * @return list<string>|null
     */
    private function levels(mixed $v, array &$err, string $where): ?array
    {
        if (!is_array($v) || !array_is_list($v) || $v === []) {
            $err[] = "$where: levels is a non-empty list.";

            return null;
        }
        foreach ($v as $l) {
            if (!is_string($l) || !in_array($l, ['critical', 'error', 'warning', 'info', 'verbose'], true)) {
                $err[] = "$where: a level must be critical, error, warning, info or verbose.";

                return null;
            }
        }

        return array_values(array_unique($v));
    }

    /**
     * @param list<string> $err
     * @return list<string>|null
     */
    private function contains(mixed $v, array &$err, string $where): ?array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > 10) {
            $err[] = "$where: contains is a list of at most 10 phrases.";

            return null;
        }
        foreach ($v as $p) {
            if (!is_string($p) || $p === '' || mb_strlen($p) > 80 || preg_match('/[\x00-\x1f\x7f]/', $p) === 1 || !mb_check_encoding($p, 'UTF-8')) {
                $err[] = "$where: each phrase is 1 to 80 printable characters.";

                return null;
            }
        }

        return array_values(array_unique($v));
    }
}
