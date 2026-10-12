<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Logs\LogActions;
use RivetCore\Rmm\Remote\RemoteActions;
use RivetCore\Rmm\Technician\ActionResult;
use RivetCore\Rmm\Technician\TechnicianActions;

/**
 * The remote-tools and log routes of the technician REST API (Phases 6 and 8). {@see TechnicianApi} hands these to it after its own authentication and module
 * checks; the grants are those of {@see RemoteActions} and {@see LogActions}. Every route is additive and a device outside the caller's clients is the same
 * 404 as a missing one.
 *
 * Service and process managers (a request queues a signed job; the answer is read from the same URL a moment later):
 *   GET    endpoint_devices/{id}/services                              the latest service list the device reported (job_id, state, data)
 *   POST   endpoint_devices/{id}/services/query                        ask the device for a fresh list (202 {job_id})
 *   POST   endpoint_devices/{id}/services/{name}/{action}              start | stop | restart | enable | disable | manual; body {confirm} (stop, restart, disable need it)
 *   GET    endpoint_devices/{id}/processes                             the latest process list (cpu, memory)
 *   POST   endpoint_devices/{id}/processes/query                       {limit?, sort?: cpu|mem} -> 202 {job_id}
 *   POST   endpoint_devices/{id}/processes/{pid}/kill                  {name, force?, confirm} (always needs confirm)
 *   GET    endpoint_devices/{id}/remote/jobs/{job_id}                  the structured result of a remote-tool job
 * Mesh:
 *   GET    endpoint_devices/{id}/remote/mesh                           is Mesh set up, is the device mapped, which views may this caller open
 *   POST   endpoint_devices/{id}/remote                                {force?, view?: desktop|terminal|files} (handled by TechnicianApi)
 *   PUT    endpoint_devices/{id}/remote/node                           {node_id} map (or clear, with "") the MeshCentral node (administrators)
 * Mesh agent (1.0.0-rc.16; needs `rmm.remote.manage` and the remote-tools switch; the install also needs MeshCentral enabled and configured and an agent that announced `job:mesh_install`):
 *   POST   endpoint_devices/{id}/mesh/install                          {force?} -> 202 {job_id} | 200 {skipped: already_installed|already_queued} (idempotent); the job carries only the stored Mesh settings
 *   POST   endpoint_devices/{id}/mesh/uninstall                        {confirm} (needed) -> 202 {job_id}; runs the Mesh agent's own uninstaller, never touches the RivetIT agent
 *   POST   endpoint_devices/mesh/install                               {target:{type,id}, force?, confirm} (needed) -> 201 {device_count, created, skipped_*}; capped by bulk_run_max
 * File transfer (needs the `filetransfer` sub-switch):
 *   GET    endpoint_devices/{id}/files                                 the device's transfers
 *   POST   endpoint_devices/{id}/files/download                        {path} -> 202 {transfer_id, job_id}
 *   POST   endpoint_devices/{id}/files/upload                          {path, size, sha256, overwrite?, confirm} (administrators) -> 201 {transfer_id}
 *   PUT    endpoint_devices/{id}/files/{transfer_id}/content           the raw bytes of a declared upload -> 202 {job_id}
 *   GET    endpoint_devices/{id}/files/{transfer_id}                   state of one transfer
 *   GET    endpoint_devices/{id}/files/{transfer_id}/content           the verified file of a finished download
 *   DELETE endpoint_devices/{id}/files/{transfer_id}                   delete the stored copy
 * Settings (administrators):
 *   GET    endpoint_devices/remote/settings                            sub-switches, allowed folders, log sources, limits, the risk and storage texts
 *   PUT    endpoint_devices/remote/settings                            {features?, transfer_allow?, logs_collect?, limits?, accept_filetransfer_risk?, accept_logs_storage?}
 *   GET    endpoint_devices/remote/mesh                                the Mesh agent install settings (group id, certificate pin, overwrite switch, id and argument tables, whether an installer can ask for the agent)
 *   PUT    endpoint_devices/remote/mesh                                {mesh_group_id?, mesh_server_cert_pin?, mesh_auto_map_overwrite?, mesh_agent_ids?, mesh_install_args?, probe_pin?}; probe_pin answers a proposed pin without saving it
 * Logs (Phase 8):
 *   GET    endpoint_devices/logs                                       search across the caller's clients (device_id, client_id, from, to, level, source, code, q, limit, offset)
 *   GET    endpoint_devices/logs/stats                                 fleet storage figures
 *   GET    endpoint_devices/{id}/logs                                  search one device
 *   GET    endpoint_devices/{id}/logs/stats                            one device's storage figures
 *   GET    endpoint_devices/logs/rules | POST logs/rules | GET,PATCH,PUT,DELETE logs/rules/{id}
 *
 * @api
 */
