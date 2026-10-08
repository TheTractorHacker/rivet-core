<?php

/**
 * RivetCore RMM pre-bootstrap gate (template; editions copy this file, see docs/rmm/CAPACITY.md "Module switch").
 *
 * Install it as `api/v1/rmm_gate.php` and make it the FIRST statement of the REST entry point, before config.php, before any
 * database connection and before Composer's autoloader:
 *
 *     define('RMM_GATE_STATE_DIR', '/var/lib/yourapp/rmm');   // the directory of RmmModuleStateInterface::stateDirectory()
 *     require __DIR__ . '/rmm_gate.php';
 *     // ... the rest of index.php: config.php, db.php, routing ...
 *
 * (The constant may instead come from the environment variable RMM_GATE_STATE_DIR. With neither, the gate does nothing.)
 *
 * WHAT IT DOES. It reads the module's state file (rmm_state.json, written by RivetCore\Rmm\RmmStateFile) and
 *  - when the module is OFF answers a request for a device endpoint (agent_enroll, agent_checkin, agent_jobs, agent_update,
 *    agent_installer) with exactly:  503, "Retry-After: 3600", "Cache-Control: no-store", "Content-Type: application/json",
 *    {"error":"The RMM service is disabled on this server.","code":"module_disabled"}, then exits. No database
 *    connection, no query, no class loaded: a stat and a read of a few hundred bytes.
 *  - never answers for the technician endpoint (endpoint_devices), on or off. That endpoint is for authenticated users: the edition
 *    authenticates first (401 for a missing or bad token) and TechnicianApi then answers 404 "disabled" when the module is off, so an
 *    anonymous caller cannot learn from the answer whether the module is on. The gate lets it through untouched.
 *  - when load shedding is at level 3 (and the level is younger than 180 s) answers agent_checkin with 503 and a jittered
 *    Retry-After from the shed window of the state file (503 {"code":"unavailable"}); enrollment, job reports and the other
 *    endpoints are never shed.
 *  - otherwise returns and lets the normal request run.
 *
 * FAIL-SAFE. A missing, unreadable, empty, garbled, wrong-type or wrong-version file is "unknown", never "off": the gate returns and the
 * request proceeds on the normal path (which rewrites the file). Only a well-formed version-1 file saying enabled=false turns a
 * request away. The reader below mirrors RmmStateFile::read(); tests/Integration/Rmm/ModuleSwitchTest.php keeps them in step.
 *
 * It never emits CORS headers: device agents are not browsers. Keep it free of includes, classes and database calls.
 */

(static function (): void {
    $dir = defined('RMM_GATE_STATE_DIR') ? constant('RMM_GATE_STATE_DIR') : getenv('RMM_GATE_STATE_DIR');
    if (!is_string($dir) || $dir === '') {
        return;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = is_string($uri) ? parse_url($uri, PHP_URL_PATH) : null;
    if (!is_string($path) || preg_match('#(?:^|/)(agent_enroll|agent_checkin|agent_jobs|agent_update|agent_installer)(?:\.php)?(?:/|$)#', $path, $m) !== 1) {
        return;
    }
    $raw = @file_get_contents(rtrim($dir, '/\\') . '/rmm_state.json', false, null, 0, 65536);
    $s = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $ok = is_array($s) && ($s['v'] ?? null) === 1 && is_bool($s['enabled'] ?? null) && is_bool($s['edition'] ?? null) && is_bool($s['master'] ?? null)
        && is_array($s['features'] ?? null) && is_array($s['limits'] ?? null) && is_int($s['shed'] ?? null) && $s['shed'] >= 0 && $s['shed'] <= 3
        && is_int($s['shed_at'] ?? null) && is_int($s['retry_after'] ?? null) && is_int($s['written_at'] ?? null) && in_array($s['ingest_mode'] ?? null, ['sync', 'queued'], true)
        && is_array($s['shed_retry'] ?? null) && count($s['shed_retry']) === 2 && is_int($s['shed_retry'][0] ?? null) && is_int($s['shed_retry'][1] ?? null);
    if ($ok) {
        foreach ($s['features'] as $k => $v) {
            $ok = $ok && is_string($k) && is_bool($v);
        }
        foreach ($s['limits'] as $k => $v) {
            $ok = $ok && is_string($k) && is_int($v);
        }
    }
    if (!$ok) {
        return;   // unknown: proceed, never "off"
    }
    $endpoint = $m[1];
    if (!$s['enabled']) {
        $status = 503;
        $body = '{"error":"The RMM service is disabled on this server.","code":"module_disabled"}';
        header('Retry-After: ' . max(1, $s['retry_after']));
    } elseif ($endpoint === 'agent_checkin' && $s['shed'] >= 3 && time() - $s['shed_at'] <= 180) {
        $status = 503;
        $body = '{"error":"The service is busy. Try again later.","code":"unavailable"}';
        $lo = max(1, min($s['shed_retry'][0], $s['shed_retry'][1]));
        $hi = max($lo, $s['shed_retry'][1]);
        header('Retry-After: ' . random_int($lo, $hi));
    } else {
        return;
    }
    http_response_code($status);
    header('Cache-Control: no-store');
    header('Content-Type: application/json');
    header('Content-Length: ' . strlen($body));
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        echo $body;
    }
    exit;
})();
