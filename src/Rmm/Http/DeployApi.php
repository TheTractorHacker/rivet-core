<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Deploy\DeployActions;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * The software-deployment routes of the technician REST API (Phase 5). {@see TechnicianApi} hands these to it after its own authentication and module checks; the grants
 * are those of {@see DeployActions} (`rmm.software.view`, `rmm.software.manage`, `rmm.software.deploy`; the host list needs `rmm.admin`). Every route is additive; a device
 * or client outside the caller's clients is the same 404 as a missing one. Nothing is changed by a read, and a run is a dry run unless it says `mode: "run"` and `confirm: true`.
 *
 * On one device:
 *   GET    endpoint_devices/{id}/deployments                  what the device should have and has: counts by state and one entry per package
 *   GET    endpoint_devices/{id}/deployments/history          install and removal history (limit, offset)
 *   POST   endpoint_devices/{id}/deployments/detect           ask the device which assigned packages it has (read only) -> 202 {job_id} | 200 {skipped}
 *   POST   endpoint_devices/{id}/deployments/run              {package_id, action?: install|uninstall, version_id?, mode?: dry_run (default) | run, confirm?, max_minutes?} -> 202 {job_id} | 202 pending_approval {approval_id}
 * Across the fleet:
 *   GET    endpoint_devices/software_packages | POST software_packages | GET,PUT,PATCH,DELETE software_packages/{id}
 *   POST   endpoint_devices/software_packages/{id}/versions   add a version (it cannot be edited afterwards)
 *   PATCH  endpoint_devices/software_packages/{id}/versions/{version_id}   {retired: true|false}
 *   GET    endpoint_devices/software_assignments | POST software_assignments {package_id, scope_type, scope_id?, action?, version_id?} | DELETE software_assignments/{id}
 *   GET    endpoint_devices/software_hosts | POST software_hosts {host, note?} | DELETE software_hosts/{host}
 *   GET    endpoint_devices/software_report                   deployed vs. expected (group_by = package|client|ring|device, client_id, package_id, ring, state, limit, offset); software_report/csv as text/csv
 *
 * @api
 */
final class DeployApi
{
    /** First path segments that are not a device id and belong here. */
    public const FLEET = ['software_packages', 'software_assignments', 'software_hosts', 'software_report'];

    public function __construct(private readonly DeployActions $deploy)
    {
    }

