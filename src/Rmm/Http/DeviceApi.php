<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

use RivetCore\Rmm\Capacity\HerdControl;
use RivetCore\Rmm\Capacity\LoadShedder;
use RivetCore\Rmm\Checkin\CheckinService;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmModuleStateInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Enrollment\EnrollmentService;
use RivetCore\Rmm\Installer\InstallerDownload;
use RivetCore\Rmm\Installer\InstallerFormat;
use RivetCore\Rmm\Installer\InstallerStamp;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Change\ChangeProtocol;
use RivetCore\Rmm\Change\ChangeService;
use RivetCore\Rmm\Logs\LogService;
use RivetCore\Rmm\Remote\RemoteProtocol;
use RivetCore\Rmm\Remote\TransferService;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\RmmState;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Update\UpdateService;

/**
 * The device-facing REST handlers: enroll, check-in, jobs (offer and report), the hosted update download and the token-gated
 * installer download. Each takes an {@see RmmRequest} the edition built and returns an {@see RmmResponse}; nothing here reads a
 * superglobal, sends a header or exits.
 *
 * Every handler authenticates its caller, reads its own bounded body and rate-limits itself. Errors are
 * {"error": "...", "code": "..."}; 401 codes are invalid_token, revoked and expired. An ApiError becomes its JSON error and anything
 * else a generic 500 (details go to the PHP log only).
 *
 * MODULE SWITCH. The edition chooses what a switched-off module answers ($disabledAnswer, a constructor option):
 *
 * - {@see self::DISABLED_UNIFORM} (the default): a master-off (or edition-off) module answers 503 module_disabled with Retry-After 3600
 *   before anything else, on every endpoint, so agents back off instead of dropping their data.
 * - {@see self::DISABLED_COMPAT}: the answer RivetIT's endpoints always gave, which its pinned goldens record: enroll and installer 403
 *   forbidden, a valid device credential 403 forbidden (after the 401 checks). Use it when an install-level gate in front of the
 *   application (docs/rmm/templates/rmm_gate.php) already gives the uniform 503 once the state file says "off", and the application
 *   itself must keep the legacy answer for the window where the file is missing or stale. The edition kill switch
 *   ({@see RmmModuleStateInterface::editionAllows()}) also answers 403 forbidden in this mode.
 *
 * In both modes a sub-switch that is off answers 503 feature_disabled on the endpoint it controls (jobs, updates). When a
 * {@see RmmState} is passed, every request first re-creates a missing or damaged state file from the settings row (one stat and read when
 * the file is fine), so a compat edition keeps the gate's fast path alive without any other code path writing it.
 *
 * @api
 */
final class DeviceApi
{
    /** Retry-After of a disabled module or feature (agents cap at one hour). */
    public const DISABLED_RETRY_AFTER_S = 3600;
    /** A switched-off module answers 503 module_disabled (Retry-After 3600) on every endpoint. The default. */
    public const DISABLED_UNIFORM = 'uniform';
    /** A switched-off module answers the legacy 403 forbidden (RivetIT's pinned goldens). */
    public const DISABLED_COMPAT = 'compat';

    /** @var \Closure(string,int,int):bool */
    private \Closure $rateLimit;
    /** @var \Closure(int):void */
    private \Closure $sleep;
    /** @var \Closure():float */
    private \Closure $now;

