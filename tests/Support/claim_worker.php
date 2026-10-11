<?php

declare(strict_types=1);

/**
 * A job-claiming worker process for tests/Integration/Rmm/IngestScaleTest.php: claims batches of `rmm.ingest` jobs with JobClaimer until none are due, marks them
 * completed, and writes the claimed job ids (one per line) to the file named by argv[1]. argv[2] = strategy (skip_locked|lease|auto), argv[3] = batch size, argv[4] = max running.
 * Uses the scratch database named by RIVETCORE_TEST_DB_*.
 */

use RivetCore\Jobs\JobClaimer;
use RivetCore\Jobs\JobQueue;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$file = (string) ($argv[1] ?? '');
$strategy = ($argv[2] ?? 'auto') === 'auto' ? null : (string) $argv[2];
$batch = (int) ($argv[3] ?? 10);
$maxRunning = (int) ($argv[4] ?? 0);
$m = ScratchDb::connect();
if ($m === null || !str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch') || $file === '') {
    fwrite(STDERR, "refused\n");
    exit(2);
}
$db = new MysqliDatabase($m);
$queue = new JobQueue($db);
$claimer = new JobClaimer($db, $queue->supportsHeartbeat(), $strategy);
$out = fopen($file, 'ab');
$idle = 0;
while ($idle < 3) {
    $jobs = $claimer->claim($batch, 'rmm.ingest', $maxRunning);
    if ($jobs === []) {
        ++$idle;
        usleep(20000);
        continue;
    }
    $idle = 0;
    foreach ($jobs as $j) {
        fwrite($out, $j['job_id'] . "\n");
    }
    usleep(random_int(1000, 8000));   // "work"
    foreach ($jobs as $j) {
        $queue->markCompleted((int) $j['job_id'], ['by' => getmypid()], (int) $j['attempts']);
    }
}
fclose($out);
echo $claimer->strategy(), "\n";
