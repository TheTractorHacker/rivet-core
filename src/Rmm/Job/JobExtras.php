<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Job;

use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Support\Sql;

/**
 * The sidecar of a job (table rmm_job_extra): which library script, version, schedule, run and approval it came from, the idempotency key that
 * keeps a scheduled run from creating its job twice, and the SECRET parameters of the run.
 *
 * Secret parameters never reach the job row: `params_json` holds {@see SECRET_MARK} in their place, the real values are sealed with the
 * edition's SecretBox in `secret_params_enc`, put back only when the job is signed for the device, wiped as soon as the job has a final
 * result, and scrubbed out of the output the device reports.
 *
 * @api
 */
final class JobExtras
{
    public const SECRET_MARK = '[redacted:secret]';

    public function __construct(private readonly Sql $sql, private readonly SecretBoxInterface $box)
    {
    }

    /**
     * Record the sidecar of a job. Returns false when the idempotency key already exists (the job was already created by an earlier attempt).
     *
     * @param array{script_id?:?int,script_version?:?int,schedule_id?:?int,run_id?:?int,approval_id?:?int,idem_key?:?string,secret?:array<string,string>} $extra
     */
    public function record(string $jobId, int $deviceId, array $extra): bool
    {
        $secret = $extra['secret'] ?? [];
        $enc = $secret === [] ? null : $this->box->encrypt((string) json_encode($secret));
        $idem = $extra['idem_key'] ?? null;
        $sql = 'INSERT' . ($idem === null ? '' : ' IGNORE') . ' INTO rmm_job_extra (job_id, device_id, script_id, script_version, schedule_id, run_id, approval_id, idem_key, secret_params_enc, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        return $this->sql->run($sql, [$jobId, $deviceId, $extra['script_id'] ?? null, $extra['script_version'] ?? null, $extra['schedule_id'] ?? null, $extra['run_id'] ?? null,
            $extra['approval_id'] ?? null, $idem, $enc, $this->sql->utcNow()]) === 1;
    }

    /** A marker row for a (schedule, slot, device) that was looked at and deliberately given no job (overlap, maintenance, ...). */
    public function markHandled(string $idemKey, int $deviceId, int $scheduleId, int $runId): bool
    {
        $id = 'skip-' . substr(hash('sha256', $idemKey), 0, 31);

        return $this->sql->run('INSERT IGNORE INTO rmm_job_extra (job_id, device_id, schedule_id, run_id, idem_key, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $deviceId, $scheduleId, $runId, $idemKey, $this->sql->utcNow()]) === 1;
    }

    public static function idemKey(int $scheduleId, string $slotUtc, int $deviceId): string
    {
        return hash('sha256', "schedule:$scheduleId:$slotUtc:device:$deviceId");
    }

    /**
     * True when a job row's params carry a secret placeholder.
     *
     * @param array<string,mixed> $job
     */
    public static function hasSecrets(array $job): bool
    {
        return is_string($job['params_json'] ?? null) && str_contains($job['params_json'], self::SECRET_MARK);
    }

    /**
     * The job row with its secret parameters put back, ready to be signed for the device. Null when they cannot be read (the job then stays queued).
     *
     * @param array<string,mixed> $job an endpoint_agent_jobs row
     * @return array<string,mixed>|null
     */
    public function withSecrets(array $job): ?array
    {
        $secret = $this->secrets((string) $job['job_id']);
        if ($secret === null) {
            return null;
        }
        $params = json_decode((string) $job['params_json'], true);
        if (!is_array($params)) {
            return null;
        }
        foreach ($params as $k => $v) {
            if ($v === self::SECRET_MARK) {
                if (!isset($secret[$k])) {
                    return null;
                }
                $params[$k] = $secret[$k];
            }
        }
        $job['params_json'] = (string) json_encode($params);

        return $job;
    }

    /** @return array<string,string>|null */
    private function secrets(string $jobId): ?array
    {
        $enc = $this->sql->val('SELECT secret_params_enc FROM rmm_job_extra WHERE job_id = ?', [$jobId]);
        if (!is_string($enc) || $enc === '') {
            return null;
        }
        try {
            $d = json_decode($this->box->decrypt($enc), true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($d)) {
            return null;
        }
        $out = [];
        foreach ($d as $k => $v) {
            if (is_string($v)) {
                $out[(string) $k] = $v;
            }
        }

        return $out;
    }

    /**
     * Remove every secret value of the job from a piece of output (the device may echo what it was given). Values shorter than four characters
     * are not scrubbed: they would shred unrelated text.
     *
     * @param array<string,mixed> $job
     */
    public function scrub(array $job, string $output): string
    {
        if (!self::hasSecrets($job)) {
            return $output;
        }
        foreach ($this->secrets((string) $job['job_id']) ?? [] as $value) {
            if (strlen($value) >= 4) {
                $output = str_replace([$value, base64_encode($value), rawurlencode($value)], '[redacted]', $output);
            }
        }

        return $output;
    }

    /** The job reached a final state: the sealed values are of no further use. */
    public function wipe(string $jobId): void
    {
        $this->sql->run('UPDATE rmm_job_extra SET secret_params_enc = NULL WHERE job_id = ? AND secret_params_enc IS NOT NULL', [$jobId]);
    }

    /** Safety net for a job that never reported (expired, cancelled, device retired). */
    public function wipeFinished(): int
    {
        return $this->sql->run("UPDATE rmm_job_extra e JOIN endpoint_agent_jobs j ON j.job_id = e.job_id SET e.secret_params_enc = NULL
            WHERE e.secret_params_enc IS NOT NULL AND j.state IN ('succeeded','failed','timed_out','cancelled','expired')");
    }
}
