<?php

declare(strict_types=1);

/*
 * Test helper for AuditChainConcurrencyTest: a separate PHP process (its own database connection) that writes N chained audit events.
 *   php audit_chain_worker.php <worker-label> <count> [<hmac-key-hex>]
 * Exits 0 when every write returned, 1 on any exception. Needs the RIVETCORE_TEST_DB_* environment.
 */

require __DIR__ . '/../../vendor/autoload.php';

use RivetCore\Audit\AuditChainWriter;
use RivetCore\Audit\AuditService;
use RivetCore\Support\NullRequestContext;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

[, $label, $count, $keyHex] = $argv + [null, 'w', '0', ''];
$m = ScratchDb::connect();
if ($m === null) {
    fwrite(STDERR, "no scratch database\n");
    exit(1);
}
$db = new MysqliDatabase($m);
$key = $keyHex !== '' ? hex2bin($keyHex) : null;
$audit = new AuditService($db, new NullRequestContext(), null, new AuditChainWriter($db, $key === false ? null : $key));
try {
    for ($i = 0; $i < (int) $count; $i++) {
        $audit->log('test.concurrent', 1, 'worker', $label, 'write', "$label #$i", ['i' => $i]);
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
exit(0);
