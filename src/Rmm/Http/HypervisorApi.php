<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Hypervisor\HypervisorActions;

/**
 * The hypervisor routes of the technician REST API (Proxmox VE and Hyper-V hosts, their VMs and storage). {@see TechnicianApi} hands these to it after its own authentication and
 * module checks; the grants are those of {@see HypervisorActions} (`rmm.hypervisor.view` to read, `rmm.hypervisor.manage` to ignore a VM or host and change thresholds, `rmm.admin` for the
 * switch). Every route is additive; a device, client or VM outside the caller's clients is the same 404 as a missing one, and nothing is changed by a read. The technician API has one
 * resource root, `endpoint_devices`, so the fleet-wide VM search lives at `endpoint_devices/hypervisor/vms`.
 *
 *   GET    endpoint_devices/hypervisor                              hosts with VM counts (q, platform, client_id, problems, limit, offset) plus a summary
 *   GET    endpoint_devices/hypervisor/vms                          VM search across the caller's clients (device_id, client_id, q, state, type, template, ignored, missing, snapshots, backup, limit, offset)
 *   GET    endpoint_devices/hypervisor/vms/{vm_id}                  one VM: facts, the ring of state changes and its open alerts
 *   PUT    endpoint_devices/hypervisor/vms/{vm_id}                  {ignored: bool, reason?}: "ignore this VM"
 *   GET    endpoint_devices/hypervisor/vms/{vm_id}/asset-suggestions  asset hints by name / guest name / MAC (mac=aa:bb:.., guest_name=); nothing is linked
 *   GET    endpoint_devices/hypervisor/settings                     the switch, thresholds, defaults and ranges
 *   PUT    endpoint_devices/hypervisor/settings                     {features?: {hypervisor}, limits?: {hypervisor_*}}
 *   GET    endpoint_devices/{id}/hypervisor                         one host: cluster, storage, a page of its VMs (q, state, type, template, ignored, missing, limit, offset)
 *   PUT    endpoint_devices/{id}/hypervisor                         {ignored: bool}: stop storing what this host reports
 *
 * @api
 */
final class HypervisorApi
{
    /** First path segments that are not a device id and belong here. */
    public const FLEET = ['hypervisor'];

    public function __construct(private readonly HypervisorActions $actions)
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
        if ($second === null) {
            return $m === 'GET' ? self::result($this->actions->hosts($who, self::filters($req, ['q', 'platform', 'client_id', 'problems']), $limit, $offset)) : self::method();
        }
        if ($second === 'settings' && !isset($seg[2])) {
            if ($m === 'GET') {
                return self::result($this->actions->settingsRead($who));
            }
            if (in_array($m, ['PUT', 'PATCH', 'POST'], true)) {
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->actions->updateSettings($who, $in));
            }

            return self::method();
        }
        if ($second !== 'vms') {
            return self::notFound();
        }
        $id = $seg[2] ?? null;
        if ($id === null) {
            return $m === 'GET' ? self::result($this->actions->vms($who, self::filters($req, ['device_id', 'client_id', 'q', 'state', 'type', 'template', 'ignored', 'missing', 'snapshots', 'backup']), $limit, $offset)) : self::method();
        }
        if (!ctype_digit($id) || strlen($id) > 18) {
            return self::notFound();
        }
        $vmId = (int) $id;
        $third = $seg[3] ?? null;
        if ($third === null) {
            if ($m === 'GET') {
                return self::result($this->actions->vm($who, $vmId));
            }
            if (in_array($m, ['PUT', 'PATCH'], true)) {
                [$in, $bad] = $this->body($req);
                if ($bad !== null) {
                    return $bad;
                }
                if (!is_bool($in['ignored'] ?? null) || array_diff(array_keys($in), ['ignored', 'reason']) !== [] || (isset($in['reason']) && !is_string($in['reason']))) {
                    return self::json(422, ['error' => 'Send {"ignored": true|false, "reason": "..."}.', 'code' => 'invalid']);
                }

                return self::result($this->actions->setVmIgnored($who, $vmId, $in['ignored'], (string) ($in['reason'] ?? '')));
            }

            return self::method();
        }
        if ($third === 'asset-suggestions' && !isset($seg[4])) {
            if ($m !== 'GET') {
                return self::method();
            }
            $macs = [];
            foreach (explode(',', (string) ($req->query['mac'] ?? '')) as $mac) {
                $mac = strtolower(str_replace('-', ':', trim($mac)));
                if ($mac !== '') {
                    $macs[] = $mac;
                }
            }
            $guest = isset($req->query['guest_name']) && is_string($req->query['guest_name']) ? $req->query['guest_name'] : null;

            return self::result($this->actions->suggestAssets($who, $vmId, array_slice($macs, 0, 16), $guest));
        }

        return self::notFound();
    }

    /**
     * A route under a device. Returns null for a path this class does not own.
     *
     * @param list<string> $seg [device id, 'hypervisor', ...]
     */
    public function device(RmmRequest $req, RmmPrincipal $who, int $id, array $seg): ?RmmResponse
    {
        if (($seg[1] ?? '') !== 'hypervisor') {
            return null;
        }
        if (isset($seg[2])) {
            return self::notFound();
        }
        $m = strtoupper($req->method);
        if ($m === 'GET') {
            $limit = max(1, min(500, (int) ($req->query['limit'] ?? 100)));
            $offset = max(0, (int) ($req->query['offset'] ?? 0));

            return self::result($this->actions->host($who, $id, self::filters($req, ['q', 'state', 'type', 'template', 'ignored', 'missing']), $limit, $offset));
        }
        if (in_array($m, ['PUT', 'PATCH'], true)) {
            [$in, $bad] = $this->body($req);
            if ($bad !== null) {
                return $bad;
            }
            if (!is_bool($in['ignored'] ?? null) || array_diff(array_keys($in), ['ignored']) !== []) {
                return self::json(422, ['error' => 'Send {"ignored": true|false}.', 'code' => 'invalid']);
            }

            return self::result($this->actions->setHostIgnored($who, $id, $in['ignored']));
        }

        return self::method();
    }

    /**
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private static function filters(RmmRequest $req, array $keys): array
    {
        $f = [];
        foreach ($keys as $k) {
            if (isset($req->query[$k]) && is_string($req->query[$k]) && $req->query[$k] !== '') {
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

    private static function result(\RivetCore\Rmm\Technician\ActionResult $r): RmmResponse
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
