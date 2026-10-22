<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\AutoInstall\AutoInstallActions;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * The "install apps when the agent installs" routes of the technician REST API (1.0.0-rc.26). Additive; handed over by {@see TechnicianApi} after its own authentication.
 *
 *   GET    endpoint_devices/software_autoinstall                         every package with its auto-install policy, the parameter names it declares and where they are set (never a value)
 *   PUT    endpoint_devices/software_autoinstall/{package_id}            {scope: off|all|clients|os, os?, clients?: [client_id | {client_id, location_id}], install_order?, required?}
 *   GET    endpoint_devices/software_autoinstall/selectable?client_id=&location_id=&os=   the installer page's helper: apps that can be ticked, pre-ticked ones, missing parameters
 *   GET    endpoint_devices/software_params/{package_id}                 where each parameter is set (no values)
 *   PUT    endpoint_devices/software_params/{package_id}                 {name, value, scope?: global|client|location, scope_id?}   the value is sealed and never shown again
 *   DELETE endpoint_devices/software_params/{package_id}                 {name, scope?, scope_id?}
 *   GET    endpoint_devices/software_files                               hosted installer files (no paths)
 *   POST   endpoint_devices/software_files                               raw body (application/octet-stream, header X-Filename): stores an installer; DELETE software_files/{id}
 *   GET    endpoint_devices/software_templates                           the starter templates (data only, all unverified); POST software_templates/{key} creates a package or version from one
 *   GET    endpoint_devices/{id}/autoinstall                             the apps this device was told to install with the agent: status and reason per app
 *
 * @api
 */
final class AutoInstallApi
{
    public const FLEET = ['software_autoinstall', 'software_params', 'software_files', 'software_templates'];

    public function __construct(private readonly AutoInstallActions $actions, private readonly int $maxUploadBytes = 536870912)
    {
    }

    /**
     * @param list<string> $seg
     */
    public function fleet(RmmRequest $req, RmmPrincipal $who, array $seg): RmmResponse
    {
        $m = strtoupper($req->method);
        $second = $seg[1] ?? null;
        if (isset($seg[2])) {
            return self::notFound();
        }
        switch ($seg[0]) {
            case 'software_autoinstall':
                if ($second === null) {
                    return $m === 'GET' ? self::result($this->actions->overview($who)) : self::method();
                }
                if ($second === 'selectable') {
                    if ($m !== 'GET') {
                        return self::method();
                    }
                    foreach (['client_id', 'location_id'] as $k) {
                        if (isset($req->query[$k]) && !ctype_digit($req->query[$k])) {
                            return self::json(422, ['error' => "$k must be a whole number.", 'code' => 'invalid']);
                        }
                    }

                    return self::result($this->actions->selectable($who, (int) ($req->query['client_id'] ?? 0), (int) ($req->query['location_id'] ?? 0), (string) ($req->query['os'] ?? 'windows')));
                }
                if (!ctype_digit($second)) {
                    return self::notFound();
                }
                if (!in_array($m, ['PUT', 'PATCH'], true)) {
                    return self::method();
                }
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->actions->setAuto($who, (int) $second, $in));
            case 'software_params':
                if ($second === null || !ctype_digit($second)) {
                    return self::notFound();
                }
                if ($m === 'GET') {
                    return self::result($this->actions->paramsOf($who, (int) $second));
                }
                if (!in_array($m, ['PUT', 'POST', 'DELETE'], true)) {
                    return self::method();
                }
                [$in, $bad] = $this->body($req);
                if ($bad !== null) {
                    return $bad;
                }

                return self::result($m === 'DELETE' ? $this->actions->clearParam($who, (int) $second, $in) : $this->actions->setParam($who, (int) $second, $in));
            case 'software_templates':
                if ($second === null) {
                    return $m === 'GET' ? self::result($this->actions->templates($who)) : self::method();
                }
                if ($m !== 'POST') {
                    return self::method();
                }
                [$in, $bad] = $this->body($req);

                return $bad ?? self::result($this->actions->fromTemplate($who, $second, $in));
            case 'software_files':
                if ($second === null) {
                    if ($m === 'GET') {
                        return self::result($this->actions->files($who));
                    }

                    return $m === 'POST' ? $this->upload($req, $who) : self::method();
                }

                return ctype_digit($second) ? ($m === 'DELETE' ? self::result($this->actions->deleteFile($who, (int) $second)) : self::method()) : self::notFound();
        }

        return self::notFound();
    }

    /**
     * A route under a device (`autoinstall`); null for a path this class does not own.
     *
     * @param list<string> $seg
     */
    public function device(RmmRequest $req, RmmPrincipal $who, int $id, array $seg): ?RmmResponse
    {
        if (($seg[1] ?? '') !== 'autoinstall') {
            return null;
        }
        if (isset($seg[2])) {
            return self::notFound();
        }

        return strtoupper($req->method) === 'GET' ? self::result($this->actions->deviceApps($who, $id)) : self::method();
    }

    private function upload(RmmRequest $req, RmmPrincipal $who): RmmResponse
    {
        // the ability, the client scope and the feature switch BEFORE the body is touched (security review F5): a caller who cannot upload costs the server no disk
        if (($denied = $this->actions->uploadGate($who)) !== null) {
            return self::result($denied);
        }
        if ($req->bodyStream === null || ($req->declaredLength !== null && $req->declaredLength > $this->maxUploadBytes)) {
            return self::json(413, ['error' => 'The installer is too large or missing.', 'code' => 'too_large']);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rvt_hosted_');
        if ($tmp === false) {
            return self::json(500, ['error' => 'No temporary file.', 'code' => 'internal']);
        }
        try {
            $out = fopen($tmp, 'wb');
            $n = $out === false ? false : stream_copy_to_stream($req->bodyStream, $out, $this->maxUploadBytes + 1);
            if ($out !== false) {
                fclose($out);
            }
            if ($n === false || $n > $this->maxUploadBytes) {
                return self::json(413, ['error' => 'The installer is too large.', 'code' => 'too_large']);
            }

            return self::result($this->actions->uploadFile($who, $tmp, (string) ($req->header('x-filename') ?? 'installer')));
        } finally {
            @unlink($tmp);
        }
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
