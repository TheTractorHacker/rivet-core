<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Read;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Binaries\BinaryStore;
use RivetCore\Rmm\Contracts\RmmAssetNamesInterface;
use RivetCore\Rmm\Crypto\Redactor;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Link\RmmLinker;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Technician\ActionResult;
use RivetCore\Rmm\Update\UpdateService;

/**
 * Everything the technician REST API and an edition's pages read, as plain arrays: the device list (filters, pagination, client
 * scope), one device (status, inventory decoded, checks with their alert ids, job history, mesh mapping, update state, what it would be
 * offered), the fleet counters, the approval queue with candidates and reasons, enrollment tokens, releases, binaries, the recent
 * rejected enrollment attempts and the settings (never a secret). Editions render these; they never write SQL against endpoint_agent_*.
 *
 * Everything device-supplied is returned as data; escaping is the renderer's job. Secrets (credential hashes, the private signing key,
 * the MeshCentral login key) are never part of any read model. Job output is redacted when it is stored, so it is returned as stored.
 *
 * Client scope: methods that take `$visibleClientIds` restrict to those clients plus client 0 (see RmmTenancyInterface::visibleClientIds);
 * null means unrestricted.
 *
 * @api
 */
final class RmmReadModel
{
    /** Why a device is waiting for approval, in words an administrator can read. */
    public const MATCH_REASONS = [
        'no_match' => 'No asset has this serial number or MAC address.',
        'hostname_only' => 'Only the hostname matches an asset. A hostname is not proof of identity.',
        'ambiguous' => 'Several assets match, or the serial number and MAC address point at different assets.',
        'scope_mismatch' => 'The matching asset belongs to a different client than the enrollment token.',
        'asset_already_linked' => 'The matching asset already belongs to another live device (duplicate enrollment).',
        'asset_retired' => 'The asset this device was linked to was retired or removed.',
    ];

