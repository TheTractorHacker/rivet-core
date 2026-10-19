<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Change\ChangeActions;

/**
 * The change-monitoring routes of the technician REST API. {@see TechnicianApi} hands these to it after its own authentication and module checks; the grants are those of
 * {@see ChangeActions} (`rmm.change.view` to read, `rmm.change.manage` for presets, custom watches and rules, `rmm.admin` for the switch and the caps). Every route is additive; a
 * device or client outside the caller's clients is the same 404 as a missing one, and nothing is changed by a read.
 *
 *   GET    endpoint_devices/changes                                   search across the caller's clients (device_id, client_id, from, to, kind, watch, change, target, item, actor, q, limit, offset; at most 30 days)
 *   GET    endpoint_devices/changes/stats                             fleet storage figures and the last day's changes by watch
 *   GET    endpoint_devices/changes/settings                          the preset catalog with what is on, custom watches, limits, the storage text and the worst-case sizes
 *   PUT    endpoint_devices/changes/settings                          {features?: {change_monitor}, presets?: {windows: [id], linux: [id]}, custom?: [watch], limits?, accept_change_storage?}
 *   GET    endpoint_devices/changes/rules | POST changes/rules | GET,PATCH,PUT,DELETE changes/rules/{id}
 *   POST   endpoint_devices/changes/rules/recommended                 {client_id?}: create the recommended alert rules that do not exist yet
 *   GET    endpoint_devices/{id}/changes                              search one device (up to 365 days)
 *   GET    endpoint_devices/{id}/changes/timeline                     who changed what: the device's changes and software inventory changes, with the actors and a per-day count (from, to, limit)
 *   GET    endpoint_devices/{id}/changes/status                       what the device is told to watch and what its agent last reported about it
 *
 * @api
 */
final class ChangeApi
{
    /** First path segments that are not a device id and belong here. */
    public const FLEET = ['changes'];

    public function __construct(private readonly ChangeActions $changes)
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
            return $m === 'GET' ? self::result($this->changes->fleetChanges($who, self::filters($req), $limit, $offset)) : self::method();
        }
        if (isset($seg[3]) && $second !== 'rules') {
            return self::notFound();
        }
        if ($second === 'stats' && !isset($seg[2])) {
            return $m === 'GET' ? self::result($this->changes->stats($who)) : self::method();
        }
        if ($second === 'settings' && !isset($seg[2])) {
            if ($m === 'GET') {
                return self::result($this->changes->settingsRead($who));
            }
            if (in_array($m, ['PUT', 'PATCH', 'POST'], true)) {
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->changes->updateSettings($who, $in));
            }

            return self::method();
        }
        if ($second !== 'rules') {
            return self::notFound();
        }
        $rule = $seg[2] ?? null;
        if ($rule === null) {
            if ($m === 'GET') {
                return self::result($this->changes->listRules($who));
            }
            if ($m === 'POST') {
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->changes->createRule($who, $in));
            }

            return self::method();
        }
        if ($rule === 'recommended' && !isset($seg[3])) {
            if ($m !== 'POST') {
                return self::method();
            }
            [$in, $bad] = $this->body($req);

            return $bad ?? self::result($this->changes->installRecommendedRules($who, $in));
        }
        if (!ctype_digit($rule) || isset($seg[3])) {
            return self::notFound();
        }
        $id = (int) $rule;
        if ($m === 'GET') {
            return self::result($this->changes->getRule($who, $id));
        }
        if (in_array($m, ['PATCH', 'PUT'], true)) {
            [$in, $bad] = $this->body($req);

            return $bad ?? self::result($this->changes->updateRule($who, $id, $in));
        }
        if ($m === 'DELETE') {
            return self::result($this->changes->deleteRule($who, $id));
        }

        return self::method();
    }

    /**
     * A route under a device. Returns null for a path this class does not own.
     *
     * @param list<string> $seg [device id, 'changes', ...]
     */
    public function device(RmmRequest $req, RmmPrincipal $who, int $id, array $seg): ?RmmResponse
    {
        if (($seg[1] ?? '') !== 'changes') {
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
                return $m === 'GET' ? self::result($this->changes->deviceChanges($who, $id, self::filters($req), $limit, $offset)) : self::method();
            case 'timeline':
                return $m === 'GET' ? self::result($this->changes->timeline($who, $id, array_intersect_key(self::filters($req), ['from' => 1, 'to' => 1]), $limit)) : self::method();
            case 'status':
                return $m === 'GET' ? self::result($this->changes->deviceStatus($who, $id)) : self::method();
        }

        return self::notFound();
    }

    /** @return array<string,mixed> */
    private static function filters(RmmRequest $req): array
    {
        $f = [];
        foreach (['device_id', 'client_id', 'from', 'to', 'kind', 'watch', 'change', 'target', 'item', 'actor', 'q'] as $k) {
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
