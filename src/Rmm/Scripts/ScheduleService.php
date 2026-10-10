<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Scripts;

use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Scheduled scripts (table rmm_schedules): definitions, validation, and the per-device result history. The work itself is done by
 * {@see ScheduleRunner} from the housekeeping cron.
 *
 * A schedule pins one VERSION of a library script and the SHA-256 of its text at the moment it is saved, so a later edit of the script never
 * changes what an approved schedule runs. It recurs on an interval or a UTC cron expression, targets a device, tag, group, client, site, policy or the
 * whole fleet, spreads its devices over `jitter_s`, and either skips a device whose previous run is still unfinished (`skip`) or queues behind it
 * (`queue`). A script flagged `requires_approval`, or a target larger than the `approval_bulk_threshold` limit, leaves the schedule inactive until a
 * second person approves it.
 *
 * Throws \InvalidArgumentException with a message that is safe to show.
 *
 * @api
 */
final class ScheduleService
{
    public const MAX_SCHEDULES = 500;
    public const INTERVAL_MIN_S = 60;
    public const INTERVAL_MAX_S = 2592000;
    public const JITTER_MAX_S = 3600;
    public const EXPIRES_MIN_S = 60;
    public const EXPIRES_MAX_S = 604800;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly ScriptService $scripts,
        private readonly ScriptRunner $runner,
        private readonly TargetResolver $targets,
    ) {
    }

    /**
     * Create (`$id` null) or change a schedule.
     *
     * @param array<string,mixed> $in {name, library_script_id, script_version?, params?, target:{type,id}, kind, interval_s | cron, start_in_s?, jitter_s?, overlap?, expires_s?, timeout_s?, enabled?, confirm?}
     * @return array{schedule:array<string,mixed>,device_count:int,needs_approval:bool,material_change:bool}
     */
    public function save(?int $id, array $in, int $userId): array
    {
        $cur = null;
        if ($id !== null) {
            $cur = $this->sql->one('SELECT * FROM rmm_schedules WHERE schedule_id = ?', [$id]);
            if ($cur === null) {
                throw new \InvalidArgumentException('Schedule not found.');
            }
        } elseif ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_schedules') >= self::MAX_SCHEDULES) {
            throw new \InvalidArgumentException('At most ' . self::MAX_SCHEDULES . ' schedules.');
        }
        $cfg = $this->settings->get();

        $name = array_key_exists('name', $in) || $cur === null ? $in['name'] ?? null : $cur['name'];
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 100 || preg_match('/[\x00-\x1f]/', $name) === 1) {
            throw new \InvalidArgumentException('A schedule needs a name of 1 to 100 characters.');
        }
        $name = trim($name);
        if ($this->sql->one('SELECT schedule_id FROM rmm_schedules WHERE name = ? AND schedule_id <> ?', [$name, $id ?? 0]) !== null) {
            throw new \InvalidArgumentException('A schedule with that name exists.');
        }

        // The script and the version it is pinned to.
        $scriptId = array_key_exists('library_script_id', $in) ? (is_numeric($in['library_script_id']) ? (int) $in['library_script_id'] : 0) : (int) ($cur['script_id'] ?? 0);
        $versionIn = array_key_exists('script_version', $in) ? (is_numeric($in['script_version']) ? (int) $in['script_version'] : 0) : null;
        if ($cur !== null && $scriptId === (int) $cur['script_id'] && $versionIn === null) {
            $versionIn = (int) $cur['script_version'];
        }
        [$loaded, $err] = $this->scripts->load($scriptId, $versionIn);
        if ($loaded === null) {
            throw new \InvalidArgumentException($err ?? 'Script not found.');
        }
        $script = $loaded['script'];
        $bodyHash = (string) $loaded['version']['body_sha256'];

        $paramsIn = array_key_exists('params', $in) ? $in['params'] : ($cur === null ? [] : json_decode((string) ($cur['params_json'] ?? '{}'), true));
        if ($paramsIn === null) {
            $paramsIn = [];
        }
        if (!is_array($paramsIn) || ($paramsIn !== [] && array_is_list($paramsIn))) {
            throw new \InvalidArgumentException('params is an object of parameter name to value.');
        }
        $perr = $this->runner->checkStatic($loaded['schema'], $paramsIn, true);
        if ($perr !== null) {
            throw new \InvalidArgumentException($perr);
        }

        [$target, $terr] = $this->targets->validate($in['target'] ?? ($cur === null ? null : ['type' => $cur['target_type'], 'id' => $cur['target_id']]));
        if ($target === null) {
            throw new \InvalidArgumentException($terr ?? 'Invalid target.');
        }

        // Timing.
        $kind = $in['kind'] ?? ($cur['kind'] ?? null);
        $interval = null;
        $cron = '';
        $nextCron = null;
        if ($kind === 'interval') {
            $interval = $in['interval_s'] ?? ($cur['interval_s'] ?? null);
            if (!is_int($interval) && !(is_string($interval) && ctype_digit($interval))) {
                throw new \InvalidArgumentException('interval_s is a whole number of seconds.');
            }
            $interval = (int) $interval;
            if ($interval < self::INTERVAL_MIN_S || $interval > self::INTERVAL_MAX_S) {
                throw new \InvalidArgumentException('interval_s is ' . self::INTERVAL_MIN_S . ' to ' . self::INTERVAL_MAX_S . '.');
            }
        } elseif ($kind === 'cron') {
            $cronIn = $in['cron'] ?? ($cur['cron_expr'] ?? null);
            if (!is_string($cronIn)) {
                throw new \InvalidArgumentException('cron is a five-field expression in UTC.');
            }
            $parsed = CronSchedule::parse($cronIn);
            $cron = $parsed->expression;
            $nextCron = $parsed;
        } else {
            throw new \InvalidArgumentException('kind must be "interval" or "cron".');
        }
        $jitter = $in['jitter_s'] ?? ($cur['jitter_s'] ?? 0);
        $overlap = $in['overlap'] ?? ($cur['overlap'] ?? 'skip');
        $expiresDefault = $kind === 'interval' ? max(3600, min((int) $interval, 86400)) : 21600;
        $expires = $in['expires_s'] ?? ($cur['expires_s'] ?? $expiresDefault);
        $timeout = $in['timeout_s'] ?? ($cur['timeout_s'] ?? $script['timeout_s']);
        foreach (['jitter_s' => $jitter, 'expires_s' => $expires, 'timeout_s' => $timeout] as $label => $v) {
            if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
                throw new \InvalidArgumentException("$label is a whole number of seconds.");
            }
        }
        $jitter = (int) $jitter;
        $expires = (int) $expires;
        $timeout = (int) $timeout;
        if ($jitter < 0 || $jitter > self::JITTER_MAX_S) {
            throw new \InvalidArgumentException('jitter_s is 0 to ' . self::JITTER_MAX_S . '.');
        }
        if ($expires < self::EXPIRES_MIN_S || $expires > self::EXPIRES_MAX_S) {
            throw new \InvalidArgumentException('expires_s is ' . self::EXPIRES_MIN_S . ' to ' . self::EXPIRES_MAX_S . '.');
        }
        if ($timeout < 1 || $timeout > (int) $cfg['job_max_timeout_s']) {
            throw new \InvalidArgumentException('timeout_s is 1 to ' . (int) $cfg['job_max_timeout_s'] . '.');
        }
        if (!is_string($overlap) || !in_array($overlap, ['skip', 'queue'], true)) {
            throw new \InvalidArgumentException('overlap must be "skip" or "queue".');
        }
        if ((int) $script['destructive'] === 1 && empty($in['confirm']) && ($cur === null || (int) $cur['script_id'] !== $scriptId || (int) $cur['script_version'] !== (int) $loaded['version']['version'])) {
            throw new \InvalidArgumentException('This script is destructive: confirm the schedule explicitly (confirm: true).');
        }
        $enabled = array_key_exists('enabled', $in) ? (bool) $in['enabled'] : ($cur === null ? true : (int) $cur['enabled'] === 1);

        $now = $this->sql->time();
        $params = $paramsIn === [] ? '{}' : (string) json_encode($paramsIn);
        $material = $cur === null || (int) $cur['script_id'] !== $scriptId || (int) $cur['script_version'] !== (int) $loaded['version']['version']
            || (string) $cur['body_sha256'] !== $bodyHash || (string) ($cur['params_json'] ?? '{}') !== $params
            || $cur['target_type'] !== $target['type'] || (int) $cur['target_id'] !== $target['id'];
        $retime = $cur === null || array_key_exists('kind', $in) || array_key_exists('interval_s', $in) || array_key_exists('cron', $in) || array_key_exists('start_in_s', $in) || (!(int) $cur['enabled'] && $enabled);
        $next = $cur === null ? null : $cur['next_run_at'];
        if ($retime || $next === null) {
            if ($kind === 'interval') {
                $startIn = $in['start_in_s'] ?? $interval;
                $startIn = is_int($startIn) ? max(0, min(self::INTERVAL_MAX_S, $startIn)) : (int) $interval;
                $next = gmdate('Y-m-d H:i:s', $now + $startIn);
            } else {
                $n = $nextCron->next(new \DateTimeImmutable('@' . $now));
                $next = $n === null ? null : $n->format('Y-m-d H:i:s');
            }
        }

        $count = $this->targets->count($target['type'], $target['id'], null, ScriptLanguage::platforms((string) $script['language']));
        $threshold = $this->settings->limits()['approval_bulk_threshold'];
        $needs = (int) $script['requires_approval'] === 1 || ($threshold > 0 && $count > $threshold);
        if ($cur === null || $material) {
            $approvedAt = $needs ? null : gmdate('Y-m-d H:i:s', $now);
            $approvalId = null;
        } else {
            $approvedAt = $cur['approved_at'];
            $approvalId = $cur['approval_id'];
            if ($approvedAt === null && !$needs) {
                $approvedAt = gmdate('Y-m-d H:i:s', $now);   // the need went away (the script no longer requires approval)
            }
        }

        $t = gmdate('Y-m-d H:i:s', $now);
        if ($cur === null) {
            $id = $this->sql->insert('INSERT INTO rmm_schedules (name, script_id, script_version, body_sha256, params_json, target_type, target_id, kind, interval_s, cron_expr, jitter_s, overlap, expires_s, timeout_s, enabled, approval_id, approved_at, next_run_at, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$name, $scriptId, $loaded['version']['version'], $bodyHash, $params, $target['type'], $target['id'], $kind, $interval, $cron,
                $jitter, $overlap, $expires, $timeout, $enabled ? 1 : 0, $approvalId, $approvedAt, $next, $userId, $t, $t]);
        } else {
            $this->sql->run('UPDATE rmm_schedules SET name = ?, script_id = ?, script_version = ?, body_sha256 = ?, params_json = ?, target_type = ?, target_id = ?, kind = ?, interval_s = ?, cron_expr = ?, jitter_s = ?,
                overlap = ?, expires_s = ?, timeout_s = ?, enabled = ?, approval_id = ?, approved_at = ?, next_run_at = ?, updated_at = ? WHERE schedule_id = ?', [$name, $scriptId, $loaded['version']['version'], $bodyHash, $params,
                $target['type'], $target['id'], $kind, $interval, $cron, $jitter, $overlap, $expires, $timeout, $enabled ? 1 : 0, $approvalId, $approvedAt, $next, $t, $id]);
        }

        return ['schedule' => $this->get((int) $id) ?? [], 'device_count' => $count, 'needs_approval' => $needs && $approvedAt === null, 'material_change' => $material];
    }

    public function attachApproval(int $scheduleId, int $approvalId): void
    {
        $this->sql->run('UPDATE rmm_schedules SET approval_id = ? WHERE schedule_id = ?', [$approvalId, $scheduleId]);
    }

    /** The approval was granted: the schedule may run. Only if it is still the version that was approved. */
    public function approve(int $scheduleId, int $approvalId): bool
    {
        return $this->sql->run('UPDATE rmm_schedules SET approved_at = ? WHERE schedule_id = ? AND approval_id = ? AND approved_at IS NULL', [$this->sql->utcNow(), $scheduleId, $approvalId]) === 1;
    }

    public function setEnabled(int $scheduleId, bool $enabled): bool
    {
        return $this->sql->run('UPDATE rmm_schedules SET enabled = ?, updated_at = ? WHERE schedule_id = ? AND enabled <> ?', [$enabled ? 1 : 0, $this->sql->utcNow(), $scheduleId, $enabled ? 1 : 0]) === 1;
    }

    public function delete(int $scheduleId): bool
    {
        return $this->sql->transaction(function () use ($scheduleId): bool {
            $this->sql->run('DELETE FROM rmm_schedule_runs WHERE schedule_id = ?', [$scheduleId]);

            return $this->sql->run('DELETE FROM rmm_schedules WHERE schedule_id = ?', [$scheduleId]) === 1;
        });
    }

    /** @return array<string,mixed>|null */
    public function get(int $scheduleId): ?array
    {
        $r = $this->sql->one('SELECT s.*, x.name AS script_name FROM rmm_schedules s LEFT JOIN rmm_scripts_v2 x ON x.script_id = s.script_id WHERE s.schedule_id = ?', [$scheduleId]);

        return $r === null ? null : self::row($r);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT s.*, x.name AS script_name FROM rmm_schedules s LEFT JOIN rmm_scripts_v2 x ON x.script_id = s.script_id ORDER BY s.name') as $r) {
            $out[] = self::row($r);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> newest first */
    public function runs(int $scheduleId, int $limit = 50): array
    {
        $out = [];
        $limit = max(1, min(200, $limit));
        foreach ($this->sql->all("SELECT * FROM rmm_schedule_runs WHERE schedule_id = ? ORDER BY run_id DESC LIMIT $limit", [$scheduleId]) as $r) {
            $out[] = ['run_id' => (int) $r['run_id'], 'slot_at' => Sql::iso((string) $r['slot_at']), 'state' => (string) $r['state'], 'jobs_created' => (int) $r['jobs_created'],
                'skipped_overlap' => (int) $r['skipped_overlap'], 'skipped_gate' => (int) $r['skipped_gate'], 'skipped_other' => (int) $r['skipped_other'],
                'started_at' => Sql::iso((string) $r['started_at']), 'finished_at' => Sql::iso($r['finished_at'] === null ? null : (string) $r['finished_at'])];
        }

        return $out;
    }

    /**
     * The per-device result history of a schedule: one row per job it created, newest first, with the job's state and exit code.
     *
     * @param list<int>|null $visibleClientIds only devices of these clients (null = all), applied in SQL
     * @return list<array<string,mixed>>
     */
    public function history(int $scheduleId, ?int $deviceId = null, int $limit = 100, int $offset = 0, ?array $visibleClientIds = null): array
    {
        [$scope, $sp] = \RivetCore\Rmm\Tags\TagService::scope($visibleClientIds, 'd');
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $out = [];
        $rows = $this->sql->all("SELECT e.device_id, e.run_id, e.created_at, j.job_id, j.state, j.reason, j.exit_code, j.started_at, j.finished_at, d.hostname
            FROM rmm_job_extra e JOIN endpoint_agent_jobs j ON j.job_id = e.job_id LEFT JOIN endpoint_agent_devices d ON d.device_id = e.device_id
            WHERE e.schedule_id = ?" . ($deviceId === null ? '' : ' AND e.device_id = ?') . "$scope ORDER BY e.created_at DESC, e.job_id LIMIT $limit OFFSET $offset", [$scheduleId, ...($deviceId === null ? [] : [$deviceId]), ...$sp]);
        foreach ($rows as $r) {
            $out[] = ['device_id' => (int) $r['device_id'], 'hostname' => (string) ($r['hostname'] ?? ''), 'run_id' => $r['run_id'] === null ? null : (int) $r['run_id'], 'job_id' => (string) $r['job_id'],
                'state' => (string) $r['state'], 'reason' => $r['reason'], 'exit_code' => $r['exit_code'] === null ? null : (int) $r['exit_code'],
                'queued_at' => Sql::iso((string) $r['created_at']), 'started_at' => Sql::iso($r['started_at'] === null ? null : (string) $r['started_at']), 'finished_at' => Sql::iso($r['finished_at'] === null ? null : (string) $r['finished_at'])];
        }

        return $out;
    }

    /**
     * A schedule row as the API shows it.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function row(array $r): array
    {
        $p = json_decode((string) ($r['params_json'] ?? '{}'), true);

        return ['schedule_id' => (int) $r['schedule_id'], 'name' => (string) $r['name'], 'script_id' => (int) $r['script_id'], 'script_name' => (string) ($r['script_name'] ?? ''),
            'script_version' => (int) $r['script_version'], 'body_sha256' => (string) $r['body_sha256'], 'params' => is_array($p) && $p !== [] ? $p : new \stdClass(),
            'target' => ['type' => (string) $r['target_type'], 'id' => (int) $r['target_id']], 'kind' => (string) $r['kind'], 'interval_s' => $r['interval_s'] === null ? null : (int) $r['interval_s'],
            'cron' => (string) $r['cron_expr'], 'jitter_s' => (int) $r['jitter_s'], 'overlap' => (string) $r['overlap'], 'expires_s' => (int) $r['expires_s'], 'timeout_s' => (int) $r['timeout_s'],
            'enabled' => (int) $r['enabled'] === 1, 'approval_id' => $r['approval_id'] === null ? null : (int) $r['approval_id'], 'approved' => $r['approved_at'] !== null,
            'next_run_at' => Sql::iso($r['next_run_at'] === null ? null : (string) $r['next_run_at']), 'last_run_at' => Sql::iso($r['last_run_at'] === null ? null : (string) $r['last_run_at'])];
    }
}