final class RemoteApi
{
    /** First path segments that are not a device id and belong here. */
    public const FLEET = ['logs', 'remote'];
    /** First path segment of the bulk Mesh agent install (1.0.0-rc.16), kept apart from {@see FLEET} so that constant's value is unchanged. */
    public const MESH = ['mesh'];
    /** Second path segments of a device route that belong here. */
    public const DEVICE = ['services', 'processes', 'files', 'logs'];

    public function __construct(private readonly RemoteActions $remote, private readonly LogActions $logs, private readonly TechnicianActions $technician)
    {
    }

    /**
     * A route that does not start with a device id.
     *
     * @param list<string> $seg
     */
    public function fleet(RmmRequest $req, RmmPrincipal $who, array $seg): RmmResponse
    {
        $m = strtoupper($req->method);
        $limit = max(1, min(500, (int) ($req->query['limit'] ?? 100)));
        $offset = max(0, (int) ($req->query['offset'] ?? 0));
        if ($seg[0] === 'mesh') {
            if (($seg[1] ?? null) !== 'install' || isset($seg[2])) {
                return self::notFound();
            }
            if ($m !== 'POST') {
                return self::method();
            }
            [$in, $bad] = $this->body($req);

            return $bad ?? self::result($this->remote->installMeshAgentBulk($who, $in));
        }
        if ($seg[0] === 'remote') {
            if (($seg[1] ?? null) === 'mesh' && !isset($seg[2])) {
                if ($m === 'GET') {
                    return self::result($this->remote->meshSettings($who));
                }
                if (in_array($m, ['PUT', 'PATCH', 'POST'], true)) {
                    [$in, $bad] = $this->body($req);

                    return $bad ?? self::result($this->remote->updateMeshSettings($who, $in));
                }

                return self::method();
            }
            if (($seg[1] ?? null) !== 'settings' || isset($seg[2])) {
                return self::notFound();
            }
            if ($m === 'GET') {
                return self::result($this->remote->settings($who));
            }
            if (in_array($m, ['PUT', 'PATCH', 'POST'], true)) {
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->remote->updateSettings($who, $in));
            }

            return self::method();
        }
        // logs
        $second = $seg[1] ?? null;
        if ($second === null) {
            return $m === 'GET' ? self::result($this->logs->fleetLogs($who, self::filters($req), $limit, $offset)) : self::method();
        }
        if ($second === 'stats' && !isset($seg[2])) {
            return $m === 'GET' ? self::result($this->logs->stats($who, null)) : self::method();
        }
        if ($second !== 'rules') {
            return self::notFound();
        }
        $rule = $seg[2] ?? null;
        if ($rule === null) {
            if ($m === 'GET') {
                return self::result($this->logs->listRules($who));
            }
            if ($m === 'POST') {
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->logs->createRule($who, $in));
            }

