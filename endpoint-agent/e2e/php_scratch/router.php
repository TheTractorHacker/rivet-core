<?php

declare(strict_types=1);

/**
 * php -S router for the REAL-agent proof of Phases 6 and 8 (see e2e/run_real_agent_proof.sh): serves the device endpoints and the technician API of
 * RivetCore\Rmm on a SCRATCH database, the way an edition's api/v1 front controller would. It is a test fixture, never a deployment:
 * the database name must contain "scratch", the technician is the fixed administrator token of scripts/rmm-golden/constants.php, and TLS is
 * decided from X-Forwarded-Proto set by the TLS proxy in front of it.
 *
 *   RIVETCORE_TEST_DB_* (scratch), RMM_GOLDEN_STATE=<dir>
 *   php -S 127.0.0.1:8680 e2e/php_scratch/router.php
 */

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Http\SapiEmitter;
use RivetCore\Tests\Support\GoldenEdition;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$K = require $root . '/scripts/rmm-golden/constants.php';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#^/api/v1/([a-z_]+)(?:/(.*))?$#', $path, $m) !== 1) {
    http_response_code(404);
    echo 'not found';

    return true;
}
$resource = $m[1];
$rest = $m[2] ?? '';
$deviceEndpoints = ['agent_enroll', 'agent_checkin', 'agent_jobs', 'agent_update', 'agent_installer', 'agent_transfer', 'agent_logs'];

mysqli_report(MYSQLI_REPORT_OFF);
$edition = new GoldenEdition((string) getenv('RMM_GOLDEN_STATE'), false);
register_shutdown_function(static function () use ($edition): void {
    $edition->save();
});
$headers = [];
foreach ((function_exists('getallheaders') ? getallheaders() : []) as $k => $v) {
    $headers[strtolower((string) $k)] = (string) $v;
}
$bearer = $headers['authorization'] ?? null;
$query = array_filter($_GET, 'is_string');
$declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
while (ob_get_level() > 0) {
    @ob_end_clean();
}

if ($resource === 'endpoint_devices') {
    $token = $bearer !== null && preg_match('/^Bearer\s+(\S+)$/i', $bearer, $b) === 1 ? $b[1] : null;
    $who = $token !== null && hash_equals($K['admin_api_token'], $token) ? new RmmPrincipal(GoldenEdition::ADMIN_USER_ID, 'proof-admin') : null;
    $req = $edition->request((string) $_SERVER['REQUEST_METHOD'], 'endpoint_devices', $query, $headers, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), true, $declared, $rest === '' ? [] : explode('/', $rest));
    (new SapiEmitter())->emit($edition->module->technicianApi()->handle($req, $who));

    return true;
}
if (!in_array($resource, $deviceEndpoints, true)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"Not found"}';

    return true;
}
$peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$secure = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';   // the TLS proxy says so; this fixture trusts it
$req = $edition->request((string) $_SERVER['REQUEST_METHOD'], $resource, $query, $headers, $peer, $secure, $declared);
(new SapiEmitter())->emit($edition->api->handle($req));

return true;
