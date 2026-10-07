<?php

declare(strict_types=1);

/**
 * php -S router that serves the device API of RivetCore\Rmm\Http\DeviceApi on a scratch database, for the golden-transcript replay.
 *
 *   RIVETCORE_TEST_DB_* (scratch), RMM_GOLDEN_STATE=<dir>, RMM_GOLDEN_INSECURE=1|0, [RIVETCORE_TEST_REDIS_PORT]
 *   php -S 127.0.0.1:8680 scripts/rmm-golden/core-router.php
 *
 * It plays the part of an edition's api/v1 front controller and bridge files: builds the RmmRequest (including the trusted-proxy TLS
 * decision), hands it to Core, emits the RmmResponse and adds the edition's CORS headers to JSON responses (see _meta.json
 * edition_headers_not_produced_by_core). The technician endpoints are served by Core's TechnicianApi behind a stub AccessPolicy (the shared test token is administrator #2).
 */

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Http\SapiEmitter;
use RivetCore\Tests\Support\GoldenEdition;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$K = require __DIR__ . '/constants.php';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#^/api/v1/([a-z_]+)(?:/(.*))?$#', $path, $m) !== 1) {
    http_response_code(404);
    echo 'not found';

    return true;
}
$resource = $m[1];
$rest = $m[2] ?? '';
$deviceEndpoints = ['agent_enroll', 'agent_checkin', 'agent_jobs', 'agent_update', 'agent_installer'];

/** The edition's CORS headers (api/v1/index.php adds them to JSON responses; downloads do not carry them). */
$cors = static function (): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Biometric, X-Api-Key');
};

mysqli_report(MYSQLI_REPORT_OFF);
$state = (string) getenv('RMM_GOLDEN_STATE');
$edition = new GoldenEdition($state, getenv('RMM_GOLDEN_INSECURE') === '1');
register_shutdown_function(static function () use ($edition): void {
    $edition->save();
});

$headers = [];
foreach ((function_exists('getallheaders') ? getallheaders() : []) as $k => $v) {
    $headers[strtolower((string) $k)] = (string) $v;
}
$bearer = $headers['authorization'] ?? null;

if ($resource === 'endpoint_devices') {
    // The edition authenticates the user (here: the shared test token is administrator #2) and hands Core the principal.
    $token = $bearer !== null && preg_match('/^Bearer\s+(\S+)$/i', $bearer, $b) === 1 ? $b[1] : null;
    $who = $token !== null && hash_equals($K['admin_api_token'], $token) ? new RmmPrincipal(GoldenEdition::ADMIN_USER_ID, 'golden-admin') : null;
    $req = $edition->request((string) $_SERVER['REQUEST_METHOD'], 'endpoint_devices', array_filter($_GET, 'is_string'), $headers, (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        true, isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null, $rest === '' ? [] : explode('/', $rest));
    $resp = $edition->module->technicianApi()->handle($req, $who);
    $cors();
    (new SapiEmitter())->emit($resp);

    return true;
}
if (!in_array($resource, $deviceEndpoints, true)) {
    http_response_code(404);
    $cors();
    header('Content-Type: application/json');
    echo '{"error":"Not found"}';

    return true;
}

// TLS decision stays in the edition: https, port 443, or a loopback/private TLS-terminating proxy that says so.
$https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
$port443 = (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
$peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$peerIsLocal = filter_var($peer, FILTER_VALIDATE_IP) !== false && filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
$proxied = $peerIsLocal && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$secure = $https || $port443 || $proxied;

$query = array_filter($_GET, 'is_string');
$declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
$req = $edition->request((string) $_SERVER['REQUEST_METHOD'], $resource, $query, $headers, $peer, $secure, $declared);
$resp = $edition->api->handle($req);
$cors();   // the recorded transcripts show the CORS headers on downloads too
while (ob_get_level() > 0) {
    @ob_end_clean();
}
(new SapiEmitter())->emit($resp);

return true;