            return self::method();
        }
        if (!ctype_digit($rule) || isset($seg[3])) {
            return self::notFound();
        }
        $id = (int) $rule;
        if ($m === 'GET') {
            return self::result($this->logs->getRule($who, $id));
        }
        if (in_array($m, ['PATCH', 'PUT'], true)) {
            [$in, $bad] = $this->body($req);

            return $bad ?? self::result($this->logs->updateRule($who, $id, $in));
        }
        if ($m === 'DELETE') {
            return self::result($this->logs->deleteRule($who, $id));
        }

        return self::method();
    }

    /**
     * A route under a device. Returns null for a path this class does not own, so the caller can try the next handler.
     *
     * @param list<string> $seg the path segments after the resource: [device id, kind, ...]
     */
    public function device(RmmRequest $req, RmmPrincipal $who, int $id, array $seg): ?RmmResponse
    {
        $kind = $seg[1] ?? '';
        $m = strtoupper($req->method);
        $a = $seg[2] ?? null;
        $b = $seg[3] ?? null;
        switch ($kind) {
            case 'services':
                if ($a === null) {
                    return $m === 'GET' ? self::result($this->remote->snapshot($who, $id, 'services')) : self::method();
                }
                if ($a === 'query' && $b === null) {
                    return $m === 'POST' ? self::result($this->remote->listServices($who, $id)) : self::method();
                }
                if ($b !== null && !isset($seg[4])) {
                    if ($m !== 'POST') {
                        return self::method();
                    }
                    [$in, $bad] = $this->body($req);

                    return $bad ?? self::result($this->remote->controlService($who, $id, rawurldecode($a), $b, !empty($in['confirm'])));
                }

                return self::notFound();
            case 'processes':
                if ($a === null) {
                    return $m === 'GET' ? self::result($this->remote->snapshot($who, $id, 'processes')) : self::method();
                }
                if ($a === 'query' && $b === null) {
                    if ($m !== 'POST') {
                        return self::method();
                    }
                    [$in, $bad] = $this->body($req);

                    return $bad ?? self::result($this->remote->listProcesses($who, $id, $in['limit'] ?? null, $in['sort'] ?? null));
                }
                if (ctype_digit($a) && $b === 'kill' && !isset($seg[4])) {
                    if ($m !== 'POST') {
                        return self::method();
                    }
                    [$in, $bad] = $this->body($req);

                    return $bad ?? self::result($this->remote->killProcess($who, $id, $a, $in['name'] ?? null, !empty($in['force']), !empty($in['confirm'])));
                }

                return self::notFound();
            case 'remote':
                if ($a === null) {
                    return null;   // POST remote (the launch) belongs to TechnicianApi; GET remote stays the 404 it always was
                }
                if ($a === 'mesh' && $b === null) {
                    return $m === 'GET' ? self::result($this->remote->meshInfo($who, $id)) : self::method();
                }
                if ($a === 'jobs' && $b !== null && !isset($seg[4])) {
                    return $m === 'GET' ? self::result($this->remote->jobResult($who, $id, $b)) : self::method();
                }
                if ($a === 'node' && $b === null) {
                    if (!in_array($m, ['PUT', 'POST'], true)) {
                        return self::method();
                    }
                    [$in, $bad] = $this->body($req);
                    if ($bad !== null) {
                        return $bad;
                    }
                    $r = $this->technician->setMeshNode($who, $id, is_string($in['node_id'] ?? null) ? $in['node_id'] : '');

                    return self::result($r->ok ? ActionResult::ok($r->message, 200, 'ok', ['node_id' => is_string($in['node_id'] ?? null) ? trim($in['node_id']) : '']) : $r);
                }

                return self::notFound();
            case 'mesh':
                if (($a !== 'install' && $a !== 'uninstall') || $b !== null) {
                    return self::notFound();
                }
                if ($m !== 'POST') {
                    return self::method();
                }
                [$in, $bad] = $this->body($req);
                if ($bad !== null) {
                    return $bad;
                }
                foreach (['force', 'confirm'] as $k) {
                    if (array_key_exists($k, $in) && !is_bool($in[$k])) {
                        return self::json(422, ['error' => "$k must be true or false.", 'code' => 'invalid']);
                    }
                }
                if (array_diff(array_keys($in), $a === 'install' ? ['force'] : ['confirm']) !== []) {
                    return self::json(422, ['error' => 'Only ' . ($a === 'install' ? 'force' : 'confirm') . ' is accepted: the install carries the stored Mesh settings and nothing the caller sends.', 'code' => 'invalid']);
                }

                return self::result($a === 'install' ? $this->remote->installMeshAgent($who, $id, !empty($in['force'])) : $this->remote->uninstallMeshAgent($who, $id, !empty($in['confirm'])));
            case 'files':
                return $this->files($req, $who, $id, $m, $seg);
            case 'logs':
                if ($a === null) {
                    return $m === 'GET' ? self::result($this->logs->deviceLogs($who, $id, self::filters($req), max(1, min(500, (int) ($req->query['limit'] ?? 100))), max(0, (int) ($req->query['offset'] ?? 0)))) : self::method();
                }
                if ($a === 'stats' && $b === null) {
                    return $m === 'GET' ? self::result($this->logs->stats($who, $id)) : self::method();
                }

                return self::notFound();
        }

        return null;
    }

    /** @param list<string> $seg */
    private function files(RmmRequest $req, RmmPrincipal $who, int $id, string $m, array $seg): RmmResponse
    {
        $a = $seg[2] ?? null;
        $b = $seg[3] ?? null;
        if ($a === null) {
            return $m === 'GET' ? self::result($this->remote->listTransfers($who, $id)) : self::method();
        }
        if ($a === 'download' && $b === null) {
            if ($m !== 'POST') {
                return self::method();
            }
            [$in, $bad] = $this->body($req);

            return $bad ?? self::result($this->remote->requestDownload($who, $id, $in['path'] ?? null));
        }
        if ($a === 'upload' && $b === null) {
            if ($m !== 'POST') {
                return self::method();
            }
            [$in, $bad] = $this->body($req);

            return $bad ?? self::result($this->remote->createUpload($who, $id, $in['path'] ?? null, $in['size'] ?? null, $in['sha256'] ?? null, !empty($in['overwrite']), !empty($in['confirm'])));
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $a) !== 1 || isset($seg[4])) {
            return self::notFound();
        }
        if ($b === null) {
            if ($m === 'GET') {
                return self::result($this->remote->transfer($who, $id, $a));
            }
            if ($m === 'DELETE') {
                return self::result($this->remote->deleteTransfer($who, $id, $a));
            }

            return self::method();
        }
        if ($b !== 'content') {
            return self::notFound();
        }
        if ($m === 'GET') {
            $r = $this->remote->downloadContent($who, $id, $a);
            if (!$r->ok) {
                return self::result($r);
            }

            return FileDownload::response(new RmmFileBody((string) $r->data['path'], (int) $r->data['length'], null, (string) $r->data['sha256']), (string) $r->data['name']);
        }
        if ($m === 'PUT') {
            if ($req->bodyStream === null) {
                return self::json(422, ['error' => 'The file content is the request body.', 'code' => 'invalid']);
            }

            return self::result($this->remote->uploadContent($who, $id, $a, $req->bodyStream));
        }

        return self::method();
    }

    /**
     * @return array<string,mixed>
     */
    private static function filters(RmmRequest $req): array
    {
        $f = [];
        foreach (['device_id', 'client_id', 'from', 'to', 'level', 'source', 'code', 'q'] as $k) {
            if (isset($req->query[$k]) && $req->query[$k] !== '') {
                $f[$k] = $req->query[$k];
            }
        }

        return $f;
    }

    /** @return array{0:array<string,mixed>,1:?RmmResponse} */
    private function body(RmmRequest $req): array
    {
        if ($req->declaredLength !== null && $req->declaredLength > TechnicianApi::MAX_BODY) {
            return [[], self::json(413, ['error' => 'Request body too large.', 'code' => 'too_large'])];
        }
        $raw = $req->bodyStream === null ? '' : stream_get_contents($req->bodyStream, TechnicianApi::MAX_BODY + 1);
        if ($raw === false || strlen($raw) > TechnicianApi::MAX_BODY) {
            return [[], self::json(413, ['error' => 'Request body too large.', 'code' => 'too_large'])];
        }
        if ($raw === '') {
            return [[], null];
        }
        $body = json_decode($raw, true);
        if (!is_array($body) || (array_is_list($body) && $body !== [])) {
            return [[], self::json(400, ['error' => 'Request body must be a JSON object'])];
        }

        /** @var array<string,mixed> $body */
        return [$body, null];
    }

    private static function result(ActionResult $r): RmmResponse
    {
        if (!$r->ok) {
            return self::json($r->http, ['error' => $r->message, 'code' => $r->code] + $r->data);
        }

        return self::json($r->http, $r->data === [] ? ['ok' => true] : $r->data);
    }

    private static function method(): RmmResponse
    {
        return self::json(405, ['error' => 'Method not allowed', 'code' => 'method_not_allowed']);
    }

    private static function notFound(): RmmResponse
    {
        return self::json(404, ['error' => 'Not found', 'code' => 'not_found']);
    }

    /** @param array<mixed> $data */
    private static function json(int $status, array $data): RmmResponse
    {
        return new RmmResponse($status, ['Content-Type' => 'application/json'], (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
