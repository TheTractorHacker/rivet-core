<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Job;

use RivetCore\Rmm\Crypto\Redactor;
use RivetCore\Rmm\Device\DeviceState;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * The durable job queue.
 *
 * STATES: queued -> running -> succeeded | failed | timed_out | cancelled, plus expired (never started before expires_at).
 *
 * LOST ACKNOWLEDGEMENT RULES
 *  - Destructive jobs (every reboot, and any job flagged destructive) are offered AT MOST ONCE. If the result never arrives
 *    (offered but never reported running past the ack timeout, or running past its deadline) they become failed / result_lost.
 *    A late real result from the agent still replaces result_lost (the agent knows the truth), but nothing is ever re-sent.
 *  - Non-destructive jobs that were offered but never reported running may be re-offered with attempt + 1 after the ack timeout,
 *    up to job_max_attempts. A job that reported running is never re-offered: past its deadline it becomes timed_out.
 *  - Every offer is signed (Ed25519 over the canonical JSON of the job fields), and the signature covers the attempt.
 *
 * @api
 */
final class JobService
{
    public const MAX_SCRIPT_BYTES = RmmProtocol::JOB_MAX_SCRIPT_BYTES;

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const TIME_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly JobTypeRegistry $registry,
        private readonly ?\RivetCore\Rmm\Support\RmmEventPublisher $events = null,
        private readonly ?JobExtras $extras = null,
        private readonly ?DeviceState $deviceState = null,
    ) {
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    public function registry(): JobTypeRegistry
    {
        return $this->registry;
    }

    // ------------------------------------------------------------------ creation (a technician, after the caller's authorization)

    /**
     * @param array<string,mixed> $dev the device row
     * @param array<array-key,mixed> $params
     * @param array{job_id?:string,expires_in_s?:int,extra?:array{script_id?:?int,script_version?:?int,schedule_id?:?int,run_id?:?int,approval_id?:?int,idem_key?:?string,secret?:array<string,string>}} $opts
     *        Phase 2: `job_id` (chosen by the caller), `expires_in_s` (how long the job may wait for the device, default job_expiry_s) and `extra`, the
     *        sidecar rows ({@see JobExtras}); with an `idem_key` already present nothing is created and `duplicate` is true. Secret parameters are listed in
     *        `extra.secret` and carry {@see JobExtras::SECRET_MARK} in `$params`
     * @return array{ok:bool,error?:string,job_id?:string,duplicate?:bool}
     */
    public function create(array $dev, string $type, ?string $script, array $params, ?int $timeout, bool $destructive, int $userId, array $opts = []): array
    {
        $cfg = $this->settings->get();
        if ($dev['revoked_at'] !== null || $dev['retired_at'] !== null || $dev['link_state'] === 'rejected') {
            return ['ok' => false, 'error' => 'This device is revoked or retired and cannot receive jobs.'];
        }
        $def = $this->registry->get($type);
        if ($def === null) {
            return ['ok' => false, 'error' => 'Unknown job type.'];
        }
        $os = (string) ($dev['os'] ?? '');
        if ($def->platforms !== [] && $os !== '' && !in_array($os, $def->platforms, true)) {
            return ['ok' => false, 'error' => 'That job type cannot run on a ' . $os . ' device.'];
        }
        if ($def->requiresScript) {
            if ($script === null || trim($script) === '' || strlen($script) > self::MAX_SCRIPT_BYTES || str_contains($script, "\0") || !mb_check_encoding($script, 'UTF-8')) {
                return ['ok' => false, 'error' => 'A PowerShell job needs a script of at most ' . self::MAX_SCRIPT_BYTES . ' bytes.'];
            }
        } else {
            $script = null;
        }
        if ($def->destructive) {
            $destructive = true;
        }
        if ($def->paramValidator !== null) {
            [$params, $err] = ($def->paramValidator)($params);
            if ($err !== null) {
                return ['ok' => false, 'error' => $err];
            }
        }
        if (count($params) > 20) {
            return ['ok' => false, 'error' => 'At most 20 parameters.'];
        }
        foreach ($params as $k => $v) {
            if (!is_string($k) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k) !== 1 || !(is_null($v) || is_bool($v) || is_int($v) || (is_string($v) && strlen($v) <= 1024 && !str_contains($v, "\0")))) {
                return ['ok' => false, 'error' => 'Parameter names must be identifiers and values short strings, whole numbers or booleans.'];
            }
        }
        $timeout ??= $def->defaultTimeoutS ?? (int) $cfg['job_default_timeout_s'];
        if ($timeout < 1 || $timeout > (int) $cfg['job_max_timeout_s']) {
            return ['ok' => false, 'error' => 'Timeout must be 1 to ' . (int) $cfg['job_max_timeout_s'] . ' seconds.'];
        }
        $id = isset($opts['job_id']) && preg_match(self::UUID_RE, $opts['job_id']) === 1 ? $opts['job_id'] : self::uuid();
        $now = $this->sql->time();
        $expiresIn = max(60, min(7 * 86400, (int) ($opts['expires_in_s'] ?? $cfg['job_expiry_s'])));
        $extra = $opts['extra'] ?? null;
        if ($extra !== null && $this->extras === null) {
            return ['ok' => false, 'error' => 'Library jobs are not available on this module.'];
        }
        $insert = function () use ($id, $dev, $type, $script, $params, $timeout, $cfg, $destructive, $now, $userId, $expiresIn, $extra): bool {
            if ($extra !== null && $this->extras !== null && !$this->extras->record($id, (int) $dev['device_id'], $extra)) {
                return false;
            }
            $this->sql->run("INSERT INTO endpoint_agent_jobs (job_id, device_id, asset_id, client_id, type, script, params_json, timeout_s, max_output_bytes, destructive, run_as, state,
                attempt, issued_at, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'SYSTEM', 'queued', 1, ?, ?, ?, ?)",
                [$id, $dev['device_id'], empty($dev['asset_id']) ? null : $dev['asset_id'], (int) $dev['client_id'], $type, $script, $params !== [] ? json_encode($params) : '{}', $timeout,
                    (int) $cfg['job_output_max_bytes'], $destructive ? 1 : 0, gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now + $expiresIn), $userId, gmdate('Y-m-d H:i:s', $now)]);

            return true;
        };
        $created = $extra === null ? $insert() : (bool) $this->sql->transaction($insert);
        if (!$created) {
            return ['ok' => true, 'duplicate' => true];
        }

        return ['ok' => true, 'job_id' => $id];
    }

    /** Scoped to the device the caller was authorized for: a job id from another client's device cannot be cancelled through this one. */
    public function cancel(string $jobId, int $deviceId, int $userId): bool
    {
        $n = $this->sql->run("UPDATE endpoint_agent_jobs SET state = 'cancelled', reason = 'cancelled_by_user', finished_at = ? WHERE job_id = ? AND device_id = ? AND state = 'queued'", [$this->sql->utcNow(), $jobId, $deviceId]);

        return $n > 0;
    }

    public function cancelAllQueued(int $deviceId, string $reason): void
    {
        $this->sql->run("UPDATE endpoint_agent_jobs SET state = 'cancelled', reason = ?, finished_at = ? WHERE device_id = ? AND state = 'queued'", [$reason, $this->sql->utcNow(), $deviceId]);
    }

    // ------------------------------------------------------------------ device side

    public function pendingCount(int $deviceId): int
    {
        $this->sweep($deviceId);
        $cfg = $this->settings->get();
        $ackBefore = gmdate('Y-m-d H:i:s', $this->sql->time() - (int) $cfg['job_ack_timeout_s']);

        $where = "device_id = ? AND state = 'queued' AND expires_at > ? AND
            (offered_count = 0 OR (destructive = 0 AND offered_count < ? AND last_offered_at <= ?))";
        $params = [$deviceId, $this->sql->utcNow(), (int) $cfg['job_max_attempts'], $ackBefore];
        $n = (int) $this->sql->val("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE $where", $params);
        if ($n === 0 || $this->deviceState === null) {
            return $n;
        }
        // A job the device did not announce its type for is never offered ({@see offer()}), so it must not be counted either: an agent told that jobs are
        // pending polls and drains at its shortest interval until the job expires.
        $caps = DeviceState::capabilitiesOf($this->deviceState->get($deviceId));
        $offerable = 0;
        foreach ($this->sql->all("SELECT type, COUNT(*) AS c FROM endpoint_agent_jobs WHERE $where GROUP BY type", $params) as $r) {
            if (self::deviceCanRun($caps, (string) $r['type'])) {
                $offerable += (int) $r['c'];
            }
        }

        return $offerable;
    }

    /**
     * @param array<string,mixed> $dev
     * @return list<array<string,mixed>> signed job objects for this device only
     */
    public function offer(array $dev): array
    {
        $cfg = $this->settings->get();
        $this->sweep((int) $dev['device_id']);
        [$sec] = $this->settings->signingKey();

        return $this->sql->transaction(function () use ($dev, $cfg, $sec): array {
            $out = [];
            $ackBefore = gmdate('Y-m-d H:i:s', $this->sql->time() - (int) $cfg['job_ack_timeout_s']);
            $rows = $this->sql->all("SELECT * FROM endpoint_agent_jobs WHERE device_id = ? AND state = 'queued' AND expires_at > ? ORDER BY created_at, job_id LIMIT " . RmmProtocol::JOBS_OFFER_LIMIT . ' FOR UPDATE',
                [$dev['device_id'], $this->sql->utcNow()]);
            $caps = null;
            foreach ($rows as $j) {
                if ($this->deviceState !== null) {
                    $caps ??= DeviceState::capabilitiesOf($this->deviceState->get((int) $dev['device_id']));
                    if (!self::deviceCanRun($caps, (string) $j['type'])) {
                        continue;   // the device announced what it can run and this is not in it: leave the job queued (it expires), never send it
                    }
                }
                $attempt = (int) $j['attempt'];
                if ((int) $j['offered_count'] > 0) {
                    if ((int) $j['destructive'] === 1 || (string) $j['last_offered_at'] > $ackBefore || (int) $j['offered_count'] >= (int) $cfg['job_max_attempts']) {
                        continue;   // never re-send a destructive job; give the agent time to acknowledge before re-sending a harmless one
                    }
                    ++$attempt;
                }
                if (JobExtras::hasSecrets($j)) {
                    $withSecrets = $this->extras?->withSecrets($j);
                    if ($withSecrets === null) {
                        continue;   // the sealed values cannot be read: the job stays queued rather than running without them
                    }
                    $j = $withSecrets;
                }
                $this->sql->run('UPDATE endpoint_agent_jobs SET attempt = ?, offered_count = offered_count + 1, last_offered_at = ? WHERE job_id = ?', [$attempt, $this->sql->utcNow(), $j['job_id']]);
                $out[] = self::jobObject($j, $attempt, $sec);
            }

            return $out;
        });
    }

    /**
     * Whether a device that announced `$caps` can run a job type. A device that announced no `job:` capability (an agent from before capability
     * negotiation) is assumed to run everything and refuses by itself what it cannot.
     *
     * @param list<string> $caps
     */
    public static function deviceCanRun(array $caps, string $type): bool
    {
        $any = false;
        foreach ($caps as $c) {
            if (str_starts_with($c, 'job:')) {
                $any = true;
                if (substr($c, 4) === $type) {
                    return true;
                }
            }
        }

        return !$any;
    }

    /**
     * @param array<string,mixed> $j an endpoint_agent_jobs row
     * @return array<string,mixed>
     */
    public static function jobObject(array $j, int $attempt, string $secretKey): array
    {
        $params = json_decode((string) ($j['params_json'] ?: '{}'));
        if (!($params instanceof \stdClass)) {
            $params = new \stdClass();
        }
        $obj = [
            'job_id' => $j['job_id'],
            'device_id' => (int) $j['device_id'],
            'attempt' => $attempt,
            'type' => $j['type'],
            'script' => $j['script'],
            'params' => $params,
            'timeout_s' => (int) $j['timeout_s'],
            'max_output_bytes' => (int) $j['max_output_bytes'],
            'issued_at' => Sql::iso((string) $j['issued_at']),
            'expires_at' => Sql::iso((string) $j['expires_at']),
        ];
        $obj['signature'] = Signer::sign(Signer::jobMessage($obj), $secretKey);

        return $obj;
    }

    /**
     * Record an agent's report.
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $b the decoded request body
     * @throws ApiError 404 unknown job (for this device), 409 conflict, 422 invalid
     */
    public function report(array $dev, array $b): void
    {
        $cfg = $this->settings->get();
        $jobId = $b['job_id'] ?? null;
        $attempt = $b['attempt'] ?? null;
        $state = $b['state'] ?? null;
        if (!is_string($jobId) || preg_match(self::UUID_RE, $jobId) !== 1 || !is_int($attempt) || $attempt < 1
            || !is_string($state) || !in_array($state, RmmProtocol::JOB_REPORTABLE_STATES, true)) {
            throw new ApiError(422, 'invalid', 'job_id, attempt and state are required');
        }
        $exit = $b['exit_code'] ?? null;
        if ($exit !== null && (!is_int($exit) || $exit < -2147483648 || $exit > 2147483647)) {
            throw new ApiError(422, 'invalid', 'exit_code must be an integer or null');
        }
        $output = $b['output'] ?? '';
        if (!is_string($output)) {
            throw new ApiError(422, 'invalid', 'output must be a string');
        }
        $started = $this->optTime($b['started_at'] ?? null, 'started_at');
        $finished = $this->optTime($b['finished_at'] ?? null, 'finished_at');

        // Failures throw ApiError out of the transaction (rolled back, nothing to keep); the one outcome that must be KEPT and still
        // answer 409 (a queued job found past its expiry) is committed first and thrown afterwards.
        $emit = null;
        $expired = $this->sql->transaction(function () use ($dev, $jobId, $attempt, $state, $exit, $output, $started, $finished, $cfg, &$emit): bool {
            $j = $this->sql->one('SELECT * FROM endpoint_agent_jobs WHERE job_id = ? AND device_id = ? FOR UPDATE', [$jobId, $dev['device_id']]);
            if ($j === null) {
                throw new ApiError(404, 'not_found', 'Unknown job.');
            }
            if ($attempt > (int) $j['attempt']) {
                throw new ApiError(409, 'conflict', 'That attempt was never issued.');
            }
            $cur = (string) $j['state'];
            $isFinal = $state !== 'running';
            $now = $this->sql->utcNow();
            $late = false;

            if (in_array($cur, RmmProtocol::JOB_FINAL_STATES, true)) {
                $late = ($cur === 'failed' && $j['reason'] === 'result_lost') || ($cur === 'timed_out' && $j['reason'] === 'no_result_by_deadline');
                if ($isFinal && $cur === $state) {
                    return false;   // an idempotent replay of the result we already hold
                }
                if (!($isFinal && $late)) {
                    throw new ApiError(409, 'conflict', "Job is already $cur.");
                }
                // The agent finally reports what happened to a job whose result was lost: the real outcome wins.
            } elseif ($cur === 'queued' && Sql::ts((string) $j['expires_at']) <= $this->sql->time()) {
                $this->sql->run("UPDATE endpoint_agent_jobs SET state = 'expired', reason = 'expired_before_run', finished_at = ? WHERE job_id = ?", [$now, $jobId]);

                return true;
            }

            if ($state === 'running') {
                $this->sql->run("UPDATE endpoint_agent_jobs SET state = 'running', started_at = COALESCE(started_at, ?), attempt = GREATEST(attempt, ?) WHERE job_id = ?",
                    [$started ?? $now, $attempt, $jobId]);
            } else {
                if ($this->extras !== null && JobExtras::hasSecrets($j)) {
                    $output = $this->extras->scrub($j, $output);
                    $this->extras->wipe($jobId);
                }
                [$text, $truncated] = self::sanitizeOutput($output, min((int) $j['max_output_bytes'], (int) $cfg['job_output_max_bytes']));
                $def = $this->registry->get((string) $j['type']);
                if ($def !== null && !$def->keepOutput) {
                    $text = '';
                }
                $this->sql->run('UPDATE endpoint_agent_jobs SET state = ?, reason = ?, exit_code = ?, output = ?, output_truncated = ?, started_at = COALESCE(started_at, ?), finished_at = ? WHERE job_id = ?',
                    [$state, $late ? 'late_result' : null, $exit, $text, $truncated ? 1 : 0, $started ?? $now, $finished ?? $now, $jobId]);
                if ($state === 'succeeded' || $state === 'failed' || $state === 'timed_out') {
                    $emit = [$state === 'succeeded' ? \RivetCore\Rmm\RmmEvent::JOB_COMPLETED : \RivetCore\Rmm\RmmEvent::JOB_FAILED, ['job_id' => $jobId, 'job_type' => (string) $j['type'], 'state' => $state, 'exit_code' => $exit]];
                }
            }

            return false;
        });
        if ($expired) {
            throw new ApiError(409, 'conflict', 'Job has expired.');
        }
        if ($emit !== null && $this->events !== null) {
            $fields = $emit[1];
            if ($emit[0] === \RivetCore\Rmm\RmmEvent::JOB_COMPLETED) {
                unset($fields['state']);
            }
            $this->events->emit($emit[0], $dev, $fields);
        }
    }

    private function optTime(mixed $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || preg_match(self::TIME_RE, $v) !== 1) {
            throw new ApiError(422, 'invalid', "$field must be an RFC 3339 timestamp");
        }
        $ts = strtotime($v);
        $now = $this->sql->time();
        if ($ts === false || $ts > $now + 300 || $ts < $now - 86400 * 30) {
            throw new ApiError(422, 'invalid', "$field is out of range");
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    // ------------------------------------------------------------------ housekeeping

    /**
     * Expire, fail or time out jobs whose result can no longer arrive in time. One device, or all of them.
     *
     * @return int jobs changed
     */
    public function sweep(?int $deviceId = null): int
    {
        $changed = 0;
        $cfg = $this->settings->get();
        $now = $this->sql->utcNow();
        $ackBefore = gmdate('Y-m-d H:i:s', $this->sql->time() - (int) $cfg['job_ack_timeout_s']);
        $scope = $deviceId === null ? '' : ' AND device_id = ?';
        $extra = $deviceId === null ? [] : [$deviceId];
        $changed += $this->sql->run("UPDATE endpoint_agent_jobs SET state = 'expired', reason = 'expired_before_run', finished_at = ? WHERE state = 'queued' AND expires_at <= ?$scope", [$now, $now, ...$extra]);
        // Destructive, offered, no running report: it may or may not have executed. Never retried.
        $changed += $this->sql->run("UPDATE endpoint_agent_jobs SET state = 'failed', reason = 'result_lost', finished_at = ? WHERE state = 'queued' AND destructive = 1 AND offered_count >= 1 AND last_offered_at <= ?$scope", [$now, $ackBefore, ...$extra]);
        $changed += $this->sql->run("UPDATE endpoint_agent_jobs SET state = 'failed', reason = 'never_started', finished_at = ? WHERE state = 'queued' AND destructive = 0 AND offered_count >= ? AND last_offered_at <= ?$scope",
            [$now, (int) $cfg['job_max_attempts'], $ackBefore, ...$extra]);
        // Running past its deadline plus grace with no result.
        $rows = $this->sql->all("SELECT job_id, destructive, timeout_s, started_at FROM endpoint_agent_jobs WHERE state = 'running'$scope", $extra);
        foreach ($rows as $r) {
            if (Sql::ts((string) $r['started_at']) + (int) $r['timeout_s'] + RmmProtocol::JOB_DEADLINE_GRACE_S > $this->sql->time()) {
                continue;
            }
            if ((int) $r['destructive'] === 1) {
                $changed += $this->sql->run("UPDATE endpoint_agent_jobs SET state = 'failed', reason = 'result_lost', finished_at = ? WHERE job_id = ? AND state = 'running'", [$now, $r['job_id']]);
            } else {
                $changed += $this->sql->run("UPDATE endpoint_agent_jobs SET state = 'timed_out', reason = 'no_result_by_deadline', finished_at = ? WHERE job_id = ? AND state = 'running'", [$now, $r['job_id']]);
            }
        }

        return $changed;
    }

    // ------------------------------------------------------------------ output handling

    /**
     * Bound the work, then redact, then cut: redacting after cutting could leave half a secret behind.
     *
     * @return array{0:string,1:bool} [redacted, size-capped text, truncated?]
     */
    public static function sanitizeOutput(string $out, int $cap): array
    {
        $cap = max(1024, $cap);
        $out = self::dropInvalidUtf8(substr($out, 0, $cap * 4));
        $out = str_replace("\0", '', $out);
        $out = Redactor::redact($out);
        $truncated = false;
        if (strlen($out) > $cap) {
            $out = substr($out, 0, $cap);
            while ($out !== '' && !mb_check_encoding($out, 'UTF-8')) {
                $out = substr($out, 0, -1);
            }
            $truncated = true;
        }

        return [$out, $truncated];
    }

    /**
     * Remove bytes that are not valid UTF-8. A bare iconv //IGNORE returns false (and the caller would keep an EMPTY string) when the
     * input ends inside a multi-byte character, which a byte-bounded cut of long output does all the time; that tail is dropped first.
     */
    private static function dropInvalidUtf8(string $s): string
    {
        for ($i = 0; $i < 4; ++$i) {
            $c = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
            if ($c !== false) {
                return $c;
            }
            $s = substr($s, 0, -1);
        }

        return (string) preg_replace('/[\x80-\xff]+/', '', $s);
    }
}