    /**
     * @param list<string> $seg
     */
    public function fleet(RmmRequest $req, RmmPrincipal $who, array $seg): RmmResponse
    {
        $m = strtoupper($req->method);
        $second = $seg[1] ?? null;
        $third = $seg[2] ?? null;
        switch ($seg[0]) {
            case 'software_packages':
                return $this->packages($req, $who, $m, $second, $third, $seg[3] ?? null, isset($seg[4]));
            case 'software_assignments':
                if ($second === null) {
                    if ($m === 'GET') {
                        return self::result($this->deploy->assignments($who));
                    }
                    if ($m === 'POST') {
                        [$in, $bad] = $this->body($req);

                        return $bad ?? self::result($this->deploy->assign($who, $in));
                    }

                    return self::method();
                }
                if (!ctype_digit($second) || $third !== null) {
                    return self::notFound();
                }

                return $m === 'DELETE' ? self::result($this->deploy->unassign($who, (int) $second)) : self::method();
            case 'software_hosts':
                if ($second === null) {
                    if ($m === 'GET') {
                        return self::result($this->deploy->hosts($who));
                    }
                    if ($m === 'POST') {
                        [$in, $bad] = $this->body($req);

                        return $bad ?? self::result($this->deploy->addHost($who, $in));
                    }

                    return self::method();
                }

                return $m === 'DELETE' && $third === null ? self::result($this->deploy->removeHost($who, rawurldecode($second))) : ($third === null ? self::method() : self::notFound());
            case 'software_report':
                if ($m !== 'GET') {
                    return self::method();
                }
                $f = [];
                foreach (['client_id', 'package_id', 'limit', 'offset'] as $k) {
                    if (isset($req->query[$k]) && $req->query[$k] !== '') {
                        if (!ctype_digit($req->query[$k])) {
                            return self::json(422, ['error' => "$k must be a whole number.", 'code' => 'invalid']);
                        }
                        $f[$k] = (int) $req->query[$k];
                    }
                }
                foreach (['group_by', 'ring', 'state'] as $k) {
                    if (isset($req->query[$k]) && $req->query[$k] !== '') {
                        $f[$k] = $req->query[$k];
                    }
                }
                if ($second === null) {
                    return self::result($this->deploy->report($who, $f));
                }
                if ($second !== 'csv' || $third !== null) {
                    return self::notFound();
                }
                $r = $this->deploy->reportCsv($who, $f);
                if (!$r->ok) {
                    return self::result($r);
                }

                return new RmmResponse(200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="software-deployment.csv"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'], (string) $r->data['csv']);
        }

        return self::notFound();
    }

    private function packages(RmmRequest $req, RmmPrincipal $who, string $m, ?string $id, ?string $sub, ?string $versionId, bool $tooDeep): RmmResponse
    {
        if ($tooDeep) {
            return self::notFound();
        }
        if ($id === null) {
            if ($m === 'GET') {
                return self::result($this->deploy->catalog($who));
            }
            if ($m === 'POST') {
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->deploy->savePackage($who, null, $in));
            }

            return self::method();
        }
        if (!ctype_digit($id)) {
            return self::notFound();
        }
        $n = (int) $id;
        if ($sub === null) {
            if ($m === 'GET') {
                return self::result($this->deploy->package($who, $n));
            }
            if (in_array($m, ['PUT', 'PATCH'], true)) {
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->deploy->savePackage($who, $n, $in));
            }

            return $m === 'DELETE' ? self::result($this->deploy->deletePackage($who, $n)) : self::method();
        }
        if ($sub !== 'versions') {
            return self::notFound();
        }
        if ($versionId === null) {
            if ($m !== 'POST') {
                return self::method();
            }
            [$in, $bad] = $this->body($req);

            return $bad ?? self::result($this->deploy->addVersion($who, $n, $in));
        }
        if (!ctype_digit($versionId)) {
            return self::notFound();
        }
        if ($m !== 'PATCH' && $m !== 'PUT') {
            return self::method();
        }
        [$in, $bad] = $this->body($req);
        if ($bad !== null) {
            return $bad;
        }
        if (array_keys($in) !== ['retired'] || !is_bool($in['retired'])) {
            return self::json(422, ['error' => 'Only {"retired": true|false} can change on a version.', 'code' => 'invalid']);
        }

        return self::result($this->deploy->retireVersion($who, (int) $versionId, $in['retired']));
    }

    /**
     * A route under a device. Returns null for a path this class does not own.
     *
     * @param list<string> $seg the path segments after the resource: [device id, 'deployments', ...]
     */
    public function device(RmmRequest $req, RmmPrincipal $who, int $id, array $seg): ?RmmResponse
    {
        if (($seg[1] ?? '') !== 'deployments') {
            return null;
        }
        $m = strtoupper($req->method);
        $a = $seg[2] ?? null;
        if (isset($seg[3])) {
            return self::notFound();
        }
        $limit = max(1, min(500, (int) ($req->query['limit'] ?? 100)));
        $offset = max(0, (int) ($req->query['offset'] ?? 0));
        switch ($a) {
            case null:
                return $m === 'GET' ? self::result($this->deploy->deviceStatus($who, $id)) : self::method();
            case 'history':
                return $m === 'GET' ? self::result($this->deploy->deviceHistory($who, $id, $limit, $offset)) : self::method();
            case 'detect':
                return $m === 'POST' ? self::result($this->deploy->detect($who, $id)) : self::method();
            case 'run':
                if ($m !== 'POST') {
                    return self::method();
                }
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->deploy->run($who, $id, $in));
        }

        return self::notFound();
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

        return self::json($r->http, $r->data === [] ? ['ok' => true] : $r->data + ($r->code === 'ok' ? [] : ['state' => $r->code]));
    }

    private static function method(): RmmResponse
    {
        return self::json(405, ['error' => 'Method not allowed', 'code' => 'method_not_allowed']);
    }

    private static function notFound(): RmmResponse
    {
        return self::json(404, ['error' => 'Not found', 'code' => 'not_found']);
    }

    /** @param array<string,mixed> $data */
    private static function json(int $status, array $data): RmmResponse
    {
        return new RmmResponse($status, ['Content-Type' => 'application/json'], (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
