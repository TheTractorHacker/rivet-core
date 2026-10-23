<?php

declare(strict_types=1);

namespace RivetCore\Panel\Http;

use RivetCore\Panel\PanelAuthenticator;
use RivetCore\Panel\PanelEnrollment;
use RivetCore\Panel\PanelLimits;
use RivetCore\Panel\PanelPrincipal;
use RivetCore\Panel\PanelProtocol;
use RivetCore\Panel\PanelService;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Http\RmmRequest;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\Support\Sql;

/**
 * The RivetPanel REST handlers. Each takes the {@see RmmRequest} the edition built (the same neutral request the RMM device API uses) and returns an
 * {@see RmmResponse}; nothing here reads a superglobal, sends a header or exits.
 *
 * Endpoints ($req->endpoint): panel_enroll (POST), panel_me (GET), panel_categories (GET), panel_tickets (GET list, POST create), panel_ticket_chat (GET messages,
 * POST message; the ticket id is the first path segment or the `ticket_id` query value).
 *
 * Every JSON answer, errors included, carries `server_time` (RFC 3339 UTC) so a panel can see its clock skew. Nothing a panel sends is a timestamp, so there is no
 * time window to violate: the RMM "collected_at in the future" rule does not exist here. Errors are {"error","code","server_time"}.
 *
 * @api
 */
final class PanelApi
{
    /** @var \Closure(string,int,int):bool */
    private \Closure $rateLimit;
    /** @var \Closure():bool */
    private \Closure $enabled;

    /**
     * @param \Closure(string,int,int):bool $rateLimit (bucket, limit, windowSeconds): true when within budget; the buckets are "panel_<kind>:<device_id>" and
     *        "panel_authfail:<sha1 of address>"
     * @param (\Closure():bool)|null $enabled the edition's on/off switch for the panel API (default on); off answers 503 module_disabled on every endpoint
     * @param bool $allowInsecureHttp skip the TLS requirement (loopback test servers only)
     */
    public function __construct(
        private readonly Sql $sql,
        private readonly PanelEnrollment $enrollment,
        private readonly PanelAuthenticator $auth,
        private readonly PanelService $service,
        private readonly PanelLimits $limits,
        \Closure $rateLimit,
        ?\Closure $enabled = null,
        private readonly bool $allowInsecureHttp = false,
    ) {
        $this->rateLimit = $rateLimit;
        $this->enabled = $enabled ?? static fn (): bool => true;
    }

