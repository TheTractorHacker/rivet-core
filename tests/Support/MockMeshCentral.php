<?php

/*
 * Minimal stand-in for a MeshCentral server, run by `php -S` (see MockMeshServer): it implements ONLY what the module calls,
 * GET /health.ashx. MOCK_MESH_MODE=ok (default) answers 200, error answers 500, slow stalls past the module's timeout. Never a real
 * MeshCentral. (Router script, not a class: no namespace, no declare.)
 */
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$mode = getenv('MOCK_MESH_MODE') ?: 'ok';
if ($path === '/health.ashx') {
    if ($mode === 'slow') {
        sleep(12);
    }
    if ($mode === 'error') {
        http_response_code(500);
        echo 'unhealthy';

        return true;
    }
    echo 'OK';

    return true;
}
http_response_code(404);
echo 'not found';

return true;
