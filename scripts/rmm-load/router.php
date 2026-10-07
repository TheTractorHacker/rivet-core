<?php

declare(strict_types=1);

/**
 * php -S router serving the device API of RivetCore\Rmm\Http\DeviceApi on a scratch database for the load measurements (S1). A minimal
 * edition front controller: the same job as an edition's api/v1 bridge files, with the pre-bootstrap gate in front.
 *
 *   RIVETCORE_TEST_DB_* (scratch), [RIVETCORE_TEST_REDIS_PORT], [RMM_LOAD_STATE_DIR], [RMM_LOAD_TRUST_SIM_IP=1]
 *   PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8700 scripts/rmm-load/router.php
 *
 * With RMM_LOAD_TRUST_SIM_IP=1 the client address of a request is taken from the X-Sim-Client-Ip header (rmm-sim -client-ip-header), so one
 * simulator host does not trip the per-IP enrollment limiter. Test harness only; nothing of this belongs in an edition.
 */

use RivetCore\Rmm\Http\SapiEmitter;
use RivetCore\Tests\Support\LoadEdition;

if (getenv('RMM_LOAD_STATE_DIR')) {
    define('RMM_GATE_STATE_DIR', (string) getenv('RMM_LOAD_STATE_DIR'));
    require dirname(__DIR__, 2) . '/docs/rmm/templates/rmm_gate.php';
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#^/api/v1/(agent_enroll|agent_checkin|agent_jobs|agent_update|agent_installer)$#', $path, $m) !== 1) {
    http_response_code(404);
    echo '{"error":"Not found"}';

    return true;
}
$edition = new LoadEdition();
$headers = [];
foreach (getallheaders() as $k => $v) {
    $headers[strtolower((string) $k)] = (string) $v;
}
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (getenv('RMM_LOAD_TRUST_SIM_IP') === '1' && !empty($headers['x-sim-client-ip'])) {
    $ip = $headers['x-sim-client-ip'];
}
$declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
$req = new \RivetCore\Rmm\Http\RmmRequest((string) $_SERVER['REQUEST_METHOD'], $m[1], [], array_filter($_GET, 'is_string'), $headers, $ip, $headers['user-agent'] ?? null, true, $declared, fopen('php://input', 'rb') ?: null);
(new SapiEmitter())->emit($edition->api->handle($req));

return true;
