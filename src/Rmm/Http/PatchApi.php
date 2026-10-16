<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Patch\PatchActions;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * The patch-management routes of the technician REST API (Phase 4). {@see TechnicianApi} hands these to it after its own authentication and module checks; the
 * grants are those of {@see PatchActions} (`rmm.patch.view`, `rmm.patch.manage`, `rmm.patch.install`). Every route is additive; a device or client outside the caller's
 * clients is the same 404 as a missing one. Nothing is installed by a read, and an install is a dry run unless it says `mode: "install"` and `confirm: true`.
 *
 * On one device:
 *   GET    endpoint_devices/{id}/patches                      the patch summary (last scan, counts, effective policy) and the device's updates (status = available|approved|declined|installed|failed|missing, kind, q, limit, offset)
 *   GET    endpoint_devices/{id}/patches/history              install and dry-run history (limit, offset)
 *   POST   endpoint_devices/{id}/patches/scan                 ask for a scan (read only) -> 202 {job_id} | 200 {skipped}
 *   POST   endpoint_devices/{id}/patches/install              {ids?, mode?: dry_run (default) | install, confirm?, reboot_policy?, confirm_reboot?, max_minutes?} -> 202 {job_id, queued, remaining}
 * Across the fleet:
 *   GET    endpoint_devices/patches                           one row per missing update id across the caller's devices (status, client_id, limit, offset)
 *   GET    endpoint_devices/patches/compliance                compliance figures (client_id, site_id, group_id, group_by = client|site|group, overdue_days, os, limit, offset)
 *   POST   endpoint_devices/patches/approve                   {update_ids, device_id?, client_id?} -> 200 {matched, changed} | 202 {approval_id} when a policy asks for a second person
 *   POST   endpoint_devices/patches/decline                   {update_ids, device_id?, client_id?, reason?}
 *   GET    endpoint_devices/patches/rules | POST patches/rules | PUT,PATCH,DELETE patches/rules/{id}
 *   GET    endpoint_devices/patches/rings | POST patches/rings | PUT,PATCH,DELETE patches/rings/{id}
 *   GET    endpoint_devices/patch_policies | POST patch_policies | GET,PUT,PATCH,DELETE patch_policies/{id} | POST patch_policies/{id}/assignments | DELETE patch_policies/{id}/assignments/{assignment_id}
 *
 * @api
 */
final class PatchApi
{
    /** First path segments that are not a device id and belong here. */
    public const FLEET = ['patches', 'patch_policies'];

    public function __construct(private readonly PatchActions $patches)
    {
    }

    /**
     * @param list<string> $seg
     */
    public function fleet(RmmRequest $req, RmmPrincipal $who, array $seg): RmmResponse
    {
        $m = strtoupper($req->method);
        $limit = max(1, min(500, (int) ($req->query['limit'] ?? 100)));
        $offset = max(0, (int) ($req->query['offset'] ?? 0));
        $second = $seg[1] ?? null;
        $third = $seg[2] ?? null;
        if ($seg[0] === 'patch_policies') {
            return $this->policies($req, $who, $m, $second, $third, $seg[3] ?? null);
        }
        if ($second === null) {
            if ($m !== 'GET') {
                return self::method();
            }
            $client = isset($req->query['client_id']) && ctype_digit($req->query['client_id']) ? (int) $req->query['client_id'] : null;

            return self::result($this->patches->fleetUpdates($who, (string) ($req->query['status'] ?? 'available'), $client, $limit, $offset));
        }
        switch ($second) {
            case 'compliance':
                if ($third !== null) {
                    return self::notFound();
                }
                if ($m !== 'GET') {
                    return self::method();
                }
                $f = ['limit' => $limit, 'offset' => $offset];
                foreach (['client_id', 'site_id', 'group_id', 'overdue_days'] as $k) {
                    if (isset($req->query[$k]) && $req->query[$k] !== '') {
                        if (!ctype_digit($req->query[$k])) {
                            return self::json(422, ['error' => "$k must be a whole number.", 'code' => 'invalid']);
                        }
                        $f[$k] = (int) $req->query[$k];
                    }
                }
                foreach (['group_by', 'os'] as $k) {
                    if (isset($req->query[$k])) {
                        $f[$k] = $req->query[$k];
                    }
                }

                return self::result($this->patches->compliance($who, $f));
            case 'approve':
            case 'decline':
                if ($third !== null) {
                    return self::notFound();
                }
                if ($m !== 'POST') {
                    return self::method();
                }
                [$in, $bad] = $this->body($req);
                if ($bad !== null) {
                    return $bad;
                }

                return self::result($second === 'approve' ? $this->patches->approve($who, $in) : $this->patches->decline($who, $in));
            case 'rules':
            case 'rings':
                return $this->rulesOrRings($req, $who, $m, $second, $third, $seg[3] ?? null);
        }

        return self::notFound();
    }

