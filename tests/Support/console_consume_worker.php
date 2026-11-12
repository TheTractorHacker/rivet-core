<?php

declare(strict_types=1);

/**
 * One racer of tests/Integration/Rmm/ConsoleRedeemTest.php: waits until argv[2] (a unix time with microseconds), then tries to consume the redeem code whose SHA-256 is argv[1]
 * with ConsoleStore::consume() on the scratch database named by RIVETCORE_TEST_DB_*, and prints 1 when it won, 0 when it lost.
 */

use RivetCore\Rmm\Remote\ConsoleStore;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;
use RivetCore\Support\SystemClock;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$hash = (string) ($argv[1] ?? '');
$startAt = (float) ($argv[2] ?? 0);
$m = ScratchDb::connect();
if ($m === null || !str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch') || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
    fwrite(STDERR, "refused\n");
    exit(2);
}
$store = new ConsoleStore(new Sql(new MysqliDatabase($m), new SystemClock()));
while (microtime(true) < $startAt) {
    usleep(200);
}
echo $store->consume($hash) ? '1' : '0';
