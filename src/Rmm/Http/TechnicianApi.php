<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Read\RmmReadModel;
use RivetCore\Rmm\Technician\ActionResult;
use RivetCore\Rmm\Technician\InventoryActions;
use RivetCore\Rmm\Technician\TechnicianActions;

/**
 * The technician REST API of the endpoint agent (`/api/v1/endpoint_devices`), framework-neutral. The edition authenticates the caller
 * (a user API token; the legacy shared key must be refused BEFORE this is called) and passes the {@see RmmPrincipal}; everything else,
 * including every authorization decision, is made here on every call by {@see RmmAuthorizer}.
 *
 *   GET  endpoint_devices                      list devices in the caller's clients (filters: status, limit, offset)
 *   GET  endpoint_devices/{id}                 device detail, checks, recent jobs
 *   GET  endpoint_devices/{id}/jobs            job history (output only with the run-saved grant)
 *   POST endpoint_devices/{id}/jobs            {type: powershell|reboot|collect, script|script_id, params, timeout_s, destructive, confirm}
 *   POST endpoint_devices/{id}/jobs/{job}/cancel
 *   POST endpoint_devices/{id}/remote          {force?} -> {url, session_id}
 *
 * Phase 1 additions (all additive; the routes above are unchanged, and the list also accepts the filters tag, group, software and
 * location_id):
 *   GET    endpoint_devices/{id}/software                     installed software (q, limit, offset, include_removed=1) + the report state
 *   GET    endpoint_devices/{id}/software/history             install / upgrade / downgrade / removal log (name, limit, offset)
 *   POST   endpoint_devices/{id}/software/refresh             ask for a full list at the next check-in (202)
 *   GET    endpoint_devices/{id}/tags                         the device's tags and groups
 *   POST   endpoint_devices/{id}/tags                         {tag: name|id} (manage)
 *   DELETE endpoint_devices/{id}/tags/{tag_id}                (manage)
 *   GET    endpoint_devices/{id}/checks/{key}/history         per-check trend (hours)
 *   GET    endpoint_devices/{id}/live                         the polling document (ETag / If-None-Match, 304 when unchanged)
 *   GET    endpoint_devices/{id}/network                      current network rate against the peak of the last hours (hours)
 *   GET    endpoint_devices/tags | POST tags | PATCH,DELETE tags/{id}
 *   GET    endpoint_devices/groups | POST groups | GET,PATCH,DELETE groups/{id} | POST groups/{id}/devices | DELETE groups/{id}/devices/{device_id}
 *          | PUT groups/{id}/tags
 *   GET    endpoint_devices/software (q, limit, offset)       software across the fleet, with device and version counts
 *   GET    endpoint_devices/software/outdated                 name, min_version: devices running an older version
 *
 * Responses are `{"error": "...", "code": "..."}` for errors, with the status codes of {@see ActionResult}. A device outside the caller's
 * clients is the same 404 as a missing one; a role that cannot view devices at all is a 403. A response carries no CORS headers and no
 * cache headers: those are the edition front controller's (the transcripts pin this).
 *
 * @api
 */
final class TechnicianApi
{
    /** Largest request body (a script is at most 100 KiB; JSON escaping can inflate it). */
    public const MAX_BODY = 1048576;

    public function __construct(
        private readonly RmmAuthorizer $authz,
        private readonly TechnicianActions $actions,
        private readonly RmmReadModel $read,
        private readonly ?InventoryActions $inventory = null,
    ) {
    }

    public function handle(RmmRequest $req, ?RmmPrincipal $who): RmmResponse
    {
        try {
            return $this->route($req, $who);
        } catch (\Throwable $e) {
            error_log('endpoint agent technician api: ' . get_class($e) . ': ' . $e->getMessage());

            return self::json(500, ['error' => 'Internal error.', 'code' => 'internal']);
        }
    }

