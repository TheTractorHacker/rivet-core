<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Software;

use RivetCore\Rmm\Device\DeviceState;
use RivetCore\Rmm\Enrollment\DeviceValidator;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * The software inventory of the endpoint agent: validates a `software` block of a check-in, applies it to the current-state table
 * (`rmm_device_software`) and records every install, upgrade, downgrade and removal in the change log (`rmm_software_history`).
 *
 * A `full` report replaces the device's list, a `delta` is applied only when its `base_hash` is the hash the module holds. When it is not,
 * or when the applied list does not hash to what the agent says, the device is flagged to resync and the next check-in response asks for a
 * full list (see docs/rmm/PROTOCOL.md, 3.2.1). The first report of a device is a baseline: it fills the current state without history
 * rows or events, so enrolling a machine never floods the change log. Removed software stays in the current table with `removed_at`
 * set until the history retention passes. Writes are batched; nothing here runs unless the `inventory_software` feature is on.
 *
 * @api
 */
final class SoftwareService
{
    public const MAX_ITEMS = 5000;
    public const MAX_REMOVED = 5000;
    public const SOURCES = ['registry', 'registry32', 'appx', 'dpkg', 'rpm', 'snap', 'flatpak'];
    /** Events published for one report; the change log keeps every change. */
    public const EVENT_CAP = 25;
    private const CHUNK = 250;

    public function __construct(private readonly Sql $sql, private readonly DeviceState $state, private readonly RmmEventPublisher $events)
    {
    }

    /**
     * Validate a `software` block. Never throws: anything unusable is null and the caller ignores the block.
     *
     * @return array{mode:string,hash:string,base_hash:?string,count:int,truncated:bool,items:list<array{key:string,name:string,source:string,version:string,publisher:string,installed:?string}>,removed:list<string>}|null
     */
    public static function cleanReport(mixed $raw): ?array
    {
        if (!is_array($raw) || array_is_list($raw)) {
            return null;
        }
        $mode = $raw['mode'] ?? null;
        $hash = $raw['hash'] ?? null;
        $base = $raw['base_hash'] ?? null;
        $items = $raw['items'] ?? [];
        $removed = $raw['removed'] ?? [];
        if (!in_array($mode, ['full', 'delta'], true) || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1
            || !is_array($items) || !array_is_list($items) || count($items) > self::MAX_ITEMS || !is_array($removed) || !array_is_list($removed) || count($removed) > self::MAX_REMOVED) {
            return null;
        }
        if ($mode === 'delta' && (!is_string($base) || preg_match('/^[0-9a-f]{64}$/', $base) !== 1)) {
            return null;
        }
        $clean = [];
        foreach ($items as $i) {
            if (!is_array($i) || !is_string($i['source'] ?? null) || !in_array($i['source'], self::SOURCES, true)) {
                continue;
            }
            $name = DeviceValidator::cleanText($i['name'] ?? null, 200);
            if ($name === null) {
                continue;
            }
            $key = SoftwareHash::key($i['source'], $name);
            $installed = $i['installed'] ?? null;
            $clean[$key] = ['key' => $key, 'name' => $name, 'source' => $i['source'], 'version' => DeviceValidator::cleanText($i['version'] ?? null, 100) ?? '',
                'publisher' => DeviceValidator::cleanText($i['publisher'] ?? null, 200) ?? '', 'installed' => self::date($installed)];
        }
        $gone = [];
        if ($mode === 'delta') {
            foreach ($removed as $r) {
                $name = is_array($r) ? DeviceValidator::cleanText($r['name'] ?? null, 200) : null;
                if ($name !== null && is_string($r['source'] ?? null) && in_array($r['source'], self::SOURCES, true)) {
                    $gone[SoftwareHash::key($r['source'], $name)] = SoftwareHash::key($r['source'], $name);
                }
            }
        }
        $count = $raw['count'] ?? null;

        return ['mode' => (string) $mode, 'hash' => $hash, 'base_hash' => $mode === 'delta' ? (string) $base : null, 'count' => is_int($count) && $count >= 0 ? $count : count($clean),
            'truncated' => !empty($raw['truncated']), 'items' => array_values($clean), 'removed' => array_values($gone)];
    }

