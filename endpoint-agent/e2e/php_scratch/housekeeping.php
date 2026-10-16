<?php

declare(strict_types=1);

/**
 * Runs one housekeeping pass of the scratch edition (what an edition's cron entry would do) and prints its counters as JSON. Used by the patch proof
 * (e2e/run_patch_proof.sh) to show that the PatchScheduler, not a person, queues scans and installs.
 *
 *   RIVETCORE_TEST_DB_* (scratch), RMM_GOLDEN_STATE=<dir>  php housekeeping.php
 */

use RivetCore\Tests\Support\GoldenEdition;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
    fwrite(STDERR, "Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
$e = new GoldenEdition((string) getenv('RMM_GOLDEN_STATE'), false);
$out = $e->module->housekeeping()->run();
$e->save();
echo json_encode($out), "\n";
