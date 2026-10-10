<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Maintenance windows: the stored definitions (see {@see MaintenanceSchedule} for the calendar rules), the lookup "which windows are open
 * for this device right now", and the housekeeping tick that announces `rmm.maintenance.started` and `rmm.maintenance.ended`.
 *
 * Two modes. `mute`: checks keep running and their state is tracked, but an alert that would OPEN is held back (it opens when the
 * window ends and the check is still bad), escalation notices pause, and an alert that is already open stays open. `suppress`: the
 * check results of the device are recorded but not evaluated at all: nothing opens, nothing resolves and the debounce counters stand still.
 *
 * Scope: all, one client, one site (the device's location_id), a group, a tag or one device. The lookup is the hook for every consumer:
 * the check evaluator uses it to hold alerts, and the Phase 2 scheduler asks {@see activeFor()} before it starts a scheduled script on a
 * device. The window list is read once per instance (one small query) and only when a caller asks; a group or tag scope costs one more
 * query, and only when a window with that scope is open.
 *
 * @api
 */
final class MaintenanceService
{
    public const MAX_WINDOWS = 500;
    /** Disabled and finished one-time windows older than this many days are removed by the tick. */
    public const PRUNE_AFTER_DAYS = 180;

    /** How long a long-lived process (a queue worker) keeps the window list before it reads it again. */
    public const CACHE_TTL_S = 30;

    /** @var list<array<string,mixed>>|null */
    private ?array $cache = null;
    private int $cachedAt = 0;
    private readonly ScopeMatcher $scopes;

    public function __construct(private readonly Sql $sql, private readonly ?RmmEventPublisher $events = null, ?ScopeMatcher $scopes = null)
    {
        $this->scopes = $scopes ?? new ScopeMatcher($sql);
    }

    public function refresh(): void
    {
        $this->cache = null;
        $this->scopes->forget();
    }

    /** @return list<array<string,mixed>> every enabled window row */
    private function enabledRows(): array
    {
        if ($this->cache === null || $this->sql->time() - $this->cachedAt > self::CACHE_TTL_S) {
            $this->cache = $this->sql->all('SELECT * FROM rmm_maintenance_windows WHERE enabled = 1 ORDER BY window_id');
            $this->cachedAt = $this->sql->time();
        }

        return $this->cache;
    }

    /**
     * Windows that are open at $at for this device.
     *
     * @param array<string,mixed> $dev the device row (device_id, client_id, location_id)
     * @return list<array<string,mixed>> window rows
     */
    public function activeFor(array $dev, ?int $at = null): array
    {
        $at ??= $this->sql->time();
        $out = [];
        foreach ($this->enabledRows() as $w) {
            if (MaintenanceSchedule::isActive($w, $at) && $this->matches($w, $dev)) {
                $out[] = $w;
            }
        }

        return $out;
    }

    /**
     * What is in force for a device now.
     *
     * @param array<string,mixed> $dev
     * @return array{mute:bool,suppress:bool,window_ids:list<int>}
     */
    public function stateFor(array $dev, ?int $at = null): array
    {
        $mute = $suppress = false;
        $ids = [];
        foreach ($this->activeFor($dev, $at) as $w) {
            $ids[] = (int) $w['window_id'];
            if ($w['mode'] === 'suppress') {
                $suppress = true;
            } else {
                $mute = true;
            }
        }

        return ['mute' => $mute || $suppress, 'suppress' => $suppress, 'window_ids' => $ids];
    }

    /**
     * @param array<string,mixed> $w
     * @param array<string,mixed> $dev
     */
    private function matches(array $w, array $dev): bool
    {
        return $this->scopes->matches((string) $w['scope_type'], (int) $w['scope_id'], $dev);
    }

    // ------------------------------------------------------------------ CRUD

    /** @return array<string,mixed>|null the API shape */
    public function find(int $windowId): ?array
    {
        $r = $this->sql->one('SELECT * FROM rmm_maintenance_windows WHERE window_id = ?', [$windowId]);

        return $r === null ? null : $this->present($r);
    }

    /**
     * @param array{scope_type?:string,scope_id?:int,active?:bool,client_ids?:?list<int>} $filters client_ids limits the list to windows a caller may see (null = all)
     * @return list<array<string,mixed>>
     */
    public function all(array $filters = []): array
    {
        $out = [];
        $now = $this->sql->time();
        foreach ($this->sql->all('SELECT * FROM rmm_maintenance_windows ORDER BY enabled DESC, window_id DESC LIMIT ' . self::MAX_WINDOWS) as $r) {
            $row = $this->present($r, $now);
            if (isset($filters['scope_type']) && $row['scope_type'] !== $filters['scope_type']) {
                continue;
            }
            if (isset($filters['scope_id']) && $row['scope_id'] !== $filters['scope_id']) {
                continue;
            }
            if (!empty($filters['active']) && !$row['active_now']) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     * @throws \InvalidArgumentException with a message safe to show
     */
    public function create(array $in, int $userId): array
    {
        [$row, $err] = MaintenanceSchedule::validate($in);
        if ($row === null) {
            throw new \InvalidArgumentException($err ?? 'Invalid window.');
        }
        $this->checkScopeExists($row);
        if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_maintenance_windows') >= self::MAX_WINDOWS) {
            throw new \InvalidArgumentException('There are already ' . self::MAX_WINDOWS . ' maintenance windows; delete finished ones first.');
        }
        $id = $this->sql->insert('INSERT INTO rmm_maintenance_windows (name, enabled, mode, scope_type, scope_id, kind, timezone, starts_at, ends_at, recur_freq, recur_interval, recur_days,
            local_start, duration_min, recur_from, recur_until, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$row['name'], $row['enabled'], $row['mode'], $row['scope_type'], $row['scope_id'], $row['kind'], $row['timezone'], $row['starts_at'], $row['ends_at'], $row['recur_freq'],
                $row['recur_interval'], $row['recur_days'], $row['local_start'], $row['duration_min'], $row['recur_from'], $row['recur_until'], $row['note'], $userId, $this->sql->utcNow()]);
        $this->refresh();

        return $this->find($id) ?? [];
    }

    /**
     * A partial update: the stored window is merged with the input and validated as a whole.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>|null null when the window does not exist
     * @throws \InvalidArgumentException
     */
    public function update(int $windowId, array $in): ?array
    {
        $cur = $this->sql->one('SELECT * FROM rmm_maintenance_windows WHERE window_id = ?', [$windowId]);
        if ($cur === null) {
            return null;
        }
        $base = $this->editable($cur);
        [$row, $err] = MaintenanceSchedule::validate($in + $base);
        if ($row === null) {
            throw new \InvalidArgumentException($err ?? 'Invalid window.');
        }
        $this->checkScopeExists($row);
        $this->sql->run('UPDATE rmm_maintenance_windows SET name = ?, enabled = ?, mode = ?, scope_type = ?, scope_id = ?, kind = ?, timezone = ?, starts_at = ?, ends_at = ?, recur_freq = ?, recur_interval = ?,
            recur_days = ?, local_start = ?, duration_min = ?, recur_from = ?, recur_until = ?, note = ?, updated_at = ? WHERE window_id = ?',
            [$row['name'], $row['enabled'], $row['mode'], $row['scope_type'], $row['scope_id'], $row['kind'], $row['timezone'], $row['starts_at'], $row['ends_at'], $row['recur_freq'],
                $row['recur_interval'], $row['recur_days'], $row['local_start'], $row['duration_min'], $row['recur_from'], $row['recur_until'], $row['note'], $this->sql->utcNow(), $windowId]);
        $this->refresh();

        return $this->find($windowId);
    }

    public function delete(int $windowId): bool
    {
        $cur = $this->sql->one('SELECT * FROM rmm_maintenance_windows WHERE window_id = ?', [$windowId]);
        if ($cur === null) {
            return false;
        }
        $this->sql->run('DELETE FROM rmm_maintenance_windows WHERE window_id = ?', [$windowId]);
        $this->refresh();
        if ((int) $cur['active'] === 1) {
            $this->announceEnd($cur);   // deleting an open window ends it
        }

        return true;
    }

    /**
     * The input shape of a stored window (what the validator takes).
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function editable(array $r): array
    {
        $base = ['name' => $r['name'], 'enabled' => (int) $r['enabled'] === 1, 'mode' => $r['mode'], 'scope_type' => $r['scope_type'], 'scope_id' => (int) $r['scope_id'],
            'kind' => $r['kind'], 'timezone' => $r['timezone'], 'note' => $r['note']];
        if ($r['kind'] === 'once') {
            $base['starts_at'] = gmdate('Y-m-d\TH:i:s\Z', Sql::ts((string) $r['starts_at']));
            $base['ends_at'] = gmdate('Y-m-d\TH:i:s\Z', Sql::ts((string) $r['ends_at']));
        } else {
            $base += ['recur_freq' => $r['recur_freq'], 'recur_interval' => (int) $r['recur_interval'], 'recur_days' => $r['recur_days'], 'local_start' => $r['local_start'],
                'duration_min' => (int) $r['duration_min'], 'recur_from' => $r['recur_from'], 'recur_until' => $r['recur_until']];
        }

        return $base;
    }

    /** @param array<string,mixed> $row */
    private function checkScopeExists(array $row): void
    {
        $this->scopes->assertExists((string) $row['scope_type'], (int) $row['scope_id']);
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public function present(array $r, ?int $now = null): array
    {
        $now ??= $this->sql->time();
        $end = MaintenanceSchedule::currentEnd($r, $now);
        $next = MaintenanceSchedule::nextStart($r, $now);

        return [
            'window_id' => (int) $r['window_id'], 'name' => (string) $r['name'], 'enabled' => (int) $r['enabled'] === 1, 'mode' => (string) $r['mode'],
            'scope_type' => (string) $r['scope_type'], 'scope_id' => (int) $r['scope_id'], 'kind' => (string) $r['kind'], 'timezone' => (string) $r['timezone'],
            'starts_at' => Sql::iso($r['starts_at'] === null ? null : (string) $r['starts_at']), 'ends_at' => Sql::iso($r['ends_at'] === null ? null : (string) $r['ends_at']),
            'recur_freq' => $r['recur_freq'] === null ? null : (string) $r['recur_freq'], 'recur_interval' => (int) $r['recur_interval'], 'recur_days' => (string) $r['recur_days'],
            'local_start' => (string) $r['local_start'], 'duration_min' => (int) $r['duration_min'],
            'recur_from' => $r['recur_from'] === null ? null : (string) $r['recur_from'], 'recur_until' => $r['recur_until'] === null ? null : (string) $r['recur_until'],
            'note' => (string) $r['note'], 'active_now' => $end !== null, 'current_ends_at' => $end === null ? null : gmdate('Y-m-d\TH:i:s\Z', $end),
            'next_starts_at' => $next === null ? null : gmdate('Y-m-d\TH:i:s\Z', $next), 'created_by' => (int) $r['created_by'], 'created_at' => Sql::iso((string) $r['created_at']),
        ];
    }

    // ------------------------------------------------------------------ housekeeping

    /**
     * Announce windows that opened or closed since the last tick (the `active` flag in the table is the memory), and remove old finished
     * one-time windows. Returns what it did.
     *
     * @return array{started:int,ended:int,pruned:int}
     */
    public function tick(): array
    {
        $this->refresh();
        $now = $this->sql->time();
        $started = $ended = 0;
        foreach ($this->sql->all('SELECT * FROM rmm_maintenance_windows') as $w) {
            $end = MaintenanceSchedule::currentEnd($w, $now);
            $was = (int) $w['active'] === 1;
            if ($end !== null && !$was) {
                $this->sql->run('UPDATE rmm_maintenance_windows SET active = 1, active_since = ? WHERE window_id = ?', [$this->sql->utcNow(), $w['window_id']]);
                $this->announce(RmmEvent::MAINTENANCE_STARTED, $w, ['ends_at' => gmdate('Y-m-d\TH:i:s\Z', $end)]);
                ++$started;
            } elseif ($end === null && $was) {
                $this->sql->run('UPDATE rmm_maintenance_windows SET active = 0, active_since = NULL WHERE window_id = ?', [$w['window_id']]);
                $this->announceEnd($w);
                ++$ended;
            }
        }
        $pruned = $this->sql->run("DELETE FROM rmm_maintenance_windows WHERE kind = 'once' AND active = 0 AND ends_at < ?", [$this->sql->utcAt(-self::PRUNE_AFTER_DAYS * 86400)]);
        $this->refresh();

        return ['started' => $started, 'ended' => $ended, 'pruned' => $pruned];
    }

    /** @param array<string,mixed> $w */
    private function announceEnd(array $w): void
    {
        $this->announce(RmmEvent::MAINTENANCE_ENDED, $w);
    }

    /**
     * @param array<string,mixed> $w
     * @param array<string,mixed> $extra
     */
    private function announce(string $event, array $w, array $extra = []): void
    {
        if ($this->events === null) {
            return;
        }
        $scope = (string) $w['scope_type'];
        $id = (int) $w['scope_id'];
        $this->events->emitScoped($event, $scope === 'client' ? $id : 0,
            ['window_id' => (int) $w['window_id'], 'name' => (string) $w['name'], 'mode' => (string) $w['mode'], 'scope_type' => $scope, 'scope_id' => $id] + $extra);
    }
}