    private static function date(mixed $v): ?string
    {
        if (!is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1990) {
            return null;
        }

        return $v;
    }

    /**
     * Apply a validated report to a device.
     *
     * @param array<string,mixed> $dev the device row
     * @param array{mode:string,hash:string,base_hash:?string,count:int,truncated:bool,items:list<array{key:string,name:string,source:string,version:string,publisher:string,installed:?string}>,removed:list<string>} $report
     * @return array{applied:bool,resync:bool,installed:int,upgraded:int,removed:int,baseline:bool}
     */
    public function apply(array $dev, array $report): array
    {
        $deviceId = (int) $dev['device_id'];
        $out = ['applied' => false, 'resync' => false, 'installed' => 0, 'upgraded' => 0, 'removed' => 0, 'baseline' => false];
        $this->events->hold();
        try {
            $this->sql->transaction(function () use ($dev, $deviceId, $report, &$out): void {
                $this->sql->run('INSERT IGNORE INTO rmm_device_state (device_id) VALUES (?)', [$deviceId]);
                $st = $this->sql->one('SELECT software_hash, software_full_at FROM rmm_device_state WHERE device_id = ? FOR UPDATE', [$deviceId]) ?? [];
                $held = $st['software_hash'] ?? null;
                if ($report['mode'] === 'delta' && ($held === null || !hash_equals((string) $held, (string) $report['base_hash']))) {
                    $this->state->requestSoftwareResync($deviceId);
                    $out['resync'] = true;

                    return;
                }
                $baseline = $held === null;
                $now = $this->sql->utcNow();
                $rows = [];
                foreach ($this->sql->all('SELECT software_key, name, source, version, publisher, removed_at FROM rmm_device_software WHERE device_id = ?', [$deviceId]) as $r) {
                    $rows[(string) $r['software_key']] = $r;
                }
                $upsert = [];
                $history = [];
                $events = [];
                $incoming = [];
                foreach ($report['items'] as $i) {
                    $incoming[$i['key']] = true;
                    $cur = $rows[$i['key']] ?? null;
                    if ($cur === null || $cur['removed_at'] !== null) {
                        $upsert[] = $i;
                        $out['installed']++;
                        $history[] = [$i, 'installed', null, $i['version']];
                        $events[] = [RmmEvent::SOFTWARE_INSTALLED, $i];
                    } elseif ((string) $cur['version'] !== $i['version'] || (string) $cur['publisher'] !== $i['publisher']) {
                        $upsert[] = $i;
                        if ((string) $cur['version'] !== $i['version']) {
                            $out['upgraded']++;
                            $cmp = SoftwareVersion::compare((string) $cur['version'], $i['version']);
                            $history[] = [$i, $cmp > 0 ? 'downgraded' : 'upgraded', (string) $cur['version'], $i['version']];
                        }
                    }
                }
                $gone = [];
                if ($report['mode'] === 'full') {
                    if (!$report['truncated']) {
                        foreach ($rows as $key => $cur) {
                            if ($cur['removed_at'] === null && !isset($incoming[$key])) {
                                $gone[] = $key;
                            }
                        }
                    }
                } else {
                    foreach ($report['removed'] as $key) {
                        if (isset($rows[$key]) && $rows[$key]['removed_at'] === null && !isset($incoming[$key])) {
                            $gone[] = $key;
                        }
                    }
                }
                if ($report['mode'] === 'full') {
                    // Everything the report still lists is seen now (one statement; the rows that change are rewritten below). A row seen within
                    // the last day is left alone, so an extra full list (a resync, a technician refresh) does not rewrite the whole table.
                    $this->sql->run('UPDATE rmm_device_software SET last_seen_at = ? WHERE device_id = ? AND removed_at IS NULL AND last_seen_at < ?', [$now, $deviceId, $this->sql->utcAt(-20 * 3600)]);
                }
                foreach (array_chunk($upsert, self::CHUNK) as $chunk) {
                    $params = [];
                    foreach ($chunk as $i) {
                        array_push($params, $deviceId, $i['key'], $i['name'], $i['source'], $i['version'], $i['publisher'], $i['installed'], $now, $now);
                    }
                    $this->sql->run('INSERT INTO rmm_device_software (device_id, software_key, name, source, version, publisher, installed_on, first_seen_at, last_seen_at) VALUES '
                        . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?)'))
                        . ' ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), publisher = VALUES(publisher), installed_on = COALESCE(VALUES(installed_on), installed_on),'
                        . ' last_seen_at = VALUES(last_seen_at), first_seen_at = IF(removed_at IS NULL, first_seen_at, VALUES(first_seen_at)), removed_at = NULL', $params);
                }
                foreach (array_chunk($gone, 500) as $chunk) {
                    $this->sql->run('UPDATE rmm_device_software SET removed_at = ? WHERE device_id = ? AND removed_at IS NULL AND software_key IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                        array_merge([$now, $deviceId], $chunk));
                }
                foreach ($gone as $key) {
                    $cur = $rows[$key];
                    $out['removed']++;
                    $item = ['key' => $key, 'name' => (string) $cur['name'], 'source' => (string) $cur['source'], 'version' => (string) $cur['version'], 'publisher' => (string) $cur['publisher'], 'installed' => null];
                    $history[] = [$item, 'removed', (string) $cur['version'], null];
                    $events[] = [RmmEvent::SOFTWARE_REMOVED, $item];
                }
                if (!$baseline) {
                    foreach (array_chunk($history, self::CHUNK) as $chunk) {
                        $params = [];
                        foreach ($chunk as [$i, $type, $old, $new]) {
                            array_push($params, $deviceId, $i['key'], $i['name'], $i['source'], $type, $old, $new, $i['publisher'], $now);
                        }
                        $this->sql->run('INSERT INTO rmm_software_history (device_id, software_key, name, source, change_type, old_version, new_version, publisher, occurred_at) VALUES '
                            . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?)')), $params);
                    }
                    foreach (array_slice($events, 0, self::EVENT_CAP) as [$event, $i]) {
                        $this->events->emit($event, $dev, ['name' => $i['name'], 'version' => $i['version'], 'publisher' => $i['publisher'] === '' ? null : $i['publisher'], 'source' => $i['source']]);
                    }
                }
                $out['baseline'] = $baseline;
                $out['applied'] = true;

                // The delta must land on the list the agent hashed; a mismatch costs one full list, never a loop (a full report is not verified).
                $resync = 0;
                if ($report['mode'] === 'delta' && SoftwareHash::of($this->current($deviceId)) !== $report['hash']) {
                    $resync = 1;
                    $out['resync'] = true;
                }
                $count = (int) $this->sql->val('SELECT COUNT(*) FROM rmm_device_software WHERE device_id = ? AND removed_at IS NULL', [$deviceId]);
                $this->sql->run('UPDATE rmm_device_state SET software_hash = ?, software_count = ?, software_at = ?, software_full_at = IF(?, ?, software_full_at), software_resync = ? WHERE device_id = ?',
                    [$report['hash'], $count, $now, $report['mode'] === 'full' ? 1 : 0, $now, $resync, $deviceId]);
            });
        } catch (\Throwable $e) {
            $this->events->discard();
            throw $e;
        }
        $this->events->release();

        return $out;
    }

    /**
     * The current list of a device in hash form.
     *
     * @return list<array{source:string,name:string,version:string,publisher:?string}>
     */
    public function current(int $deviceId): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT source, name, version, publisher FROM rmm_device_software WHERE device_id = ? AND removed_at IS NULL', [$deviceId]) as $r) {
            $out[] = ['source' => (string) $r['source'], 'name' => (string) $r['name'], 'version' => (string) $r['version'], 'publisher' => (string) $r['publisher']];
        }

        return $out;
    }
}
