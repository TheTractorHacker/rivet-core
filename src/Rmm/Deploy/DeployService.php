<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

use RivetCore\Rmm\Enrollment\DeviceValidator;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Software deployment: turn a catalog version into a signed `software_install` job, ask devices which packages they have with `software_detect`, and apply what the
 * agent reports back.
 *
 * SAFETY, in the order it is enforced here:
 *  - the job carries NOTHING a person typed: every value is rebuilt from the catalog row, which was validated when it was saved and is validated again here
 *    ({@see PackageCatalog::validateVersion()}, the source policy is asked again for the device's client, and the job validator checks the final parameters);
 *  - a retired version is never installed (it can still be removed); a disabled package is never touched;
 *  - `mode` is `dry_run` unless the caller says `run` (and the scheduler says `run` only while the `software_live_install` limit is on);
 *  - at most one install job per device is waiting or running; the agent re-checks every parameter, verifies the hash of what it downloaded, and verifies by detection
 *    that the install did what it was asked to do. It never restarts the device.
 *
 * @api
 */
final class DeployService
{
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly JobService $jobs,
        private readonly PackageCatalog $catalog,
        private readonly DeployExpectations $expectations,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    // ------------------------------------------------------------------ queueing

    /** The id of a detect or install job of this device that is still queued or running (and not older than a day), else null. */
    public function pendingJob(int $deviceId, string $type): ?string
    {
        $r = $this->sql->one("SELECT p.job_id FROM rmm_package_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id
            WHERE p.device_id = ? AND p.type = ? AND j.state IN ('queued','running') AND p.created_at > ? ORDER BY p.created_at DESC LIMIT 1", [$deviceId, $type, $this->sql->utcAt(-86400)]);

        return $r === null ? null : (string) $r['job_id'];
    }

    /**
     * The parameters of an install job for a catalog version, or why none can be built. Everything comes from the row.
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $v a catalog version row ({@see PackageCatalog::version()})
     * @return array{ok:true,params:array<string,mixed>}|array{ok:false,error:string,code:string}
     */
    public function paramsFor(array $dev, array $v, string $action, string $mode, int $maxMinutes = DeployProtocol::MAX_MINUTES_DEFAULT): array
    {
        $fail = static fn (string $why, string $code = 'invalid'): array => ['ok' => false, 'error' => $why, 'code' => $code];
        if (!in_array($action, DeployProtocol::ACTIONS, true) || !in_array($mode, DeployProtocol::MODES, true)) {
            return $fail('action or mode is not valid.');
        }
        $os = (string) ($dev['os'] ?? '');
        if ($v['os'] !== $os || !DeployProtocol::formatFits((string) $v['fmt'], $os)) {
            return $fail('That version is for ' . $v['os'] . ' and the device runs ' . $os . '.', 'unsupported');
        }
        if ($action === 'install' && $v['retired']) {
            return $fail('That version is retired and cannot be installed.');
        }
        $mgr = $this->expectations->managerFor($dev);
        if ($os === 'linux' && $mgr !== null && $v['fmt'] !== $mgr) {
            return $fail('That version is for ' . $v['fmt'] . ' and the device uses ' . $mgr . '.', 'unsupported');
        }
        $p = ['action' => $action, 'mode' => $mode, 'fmt' => $v['fmt']];
        if ($os === 'linux') {
            $p['pkg'] = $v['pkg_name'];
            if ($action === 'install' && $v['pin_version']) {
                $p['version'] = $v['version'];
            }
        } else {
            // Windows: the version row is checked once more as if it were being saved now, so a row that was edited behind the API's back, or a host that was taken off
            // the list since, never reaches an agent.
            try {
                PackageCatalog::validateVersion(self::asInput($v), $this->catalog->sources());
            } catch (\InvalidArgumentException $e) {
                return $fail('The catalog entry no longer passes the checks: ' . $e->getMessage(), 'source_refused');
            }
            if ($action === 'install' && !$this->catalog->sources()->allows((string) $v['url'], (int) $dev['client_id'])) {
                return $fail('The download host is not allowed for this client.', 'source_refused');
            }
            if ($action === 'install') {
                $p += ['url' => $v['url'], 'sha256' => $v['sha256'], 'size' => $v['size_bytes']];
                if ($v['fmt'] === 'exe') {
                    $p['args'] = $v['silent_args'];
                } else {
                    $p['product_code'] = $v['product_code'];
                }
                if ($v['fmt'] === 'exe') {
                    $p += ['detect_kind' => $v['detect_kind'], 'detect_value' => $v['detect_value'], 'detect_min' => $v['detect_min']];
                } else {
                    $p['detect_min'] = $v['detect_min'];   // an msi is detected by its product code
                }
            } else {
                if ($v['fmt'] !== 'msi') {
                    return $fail('Only an msi can be removed this way.', 'unsupported');
                }
                $p['product_code'] = $v['product_code'];
            }
        }
        $p['max_minutes'] = max(1, min(DeployProtocol::MAX_MINUTES_MAX, $maxMinutes));

        // Final word: the same validator the job service applies.
        [$clean, $err] = DeployJobTypes::install($p);
        if ($err !== null) {
            return $fail($err);
        }

        return ['ok' => true, 'params' => $clean];
    }

    /**
     * @param array<string,mixed> $v a version row
     * @return array<string,mixed> the input shape {@see PackageCatalog::validateVersion()} reads
     */
    private static function asInput(array $v): array
    {
        $in = ['version' => $v['version'], 'os' => $v['os'], 'fmt' => $v['fmt']];
        if ($v['os'] === 'linux') {
            return $in + ['pkg_name' => $v['pkg_name'], 'pin_version' => (bool) $v['pin_version']];
        }
        $in += ['url' => $v['url'], 'sha256' => $v['sha256'], 'size_bytes' => $v['size_bytes']];
        if ($v['fmt'] === 'exe') {
            return $in + ['silent_args' => $v['silent_args'], 'detect_kind' => $v['detect_kind'], 'detect_value' => $v['detect_value'], 'detect_min' => $v['detect_min']];
        }

        return $in + ['product_code' => $v['product_code'], 'detect_min' => $v['detect_min']];
    }

    /**
     * Queue an installation or removal (or its dry run) of one catalog version on one device.
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $v a catalog version row
     * @param array{source?:string,user_id?:?int,max_minutes?:int,idem?:bool} $opts
     * @return array{ok:bool,job_id?:string,skipped?:string,error?:string,code?:string}
     */
    public function queueInstall(array $dev, array $v, string $action, string $mode, array $opts = []): array
    {
        $deviceId = (int) $dev['device_id'];
        $pkg = $this->catalog->package((int) $v['package_id']);
        if ($pkg === null || !$pkg['enabled']) {
            return ['ok' => false, 'error' => 'That package is switched off.', 'code' => 'invalid'];
        }
        $built = $this->paramsFor($dev, $v, $action, $mode, (int) ($opts['max_minutes'] ?? DeployProtocol::MAX_MINUTES_DEFAULT));
        if (!$built['ok']) {
            return $built;
        }
        if ($this->pendingJob($deviceId, DeployProtocol::JOB_INSTALL) !== null) {
            return ['ok' => true, 'skipped' => 'already_queued'];   // one install at a time per device
        }
        $source = $opts['source'] ?? 'manual';
        $now = $this->sql->time();
        $idem = ($opts['idem'] ?? false) === true ? hash('sha256', "deploy:$deviceId:{$v['package_id']}:{$v['version_id']}:$action:$mode:" . intdiv($now, 3600)) : null;
        $jobId = JobService::uuid();
        $ledger = $this->sql->run('INSERT IGNORE INTO rmm_package_jobs (job_id, device_id, client_id, type, package_id, version_id, action, mode, source, idem_key, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$jobId, $deviceId, (int) $dev['client_id'], DeployProtocol::JOB_INSTALL, $v['package_id'], $v['version_id'], $action, $mode, $source, $idem, $opts['user_id'] ?? null, $this->sql->utcNow()]);
        if ($ledger === 0) {
            return ['ok' => true, 'skipped' => 'duplicate'];
        }
        $timeoutCap = (int) $this->settings->get()['job_max_timeout_s'];
        $mins = (int) $built['params']['max_minutes'];
        $r = $this->jobs->create($dev, DeployProtocol::JOB_INSTALL, null, $built['params'], min($timeoutCap, $mins * 60 + 300), true, (int) ($opts['user_id'] ?? 0), ['job_id' => $jobId]);
        if (!$r['ok']) {
            $this->sql->run('DELETE FROM rmm_package_jobs WHERE job_id = ?', [$jobId]);

            return ['ok' => false, 'error' => (string) ($r['error'] ?? 'The job could not be queued.'), 'code' => (string) ($r['code'] ?? 'invalid')];
        }
        $now = $this->sql->utcNow();
        $this->sql->run('INSERT INTO rmm_package_installs (device_id, package_id, client_id, last_job_id, last_attempt_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE last_job_id = VALUES(last_job_id), last_attempt_at = VALUES(last_attempt_at), updated_at = VALUES(updated_at)', [$deviceId, $v['package_id'], (int) $dev['client_id'], $jobId, $now, $now]);
        $this->events?->emit(RmmEvent::DEPLOY_STARTED, $dev, ['job_id' => $jobId, 'package' => mb_substr((string) $pkg['name'], 0, 100), 'action' => $action, 'mode' => $mode, 'source' => $source]);

        return ['ok' => true, 'job_id' => $jobId];
    }

    /**
     * Ask a device which of its expected packages it has. Queues one job per batch of rules that fits a job; a detection already waiting is not queued twice.
     *
     * @param array<string,mixed> $dev
     * @return array{ok:bool,job_id?:string,skipped?:string,error?:string,code?:string}
     */
    public function queueDetect(array $dev, ?int $userId, string $source = 'manual'): array
    {
        $deviceId = (int) $dev['device_id'];
        if (($pending = $this->pendingJob($deviceId, DeployProtocol::JOB_DETECT)) !== null) {
            return ['ok' => true, 'skipped' => 'already_queued', 'job_id' => $pending];
        }
        $rows = $this->sql->all('SELECT package_id, expected_version_id FROM rmm_package_installs WHERE device_id = ? AND expected_action IS NOT NULL ORDER BY package_id LIMIT ' . DeployProtocol::DETECT_ITEMS_MAX, [$deviceId]);
        $rules = [];
        $keys = [];
        foreach ($rows as $r) {
            $v = $r['expected_version_id'] === null ? null : $this->catalog->version((int) $r['expected_version_id']);
            if ($v === null || $v['detect_kind'] === '') {
                continue;
            }
            $key = 'p' . (int) $r['package_id'];
            $rule = ['key' => $key, 'kind' => $v['detect_kind'], 'value' => $v['detect_value'], 'min' => $v['detect_min']];
            if (strlen(DeployJobTypes::encodeRules([...$rules, $rule])) > DeployProtocol::RULES_MAX_CHARS) {
                break;   // the rest follows in the next job (the detection time is only recorded for the ones asked)
            }
            $rules[] = $rule;
            $keys[$key] = (int) $r['package_id'];
        }
        if ($rules === []) {
            return ['ok' => true, 'skipped' => 'nothing_to_detect'];
        }
        $jobId = JobService::uuid();
        $now = $this->sql->utcNow();
        $this->sql->run('INSERT INTO rmm_package_jobs (job_id, device_id, client_id, type, action, mode, keys_json, source, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$jobId, $deviceId, (int) $dev['client_id'], DeployProtocol::JOB_DETECT, '', '', (string) json_encode($keys), $source, $userId, $now]);
        $r = $this->jobs->create($dev, DeployProtocol::JOB_DETECT, null, ['rules' => DeployJobTypes::encodeRules($rules)], null, false, $userId ?? 0, ['job_id' => $jobId]);
        if (!$r['ok']) {
            $this->sql->run('DELETE FROM rmm_package_jobs WHERE job_id = ?', [$jobId]);

            return ['ok' => false, 'error' => (string) ($r['error'] ?? 'The detection could not be queued.'), 'code' => (string) ($r['code'] ?? 'invalid')];
        }
        $this->sql->run('INSERT INTO rmm_deploy_state (device_id, client_id, detect_requested_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE detect_requested_at = VALUES(detect_requested_at)', [$deviceId, (int) $dev['client_id'], $now]);

        return ['ok' => true, 'job_id' => $jobId];
    }

    // ------------------------------------------------------------------ results

    /**
     * Parse the output of a finished `software_install` job.
     *
     * @return array{ok:bool,result:string,code:string,detail:string,version:string,reboot_required:bool}|null null when unreadable
     */
    public static function cleanInstallResult(string $output): ?array
    {
        $j = json_decode($output, true);
        if (!is_array($j) || array_is_list($j) || !is_string($j['result'] ?? null) || !in_array($j['result'], DeployProtocol::RESULTS, true)) {
            return null;
        }
        $code = $j['code'] ?? '';
        $version = $j['version'] ?? '';

        return ['ok' => ($j['ok'] ?? false) === true, 'result' => $j['result'], 'code' => DeviceValidator::cleanText(is_scalar($code) ? (string) $code : '', 40) ?? '',
            'detail' => DeviceValidator::cleanText($j['detail'] ?? null, 200) ?? '', 'version' => DeviceValidator::cleanText(is_scalar($version) ? (string) $version : '', 64) ?? '',
            'reboot_required' => ($j['reboot_required'] ?? false) === true];
    }

    /**
     * Parse the output of a finished `software_detect` job.
     *
     * @return list<array{key:string,installed:bool,version:string,satisfied:bool}>|null null when unreadable
     */
    public static function cleanDetectResult(string $output): ?array
    {
        $j = json_decode($output, true);
        if (!is_array($j) || array_is_list($j) || ($j['ok'] ?? false) !== true || !is_array($j['items'] ?? null) || !array_is_list($j['items'])) {
            return null;
        }
        $out = [];
        foreach (array_slice($j['items'], 0, DeployProtocol::DETECT_ITEMS_MAX) as $it) {
            if (!is_array($it) || !is_string($it['key'] ?? null) || preg_match(DeployProtocol::KEY_RE, $it['key']) !== 1 || !is_bool($it['installed'] ?? null)) {
                continue;
            }
            $v = $it['version'] ?? '';
            $out[] = ['key' => $it['key'], 'installed' => $it['installed'], 'version' => DeviceValidator::cleanText(is_scalar($v) ? (string) $v : '', 64) ?? '',
                'satisfied' => $it['installed'] && ($it['satisfied'] ?? false) === true];
        }

        return $out;
    }

    /**
     * Apply the result of a finished software job. Idempotent: the ledger row is claimed first.
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $job the endpoint_agent_jobs row
     */
    public function applyJob(array $dev, array $job): bool
    {
        $led = $this->sql->one('SELECT * FROM rmm_package_jobs WHERE job_id = ?', [$job['job_id']]);
        if ($led === null || $led['applied_at'] !== null) {
            return false;
        }
        if ($this->sql->run('UPDATE rmm_package_jobs SET applied_at = ? WHERE job_id = ? AND applied_at IS NULL', [$this->sql->utcNow(), $job['job_id']]) !== 1) {
            return false;
        }
        if ($led['type'] === DeployProtocol::JOB_DETECT) {
            $this->applyDetect($dev, $job, $led);
        } else {
            $this->applyInstall($dev, $job, $led);
        }

        return true;
    }

    /**
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $job
     * @param array<string,mixed> $led
     */
    private function applyDetect(array $dev, array $job, array $led): void
    {
        $deviceId = (int) $dev['device_id'];
        $items = (string) $job['state'] === 'succeeded' ? self::cleanDetectResult((string) ($job['output'] ?? '')) : null;
        if ($items === null) {
            return;   // nothing learned; the next detection tries again
        }
        $map = json_decode((string) $led['keys_json'], true);
        $map = is_array($map) ? $map : [];
        $now = $this->sql->utcNow();
        foreach ($items as $it) {
            // Only keys this server asked about are applied: an agent cannot write to a package it was not asked for.
            if (!isset($map[$it['key']]) || !is_int($map[$it['key']])) {
                continue;
            }
            $this->sql->run('UPDATE rmm_package_installs SET installed = ?, installed_version = ?, satisfied = ?, detected_at = ?, updated_at = ? WHERE device_id = ? AND package_id = ?',
                [$it['installed'] ? 1 : 0, $it['installed'] ? $it['version'] : '', $it['satisfied'] ? 1 : 0, $now, $now, $deviceId, $map[$it['key']]]);
        }
        $this->sql->run('INSERT INTO rmm_deploy_state (device_id, client_id, detected_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE detected_at = VALUES(detected_at)', [$deviceId, (int) $dev['client_id'], $now]);
    }

    /**
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $job
     * @param array<string,mixed> $led
     */
    private function applyInstall(array $dev, array $job, array $led): void
    {
        $deviceId = (int) $dev['device_id'];
        $client = (int) $dev['client_id'];
        $packageId = (int) $led['package_id'];
        $mode = (string) $led['mode'];
        $action = (string) $led['action'];
        $now = $this->sql->utcNow();
        $parsed = in_array((string) $job['state'], ['succeeded', 'failed'], true) ? self::cleanInstallResult((string) ($job['output'] ?? '')) : null;
        if ($parsed === null) {
            $why = (string) ($job['reason'] ?? '') !== '' ? (string) $job['reason'] : 'job_' . (string) $job['state'];
            $parsed = ['ok' => false, 'result' => 'failed', 'code' => mb_substr($why, 0, 40), 'detail' => 'The job did not finish: ' . (string) $job['state'], 'version' => '', 'reboot_required' => false];
            $lost = in_array((string) $job['state'], ['cancelled', 'expired'], true);   // never ran: not a failed attempt
        } else {
            $lost = false;
        }
        $isDry = $mode === 'dry_run';
        $okResult = in_array($parsed['result'], DeployProtocol::OK_RESULTS, true) || in_array($parsed['result'], DeployProtocol::DRY_RESULTS, true);
        $this->sql->run('INSERT INTO rmm_package_history (device_id, client_id, package_id, version_id, action, mode, result, code, detail, version, at, job_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$deviceId, $client, $packageId, $led['version_id'], $action, $mode, $parsed['result'], $parsed['code'], $parsed['detail'], $parsed['version'], $now, $job['job_id'], $led['created_by']]);
        $this->sql->run('INSERT INTO rmm_package_installs (device_id, package_id, client_id, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)', [$deviceId, $packageId, $client, $now]);
        $this->sql->run('UPDATE rmm_package_installs SET last_action = ?, last_result = ?, last_code = ?, last_detail = ?, last_job_id = ? WHERE device_id = ? AND package_id = ?',
            [$action, $parsed['result'], $parsed['code'], $parsed['detail'], $job['job_id'], $deviceId, $packageId]);
        if (!$isDry && !$lost) {
            if ($okResult) {
                $installed = $action === 'install' ? 1 : 0;
                $this->sql->run('UPDATE rmm_package_installs SET installed = ?, installed_version = ?, satisfied = ?, detected_at = ?, fail_count = 0, attempts = attempts + 1 WHERE device_id = ? AND package_id = ?',
                    [$installed, $installed === 1 ? $parsed['version'] : '', $installed, $now, $deviceId, $packageId]);
            } else {
                $this->sql->run('UPDATE rmm_package_installs SET fail_count = fail_count + 1, attempts = attempts + 1 WHERE device_id = ? AND package_id = ?', [$deviceId, $packageId]);
            }
        }
        $name = (string) ($this->sql->val('SELECT name FROM rmm_packages WHERE package_id = ?', [$packageId]) ?? '');
        $fields = ['job_id' => (string) $job['job_id'], 'package' => mb_substr($name, 0, 100), 'action' => $action, 'mode' => $mode];
        if ($okResult) {
            $this->events?->emit(RmmEvent::DEPLOY_FINISHED, $dev, $fields + ['result' => $parsed['result'], 'version' => $parsed['version'], 'reboot_required' => $parsed['reboot_required']]);
        } else {
            $this->events?->emit(RmmEvent::DEPLOY_FAILED, $dev, $fields + ['result' => $parsed['result'], 'code' => $parsed['code']]);
        }
    }

    /**
     * Apply finished software results that were not applied yet (a pass during load shedding, a listener that failed, a late result). Bounded.
     *
     * @return int jobs applied
     */
    public function applyPending(int $limit): int
    {
        $rows = $this->sql->all("SELECT j.* FROM rmm_package_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id
            WHERE p.applied_at IS NULL AND j.state IN ('succeeded','failed','timed_out','cancelled','expired')
              AND NOT (COALESCE(j.reason, '') IN ('result_lost','no_result_by_deadline') AND j.finished_at > ?) ORDER BY p.created_at LIMIT " . max(1, $limit), [$this->sql->utcAt(-3600)]);
        $n = 0;
        foreach ($rows as $j) {
            $dev = $this->expectationsDevice((int) $j['device_id']);
            if ($dev === null) {
                $this->sql->run('UPDATE rmm_package_jobs SET applied_at = ? WHERE job_id = ?', [$this->sql->utcNow(), $j['job_id']]);
                continue;
            }
            $n += $this->applyJob($dev, $j) ? 1 : 0;
        }

        return $n;
    }

    /** @return array<string,mixed>|null */
    private function expectationsDevice(int $deviceId): ?array
    {
        return $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]);
    }
}
