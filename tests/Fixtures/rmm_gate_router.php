<?php

declare(strict_types=1);

/**
 * php -S router for the gate tests: the gate template first, then (only when it lets the request through) a real database round trip
 * on the scratch database so the test can see the server's own counters move. Never reached for a request the gate turns away.
 *
 *   RMM_GATE_STATE_DIR=<dir> RIVETCORE_TEST_DB_* php -S 127.0.0.1:<port> tests/Fixtures/rmm_gate_router.php
 */

require dirname(__DIR__, 2) . '/docs/rmm/templates/rmm_gate.php';

mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', (string) getenv('RIVETCORE_TEST_DB_USER'), (string) getenv('RIVETCORE_TEST_DB_PASS'), (string) getenv('RIVETCORE_TEST_DB_NAME'), (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 0));
$ok = !$m->connect_errno && $m->query('SELECT COUNT(*) FROM endpoint_agent_settings') !== false;
header('Content-Type: application/json');
echo json_encode(['passed' => true, 'db' => $ok]);

return true;
