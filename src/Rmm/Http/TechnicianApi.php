<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Read\RmmReadModel;
use RivetCore\Rmm\Technician\ActionResult;
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
            $list = $this->read->listDevices($filters, $this->authz->visibleClientIds($uid), $limit, $offset);

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

        return self::notFound();
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