    /**
     * @param \Closure(string,int,int):bool $rateLimit (bucket, limit, windowSeconds): true when the call is within budget. Redis or database
     *        backed, the edition decides (including whether it fails open); the per-device buckets are "agent_<kind>:<device_id>"
     * @param bool $allowInsecureHttp skip the TLS requirement (loopback test servers only)
     * @param (\Closure(int):void)|null $sleep microseconds to wait between long-poll rounds (default usleep)
     * @param (\Closure():float)|null $now monotonic seconds for the long-poll deadline (default microtime(true)); a seam for tests
     * @param string $disabledAnswer {@see self::DISABLED_UNIFORM} (default) or {@see self::DISABLED_COMPAT}
     * @param RmmState|null $state when given, a missing or damaged state file is re-created lazily on each request (see class docs)
     * @throws \InvalidArgumentException for an unknown $disabledAnswer
     */
    public function __construct(
        private readonly RmmSettings $settings,
        private readonly DeviceRepository $devices,
        private readonly EnrollmentService $enrollment,
        private readonly CheckinService $checkin,
        private readonly JobService $jobs,
        private readonly UpdateService $updates,
        private readonly InstallerDownload $installer,
        private readonly RmmAuditInterface $audit,
        \Closure $rateLimit,
        private readonly bool $allowInsecureHttp = false,
        private readonly ?RmmModuleStateInterface $moduleState = null,
        ?\Closure $sleep = null,
        ?\Closure $now = null,
        private readonly ?LoadShedder $shedder = null,
        private readonly string $disabledAnswer = self::DISABLED_UNIFORM,
        private readonly ?RmmState $state = null,
        private readonly ?HerdControl $herd = null,
        private readonly ?TransferService $transfers = null,
        private readonly ?LogService $logs = null,
        private readonly ?ChangeService $changes = null,
    ) {
        if ($disabledAnswer !== self::DISABLED_UNIFORM && $disabledAnswer !== self::DISABLED_COMPAT) {
            throw new \InvalidArgumentException('disabledAnswer must be "uniform" or "compat".');
        }
        $this->rateLimit = $rateLimit;
        $this->now = $now ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (int $us): void {
            usleep($us);
        };
    }

    /** Route by {@see RmmRequest::$endpoint}; an unknown device endpoint is a 404. */
    public function handle(RmmRequest $req): RmmResponse
    {
        return match ($req->endpoint) {
            'agent_enroll' => $this->enroll($req),
            'agent_checkin' => $this->checkin($req),
            'agent_jobs' => $this->jobs($req),
            'agent_update' => $this->update($req),
            'agent_installer' => $this->installer($req),
            'agent_transfer' => $this->transfer($req),
            'agent_logs' => $this->logs($req),
            'agent_changes' => $this->changes($req),
            default => RmmResponse::error(new ApiError(404, 'not_found', 'Not found.')),
        };
    }

    // ------------------------------------------------------------------ agent_enroll

    /** POST: the enrollment token is in the body. 201 {device_id, device_token (shown once), ...}. */
    public function enroll(RmmRequest $req): RmmResponse
    {
        return $this->guard(function () use ($req): RmmResponse {
            if (($off = $this->moduleOff()) !== null) {
                return $off;
            }
            $this->requireTls($req);
            if (strtoupper($req->method) !== 'POST') {
                throw new ApiError(405, 'method_not_allowed', 'Use POST.', ['Allow' => 'POST']);
            }
            if (!$this->settings->enabled()) {
                throw new ApiError(403, 'forbidden', 'The endpoint agent service is disabled.');
            }
            $body = $this->body($req, RmmProtocol::ENROLL_MAX_BODY);

            return RmmResponse::json(201, $this->enrollment->enroll($body, $req->clientIp));
        });
    }

    // ------------------------------------------------------------------ agent_checkin

    /** POST (device auth): idempotent by (device_id, seq). 200 {ok, status, next_check_in_s, jobs_pending, config, update, ...}. */
    public function checkin(RmmRequest $req): RmmResponse
    {
        return $this->guard(function () use ($req): RmmResponse {
            if (($off = $this->moduleOff()) !== null) {
                return $off;
            }
            $this->requireTls($req);
            if (strtoupper($req->method) !== 'POST') {
                throw new ApiError(405, 'method_not_allowed', 'Use POST.', ['Allow' => 'POST']);
            }
            $this->herd?->beforeCheckin($req->header('authorization'));   // warm start after an outage (off by default): refuses a share of check-ins before any query
            $this->shedder?->tick();   // at most one evaluation per 10 s, from cheap signals
            if ((int) ($this->settings->get()['shed_level'] ?? 0) >= 3) {
                throw LoadShedder::refusal($this->settings->limits());   // level 3: refused before any other work
            }
            $dev = $this->devices->authenticate($req->header('authorization'));
            $this->deviceRateLimit((int) $dev['device_id'], 'checkin', ...RmmProtocol::RATE_CHECKIN);
            $this->globalCheckinLimit();
            $this->herd?->clientBucket('checkin', (int) $dev['client_id']);   // opt-in token bucket per client (client_bucket_burst)
            $body = $this->body($req, RmmProtocol::CHECKIN_MAX_BODY);

            return RmmResponse::json(200, $this->checkin->handle($dev, $body, $req->clientIp));
        });
    }

