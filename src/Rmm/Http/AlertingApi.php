<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Alerting\AlertingActions;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * The alerting routes of the technician REST API (Phase 3). {@see TechnicianApi} hands these to it after its own authentication and module
 * checks; the grants are those of {@see AlertingActions}. Every route is additive.
 *
 *   GET    endpoint_devices/alerts                           list (state: open|acknowledged|resolved|active, severity: warn|crit, device_id, client_id, check_key, group_key, limit, offset)
 *   GET    endpoint_devices/alerts/groups                    active alerts grouped by client and check
 *   GET    endpoint_devices/alerts/{alert_id}                one alert
 *   POST   endpoint_devices/alerts/{alert_id}/ack            acknowledge (stops its escalation)
 *   POST   endpoint_devices/alerts/{alert_id}/resolve        resolve by hand
 *   GET    endpoint_devices/maintenance                      windows (scope_type, scope_id, active=1)
 *   POST   endpoint_devices/maintenance                      create
 *   GET,PATCH,PUT,DELETE endpoint_devices/maintenance/{id}
 *   GET    endpoint_devices/escalation_policies              policies with their steps
 *   POST   endpoint_devices/escalation_policies              create
 *   GET,PATCH,PUT,DELETE endpoint_devices/escalation_policies/{id}
 *   GET,PUT,PATCH endpoint_devices/alerting/settings         the `alerting` switch state and the storm-control limits
 *   GET    endpoint_devices/check_types                      the check type catalog (platforms, params, whether it reports a value)
 *   GET    endpoint_devices/{id}/alerts                      alerts of one device
 *   GET    endpoint_devices/{id}/maintenance                 windows open for the device now
 *   GET    endpoint_devices/{id}/alerting                    why a bad check has no alert: windows, dependency, tiers, flap state
 *   GET,PUT,DELETE endpoint_devices/{id}/parent              the device's parent ({parent_device_id}) and children
 *   GET,PUT,DELETE endpoint_devices/{id}/checks/{key}/thresholds   definition, device override, effective thresholds and live tier
 *
 * @api
 */
final class AlertingApi
{
    public const FLEET = ['alerts', 'maintenance', 'escalation_policies', 'alerting', 'check_types'];

    public function __construct(private readonly AlertingActions $actions)
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
        $a = $this->actions;
        $kind = $seg[0];
        $id = isset($seg[1]) && ctype_digit($seg[1]) ? (int) $seg[1] : null;
        $sub = $seg[2] ?? null;
        $second = $seg[1] ?? null;
        $limit = max(1, min(500, (int) ($req->query['limit'] ?? 100)));
        $offset = max(0, (int) ($req->query['offset'] ?? 0));

        switch ($kind) {
            case 'check_types':
                return $m === 'GET' && !isset($seg[1]) ? self::result($a->checkTypes($who)) : self::notFound();
            case 'alerting':
                if ($second !== 'settings' || isset($seg[2])) {
                    return self::notFound();
                }
                if ($m === 'GET') {
                    return self::result($a->settings($who));
                }
                if (in_array($m, ['PUT', 'PATCH', 'POST'], true)) {
                    [$in, $bad] = $this->body($req);

                    return $bad ?? self::result($a->updateSettings($who, $in));
                }

                return self::method();
            case 'alerts':
                if ($m === 'GET' && !isset($seg[1])) {
                    return self::result($a->listAlerts($who, $req->query, $limit, $offset));
                }
                if ($m === 'GET' && $second === 'groups' && !isset($seg[2])) {
                    return self::result($a->alertGroups($who));
                }
                if ($id === null) {
                    return self::notFound();
                }
                if ($sub === null) {
                    return $m === 'GET' ? self::result($a->getAlert($who, $id)) : self::method();
                }
                if ($m === 'POST' && $sub === 'ack' && !isset($seg[3])) {
                    return self::result($a->acknowledge($who, $id));
                }
                if ($m === 'POST' && $sub === 'resolve' && !isset($seg[3])) {
                    return self::result($a->resolve($who, $id));
                }

                return self::notFound();
            case 'maintenance':
                if (!isset($seg[1])) {
                    if ($m === 'GET') {
                        return self::result($a->listWindows($who, $req->query));
                    }
                    if ($m === 'POST') {
                        [$in, $bad] = $this->body($req);

                        return $bad ?? self::result($a->createWindow($who, $in));
                    }

                    return self::method();
                }
                if ($id === null || isset($seg[2])) {
                    return self::notFound();
                }
                if ($m === 'GET') {
                    return self::result($a->getWindow($who, $id));
                }
                if (in_array($m, ['PATCH', 'PUT'], true)) {
                    [$in, $bad] = $this->body($req);

                    return $bad ?? self::result($a->updateWindow($who, $id, $in));
                }
                if ($m === 'DELETE') {
                    return self::result($a->deleteWindow($who, $id));
                }

                return self::method();
            case 'escalation_policies':
                if (!isset($seg[1])) {
                    if ($m === 'GET') {
                        return self::result($a->listPolicies($who));
                    }
                    if ($m === 'POST') {
                        [$in, $bad] = $this->body($req);

                        return $bad ?? self::result($a->createPolicy($who, $in));
                    }

                    return self::method();
                }
                if ($id === null || isset($seg[2])) {
                    return self::notFound();
                }
                if ($m === 'GET') {
                    return self::result($a->getPolicy($who, $id));
                }
                if (in_array($m, ['PATCH', 'PUT'], true)) {
                    [$in, $bad] = $this->body($req);

                    return $bad ?? self::result($a->updatePolicy($who, $id, $in));
                }
                if ($m === 'DELETE') {
                    return self::result($a->deletePolicy($who, $id));
                }

                return self::method();
        }