    /** Status filter values that mean "by check-in age"; every other accepted value is a link state. */
    public const STATUS_FILTERS = ['online', 'offline', 'stale', 'never'];

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly DeviceRepository $devices,
        private readonly UpdateService $updates,
        private readonly BinaryStore $binaries,
        private readonly ?RmmAssetNamesInterface $assetNames = null,
        private readonly string $clientLabel = 'client',
        private readonly ?RmmAuthorizer $authz = null,
    ) {
    }

    /** {@see MATCH_REASONS} in the edition's terminology ("client" is replaced by the configured label); an unknown code is returned as is. */
    public function matchReasonText(string $code): string
    {
        $t = self::MATCH_REASONS[$code] ?? $code;

        return $this->clientLabel === 'client' ? $t : str_replace(' client ', ' ' . $this->clientLabel . ' ', $t);
    }

    // ------------------------------------------------------------------ devices

    /**
     * @param array<string,mixed> $d a device row
     * @param array<string,mixed>|null $cfg the settings row
     * @return array<string,mixed>
     */
    public function summary(array $d, ?array $cfg = null): array
    {
        $st = $this->devices->status($d, $cfg);

        return [
            'device_id' => (int) $d['device_id'],
            'hostname' => $d['hostname'],
            'asset_id' => $d['asset_id'] === null ? null : (int) $d['asset_id'],
            'client_id' => (int) $d['client_id'],
            'link_state' => $d['link_state'],
            'status' => $st['state'],
            'last_checkin_at' => $st['last_checkin_at'],
            'offline_since' => $st['offline_since'],
            'agent_version' => $d['agent_version'],
            'ring' => $d['ring'],
            'os_version' => $d['os_version'],
            'revoked' => $d['revoked_at'] !== null,
            'retired' => $d['retired_at'] !== null,
        ];
    }

    /**
     * The REST device detail: the summary plus identity, the latest inventory and metrics, checks and recent jobs.
     *
     * @param array<string,mixed> $d a device row
     * @param array<string,mixed>|null $cfg the settings row
     * @return array<string,mixed>
     */
    public function detail(array $d, bool $showJobOutput, ?array $cfg = null): array
    {
        return $this->detailBase($d, $showJobOutput, $cfg, false, 20);
    }

    /**
     * @param array<string,mixed> $d
     * @param array<string,mixed>|null $cfg
     * @return array<string,mixed>
     */
    private function detailBase(array $d, bool $showJobOutput, ?array $cfg, bool $richChecks, int $jobLimit): array
    {
        $out = $this->summary($d, $cfg);
        $out['serial'] = $d['serial'];
        $out['manufacturer'] = $d['manufacturer'];
        $out['model'] = $d['model'];
        $out['arch'] = $d['arch'];
        $out['logged_in_user'] = $d['logged_in_user'];
        $out['pending_reboot'] = $d['pending_reboot'] === null ? null : (bool) $d['pending_reboot'];
        $out['uptime_s'] = $d['uptime_s'] === null ? null : (int) $d['uptime_s'];
        $out['last_collected_at'] = Sql::iso($d['last_collected_at'] === null ? null : (string) $d['last_collected_at']);
        $out['inventory'] = self::decode($d['inventory_json'] ?? null);
        $out['metrics'] = self::decode($d['last_metrics_json'] ?? null);
        $out['match_reason'] = $d['match_reason'];
        $out['mesh_mapped'] = $this->sql->val('SELECT 1 FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$d['device_id']]) !== null;
        $out['checks'] = array_map($richChecks ? self::richCheck(...) : self::shortCheck(...),
            $this->sql->all('SELECT * FROM endpoint_agent_checks WHERE device_id = ? ORDER BY check_key', [$d['device_id']]));
        $out['jobs'] = $this->jobs((int) $d['device_id'], $jobLimit, $showJobOutput);

        return $out;
    }

    /**
     * @param array<string,mixed> $c a check row
     * @return array<string,mixed>
     */
    private static function shortCheck(array $c): array
    {
        return ['key' => $c['check_key'], 'status' => $c['status'], 'detail' => $c['detail'],
            'consecutive_failures' => (int) $c['consecutive_failures'], 'last_reported_at' => Sql::iso($c['last_reported_at'] === null ? null : (string) $c['last_reported_at'])];
    }

    /**
     * @param array<string,mixed> $c a check row
     * @return array<string,mixed>
     */
    private static function richCheck(array $c): array
    {
        return [
            'key' => $c['check_key'], 'status' => $c['status'], 'detail' => $c['detail'], 'consecutive_failures' => (int) $c['consecutive_failures'],
            'consecutive_ok' => (int) $c['consecutive_ok'], 'episode' => (int) $c['episode'], 'alert_id' => $c['alert_id'] === null ? null : (int) $c['alert_id'],
            'last_reported_at' => Sql::iso($c['last_reported_at'] === null ? null : (string) $c['last_reported_at']),
            'last_changed_at' => Sql::iso($c['last_changed_at'] === null ? null : (string) $c['last_changed_at']),
        ];
    }

    /**
     * Job history of one device, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function jobs(int $deviceId, int $limit, bool $withOutput): array
    {
        $rows = $this->sql->all('SELECT * FROM endpoint_agent_jobs WHERE device_id = ? ORDER BY created_at DESC, job_id LIMIT ' . max(1, min(200, $limit)), [$deviceId]);

        return array_map(static fn (array $j): array => self::jobRow($j, $withOutput), $rows);
    }

    /**
     * @param array<string,mixed> $j a job row
     * @return array<string,mixed>
     */
    private static function jobRow(array $j, bool $withOutput): array
    {
        $o = ['job_id' => $j['job_id'], 'type' => $j['type'], 'state' => $j['state'], 'reason' => $j['reason'], 'attempt' => (int) $j['attempt'],
            'destructive' => (bool) $j['destructive'], 'run_as' => $j['run_as'], 'timeout_s' => (int) $j['timeout_s'], 'exit_code' => $j['exit_code'] === null ? null : (int) $j['exit_code'],
            'created_by' => (int) $j['created_by'], 'created_at' => Sql::iso((string) $j['created_at']), 'started_at' => Sql::iso($j['started_at'] === null ? null : (string) $j['started_at']),
            'finished_at' => Sql::iso($j['finished_at'] === null ? null : (string) $j['finished_at']), 'expires_at' => Sql::iso((string) $j['expires_at'])];
        if ($withOutput) {
            $o['output'] = $j['output'];
            $o['output_truncated'] = (bool) $j['output_truncated'];
        }

        return $o;
    }

    /**
     * One job of one device, for the person who asked: the same fields as {@see jobs()} plus its output. Output is redacted when it is stored
     * and passed through the redactor again here, so it can never come back with a secret in it.
     *
     * Order of the answers (the same as the technician API): module off or no `device.view` -> 403; a missing device, a device outside the
     * principal's client scope and a job id that is not that device's all answer the same 404; a principal without `rmm.job.run_saved`
     * for the device's client (the grant that shows output) -> 403 with the edition's denial text.
     *
     * @param string $jobId the job's UUID (matched case-insensitively)
     * @return ActionResult ok: `data` is the job (`job_id`, ..., `output`, `output_truncated`, `device_id`, `hostname`); failure: 403 `forbidden` or 404 `not_found`
     * @throws \LogicException when the read model was built without an authorizer (RmmModule::readModel() always passes one when the module has a policy)
     */
    public function job(int $deviceId, string $jobId, RmmPrincipal $who): ActionResult
    {
        $authz = $this->requireAuthz();
        $denied = $authz->check($who->userId, RmmAbility::DEVICE_VIEW, 0);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $d = $deviceId > 0 && preg_match('/^[0-9a-fA-F-]{36}$/', $jobId) === 1 ? $this->devices->find($deviceId) : null;
        $client = $d === null ? 0 : (int) $d['client_id'];
        if ($d === null || $authz->check($who->userId, RmmAbility::DEVICE_VIEW, $client) !== null) {
            return ActionResult::fail(404, 'not_found', 'Not found.');
        }
        $row = $this->sql->one('SELECT * FROM endpoint_agent_jobs WHERE device_id = ? AND job_id = ?', [$deviceId, $jobId]);
        if ($row === null || strcasecmp((string) $row['job_id'], $jobId) !== 0) {
            return ActionResult::fail(404, 'not_found', 'Not found.');
        }
        if (!$authz->allowed($who->userId, RmmAbility::JOB_RUN_SAVED, $client)) {
            return ActionResult::fail(403, 'forbidden', $authz->denial(RmmAbility::JOB_RUN_SAVED));
        }
        $job = self::jobRow($row, true);
        $job['output'] = Redactor::redact((string) ($row['output'] ?? ''));
        $job['device_id'] = $deviceId;
        $job['hostname'] = (string) $d['hostname'];

        return ActionResult::ok('ok', 200, 'ok', $job);
    }

    /**
     * The latest failed or timed-out jobs across the fleet, newest first, metadata only (never the script text or the output), limited to
     * the clients the principal may see (the job's own client, plus client 0). Returns an empty list when the module is off or the
     * principal lacks `device.view` (the caller shows its own denial; this never reveals why).
     *
     * @param int $limit 1 to 50
     * @return list<array{job_id:string,device_id:int,type:string,state:string,reason:mixed,exit_code:?int,at:?string,hostname:string,asset_id:?int,client_id:int}>
     * @throws \LogicException when the read model was built without an authorizer
     */
    public function recentFailedJobs(int $limit, RmmPrincipal $who): array
    {
        $authz = $this->requireAuthz();
        if (!$authz->allowed($who->userId, RmmAbility::DEVICE_VIEW, 0)) {
            return [];
        }
        $where = ["j.state IN ('failed','timed_out')"];
        $params = [];
        $visible = $authz->visibleClientIds($who->userId);
        if ($visible !== null) {
            if ($visible === []) {
                $where[] = 'j.client_id = 0';
            } else {
                $where[] = '(j.client_id = 0 OR j.client_id IN (' . implode(',', array_fill(0, count($visible), '?')) . '))';
                array_push($params, ...array_map('intval', $visible));
            }
        }
        $rows = $this->sql->all('SELECT j.job_id, j.device_id, j.client_id, j.type, j.state, j.reason, j.exit_code, j.finished_at, j.created_at, d.hostname, d.asset_id
            FROM endpoint_agent_jobs j JOIN endpoint_agent_devices d ON d.device_id = j.device_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY COALESCE(j.finished_at, j.created_at) DESC, j.job_id LIMIT ' . max(1, min(50, $limit)), $params);

        return array_map(static fn (array $r): array => [
            'job_id' => (string) $r['job_id'], 'device_id' => (int) $r['device_id'], 'type' => (string) $r['type'], 'state' => (string) $r['state'], 'reason' => $r['reason'],
            'exit_code' => $r['exit_code'] === null ? null : (int) $r['exit_code'], 'at' => Sql::iso((string) ($r['finished_at'] ?? $r['created_at'])),
            'hostname' => (string) $r['hostname'], 'asset_id' => $r['asset_id'] === null ? null : (int) $r['asset_id'], 'client_id' => (int) $r['client_id'],
        ], $rows);
    }

    private function requireAuthz(): RmmAuthorizer
    {
        return $this->authz ?? throw new \LogicException('This read method needs an authorizer: build RmmReadModel through RmmModule::readModel() with an AccessPolicyInterface.');
    }

    /**
     * The device list: filters, pagination and client scope, with the total of what matches (before the page is cut).
     *
     * @param array{status?:string,client_id?:int,ring?:string,q?:string,retired?:string} $filters
     *        status: online|offline|stale|never (by check-in age) or linked|pending_approval|rejected (link state);
     *        retired: 'hide' (default), 'only' or 'all'; q: part of the hostname or serial number
     * @param list<int>|null $visibleClientIds
     * @param bool $withExtras add `arch` (the device's reported architecture, string|null), `asset_name` (string|null; null when the device has no asset or the assets adapter does not implement
     *        {@see RmmAssetNamesInterface}) and `update_state` (the decoded self-update state, `failed_versions` included, or null) to each
     *        summary, with one batched name lookup per page. The technician REST API passes false: its JSON is frozen.
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function listDevices(array $filters = [], ?array $visibleClientIds = null, int $limit = 50, int $offset = 0, bool $withExtras = true): array
    {
        $cfg = $this->settings->get();
        [$where, $params] = $this->deviceWhere($filters, $visibleClientIds, $cfg);
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM endpoint_agent_devices WHERE $where", $params);
        $rows = $this->sql->all("SELECT * FROM endpoint_agent_devices WHERE $where ORDER BY hostname, device_id LIMIT $limit OFFSET $offset", $params);

        if (!$withExtras) {
            return ['items' => array_map(fn (array $d): array => $this->summary($d, $cfg), $rows), 'total' => $total];
        }
        $ids = [];
        foreach ($rows as $d) {
            if ($d['asset_id'] !== null && (int) $d['asset_id'] > 0) {
                $ids[(int) $d['asset_id']] = (int) $d['asset_id'];
            }
        }
        $names = $ids !== [] && $this->assetNames !== null ? $this->assetNames->assetNames(array_values($ids)) : [];

        return ['items' => array_map(function (array $d) use ($cfg, $names): array {
            $aid = $d['asset_id'] === null ? 0 : (int) $d['asset_id'];

            return $this->summary($d, $cfg) + [
                'asset_name' => $aid > 0 && isset($names[$aid]) ? $names[$aid] : null,
                'arch' => $d['arch'],
                'update_state' => self::decode($d['update_state_json'] ?? null),
            ];
        }, $rows), 'total' => $total];
    }

    /**
     * @param array<string,mixed> $filters
     * @param list<int>|null $visibleClientIds
     * @param array<string,mixed> $cfg
     * @return array{0:string,1:list<mixed>}
     */
    private function deviceWhere(array $filters, ?array $visibleClientIds, array $cfg): array
    {
        $where = ['1 = 1'];
        $params = [];
        $retired = $filters['retired'] ?? 'hide';
        if ($retired === 'hide') {
            $where[] = 'retired_at IS NULL';
        } elseif ($retired === 'only') {
            $where[] = 'retired_at IS NOT NULL';
        }
        if ($visibleClientIds !== null) {
            if ($visibleClientIds === []) {
                $where[] = 'client_id = 0';
            } else {
                $where[] = '(client_id = 0 OR client_id IN (' . implode(',', array_fill(0, count($visibleClientIds), '?')) . '))';
                array_push($params, ...array_map('intval', $visibleClientIds));
            }
        }
        if (isset($filters['client_id']) && (int) $filters['client_id'] > 0) {
            $where[] = 'client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (isset($filters['ring']) && in_array($filters['ring'], RmmProtocol::RINGS, true)) {
            $where[] = 'ring = ?';
            $params[] = $filters['ring'];
        }
        if (isset($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $like = '%' . addcslashes(trim($filters['q']), '\\%_') . '%';
            $where[] = '(hostname LIKE ? OR serial LIKE ?)';
            array_push($params, $like, $like);
        }
        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $onlineSince = $this->sql->utcAt(-(int) $cfg['offline_after_s']);
            $staleBefore = $this->sql->utcAt(-(int) $cfg['stale_after_s']);
            switch ($status) {
                case 'never':
                    $where[] = 'last_checkin_at IS NULL';
                    break;
                case 'online':
                    $where[] = 'last_checkin_at >= ?';
                    $params[] = $onlineSince;
                    break;
                case 'offline':
                    $where[] = 'last_checkin_at < ? AND last_checkin_at >= ?';
                    array_push($params, $onlineSince, $staleBefore);
                    break;
                case 'stale':
                    $where[] = 'last_checkin_at < ?';
                    $params[] = $staleBefore;
                    break;
                default:
                    // A link state; a value that is not one matches nothing (as the original filter did).
                    $where[] = 'link_state = ?';
                    $params[] = $status;
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Counters of the live fleet (not revoked, not retired): by check-in age, plus the devices waiting for approval.
     *
     * @param list<int>|null $visibleClientIds
     * @return array{online:int,offline:int,stale:int,never:int,total:int,pending_approval:int}
     */
    public function fleetCounts(?array $visibleClientIds = null): array
    {
        $cfg = $this->settings->get();
        [$scope, $params] = $this->deviceWhere(['retired' => 'hide'], $visibleClientIds, $cfg);
        $onlineSince = $this->sql->utcAt(-(int) $cfg['offline_after_s']);
        $staleBefore = $this->sql->utcAt(-(int) $cfg['stale_after_s']);
        $r = $this->sql->one("SELECT COUNT(*) AS total, COALESCE(SUM(last_checkin_at IS NULL), 0) AS never_n, COALESCE(SUM(last_checkin_at >= ?), 0) AS online_n,
            COALESCE(SUM(last_checkin_at < ? AND last_checkin_at >= ?), 0) AS offline_n, COALESCE(SUM(last_checkin_at < ?), 0) AS stale_n,
            COALESCE(SUM(link_state = 'pending_approval'), 0) AS pending_n FROM endpoint_agent_devices WHERE revoked_at IS NULL AND $scope",
            array_merge([$onlineSince, $onlineSince, $staleBefore, $staleBefore], $params)) ?? [];

        return ['online' => (int) ($r['online_n'] ?? 0), 'offline' => (int) ($r['offline_n'] ?? 0), 'stale' => (int) ($r['stale_n'] ?? 0),
            'never' => (int) ($r['never_n'] ?? 0), 'total' => (int) ($r['total'] ?? 0), 'pending_approval' => (int) ($r['pending_n'] ?? 0)];
    }

    /**
     * The device page: everything an administrator or technician sees about one device. No secret is included.
     *
     * @return array<string,mixed>|null null when there is no such device (the caller decides whether the user may see it)
     */
    public function deviceView(int $deviceId, bool $showJobOutput, int $jobLimit = 15): ?array
    {
        $d = $this->devices->find($deviceId);
        if ($d === null) {
            return null;
        }
        $cfg = $this->settings->get();
        $st = $this->devices->status($d, $cfg);
        $mesh = $this->sql->one('SELECT mesh_node_id, source, updated_at FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$deviceId]);
        $update = self::decode($d['update_state_json'] ?? null);
        $offered = $d['retired_at'] === null && $d['revoked_at'] === null ? $this->updates->offeredRelease($d) : null;

        // detailBase() builds the rich checks and the requested job window itself: merging over detail()'s output with `+` kept its shorter
        // `checks` and its 20 jobs, because the left side of an array union wins.
        return $this->detailBase($d, $showJobOutput, $cfg, true, $jobLimit) + [
            'status_info' => $st,
            'location_id' => (int) $d['location_id'],
            'os' => $d['os'],
            'mac_addresses' => self::decode($d['mac_addresses'] ?? null) ?? [],
            'first_seen_at' => Sql::iso((string) $d['first_seen_at']),
            'last_inventory_at' => Sql::iso($d['last_inventory_at'] === null ? null : (string) $d['last_inventory_at']),
            'last_ip' => $d['last_ip'],
            'enroll_count' => (int) $d['enroll_count'],
            'revoked_at' => Sql::iso($d['revoked_at'] === null ? null : (string) $d['revoked_at']),
            'revoked_reason' => $d['revoked_reason'],
            'retired_at' => Sql::iso($d['retired_at'] === null ? null : (string) $d['retired_at']),
            'token_issued_at' => Sql::iso($d['token_issued_at'] === null ? null : (string) $d['token_issued_at']),
            'token_expires_at' => Sql::iso($d['token_expires_at'] === null ? null : (string) $d['token_expires_at']),
            'agent_key' => RmmLinker::agentKey($deviceId),
            'integration_id' => (int) $this->settings->get()['integration_id'],
            'mesh' => ['mapped' => $mesh !== null, 'node_id' => $mesh['mesh_node_id'] ?? null, 'source' => $mesh['source'] ?? null],
            'update_state' => $update,
            'offered_release' => $offered === null ? null : ['version' => $offered['version'], 'ring' => $offered['ring'], 'rollout_pct' => (int) $offered['rollout_pct'], 'arch' => $offered['arch']],
            'match_reason_text' => $this->matchReasonText((string) $d['match_reason']),
            'candidates' => self::candidates($d),
        ];
    }

    // ------------------------------------------------------------------ approvals

    /**
     * Devices waiting for an administrator's decision, oldest first, each with the reason in words and the asset candidates.
     *
     * @param list<int>|null $visibleClientIds
     * @return list<array<string,mixed>>
     */
    public function pendingApprovals(?array $visibleClientIds = null, int $limit = 200): array
    {
        $cfg = $this->settings->get();
        [$scope, $params] = $this->deviceWhere(['retired' => 'hide'], $visibleClientIds, $cfg);
        $rows = $this->sql->all("SELECT * FROM endpoint_agent_devices WHERE link_state = 'pending_approval' AND revoked_at IS NULL AND $scope ORDER BY first_seen_at, device_id LIMIT " . max(1, min(500, $limit)), $params);

        return array_map(fn (array $d): array => [
            'device_id' => (int) $d['device_id'],
            'hostname' => $d['hostname'],
            'client_id' => (int) $d['client_id'],
            'serial' => $d['serial'],
            'manufacturer' => $d['manufacturer'],
            'model' => $d['model'],
            'os_version' => $d['os_version'],
            'first_seen_at' => Sql::iso((string) $d['first_seen_at']),
            'match_reason' => $d['match_reason'],
            'match_reason_text' => $this->matchReasonText((string) $d['match_reason']),
            'candidates' => self::candidates($d),
        ], $rows);
    }

    /**
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private static function candidates(array $d): array
    {
        $c = self::decode($d['match_candidates_json'] ?? null);

        return is_array($c) && array_is_list($c) ? $c : [];
    }

    // ------------------------------------------------------------------ tokens, attempts, releases, binaries

    /**
     * Enrollment tokens, newest first, with their state (active, revoked, expired, used_up). Only the selector, never the secret.
     *
     * @return list<array<string,mixed>>
     */
    public function tokens(int $limit = 50): array
    {
        $now = $this->sql->time();

        return array_map(static function (array $t) use ($now): array {
            $state = $t['revoked_at'] !== null ? 'revoked' : (Sql::ts((string) $t['expires_at']) <= $now ? 'expired' : ((int) $t['use_count'] >= (int) $t['max_uses'] ? 'used_up' : 'active'));

            return ['token_id' => (int) $t['token_id'], 'selector' => $t['token_selector'], 'label' => $t['label'], 'client_id' => (int) $t['client_id'], 'location_id' => (int) $t['location_id'],
                'ring' => $t['ring'], 'max_uses' => (int) $t['max_uses'], 'use_count' => (int) $t['use_count'], 'expires_at' => Sql::iso((string) $t['expires_at']),
                'revoked_at' => Sql::iso($t['revoked_at'] === null ? null : (string) $t['revoked_at']), 'last_used_at' => Sql::iso($t['last_used_at'] === null ? null : (string) $t['last_used_at']),
                'created_by' => (int) $t['created_by'], 'created_at' => Sql::iso((string) $t['created_at']), 'state' => $state];
        }, $this->sql->all('SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT ' . max(1, min(500, $limit))));
    }

    /**
     * The most recent rejected enrollment and installer attempts.
     *
     * @return list<array{attempted_at:?string,ip:string,reason:string,token_selector:string}>
     */
    public function recentFailedAttempts(int $limit = 15): array
    {
        return array_map(static fn (array $a): array => ['attempted_at' => Sql::iso((string) $a['attempted_at']), 'ip' => (string) $a['ip_text'], 'reason' => (string) $a['reason'],
            'token_selector' => (string) $a['token_selector']],
            $this->sql->all('SELECT attempted_at, ip_text, reason, token_selector FROM endpoint_agent_enroll_attempts WHERE success = 0 ORDER BY attempt_id DESC LIMIT ' . max(1, min(200, $limit))));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function releases(int $limit = 30): array
    {
        return array_map(static fn (array $r): array => ['release_id' => (int) $r['release_id'], 'version' => $r['version'], 'ring' => $r['ring'], 'arch' => $r['arch'], 'min_version' => $r['min_version'],
            'rollout_pct' => (int) $r['rollout_pct'], 'active' => (bool) $r['active'], 'hosted' => $r['binary_id'] !== null, 'binary_id' => $r['binary_id'] === null ? null : (int) $r['binary_id'],
            'url' => $r['url'], 'sha256' => $r['sha256'], 'notes' => $r['notes'], 'created_at' => Sql::iso((string) $r['created_at'])],
            $this->sql->all('SELECT * FROM endpoint_agent_releases ORDER BY release_id DESC LIMIT ' . max(1, min(200, $limit))));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function binaries(int $limit = 40): array
    {
        return array_map(static fn (array $b): array => ['binary_id' => (int) $b['binary_id'], 'version' => $b['version'], 'arch' => $b['arch'], 'sha256' => $b['sha256'],
            'size_bytes' => (int) $b['size_bytes'], 'active' => (bool) $b['active'], 'is_current' => (bool) $b['is_current'], 'uploaded_by' => (int) $b['uploaded_by'], 'created_at' => Sql::iso((string) $b['created_at'])],
            $this->sql->all('SELECT * FROM endpoint_agent_binaries ORDER BY binary_id DESC LIMIT ' . max(1, min(200, $limit))));
    }

    /**
     * The binary new installers are stamped from, per architecture.
     *
     * @return array{amd64:?array<string,mixed>,arm64:?array<string,mixed>}
     */
    public function currentBinaries(): array
    {
        return ['amd64' => $this->updates->currentBinary('amd64'), 'arm64' => $this->updates->currentBinary('arm64')];
    }

    /** The largest upload the web form can accept, in bytes (the configured cap bounded by PHP's limits). */
    public function uploadLimit(): int
    {
        return $this->binaries->effectiveUploadLimit();
    }

    // ------------------------------------------------------------------ settings

    /**
     * The settings for the administration page. No secret: the private signing key and the MeshCentral login key are reported as
     * "set" flags only.
     *
     * @return array<string,mixed>
     */
    public function settingsSummary(): array
    {
        $c = $this->settings->get(true);
        $out = [];
        foreach (RmmSettings::WRITABLE as $k) {
            if (array_key_exists($k, $c) && $k !== 'mesh_login_key_enc') {
                $out[$k] = $c[$k];
            }
        }
        $out['signing_key_id'] = (string) $c['signing_key_id'];
        $out['signing_public_key'] = (string) $c['signing_public_key'];
        $out['signing_key_created_at'] = Sql::iso($c['signing_key_created_at'] === null ? null : (string) $c['signing_key_created_at']);
        $out['signing_key_set'] = (string) $c['signing_public_key'] !== '' && !empty($c['signing_private_key_enc']);
        $out['mesh_login_key_set'] = !empty($c['mesh_login_key_enc']);
        $out['checks'] = $this->settings->checks();
        $out['features'] = $this->settings->features();
        $out['limits'] = $this->settings->limits();
        $out['service_base'] = $this->updates->serviceBase();

        return $out;
    }

    /** @return mixed the decoded JSON, or null when empty or invalid */
    private static function decode(mixed $json): mixed
    {
        return is_string($json) && $json !== '' ? json_decode($json, true) : null;
    }
}