    // ------------------------------------------------------------------ agent_jobs

    /**
     * GET (device auth, optional ?wait=1..5 long poll): {"jobs":[ signed job objects ]}.
     * POST (device auth): the job report {job_id, attempt, state, exit_code, output, started_at, finished_at} -> {"ok":true}.
     * A device only ever sees and reports its OWN jobs: the device id comes from the credential, never from the request.
     */
    public function jobs(RmmRequest $req): RmmResponse
    {
        return $this->guard(function () use ($req): RmmResponse {
            if (($off = $this->moduleOff()) !== null) {
                return $off;
            }
            $this->requireTls($req);
            if (($off = $this->featureOff('jobs')) !== null) {
                return $off;
            }
            $dev = $this->devices->authenticate($req->header('authorization'));
            $deviceId = (int) $dev['device_id'];
            $this->deviceRateLimit($deviceId, 'jobs', ...RmmProtocol::RATE_JOBS);
            $method = strtoupper($req->method);
            if ($method === 'GET') {
                $wait = max(0, min(RmmProtocol::JOBS_WAIT_MAX_S, (int) ($req->query['wait'] ?? 0)));
                $deadline = ($this->now)() + $wait;
                do {
                    $jobs = $this->jobs->offer($dev);
                    if ($jobs !== [] || ($this->now)() >= $deadline) {
                        break;
                    }
                    ($this->sleep)(RmmProtocol::JOBS_POLL_STEP_US);
                } while (true);

                return RmmResponse::json(200, ['jobs' => $jobs]);
            }
            if ($method === 'POST') {
                $this->jobs->report($dev, $this->body($req, RmmProtocol::JOBS_REPORT_MAX_BODY));

                return RmmResponse::json(200, ['ok' => true]);
            }
            throw new ApiError(405, 'method_not_allowed', 'Use GET or POST.', ['Allow' => 'GET, POST']);
        });
    }

    // ------------------------------------------------------------------ agent_transfer (Phase 6, additive)

