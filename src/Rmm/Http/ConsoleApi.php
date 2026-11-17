<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Remote\ConsoleEventsService;
use RivetCore\Rmm\Remote\ConsoleRedeemService;

/**
 * The two PUBLIC endpoints of the Rivet Console (the optional desktop app), framework-neutral like {@see DeviceApi}: the edition builds an {@see RmmRequest} and sends the
 * {@see RmmResponse}.
 *
 *  - `POST /api/v1/endpoint_console/redeem` -> {@see redeem()}: `{"code":"...","client":{"app","version","platform"}}`, answers the MeshCentral login (see {@see ConsoleRedeemService}).
 *  - `POST /api/v1/endpoint_console/events` -> {@see events()}: `Authorization: Bearer <report token>`, `{"session_id","events":[...]}` (see {@see ConsoleEventsService}).
 *
 * EDITION REQUIREMENTS. Mount them as public routes (no session, no CSRF token, NO CORS headers), POST only, JSON only. Route through {@see handle()} with endpoint
 * `endpoint_console` and path segment `redeem` or `events`, or call the two methods. The client address must come from the edition's trusted-proxy rule, never from a header the
 * caller can set. The edition must NOT log the request body anywhere (web server, WAF, application): it carries a login code or a bearer token. Every response is JSON with
 * `Cache-Control: no-store`. This class never logs a body, a code or a token, and logs an unexpected failure as its class name only.
 *
 * Answers (redeem): 200; 400 `invalid_or_expired` (the same bytes for every cause); 403 `approval_required`; 405; 415; 426 `client_too_old`; 429 `rate_limited`; 503/500 generic.
 * Answers (events): 200 `{"ok":true,"accepted":N}`; 400 `invalid_request`; 401 `unauthorized` (the same for a missing, unknown, wrong or expired token); 405; 413 `too_large`; 415;
 * 429 `rate_limited`.
 *
 * @api
 */
final class ConsoleApi
{
    /** The redeem request is a code and a small client block. */
    public const REDEEM_MAX_BODY = 4096;

    public function __construct(
        private readonly ConsoleRedeemService $redeemer,
        private readonly ConsoleEventsService $events,
        private readonly bool $allowInsecureHttp = false,
    ) {
    }

    /** Route by the first path segment: `redeem` or `events`; anything else is a 404. */
    public function handle(RmmRequest $req): RmmResponse
    {
        return match ($req->pathSegments[0] ?? '') {
            'redeem' => $this->redeem($req),
            'events' => $this->events($req),
            default => RmmResponse::json(404, ['ok' => false, 'error' => 'not_found']),
        };
    }

    public function redeem(RmmRequest $req): RmmResponse
    {
        return $this->guard(function () use ($req): RmmResponse {
            if (($early = $this->preflight($req)) !== null) {
                return $early;
            }
            // Every request counts against the per-address limit inside the service, before the body is looked at.
            $raw = $this->read($req, self::REDEEM_MAX_BODY);
            $d = $raw === null ? null : json_decode($raw, true);
            $code = is_array($d) && is_string($d['code'] ?? null) ? $d['code'] : '';
            $client = is_array($d) && is_array($d['client'] ?? null) ? $d['client'] : null;
            $r = $this->redeemer->redeem($code, $client, $req->clientIp);

            return RmmResponse::json($r['http'], $r['body'], $r['headers']);
        });
    }

    public function events(RmmRequest $req): RmmResponse
    {
        return $this->guard(function () use ($req): RmmResponse {
            if (($early = $this->preflight($req)) !== null) {
                return $early;
            }
            $session = $this->events->authenticate($req->header('authorization'));
            if ($session === null) {
                return RmmResponse::json(401, ['ok' => false, 'error' => 'unauthorized']);
            }
            if (!$this->events->withinRate($session['session_id'])) {
                return RmmResponse::json(429, ['ok' => false, 'error' => 'rate_limited'], ['Retry-After' => (string) ConsoleEventsService::RATE_WINDOW_S]);
            }
            if ($req->declaredLength !== null && $req->declaredLength > ConsoleEventsService::MAX_BYTES) {
                return RmmResponse::json(413, ['ok' => false, 'error' => 'too_large']);
            }
            $raw = $this->read($req, ConsoleEventsService::MAX_BYTES);
            if ($raw === null) {
                return RmmResponse::json(413, ['ok' => false, 'error' => 'too_large']);
            }
            $r = $this->events->accept($session, $raw);

            return RmmResponse::json($r['http'], $r['body']);
        });
    }

    /** Method, content type and transport: the answers that do not depend on the caller's identity or the body. */
    private function preflight(RmmRequest $req): ?RmmResponse
    {
        if (strtoupper($req->method) !== 'POST') {
            return RmmResponse::json(405, ['ok' => false, 'error' => 'method_not_allowed'], ['Allow' => 'POST']);
        }
        if (preg_match('#^application/json\s*(;|$)#i', trim((string) $req->header('content-type'))) !== 1) {
            return RmmResponse::json(415, ['ok' => false, 'error' => 'unsupported_media_type']);
        }
        if (!$this->allowInsecureHttp && !$req->secureTransport) {
            return RmmResponse::json(403, ['ok' => false, 'error' => 'tls_required']);
        }

        return null;
    }

    /** The bounded body, or null when it is larger than `$max`. */
    private function read(RmmRequest $req, int $max): ?string
    {
        if ($req->declaredLength !== null && $req->declaredLength > $max) {
            return null;
        }
        $raw = $req->bodyStream === null ? '' : stream_get_contents($req->bodyStream, $max + 1);

        return $raw === false || strlen($raw) > $max ? null : $raw;
    }

    /** @param \Closure():RmmResponse $fn */
    private function guard(\Closure $fn): RmmResponse
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            // The class name only: a message could echo a query or a value of the request, and the request carries a code or a token.
            error_log('rivet console: ' . get_class($e));

            return RmmResponse::json(500, ['ok' => false, 'error' => 'internal']);
        }
    }
}