    public function handle(RmmRequest $req): RmmResponse
    {
        try {
            if (!($this->enabled)()) {
                return $this->json(503, ['error' => 'The panel service is disabled on this server.', 'code' => 'module_disabled'], ['Retry-After' => (string) PanelProtocol::DISABLED_RETRY_AFTER_S]);
            }
            if (!$this->allowInsecureHttp && !$req->secureTransport) {
                throw new ApiError(426, 'tls_required', 'TLS is required.');
            }

            return match ($req->endpoint) {
                'panel_enroll' => $this->enroll($req),
                'panel_me' => $this->me($req),
                'panel_categories' => $this->categories($req),
                'panel_tickets' => $this->tickets($req),
                'panel_ticket_chat' => $this->chat($req),
                default => throw new ApiError(404, 'not_found', 'Not found.'),
            };
        } catch (ApiError $e) {
            return $this->json($e->http, ['error' => $e->getMessage(), 'code' => $e->errCode] + $e->extra, $e->headers);
        } catch (\Throwable $e) {
            error_log('panel api: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->json(500, ['error' => 'Internal error.', 'code' => 'internal']);
        }
    }

    private function enroll(RmmRequest $req): RmmResponse
    {
        $this->method($req, ['POST']);

        return $this->json(201, $this->enrollment->enroll($this->body($req, PanelProtocol::ENROLL_MAX_BODY), $req->clientIp));
    }

    private function me(RmmRequest $req): RmmResponse
    {
        $this->method($req, ['GET']);
        $p = $this->authenticate($req, 'read');

        return $this->json(200, $this->service->me($p));
    }

    private function categories(RmmRequest $req): RmmResponse
    {
        $this->method($req, ['GET']);
        $p = $this->authenticate($req, 'read');

        return $this->json(200, ['categories' => $this->service->categories($p)]);
    }

    private function tickets(RmmRequest $req): RmmResponse
    {
        $this->method($req, ['GET', 'POST']);
        if (strtoupper($req->method) === 'GET') {
            return $this->json(200, $this->service->listTickets($this->authenticate($req, 'read'), $req->query));
        }
        $p = $this->authenticate($req, 'create');
        $r = $this->service->createTicket($p, $this->body($req, $this->limits->postBodyMax()));

        return $this->json($r['replayed'] ? 200 : 201, ['ticket_id' => $r['ticket_id'], 'replayed' => $r['replayed']]);
    }

    private function chat(RmmRequest $req): RmmResponse
    {
        $this->method($req, ['GET', 'POST']);
        $raw = $req->pathSegments[0] ?? ($req->query['ticket_id'] ?? '');
        $ticketId = preg_match('/^[1-9]\d{0,17}$/', $raw) === 1 ? (int) $raw : 0;
        if (strtoupper($req->method) === 'GET') {
            return $this->json(200, $this->service->chat($this->authenticate($req, 'read'), $ticketId, $req->query));
        }
        $p = $this->authenticate($req, 'chat');

        return $this->json(201, $this->service->postChat($p, $ticketId, $this->body($req, $this->limits->postBodyMax())));
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * @param array<mixed> $data
     * @param array<string,string> $headers
     */
    private function json(int $status, array $data, array $headers = []): RmmResponse
    {
        return RmmResponse::json($status, $data + ['server_time' => $this->sql->isoNow()], $headers);
    }

    /** @param list<string> $allowed */
    private function method(RmmRequest $req, array $allowed): void
    {
        if (!in_array(strtoupper($req->method), $allowed, true)) {
            throw new ApiError(405, 'method_not_allowed', 'Use ' . implode(' or ', $allowed) . '.', ['Allow' => implode(', ', $allowed)]);
        }
    }

    /** Authenticates, then spends one call of the device's budget for $kind (read | create | chat). */
    private function authenticate(RmmRequest $req, string $kind): PanelPrincipal
    {
        try {
            $p = $this->auth->authenticate($req->header('authorization'), $req->clientIp);
        } catch (ApiError $e) {
            [$limit, $window] = PanelProtocol::RATE_AUTH_FAIL;
            if (!($this->rateLimit)('panel_authfail:' . sha1($req->clientIp), $limit, $window)) {
                throw new ApiError(429, 'rate_limited', 'Too many requests.', ['Retry-After' => (string) $window]);
            }
            throw $e;
        }
        [$limit, $window] = match ($kind) {
            'create' => PanelProtocol::RATE_CREATE,
            'chat' => PanelProtocol::RATE_CHAT,
            default => PanelProtocol::RATE_READ,
        };
        if (!($this->rateLimit)("panel_{$kind}:{$p->deviceId}", $limit, $window)) {
            throw new ApiError(429, 'rate_limited', 'Too many requests.', ['Retry-After' => (string) $window]);
        }

        return $p;
    }

    /**
     * Bounded JSON object body. 413 above $max bytes (declared or actual), 422 when it is not a JSON object.
     *
     * @return array<string,mixed>
     */
    private function body(RmmRequest $req, int $max): array
    {
        if ($req->declaredLength !== null && $req->declaredLength > $max) {
            throw new ApiError(413, 'too_large', 'Request body too large.');
        }
        $raw = $req->bodyStream === null ? '' : stream_get_contents($req->bodyStream, $max + 1);
        if ($raw === false || strlen($raw) > $max) {
            throw new ApiError(413, 'too_large', 'Request body too large.');
        }
        $d = json_decode($raw, true, 16);
        if (!is_array($d) || ($d !== [] && array_is_list($d))) {
            throw new ApiError(422, 'invalid', 'A JSON object body is required.');
        }

        /** @var array<string,mixed> $d */
        return $d;
    }
}