        return self::notFound();
    }

    /**
     * A route under one device: `alerts`, `maintenance`, `parent` and `checks/{key}/thresholds`. Returns null when the path is none of them.
     *
     * @param list<string> $seg the whole path (seg[0] is the device id)
     */
    public function device(RmmRequest $req, RmmPrincipal $who, int $deviceId, array $seg): ?RmmResponse
    {
        $m = strtoupper($req->method);
        $a = $this->actions;
        $what = $seg[1] ?? null;
        if ($what === 'alerts' && !isset($seg[2])) {
            return $m === 'GET' ? self::result($a->listAlerts($who, ['device_id' => $deviceId] + $req->query, max(1, min(500, (int) ($req->query['limit'] ?? 100))), max(0, (int) ($req->query['offset'] ?? 0)))) : self::method();
        }
        if ($what === 'alerting' && !isset($seg[2])) {
            return $m === 'GET' ? self::result($a->deviceAlerting($who, $deviceId)) : self::method();
        }
        if ($what === 'maintenance' && !isset($seg[2])) {
            return $m === 'GET' ? self::result($a->deviceMaintenance($who, $deviceId)) : self::method();
        }
        if ($what === 'parent' && !isset($seg[2])) {
            if ($m === 'GET') {
                return self::result($a->getParent($who, $deviceId));
            }
            if ($m === 'PUT' || $m === 'POST') {
                [$in, $bad] = $this->body($req);
                $p = $in['parent_device_id'] ?? null;

                return $bad ?? (is_int($p) && $p > 0 ? self::result($a->setParent($who, $deviceId, $p)) : self::json(422, ['error' => 'parent_device_id is required.', 'code' => 'invalid']));
            }
            if ($m === 'DELETE') {
                return self::result($a->clearParent($who, $deviceId));
            }

            return self::method();
        }
        if ($what === 'checks' && isset($seg[2], $seg[3]) && $seg[3] === 'thresholds' && !isset($seg[4])) {
            if ($m === 'GET') {
                return self::result($a->getThresholds($who, $deviceId, $seg[2]));
            }
            if ($m === 'PUT' || $m === 'PATCH') {
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($a->setThresholds($who, $deviceId, $seg[2], $in));
            }
            if ($m === 'DELETE') {
                return self::result($a->setThresholds($who, $deviceId, $seg[2], []));
            }

            return self::method();
        }

        return null;
    }

    // ------------------------------------------------------------------ helpers

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
        if (!is_array($body)) {
            return [[], self::json(400, ['error' => 'Request body must be a JSON object'])];
        }

        /** @var array<string,mixed> $body */
        return [$body, null];
    }

    private static function result(ActionResult $r): RmmResponse
    {
        if (!$r->ok) {
            return self::json($r->http, ['error' => $r->message, 'code' => $r->code]);
        }
        $data = $r->data;

        return self::json($r->http, $data === [] ? ['ok' => true] : $data);
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