    /**
     * File transfer chunks, device auth plus the one-time transfer token in the `X-Transfer-Token` header (never in the URL), only while the `filetransfer`
     * sub-switch is on.
     *
     * POST ?transfer_id=U&offset=N (raw body, at most 1 MiB; `X-Transfer-Last: 1` and `X-Transfer-Sha256` on the final chunk): a download from the device.
     *   200 {ok, received, complete}; 409 {code: conflict, received} to resume; 413 over the size cap; 422 checksum_mismatch.
     * GET ?transfer_id=U&offset=N&length=M: a range of an upload to the device, raw bytes with `X-Transfer-Size` and `X-Transfer-Sha256`.
     */
    public function transfer(RmmRequest $req): RmmResponse
    {
        return $this->guard(function () use ($req): RmmResponse {
            if (($off = $this->moduleOff()) !== null) {
                return $off;
            }
            $this->requireTls($req);
            if ($this->transfers === null || ($off = $this->featureOff(RemoteProtocol::FEATURE_FILE_TRANSFER)) !== null) {
                return $off ?? RmmResponse::error(new ApiError(404, 'not_found', 'Not found.'));
            }
            $dev = $this->devices->authenticate($req->header('authorization'));
            $this->deviceRateLimit((int) $dev['device_id'], 'transfer', ...RemoteProtocol::TRANSFER_RATE);
            $tid = $req->query['transfer_id'] ?? '';
            $token = $req->header('x-transfer-token') ?? '';
            $offset = isset($req->query['offset']) && ctype_digit($req->query['offset']) && strlen($req->query['offset']) <= 12 ? (int) $req->query['offset'] : null;
            if (preg_match(RemoteProtocol::TRANSFER_TOKEN_RE, $token) !== 1 || $offset === null) {
                throw new ApiError(422, 'invalid', 'transfer_id, offset and the X-Transfer-Token header are required.');
            }
            $method = strtoupper($req->method);
            if ($method === 'POST') {
                $bytes = $this->rawBody($req, RemoteProtocol::TRANSFER_CHUNK_MAX);
                $sha = $req->header('x-transfer-sha256');
                $r = $this->transfers->receiveChunk($dev, $tid, $token, $offset, $bytes, $req->header('x-transfer-last') === '1', $sha === null ? null : strtolower($sha));

                return RmmResponse::json($r['http'], $r['body']);
            }
            if ($method === 'GET') {
                $len = isset($req->query['length']) && ctype_digit($req->query['length']) && strlen($req->query['length']) <= 9 ? (int) $req->query['length'] : 0;
                $r = $this->transfers->serveChunk($dev, $tid, $token, $offset, $len);
                if ($r['http'] !== 200) {
                    return RmmResponse::json($r['http'], $r['body']);
                }

                return new RmmResponse(200, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Transfer-Size' => (string) $r['size'],
                    'X-Transfer-Sha256' => (string) $r['sha256'], 'X-Transfer-Offset' => (string) $offset], $r['data'] ?? '');
            }
            throw new ApiError(405, 'method_not_allowed', 'Use GET or POST.', ['Allow' => 'GET, POST']);
        });
    }

    // ------------------------------------------------------------------ agent_logs (Phase 8, additive)

    /**
     * POST (device auth, body cap 262144 bytes): a batch of collected log entries `{events: [{ts, level, source, event_id, message}], dropped, config_version}` ->
     * 200 `{ok, accepted, dropped, duplicates}`. 503 `feature_disabled` while the `logs` sub-switch is off, 503 `unavailable` with a jittered Retry-After under load
     * shedding (level 1 and up: log ingest is the first thing the server gives up), 429 above 30 requests per 60 s per device.
     */
    public function logs(RmmRequest $req): RmmResponse
    {
        return $this->guard(function () use ($req): RmmResponse {
            if (($off = $this->moduleOff()) !== null) {
                return $off;
            }
            $this->requireTls($req);
            if (strtoupper($req->method) !== 'POST') {
                throw new ApiError(405, 'method_not_allowed', 'Use POST.', ['Allow' => 'POST']);
            }
            if ($this->logs === null || ($off = $this->featureOff(RemoteProtocol::FEATURE_LOGS)) !== null) {
                return $off ?? RmmResponse::error(new ApiError(404, 'not_found', 'Not found.'));
            }
            $this->shedder?->tick();
            if ((int) ($this->settings->get()['shed_level'] ?? 0) >= 1) {
                throw LoadShedder::refusal($this->settings->limits());   // level 1 and up: logs are the first optional work to go
            }
            $dev = $this->devices->authenticate($req->header('authorization'));
            $this->deviceRateLimit((int) $dev['device_id'], 'logs', ...RemoteProtocol::LOG_RATE);
            $body = $this->body($req, RemoteProtocol::LOG_BODY_MAX);

            return RmmResponse::json(200, $this->logs->ingest($dev, $body));
        });
    }

    // ------------------------------------------------------------------ agent_changes (change monitoring, additive)

    /**
     * POST (device auth, body cap 262144 bytes): a batch of detected changes `{changes: [{ts, kind, watch, change, target, item, old, new, summary, old_hash, new_hash, actor, process}], dropped, config_version, status}`
     * -> 200 `{ok, accepted, dropped, duplicates}`. 503 `feature_disabled` while the `change_monitor` sub-switch is off, 503 `unavailable` with a jittered Retry-After under load shedding
     * (level 2 and up), 429 above 30 requests per 60 s per device. The device id always comes from the credential; every record is checked against what that device was told to watch.
     */
    public function changes(RmmRequest $req): RmmResponse
    {
        return $this->guard(function () use ($req): RmmResponse {
            if (($off = $this->moduleOff()) !== null) {
                return $off;
            }
            $this->requireTls($req);
            if (strtoupper($req->method) !== 'POST') {
                throw new ApiError(405, 'method_not_allowed', 'Use POST.', ['Allow' => 'POST']);
            }
            if ($this->changes === null || ($off = $this->featureOff(ChangeProtocol::FEATURE)) !== null) {
                return $off ?? RmmResponse::error(new ApiError(404, 'not_found', 'Not found.'));
            }
            $this->shedder?->tick();
            if ((int) ($this->settings->get()['shed_level'] ?? 0) >= 2) {
                throw LoadShedder::refusal($this->settings->limits());   // level 2 and up: change records wait, the agent keeps them
            }
            $dev = $this->devices->authenticate($req->header('authorization'));
            $this->deviceRateLimit((int) $dev['device_id'], 'changes', ...ChangeProtocol::RATE);
            $body = $this->body($req, ChangeProtocol::BODY_MAX);

            return RmmResponse::json(200, $this->changes->ingest($dev, $body));
        });
    }

    // ------------------------------------------------------------------ agent_update

    /**
     * GET ?arch=amd64|arm64&version=1.2.3 (device auth): the UNSTAMPED agent executable of the hosted release this device is
     * currently offered, as a file body. Only that release, only for the device's own architecture, only while the stored file still
     * matches the manifest's SHA-256; anything else is a generic 404.
     */
    public function update(RmmRequest $req): RmmResponse
    {
        return $this->guard(function () use ($req): RmmResponse {
            if (($off = $this->moduleOff()) !== null) {
                return $off;
            }
            $this->requireTls($req);
            if (strtoupper($req->method) !== 'GET') {
                throw new ApiError(405, 'method_not_allowed', 'Use GET.', ['Allow' => 'GET']);
            }
            if (($off = $this->featureOff('updates')) !== null) {
                return $off;
            }
            $dev = $this->devices->authenticate($req->header('authorization'));
            $deviceId = (int) $dev['device_id'];
            $this->deviceRateLimit($deviceId, 'update', ...RmmProtocol::RATE_UPDATE);
            $arch = $req->query['arch'] ?? null;
            $version = $req->query['version'] ?? null;
            if (!is_string($arch) || !isset(RmmProtocol::ARCHS[$arch]) || !is_string($version) || preg_match(RmmProtocol::BINARY_VERSION_RE, $version) !== 1) {
                throw new ApiError(422, 'invalid', 'arch (amd64 or arm64) and version are required.');
            }
            $bin = $this->updates->hostedBinaryFor($dev, $arch, $version);
            if ($bin === null) {
                throw new ApiError(404, 'not_found', 'Not found.');
            }
            $this->audit->record('Agent Update Downloaded', "Device $deviceId downloaded hosted agent $version ($arch)", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

            return $this->download($bin, RmmProtocol::DOWNLOAD_NAME_PREFIX . $arch . '.exe', '');
        });
    }

    // ------------------------------------------------------------------ agent_installer

    /**
     * POST {"token":"rvte1....","arch":"amd64|arm64","format":"exe|msi"} (JSON or form; the token may instead be an Authorization: Bearer header; `format` is optional and
     * defaults to exe, the only answer before 1.0.0-rc.16): the stamped per-client installer, or a generic 404 for any token that is not currently usable. The token is NEVER read from the
     * query string, so it stays out of access logs.
     */
    public function installer(RmmRequest $req): RmmResponse
    {
        return $this->guard(function () use ($req): RmmResponse {
            if (($off = $this->moduleOff()) !== null) {
                return $off;
            }
            $this->requireTls($req);
            if (strtoupper($req->method) !== 'POST') {
                throw new ApiError(405, 'method_not_allowed', 'Use POST.', ['Allow' => 'POST']);
            }
            if (isset($req->query['token'])) {
                throw new ApiError(400, 'token_in_url', 'Send the token in the request body or an Authorization header, never in the URL.');
            }
            if (!$this->settings->enabled()) {
                throw new ApiError(403, 'forbidden', 'The endpoint agent service is disabled.');
            }
            $ip = $req->clientIp;
            $this->installer->checkRate($ip);

            $raw = $this->rawBody($req, RmmProtocol::INSTALLER_MAX_BODY);
            $ctype = strtolower((string) $req->header('content-type'));
            if (str_contains($ctype, 'json')) {
                $body = json_decode($raw, true);
            } else {
                $body = [];
                parse_str($raw, $body);
            }
            if (!is_array($body)) {
                throw new ApiError(422, 'invalid', 'A JSON object or form body with token and arch is required.');
            }
            $token = $body['token'] ?? null;
            if ($token === null && preg_match('/^Bearer\s+(\S+)$/i', (string) $req->header('authorization'), $m) === 1) {
                $token = $m[1];
            }
            $arch = $body['arch'] ?? null;
            if (!is_string($token) || $token === '' || strlen($token) > 200 || !is_string($arch) || !isset(RmmProtocol::ARCHS[$arch])) {
                throw new ApiError(422, 'invalid', 'token and arch (amd64 or arm64) are required.');
            }
            $format = InstallerFormat::normalize($body['format'] ?? null);
            if ($format === null) {
                throw new ApiError(422, 'invalid', 'format must be exe or msi.');
            }
            $parts = explode('.', $token);
            $this->installer->checkRate($ip, count($parts) === 3 ? substr($parts[1], 0, 12) : '');

            $tok = $this->installer->authenticate($token, $ip);
            $refusal = $this->installer->preflight($arch, $format);
            if ($refusal !== null) {
                throw new ApiError(409, 'unavailable', $refusal);
            }
            $installerId = JobService::uuid();
            [$payload, $err] = $this->installer->payloadFor($tok, $token, $installerId);
            if ($payload === null) {
                throw new ApiError(409, 'unavailable', (string) $err);
            }
            $bin = $format === InstallerFormat::MSI ? $this->installer->msiTemplate($arch) : $this->updates->currentBinary($arch);
            if ($bin === null) {
                throw new ApiError(409, 'unavailable', ($format === InstallerFormat::MSI ? 'No MSI template is published for ' : 'No agent binary is published for ') . $arch . '.');
            }
            $name = $this->installer->filename($this->installer->departmentName((int) $tok['client_id']), $arch, $format);
            $this->installer->recordSuccess($tok, $ip, $arch . ($format === InstallerFormat::MSI ? ', msi' : ''), $installerId);

            return $this->download($bin, $name, InstallerStamp::trailer($payload));
        });
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * @param array<string,mixed> $bin an endpoint_agent_binaries row
     */
    private function download(array $bin, string $filename, string $trailer): RmmResponse
    {
        [$file, $error] = $this->updates->openBinary($bin, $trailer);
        if ($file === null) {
            throw new ApiError(503, 'unavailable', (string) $error);
        }

        return FileDownload::response($file, $filename);
    }

    /** @param \Closure():RmmResponse $fn */
    private function guard(\Closure $fn): RmmResponse
    {
        try {
            return $fn();
        } catch (ApiError $e) {
            return RmmResponse::error($e);
        } catch (\Throwable $e) {
            error_log('endpoint agent: ' . get_class($e) . ': ' . $e->getMessage());

            return RmmResponse::error(new ApiError(500, 'internal', 'Internal error.'));
        }
    }

    /** TLS is required unless the edition allows plain http (loopback test servers). */
    private function requireTls(RmmRequest $req): void
    {
        if ($this->allowInsecureHttp || $req->secureTransport) {
            return;
        }
        throw new ApiError(426, 'tls_required', 'TLS is required.');
    }

    /**
     * The 503 a switched-off module answers in uniform mode; in compat mode only the edition kill switch answers here (403 forbidden) and
     * the legacy per-endpoint checks do the rest. Null while the module is on. Also repairs a missing state file (cheap when it is fine).
     */
    private function moduleOff(): ?RmmResponse
    {
        if ($this->state !== null) {
            try {
                $this->state->ensureFile();
            } catch (\Throwable) {
                // the file is only a cache; the answer below comes from the database
            }
        }
        if ($this->state !== null) {
            // the state file answers both switches without database work; the edition is asked only while the file is unknown
            $sw = $this->state->switches();
            $editionAllows = $sw['edition'];
            $master = $sw['master'];
        } else {
            $editionAllows = $this->moduleState === null || $this->moduleState->editionAllows();
            $master = null;
        }
        if ($this->disabledAnswer === self::DISABLED_COMPAT) {
            return $editionAllows ? null : RmmResponse::error(new ApiError(403, 'forbidden', 'The endpoint agent service is disabled.'));
        }
        if ($editionAllows && ($master ?? $this->settings->enabled())) {
            return null;
        }

        return RmmResponse::json(503, ['error' => 'The RMM service is disabled on this server.', 'code' => 'module_disabled'], ['Retry-After' => (string) self::DISABLED_RETRY_AFTER_S]);
    }

    private function featureOff(string $feature): ?RmmResponse
    {
        if ($this->settings->featureOn($feature)) {
            return null;
        }

        return RmmResponse::json(503, ['error' => 'This feature is disabled on this server.', 'code' => 'feature_disabled'], ['Retry-After' => (string) self::DISABLED_RETRY_AFTER_S]);
    }

    private function deviceRateLimit(int $deviceId, string $kind, int $limit, int $window): void
    {
        if (!($this->rateLimit)("agent_{$kind}:$deviceId", $limit, $window)) {
            throw new ApiError(429, 'rate_limited', 'Too many requests.', ['Retry-After' => (string) $window]);
        }
    }

    /** limits_json max_checkins_per_min (0 = off): over the budget the server asks the fleet to come back in 30 to 120 s (503, not 429). */
    private function globalCheckinLimit(): void
    {
        $limits = $this->settings->limits();
        $max = $limits['max_checkins_per_min'];
        if ($max > 0 && !($this->rateLimit)('rmm_checkins_global', $max, 60)) {
            throw new ApiError(503, 'unavailable', 'The service is busy. Try again later.', ['Retry-After' => (string) random_int($limits['retry_after_min_s'], $limits['retry_after_max_s'])]);
        }
    }

    /**
     * Bounded raw body. 413 above $max bytes (declared or actual).
     *
     * @throws ApiError
     */
    private function rawBody(RmmRequest $req, int $max): string
    {
        if ($req->declaredLength !== null && $req->declaredLength > $max) {
            throw new ApiError(413, 'too_large', 'Request body too large.');
        }
        $raw = $req->bodyStream === null ? '' : stream_get_contents($req->bodyStream, $max + 1);
        if ($raw === false || strlen($raw) > $max) {
            throw new ApiError(413, 'too_large', 'Request body too large.');
        }

        return $raw;
    }

    /**
     * Bounded JSON object body. 413 above $max bytes, 422 when it is not a JSON object.
     *
     * @return array<string,mixed>
     * @throws ApiError
     */
    private function body(RmmRequest $req, int $max): array
    {
        $d = json_decode($this->rawBody($req, $max), true);
        if (!is_array($d) || ($d !== [] && array_is_list($d))) {
            throw new ApiError(422, 'invalid', 'A JSON object body is required.');
        }

        /** @var array<string,mixed> $d */
        return $d;
    }
}