    private function rulesOrRings(RmmRequest $req, RmmPrincipal $who, string $m, string $kind, ?string $id, ?string $extra): RmmResponse
    {
        if ($extra !== null) {
            return self::notFound();
        }
        if ($id === null) {
            if ($m === 'GET') {
                $r = $this->patches->listPolicies($who);
                if (!$r->ok) {
                    return self::result($r);
                }

                return self::json(200, [$kind => $r->data[$kind] ?? []]);
            }
            if ($m === 'POST') {
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($kind === 'rules' ? $this->patches->saveRule($who, null, $in) : $this->patches->saveRing($who, null, $in));
            }

            return self::method();
        }
        if (!ctype_digit($id)) {
            return self::notFound();
        }
        $n = (int) $id;
        if (in_array($m, ['PUT', 'PATCH'], true)) {
            [$in, $bad] = $this->body($req);

            return $bad ?? self::result($kind === 'rules' ? $this->patches->saveRule($who, $n, $in) : $this->patches->saveRing($who, $n, $in));
        }
        if ($m === 'DELETE') {
            return self::result($kind === 'rules' ? $this->patches->deleteRule($who, $n) : $this->patches->deleteRing($who, $n));
        }

        return self::method();
    }

    private function policies(RmmRequest $req, RmmPrincipal $who, string $m, ?string $id, ?string $sub, ?string $sub2): RmmResponse
    {
        if ($id === null) {
            if ($m === 'GET') {
                $r = $this->patches->listPolicies($who);

                return $r->ok ? self::json(200, ['data' => $r->data['policies'] ?? [], 'rings' => $r->data['rings'] ?? [], 'settings' => $r->data['settings'] ?? []]) : self::result($r);
            }
            if ($m === 'POST') {
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->patches->savePolicy($who, null, $in));
            }

            return self::method();
        }
        if (!ctype_digit($id)) {
            return self::notFound();
        }
        $n = (int) $id;
        if ($sub === null) {
            if ($m === 'GET') {
                return self::result($this->patches->getPolicy($who, $n));
            }
            if (in_array($m, ['PUT', 'PATCH'], true)) {
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->patches->savePolicy($who, $n, $in));
            }
            if ($m === 'DELETE') {
                return self::result($this->patches->deletePolicy($who, $n));
            }

            return self::method();
        }
        if ($sub !== 'assignments') {
            return self::notFound();
        }
        if ($sub2 === null) {
            if ($m !== 'POST') {
                return self::method();
            }
            [$in, $bad] = $this->body($req);

            return $bad ?? self::result($this->patches->assignPolicy($who, $n, $in));
        }
        if (!ctype_digit($sub2)) {
            return self::notFound();
        }

        return $m === 'DELETE' ? self::result($this->patches->unassignPolicy($who, $n, (int) $sub2)) : self::method();
    }

    /**
     * A route under a device. Returns null for a path this class does not own.
     *
     * @param list<string> $seg the path segments after the resource: [device id, 'patches', ...]
     */
    public function device(RmmRequest $req, RmmPrincipal $who, int $id, array $seg): ?RmmResponse
    {
        if (($seg[1] ?? '') !== 'patches') {
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
                if ($m !== 'GET') {
                    return self::method();
                }
                $f = [];
                foreach (['status', 'kind', 'q'] as $k) {
                    if (isset($req->query[$k]) && $req->query[$k] !== '') {
                        $f[$k] = $req->query[$k];
                    }
                }

                return self::result($this->patches->updates($who, $id, $f, $limit, $offset));
            case 'history':
                return $m === 'GET' ? self::result($this->patches->history($who, $id, $limit, $offset)) : self::method();
            case 'scan':
                return $m === 'POST' ? self::result($this->patches->scan($who, $id)) : self::method();
            case 'install':
                if ($m !== 'POST') {
                    return self::method();
                }
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->patches->install($who, $id, $in));
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