    private function route(RmmRequest $req, ?RmmPrincipal $who): RmmResponse
    {
        if ($who === null || $who->userId <= 0) {
            return self::json(401, ['error' => 'Unauthorized']);
        }
        if (!$this->authz->moduleEnabled()) {
            return self::json(404, ['error' => 'The endpoint agent is not enabled.', 'code' => 'disabled']);
        }
        $uid = $who->userId;
        $method = strtoupper($req->method);
        $seg = $req->pathSegments;
        $id = isset($seg[0]) && ctype_digit($seg[0]) ? (int) $seg[0] : null;
        $what = $seg[1] ?? null;
        $job = $seg[2] ?? null;
        $op = $seg[3] ?? null;

        if ($id === null) {
            if ($seg !== [] && $this->inventory !== null && in_array($seg[0], ['tags', 'groups', 'software'], true)) {
                return $this->fleetRoute($req, $who, $method, $seg);
            }
            if ($method !== 'GET' || $seg !== []) {
                return self::notFound();
            }
            $denied = $this->authz->check($uid, RmmAbility::DEVICE_VIEW, 0);
            if ($denied !== null) {
                return self::json(403, ['error' => $denied, 'code' => 'forbidden']);
            }
            $limit = max(1, min(200, (int) ($req->query['limit'] ?? 50)));
            $offset = max(0, (int) ($req->query['offset'] ?? 0));
            $filters = isset($req->query['status']) && $req->query['status'] !== '' ? ['status' => $req->query['status']] : [];
            foreach (['tag', 'group', 'software', 'location_id'] as $f) {
                if (isset($req->query[$f]) && $req->query[$f] !== '') {
                    $filters[$f] = in_array($f, ['group', 'location_id'], true) ? (int) $req->query[$f] : $req->query[$f];
                }
            }
            $list = $this->read->listDevices($filters, $this->authz->visibleClientIds($uid), $limit, $offset, false);

            return self::json(200, ['data' => $list['items'], 'total' => $list['total']]);
        }

        $denied = $this->authz->check($uid, RmmAbility::DEVICE_VIEW, 0);
        if ($denied !== null) {
            return self::json(403, ['error' => $denied, 'code' => 'forbidden']);
        }
        $dev = $this->actions->visibleDevice($uid, $id);
        if ($dev === null) {
            return self::json(404, ['error' => 'Device not found.', 'code' => 'not_found']);
        }
        $canSeeOutput = $this->authz->allowed($uid, RmmAbility::JOB_RUN_SAVED, (int) $dev['client_id']);

        if ($what === null) {
            if ($method !== 'GET') {
                return self::json(405, ['error' => 'Method not allowed', 'code' => 'method_not_allowed']);
            }

            return self::json(200, $this->read->detail($dev, $canSeeOutput));
        }

        if ($what === 'jobs') {
            if ($job === null && $method === 'GET') {
                return self::json(200, ['data' => $this->read->jobs($id, (int) ($req->query['limit'] ?? 50), $canSeeOutput)]);
            }
            if ($job === null && $method === 'POST') {
                [$in, $bad] = $this->jsonBody($req);
                if ($bad !== null) {
                    return $bad;
                }
                $r = $this->actions->submitJob($who, $id, $in);

                return $r->ok ? self::json(201, ['job_id' => $r->data['job_id'] ?? null, 'state' => 'queued']) : self::failure($r);
            }
            if ($job !== null && $op === 'cancel' && $method === 'POST') {
                $r = $this->actions->cancelJob($who, $id, $job);

                return $r->ok ? self::json(200, ['ok' => true]) : self::failure($r);
            }

            return self::notFound();
        }

        if ($what === 'remote' && $method === 'POST') {
            [$in, $bad] = $this->jsonBody($req);
            if ($bad !== null) {
                return $bad;
            }
            $r = $this->actions->launchRemote($who, $id, !empty($in['force']), $req->clientIp, $req->userAgent);

            return $r->ok ? self::json(200, ['url' => $r->data['url'] ?? null, 'session_id' => $r->data['session_id'] ?? null]) : self::failure($r);
        }

        if ($this->inventory !== null) {
            switch ($what) {
                case 'software':
                    return $this->deviceSoftware($req, $who, $method, $id, $job);
                case 'tags':
                    return $this->deviceTags($req, $who, $method, $id, $job);
                case 'checks':
                    if ($method === 'GET' && $job !== null && $op === 'history' && !isset($seg[4])) {
                        return self::json(200, $this->read->checkHistory($id, $job, (int) ($req->query['hours'] ?? 24)));
                    }
                    break;
                case 'live':
                    if ($method === 'GET' && $job === null) {
                        $live = $this->read->deviceLive($dev, $req->header('if-none-match'));
                        $headers = ['Content-Type' => 'application/json', 'ETag' => $live['etag']];

                        return $live['body'] === null ? new RmmResponse(304, ['ETag' => $live['etag']], null)
                            : new RmmResponse(200, $headers, (string) json_encode($live['body'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION));
                    }
                    break;
                case 'network':
                    if ($method === 'GET' && $job === null) {
                        return self::json(200, $this->read->networkPeak($id, (int) ($req->query['hours'] ?? 24)));
                    }
                    break;
            }
        }

        return self::notFound();
    }

    /** @param list<string> $seg */
    private function fleetRoute(RmmRequest $req, RmmPrincipal $who, string $method, array $seg): RmmResponse
    {
        $uid = $who->userId;
        $inv = $this->inventory;
        if ($inv === null) {
            return self::notFound();
        }
        $denied = $this->authz->check($uid, RmmAbility::DEVICE_VIEW, 0);
        if ($denied !== null) {
            return self::json(403, ['error' => $denied, 'code' => 'forbidden']);
        }
        $limit = max(1, min(500, (int) ($req->query['limit'] ?? 100)));
        $offset = max(0, (int) ($req->query['offset'] ?? 0));
        $kind = $seg[0];
        $rid = isset($seg[1]) && ctype_digit($seg[1]) ? (int) $seg[1] : null;
        $sub = $seg[2] ?? null;

        if ($kind === 'software') {
            if ($method !== 'GET') {
                return self::json(405, ['error' => 'Method not allowed', 'code' => 'method_not_allowed']);
            }
            $visible = $this->authz->visibleClientIds($uid);
            if (($seg[1] ?? null) === 'outdated' && !isset($seg[2])) {
                $name = (string) ($req->query['name'] ?? '');
                $min = (string) ($req->query['min_version'] ?? '');
                if (trim($name) === '' || trim($min) === '') {
                    return self::json(422, ['error' => 'name and min_version are required.', 'code' => 'invalid']);
                }

                return self::json(200, ['data' => $this->read->outdatedSoftware($name, $min, $visible, $limit)]);
            }
            if (isset($seg[1])) {
                return self::notFound();
            }
            $c = $this->read->softwareCatalog(isset($req->query['q']) ? (string) $req->query['q'] : null, $visible, $limit, $offset);

            return self::json(200, ['data' => $c['items'], 'total' => $c['total']]);
        }

        if ($kind === 'tags') {
            if ($rid === null && !isset($seg[1])) {
                if ($method === 'GET') {
                    return self::json(200, ['data' => $this->read->tags($this->authz->visibleClientIds($uid))]);
                }
                if ($method === 'POST') {
                    [$in, $bad] = $this->jsonBody($req);

                    return $bad ?? self::action($inv->createTag($who, $in), fn (ActionResult $r): array => ['tag' => $r->data['tag'] ?? null]);
                }

                return self::json(405, ['error' => 'Method not allowed', 'code' => 'method_not_allowed']);
            }
            if ($rid !== null && $sub === null) {
                if ($method === 'PATCH' || $method === 'PUT') {
                    [$in, $bad] = $this->jsonBody($req);

                    return $bad ?? self::action($inv->updateTag($who, $rid, $in), fn (ActionResult $r): array => ['tag' => $r->data['tag'] ?? null]);
                }
                if ($method === 'DELETE') {
                    return self::action($inv->deleteTag($who, $rid), static fn (ActionResult $r): array => ['ok' => true]);
                }

                return self::json(405, ['error' => 'Method not allowed', 'code' => 'method_not_allowed']);
            }

            return self::notFound();
        }

        // groups
        if (!isset($seg[1])) {
            if ($method === 'GET') {
                return self::json(200, ['data' => $this->read->groups($this->authz->visibleClientIds($uid))]);
            }
            if ($method === 'POST') {
                [$in, $bad] = $this->jsonBody($req);

                return $bad ?? self::action($inv->createGroup($who, $in), fn (ActionResult $r): array => ['group' => $r->data['group'] ?? null]);
            }

            return self::json(405, ['error' => 'Method not allowed', 'code' => 'method_not_allowed']);
        }
        if ($rid === null) {
            return self::notFound();
        }
        if ($sub === null) {
            if ($method === 'GET') {
                $g = null;
                foreach ($this->read->groups($this->authz->visibleClientIds($uid)) as $row) {
                    if ($row['group_id'] === $rid) {
                        $g = $row;
                    }
                }
                if ($g === null) {
                    return self::json(404, ['error' => 'Group not found.', 'code' => 'not_found']);
                }
                $list = $this->read->listDevices(['group' => $rid], $this->authz->visibleClientIds($uid), min(200, $limit), $offset, false);

                return self::json(200, ['group' => $g, 'devices' => $list['items'], 'total' => $list['total']]);
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                [$in, $bad] = $this->jsonBody($req);

                return $bad ?? self::action($inv->updateGroup($who, $rid, $in), fn (ActionResult $r): array => ['group' => $r->data['group'] ?? null]);
            }
            if ($method === 'DELETE') {
                return self::action($inv->deleteGroup($who, $rid), static fn (ActionResult $r): array => ['ok' => true]);
            }

            return self::json(405, ['error' => 'Method not allowed', 'code' => 'method_not_allowed']);
        }
        if ($sub === 'devices' && !isset($seg[3]) && $method === 'POST') {
            [$in, $bad] = $this->jsonBody($req);
            if ($bad !== null) {
                return $bad;
            }

            return self::action($inv->addGroupDevices($who, $rid, is_array($in['device_ids'] ?? null) ? array_values($in['device_ids']) : []), static fn (ActionResult $r): array => ['added' => $r->data['added'] ?? 0]);
        }
        if ($sub === 'devices' && isset($seg[3]) && ctype_digit($seg[3]) && !isset($seg[4]) && $method === 'DELETE') {
            return self::action($inv->removeGroupDevice($who, $rid, (int) $seg[3]), static fn (ActionResult $r): array => ['ok' => true]);
        }
        if ($sub === 'tags' && !isset($seg[3]) && ($method === 'PUT' || $method === 'POST')) {
            [$in, $bad] = $this->jsonBody($req);
            if ($bad !== null) {
                return $bad;
            }

            return self::action($inv->setGroupTags($who, $rid, is_array($in['tag_ids'] ?? null) ? array_values($in['tag_ids']) : []), static fn (ActionResult $r): array => ['ok' => true]);
        }

        return self::notFound();
    }

    private function deviceSoftware(RmmRequest $req, RmmPrincipal $who, string $method, int $id, ?string $sub): RmmResponse
    {
        $limit = max(1, min(500, (int) ($req->query['limit'] ?? 100)));
        $offset = max(0, (int) ($req->query['offset'] ?? 0));
        if ($sub === null && $method === 'GET') {
            $r = $this->read->softwareFor($id, ['q' => $req->query['q'] ?? '', 'limit' => $limit, 'offset' => $offset, 'include_removed' => ($req->query['include_removed'] ?? '') === '1']);

            return self::json(200, ['data' => $r['items'], 'total' => $r['total'], 'state' => $this->read->softwareState($id)]);
        }
        if ($sub === 'history' && $method === 'GET') {
            $r = $this->read->softwareHistory($id, isset($req->query['name']) ? (string) $req->query['name'] : null, $limit, $offset);

            return self::json(200, ['data' => $r['items'], 'total' => $r['total']]);
        }
        if ($sub === 'refresh' && $method === 'POST' && $this->inventory !== null) {
            return self::action($this->inventory->refreshSoftware($who, $id), static fn (ActionResult $r): array => ['ok' => true, 'state' => 'queued']);
        }

        return self::notFound();
    }

    private function deviceTags(RmmRequest $req, RmmPrincipal $who, string $method, int $id, ?string $tagId): RmmResponse
    {
        if ($tagId === null && $method === 'GET') {
            return self::json(200, ['data' => $this->read->deviceTags($id), 'groups' => $this->read->deviceGroups($id)]);
        }
        if ($this->inventory === null) {
            return self::notFound();
        }
        if ($tagId === null && $method === 'POST') {
            [$in, $bad] = $this->jsonBody($req);
            if ($bad !== null) {
                return $bad;
            }
            $tag = $in['tag'] ?? null;
            if (!(is_string($tag) && $tag !== '') && !is_int($tag)) {
                return self::json(422, ['error' => 'tag must be a tag name or id.', 'code' => 'invalid']);
            }

            return self::action($this->inventory->tagDevice($who, $id, is_string($tag) && ctype_digit($tag) ? (int) $tag : $tag), static fn (ActionResult $r): array => ['tag' => $r->data['tag'] ?? null]);
        }
        if ($tagId !== null && ctype_digit($tagId) && $method === 'DELETE') {
            return self::action($this->inventory->untagDevice($who, $id, (int) $tagId), static fn (ActionResult $r): array => ['ok' => true]);
        }

        return self::notFound();
    }

    /**
     * @param \Closure(ActionResult):array<string,mixed> $body the success body
     */
    private static function action(ActionResult $r, \Closure $body): RmmResponse
    {
        return $r->ok ? self::json($r->http, $body($r)) : self::failure($r);
    }

    /**
     * The JSON object body ('' is an empty object).
     *
     * @return array{0:array<string,mixed>,1:?RmmResponse} [body, error response]
     */
    private function jsonBody(RmmRequest $req): array
    {
        if ($req->declaredLength !== null && $req->declaredLength > self::MAX_BODY) {
            return [[], self::json(413, ['error' => 'Request body too large.', 'code' => 'too_large'])];
        }
        $raw = $req->bodyStream === null ? '' : stream_get_contents($req->bodyStream, self::MAX_BODY + 1);
        if ($raw === false || strlen($raw) > self::MAX_BODY) {
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

    private static function failure(ActionResult $r): RmmResponse
    {
        return self::json($r->http, ['error' => $r->message, 'code' => $r->code]);
    }

    private static function notFound(): RmmResponse
    {
        return self::json(404, ['error' => 'Not found', 'code' => 'not_found']);
    }

    /**
     * Plain JSON, as the edition's api_response() emits it: Content-Type only (no Cache-Control), PHP's default encoding flags
     * (escaped slashes) plus substitution of invalid UTF-8 so a hostile device string can never blank a whole response.
     *
     * @param array<mixed> $data
     */
    private static function json(int $status, array $data): RmmResponse
    {
        return new RmmResponse($status, ['Content-Type' => 'application/json'], (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
