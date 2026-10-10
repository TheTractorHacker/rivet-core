<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Read;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Binaries\BinaryStore;
use RivetCore\Rmm\Contracts\RmmAssetNamesInterface;
use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;
use RivetCore\Rmm\Crypto\Redactor;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Device\DeviceState;
use RivetCore\Rmm\Link\RmmLinker;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Software\SoftwareVersion;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Tags\GroupService;
use RivetCore\Rmm\Tags\TagService;
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
        private readonly ?TagService $tagService = null,
        private readonly ?GroupService $groupService = null,
        private readonly ?RmmMetricReaderInterface $metricReader = null,
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
     * @param array{status?:string,client_id?:int,ring?:string,q?:string,retired?:string,tag?:string|int,group?:int,software?:string,location_id?:int} $filters
     *        status: online|offline|stale|never (by check-in age) or linked|pending_approval|rejected (link state);
     *        retired: 'hide' (default), 'only' or 'all'; q: part of the hostname or serial number;
     *        tag: a tag name or id the device carries; group: a group id (static member or carrying one of its tags);
     *        software: part of the name of a software item currently installed on the device; location_id: the edition's location (site)
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
        $tags = $this->tagService === null ? [] : $this->tagService->forDevices(array_map(static fn (array $d): int => (int) $d['device_id'], $rows));

        return ['items' => array_map(function (array $d) use ($cfg, $names, $tags): array {
            $aid = $d['asset_id'] === null ? 0 : (int) $d['asset_id'];

            return $this->summary($d, $cfg) + [
                'asset_name' => $aid > 0 && isset($names[$aid]) ? $names[$aid] : null,
                'arch' => $d['arch'],
                'update_state' => self::decode($d['update_state_json'] ?? null),
                'tags' => $tags[(int) $d['device_id']] ?? [],
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
        if (isset($filters['location_id']) && (int) $filters['location_id'] > 0) {
            $where[] = 'location_id = ?';
            $params[] = (int) $filters['location_id'];
        }
        if (isset($filters['tag']) && $filters['tag'] !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM rmm_device_tags ft JOIN rmm_tags t ON t.tag_id = ft.tag_id WHERE ft.device_id = endpoint_agent_devices.device_id AND (t.name = ? OR t.tag_id = ?))';
            array_push($params, (string) $filters['tag'], is_int($filters['tag']) || ctype_digit((string) $filters['tag']) ? (int) $filters['tag'] : 0);
        }
        if (isset($filters['group']) && (int) $filters['group'] > 0) {
            $where[] = GroupService::membershipPredicate('endpoint_agent_devices');
            array_push($params, (int) $filters['group'], (int) $filters['group']);
        }
        if (isset($filters['software']) && is_string($filters['software']) && trim($filters['software']) !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM rmm_device_software fs WHERE fs.device_id = endpoint_agent_devices.device_id AND fs.removed_at IS NULL AND fs.name LIKE ?)';
            $params[] = '%' . addcslashes(trim($filters['software']), '\\%_') . '%';
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

    // ------------------------------------------------------------------ tags and groups (Phase 1)

    /**
     * The tags a device carries.
     *
     * @return list<array<string,mixed>> {tag_id, name, color, description, source}
     */
    public function deviceTags(int $deviceId): array
    {
        return $this->tagService?->forDevice($deviceId) ?? [];
    }

    /**
     * Every tag with its device count (live devices; with $visibleClientIds only those in the caller's clients).
     *
     * @param list<int>|null $visibleClientIds
     * @return list<array<string,mixed>>
     */
    public function tags(?array $visibleClientIds = null): array
    {
        return $this->tagService?->all($visibleClientIds) ?? [];
    }

    /**
     * Every group with its tags and member count (with $visibleClientIds only the devices in the caller's clients).
     *
     * @param list<int>|null $visibleClientIds
     * @return list<array<string,mixed>>
     */
    public function groups(?array $visibleClientIds = null): array
    {
        return $this->groupService?->all($visibleClientIds) ?? [];
    }

    /**
     * The groups a device belongs to.
     *
     * @return list<array{group_id:int,name:string}>
     */
    public function deviceGroups(int $deviceId): array
    {
        return $this->groupService?->forDevice($deviceId) ?? [];
    }

    // ------------------------------------------------------------------ software inventory (Phase 1)

    /**
     * What the module knows about a device's software report: whether one ever arrived, when, and how many items are installed.
     *
     * @return array{reported:bool,count:int,reported_at:?string,full_at:?string,resync_requested:bool,capable:bool}
     */
    public function softwareState(int $deviceId): array
    {
        $r = $this->sql->one('SELECT * FROM rmm_device_state WHERE device_id = ?', [$deviceId]);

        return ['reported' => $r !== null && $r['software_hash'] !== null, 'count' => (int) ($r['software_count'] ?? 0),
            'reported_at' => Sql::iso($r === null || $r['software_at'] === null ? null : (string) $r['software_at']),
            'full_at' => Sql::iso($r === null || $r['software_full_at'] === null ? null : (string) $r['software_full_at']),
            'resync_requested' => (int) ($r['software_resync'] ?? 0) === 1, 'capable' => DeviceState::announces($r, DeviceState::CAP_SOFTWARE_INVENTORY)];
    }

    /**
     * The software installed on one device (or, with include_removed, also what was removed and when), by name.
     *
     * @param array{q?:string,limit?:int,offset?:int,include_removed?:bool} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function softwareFor(int $deviceId, array $opts = []): array
    {
        $where = ['device_id = ?'];
        $params = [$deviceId];
        if (empty($opts['include_removed'])) {
            $where[] = 'removed_at IS NULL';
        }
        if (isset($opts['q']) && trim((string) $opts['q']) !== '') {
            $where[] = 'name LIKE ?';
            $params[] = '%' . addcslashes(trim((string) $opts['q']), '\\%_') . '%';
        }
        $limit = max(1, min(500, (int) ($opts['limit'] ?? 100)));
        $offset = max(0, (int) ($opts['offset'] ?? 0));
        $w = implode(' AND ', $where);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM rmm_device_software WHERE $w", $params);
        $items = [];
        foreach ($this->sql->all("SELECT * FROM rmm_device_software WHERE $w ORDER BY name, source LIMIT $limit OFFSET $offset", $params) as $r) {
            $items[] = ['name' => (string) $r['name'], 'version' => (string) $r['version'], 'publisher' => (string) $r['publisher'] === '' ? null : (string) $r['publisher'], 'source' => (string) $r['source'],
                'installed_on' => $r['installed_on'] === null ? null : (string) $r['installed_on'], 'first_seen_at' => Sql::iso((string) $r['first_seen_at']),
                'last_seen_at' => Sql::iso((string) $r['last_seen_at']), 'removed_at' => Sql::iso($r['removed_at'] === null ? null : (string) $r['removed_at'])];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * The change log of a device's software, newest first: installed, upgraded, downgraded, removed.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function softwareHistory(int $deviceId, ?string $name = null, int $limit = 100, int $offset = 0): array
    {
        $where = 'device_id = ?';
        $params = [$deviceId];
        if ($name !== null && trim($name) !== '') {
            $where .= ' AND name LIKE ?';
            $params[] = '%' . addcslashes(trim($name), '\\%_') . '%';
        }
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM rmm_software_history WHERE $where", $params);
        $items = [];
        foreach ($this->sql->all("SELECT * FROM rmm_software_history WHERE $where ORDER BY occurred_at DESC, history_id DESC LIMIT $limit OFFSET $offset", $params) as $r) {
            $items[] = ['name' => (string) $r['name'], 'source' => (string) $r['source'], 'change' => (string) $r['change_type'], 'old_version' => $r['old_version'], 'new_version' => $r['new_version'],
                'publisher' => (string) $r['publisher'] === '' ? null : (string) $r['publisher'], 'at' => Sql::iso((string) $r['occurred_at'])];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * The software installed across the fleet, grouped by name and source, with how many devices run it and how many versions are out there.
     *
     * @param list<int>|null $visibleClientIds
     * @return array{items:list<array{name:string,source:string,devices:int,versions:int}>,total:int}
     */
    public function softwareCatalog(?string $q = null, ?array $visibleClientIds = null, int $limit = 100, int $offset = 0): array
    {
        [$scope, $params] = $this->deviceWhere(['retired' => 'hide'], $visibleClientIds, $this->settings->get());
        $where = 's.removed_at IS NULL AND s.device_id IN (SELECT device_id FROM endpoint_agent_devices WHERE ' . $scope . ')';
        if ($q !== null && trim($q) !== '') {
            $where .= ' AND s.name LIKE ?';
            $params[] = '%' . addcslashes(trim($q), '\\%_') . '%';
        }
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM (SELECT 1 FROM rmm_device_software s WHERE $where GROUP BY s.name, s.source) g", $params);
        $items = [];
        foreach ($this->sql->all("SELECT s.name, s.source, COUNT(*) AS devices, COUNT(DISTINCT s.version) AS versions FROM rmm_device_software s WHERE $where
            GROUP BY s.name, s.source ORDER BY devices DESC, s.name LIMIT $limit OFFSET $offset", $params) as $r) {
            $items[] = ['name' => (string) $r['name'], 'source' => (string) $r['source'], 'devices' => (int) $r['devices'], 'versions' => (int) $r['versions']];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Devices running a version of a product older than $minVersion. $name matches part of the software name (case-insensitive); versions
     * are compared with {@see SoftwareVersion::compare()} (a forgiving comparison, not a package manager's). One row per device and item.
     *
     * @param list<int>|null $visibleClientIds
     * @return list<array{device_id:int,hostname:string,client_id:int,asset_id:?int,name:string,source:string,version:string}>
     */
    public function outdatedSoftware(string $name, string $minVersion, ?array $visibleClientIds = null, int $limit = 200): array
    {
        $name = trim($name);
        if ($name === '' || trim($minVersion) === '') {
            return [];
        }
        [$scope, $params] = $this->deviceWhere(['retired' => 'hide'], $visibleClientIds, $this->settings->get());
        $params[] = '%' . addcslashes($name, '\\%_') . '%';
        $limit = max(1, min(1000, $limit));
        $out = [];
        $afterDevice = 0;
        $afterKey = '';
        // Read in key order and compare in PHP; stop at $limit hits or after a bounded number of scanned rows.
        for ($scanned = 0; $scanned < 20000 && count($out) < $limit;) {
            $rows = $this->sql->all('SELECT d.device_id, d.hostname, d.client_id, d.asset_id, s.name, s.source, s.version, s.software_key
                FROM rmm_device_software s JOIN endpoint_agent_devices d ON d.device_id = s.device_id
                WHERE s.removed_at IS NULL AND s.device_id IN (SELECT device_id FROM endpoint_agent_devices WHERE ' . $scope . ') AND s.name LIKE ?
                AND (s.device_id > ? OR (s.device_id = ? AND s.software_key > ?)) ORDER BY s.device_id, s.software_key LIMIT 1000', array_merge($params, [$afterDevice, $afterDevice, $afterKey]));
            foreach ($rows as $r) {
                if (SoftwareVersion::compare((string) $r['version'], $minVersion) < 0) {
                    $out[] = ['device_id' => (int) $r['device_id'], 'hostname' => (string) $r['hostname'], 'client_id' => (int) $r['client_id'],
                        'asset_id' => $r['asset_id'] === null ? null : (int) $r['asset_id'], 'name' => (string) $r['name'], 'source' => (string) $r['source'], 'version' => (string) $r['version']];
                    if (count($out) >= $limit) {
                        break;
                    }
                }
            }
            $scanned += count($rows);
            if (count($rows) < 1000) {
                break;
            }
            $last = $rows[count($rows) - 1];
            $afterDevice = (int) $last['device_id'];
            $afterKey = (string) $last['software_key'];
        }

        return $out;
    }

    // ------------------------------------------------------------------ check history and network peak (Phase 1)

    /**
     * The recorded results of one check of one device over the last $hours, oldest first, with the time spent in each state. The ring keeps a
     * result when the status changed and otherwise at most one per check_history_gap_s, so the points are a trend, not every sample.
     *
     * @return array{key:string,since:string,hours:int,points:list<array{at:string,status:string,detail:string}>,seconds:array<string,int>,availability_pct:?float,changes:int,retention_days:int}
     */
    public function checkHistory(int $deviceId, string $key, int $hours = 24, int $limit = 500): array
    {
        $hours = max(1, min(24 * 365, $hours));
        $limit = max(1, min(2000, $limit));
        $now = $this->sql->time();
        $since = $now - $hours * 3600;
        $rows = $this->sql->all('SELECT status, detail, reported_at FROM endpoint_agent_check_history WHERE device_id = ? AND check_key = ? AND reported_at >= ? ORDER BY reported_at, hist_id LIMIT ' . $limit,
            [$deviceId, $key, gmdate('Y-m-d H:i:s', $since)]);
        $before = $this->sql->one('SELECT status, reported_at FROM endpoint_agent_check_history WHERE device_id = ? AND check_key = ? AND reported_at < ? ORDER BY reported_at DESC, hist_id DESC LIMIT 1',
            [$deviceId, $key, gmdate('Y-m-d H:i:s', $since)]);
        $seconds = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'unknown' => 0];
        $points = [];
        $changes = 0;
        $cursor = $since;
        $state = $before === null ? null : (string) $before['status'];
        foreach ($rows as $r) {
            $at = Sql::ts((string) $r['reported_at']);
            if ($state !== null && isset($seconds[$state])) {
                $seconds[$state] += max(0, $at - $cursor);
            }
            if ($state !== null && $state !== (string) $r['status']) {
                ++$changes;
            }
            $state = (string) $r['status'];
            $cursor = max($cursor, $at);
            $points[] = ['at' => gmdate('Y-m-d\TH:i:s\Z', $at), 'status' => $state, 'detail' => (string) $r['detail']];
        }
        if ($state !== null && isset($seconds[$state])) {
            $seconds[$state] += max(0, $now - $cursor);
        }
        $measured = $seconds['ok'] + $seconds['warn'] + $seconds['fail'];

        return ['key' => $key, 'since' => gmdate('Y-m-d\TH:i:s\Z', $since), 'hours' => $hours, 'points' => $points, 'seconds' => $seconds,
            'availability_pct' => $measured > 0 ? round(100 * $seconds['ok'] / $measured, 2) : null, 'changes' => $changes, 'retention_days' => $this->settings->limits()['check_history_days']];
    }

    /**
     * The network bar of the device page: the current receive and transmit rate against the peak of the last $hours (bytes per second).
     * `history` is false when the module's metric sink keeps no history (the edition's sink does not implement
     * {@see RmmMetricReaderInterface}) or the device has no asset; the peaks are then null and only the current rate is given.
     *
     * @return array{window_hours:int,history:bool,rx:array{current:?float,peak:?float,avg:?float,peak_at:?string},tx:array{current:?float,peak:?float,avg:?float,peak_at:?string}}
     */
    public function networkPeak(int $deviceId, int $hours = 24): array
    {
        $hours = max(1, min(24 * 30, $hours));
        $d = $this->devices->find($deviceId);
        $m = $d === null ? null : self::decode($d['last_metrics_json'] ?? null);
        $asset = $d === null || $d['asset_id'] === null ? 0 : (int) $d['asset_id'];
        $history = $this->metricReader !== null && $asset > 0;
        $since = new \DateTimeImmutable('@' . ($this->sql->time() - $hours * 3600));
        $side = function (string $field, string $key) use ($m, $history, $asset, $since): array {
            $bps = is_array($m) && (is_int($m[$field] ?? null) || is_float($m[$field] ?? null)) ? (float) $m[$field] : null;   // the agent reports bits per second
            $p = $history && $this->metricReader !== null ? $this->metricReader->peak($asset, $key, 'total', $since) : null;

            return ['current' => $bps === null ? null : $bps / 8, 'peak' => $p === null ? null : $p['max'], 'avg' => $p === null ? null : $p['avg'],
                'peak_at' => $p === null ? null : $p['peak_at']->format('Y-m-d\TH:i:s\Z')];
        };

        return ['window_hours' => $hours, 'history' => $history, 'rx' => $side('net_rx_bps', 'network.rx_bytes_per_s'), 'tx' => $side('net_tx_bps', 'network.tx_bytes_per_s')];
    }

    // ------------------------------------------------------------------ the live document (Phase 1)

    /** Seconds between polls of {@see deviceLive()} when nothing is shedding load; the floor an edition may enforce is {@see LIVE_POLL_FLOOR_S}. */
    public const LIVE_POLL_S = 30;
    public const LIVE_POLL_FLOOR_S = 15;

    /**
     * The small JSON document the device page polls (ASSET_PAGE_REDESIGN.md 7.2): status, the latest gauges, the checks, open alert and job
     * counts and the poll interval the server wants. Missing readings are null, never 0. A weak ETag is built from the device row and two
     * cheap aggregates; when the caller's `If-None-Match` equals it, `status` is 304 and `body` is null without the checks list being read.
     *
     * `poll_s` doubles at load-shedding level 1 and is 0 (paused) from level 2, which the page shows as "Live updates paused by server load".
     * Reads: the device row (given), one aggregate over its checks, one over its running and queued jobs, then the checks.
     *
     * @param array<string,mixed> $dev the device row
     * @return array{status:int,etag:string,body:?array<string,mixed>}
     */
    public function deviceLive(array $dev, ?string $ifNoneMatch = null): array
    {
        $deviceId = (int) $dev['device_id'];
        $cfg = $this->settings->get();
        $c = $this->sql->one('SELECT COUNT(*) AS n, COALESCE(SUM(alert_id IS NOT NULL), 0) AS alerts, MAX(last_changed_at) AS changed FROM endpoint_agent_checks WHERE device_id = ?', [$deviceId]) ?? [];
        $j = $this->sql->one("SELECT COALESCE(SUM(state = 'queued'), 0) AS queued, COALESCE(SUM(state = 'running'), 0) AS running, MAX(updated_at) AS touched
            FROM endpoint_agent_jobs WHERE device_id = ? AND state IN ('queued', 'running')", [$deviceId]) ?? [];
        $shed = (int) ($cfg['shed_level'] ?? 0);
        $etag = 'W/"' . md5(implode('|', [(int) $dev['last_seq'], (string) ($dev['last_checkin_at'] ?? ''), (string) ($dev['link_state'] ?? ''), (string) ($dev['revoked_at'] ?? ''), (string) ($dev['retired_at'] ?? ''),
            (int) ($c['n'] ?? 0), (int) ($c['alerts'] ?? 0), (string) ($c['changed'] ?? ''), (int) ($j['queued'] ?? 0), (int) ($j['running'] ?? 0), (string) ($j['touched'] ?? ''), $shed])) . '"';
        if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
            return ['status' => 304, 'etag' => $etag, 'body' => null];
        }
        $st = $this->devices->status($dev, $cfg);
        $m = self::decode($dev['last_metrics_json'] ?? null);
        $inv = self::decode($dev['inventory_json'] ?? null);
        $num = static fn (mixed $v): int|float|null => (is_int($v) || is_float($v)) ? $v : null;
        $totals = [];
        foreach (is_array($inv) && is_array($inv['disks'] ?? null) ? $inv['disks'] : [] as $d) {
            if (is_array($d) && isset($d['mount'])) {
                $totals[(string) $d['mount']] = $d;
            }
        }
        $disks = [];
        foreach (is_array($m) && is_array($m['disk'] ?? null) ? $m['disk'] : [] as $d) {
            if (is_array($d) && isset($d['mount'])) {
                $t = $totals[(string) $d['mount']] ?? [];
                $disks[] = ['mount' => (string) $d['mount'], 'used_pct' => $num($d['used_pct'] ?? null), 'free_bytes' => $num($t['free_bytes'] ?? null), 'total_bytes' => $num($t['total_bytes'] ?? null)];
            }
        }
        $checks = [];
        $worst = null;
        foreach ($this->sql->all('SELECT check_key, status, detail, last_changed_at, alert_id FROM endpoint_agent_checks WHERE device_id = ? ORDER BY check_key', [$deviceId]) as $r) {
            $checks[] = ['key' => (string) $r['check_key'], 'status' => (string) $r['status'], 'detail' => (string) $r['detail'], 'since' => Sql::iso($r['last_changed_at'] === null ? null : (string) $r['last_changed_at']),
                'alert_id' => $r['alert_id'] === null ? null : (int) $r['alert_id']];
            if ($r['alert_id'] !== null) {
                $worst = $r['status'] === 'fail' ? 'error' : ($worst ?? 'warning');
            }
        }
        $poll = $shed >= 2 ? 0 : ($shed === 1 ? self::LIVE_POLL_S * 2 : self::LIVE_POLL_S);
        $body = [
            'v' => 1,
            'state' => $st['state'],
            'last_checkin_at' => $st['last_checkin_at'],
            'age_s' => $st['age_s'],
            'next_check_in_s' => (int) $cfg['check_in_interval_s'],
            'agent_version' => $dev['agent_version'],
            'uptime_s' => $dev['uptime_s'] === null ? null : (int) $dev['uptime_s'],
            'pending_reboot' => $dev['pending_reboot'] === null ? null : (bool) $dev['pending_reboot'],
            'gauges' => ['sampled_at' => Sql::iso($dev['last_collected_at'] === null ? null : (string) $dev['last_collected_at']), 'cpu_pct' => $num(is_array($m) ? ($m['cpu_pct'] ?? null) : null),
                'mem_pct' => $num(is_array($m) ? ($m['mem_pct'] ?? null) : null), 'disks' => $disks, 'net_rx_bps' => $num(is_array($m) ? ($m['net_rx_bps'] ?? null) : null),
                'net_tx_bps' => $num(is_array($m) ? ($m['net_tx_bps'] ?? null) : null)],
            'checks' => $checks,
            'alerts' => ['open' => (int) ($c['alerts'] ?? 0), 'worst' => $worst],
            'jobs' => ['queued' => (int) ($j['queued'] ?? 0), 'running' => (int) ($j['running'] ?? 0)],
            'poll_s' => $poll,
            'shed' => $shed,
            'seq' => (int) $dev['last_seq'],
        ];

        return ['status' => 200, 'etag' => $etag, 'body' => $body];
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
