<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Technician\ActionResult;
use RivetCore\Rmm\Technician\StarterActions;

/**
 * The starter pack routes of the technician REST API. {@see TechnicianApi} hands them over after its own authentication and module checks; every route
 * needs `rmm.admin`, and a role without it gets the same 403 on all of them.
 *
 *   GET  endpoint_devices/starter/catalog      the packs, each script (no text), check template set and policy draft with its state in this instance
 *                                              (query: pack, platform, risk, tags, q)
 *   POST endpoint_devices/starter/plan         {pack?, ids?, tags?, platform?, risk?, kinds?, params?, policy_params?}: what an install would add,
 *                                              update, park for review or skip (writes nothing)
 *   POST endpoint_devices/starter/install      the same body: installs it; per item outcome added, updated, staged, unchanged, skipped, blocked, failed
 *   POST endpoint_devices/starter/uninstall    the same body, plus {dry_run?}: retires untouched starter scripts and deletes untouched, off, unassigned drafts
 *
 * @api
 */
final class StarterApi
{
    /** First path segment that belongs here. */
    public const FLEET = ['starter'];

    public function __construct(private readonly StarterActions $starter)
    {
    }

    /** @param list<string> $seg */
    public function fleet(RmmRequest $req, RmmPrincipal $who, array $seg): RmmResponse
    {
        $m = strtoupper($req->method);
        $what = $seg[1] ?? null;
        if (isset($seg[2]) || $what === null) {
            return self::json(404, ['error' => 'Not found', 'code' => 'not_found']);
        }
        if ($what === 'catalog') {
            if ($m !== 'GET') {
                return self::method();
            }
            $f = [];
            foreach (['pack', 'platform', 'risk', 'tags', 'q'] as $k) {
                if (isset($req->query[$k]) && $req->query[$k] !== '') {
                    $f[$k] = in_array($k, ['risk', 'tags'], true) ? array_values(array_filter(explode(',', $req->query[$k]), static fn (string $x): bool => $x !== '')) : $req->query[$k];
                }
            }

            return self::result($this->starter->catalog($who, $f));
        }
        if (!in_array($what, ['plan', 'install', 'uninstall'], true)) {
            return self::json(404, ['error' => 'Not found', 'code' => 'not_found']);
        }
        if ($m !== 'POST') {
            return self::method();
        }
        [$in, $bad] = $this->body($req);
        if ($bad !== null) {
            return $bad;
        }
        if ($what === 'plan') {
            return self::result($this->starter->plan($who, $in));
        }
        if ($what === 'install') {
            return self::result($this->starter->install($who, $in));
        }
        $dry = !empty($in['dry_run']);
        unset($in['dry_run']);

        return self::result($this->starter->uninstall($who, $in, $dry));
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

    /** @param array<mixed> $data */
    private static function json(int $status, array $data): RmmResponse
    {
        return new RmmResponse($status, ['Content-Type' => 'application/json'], (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
    }
}
