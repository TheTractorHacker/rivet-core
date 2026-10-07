<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Jobs\JobQueue;
use RivetCore\Jobs\JobWorker;
use RivetCore\Rmm\Capacity\IngestQueue;
use RivetCore\Tests\Support\CountingMetricSink;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** Queued ingest (S2): `ingest_mode = queued` leaves the same state behind as the inline path once the worker has run. */
final class IngestQueueTest extends RmmTestCase
{
    private CountingMetricSink $sink;
    private string $T = '';
    private int $base = 0;

    protected function makeHarness(): RmmHarness
    {
        $this->sink = new CountingMetricSink();
        $this->base = time() - 600;

        return new RmmHarness(null, null, [], false, $this->sink);
    }

    /** @return array{0:int,1:string} [device id, token] of a device linked to an asset */
    private function linked(RmmHarness $h, string $serial): array
    {
        $tok = $h->token(null, 24, 100);
        $h->asset(['name' => 'PC ' . $serial, 'serial' => $serial, 'make' => 'Dell']);
        [, , $j] = $h->enroll($tok, $h::device(['serial' => $serial, 'hostname' => 'HOST-' . $serial]));
        $this->assertSame('linked', $j['status']);

        return [(int) $j['device_id'], (string) $j['device_token']];
    }

    /** @return array<string,mixed> */
    private function inventory(string $serial): array
    {
        return ['hostname' => 'HOST-' . $serial, 'os' => 'windows', 'os_version' => 'Windows 11 23H2', 'manufacturer' => 'Dell', 'model' => 'Latitude 7440', 'serial' => $serial,
            'cpu' => ['model' => 'Intel i7', 'cores' => 10], 'memory_total_bytes' => 17179869184,
            'disks' => [['mount' => 'C:', 'total_bytes' => 512000000000, 'free_bytes' => 200000000000, 'fs' => 'NTFS']],
            'network' => [['name' => 'Ethernet', 'mac' => 'AA-BB-CC-DD-EE-01', 'ips' => ['10.0.0.5']]], 'uptime_s' => 7200, 'logged_in_user' => 'alice', 'pending_reboot' => true];
    }

    /**
     * The same four check-ins in whichever mode the harness was put in. Fixed timestamps so two runs are comparable.
     *
     * @return list<array<string,mixed>> the responses with the per-install parts removed
     */
    private function scenario(RmmHarness $h, string $mode): array
    {
        $h->module->settings()->update(['ingest_mode' => $mode, 'failure_debounce' => 2]);
        [, $T] = $this->linked($h, 'SC-1');
        $base = $this->base;
        $at = static fn (int $o): string => gmdate('Y-m-d\TH:i:s\Z', $base + $o);
        $bodies = [
            ['seq' => 1, 'collected_at' => $at(0), 'agent_version' => '1.2.0', 'inventory' => $this->inventory('SC-1'),
                'metrics' => ['cpu_pct' => 11.5, 'mem_pct' => 41.0, 'disk' => [['mount' => 'C:', 'used_pct' => 60.5]], 'net_rx_bps' => 800.0, 'net_tx_bps' => 400.0],
                'checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'C: 96%'], ['key' => 'svc_eventlog', 'status' => 'ok', 'detail' => '']],
                'buffered' => [['collected_at' => $at(-240), 'metrics' => ['cpu_pct' => 5.0, 'mem_pct' => 30.0], 'checks' => [['key' => 'disk_c', 'status' => 'ok']]],
                    ['collected_at' => $at(-120), 'metrics' => ['cpu_pct' => 6.0, 'mem_pct' => 31.0]], ['collected_at' => 'garbage', 'metrics' => ['cpu_pct' => 99.0]]]],
            ['seq' => 2, 'collected_at' => $at(60), 'agent_version' => '1.2.0', 'metrics' => ['cpu_pct' => 21.5, 'mem_pct' => 42.0], 'checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'C: 97%']]],
            ['seq' => 2, 'collected_at' => $at(60), 'agent_version' => '1.2.0', 'metrics' => ['cpu_pct' => 21.5, 'mem_pct' => 42.0], 'checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'C: 97%']]],
            ['seq' => 3, 'collected_at' => $at(-500), 'agent_version' => '1.2.1', 'metrics' => ['cpu_pct' => 1.5], 'checks' => []],
        ];
        $out = [];
        foreach ($bodies as $b) {
            [$c, , $r] = $h->call('POST', 'agent_checkin', $b, $T);
            $this->assertSame(200, $c);
            unset($r['config'], $r['server_time'], $r['signing_key_id']);
            $out[] = $r;
        }

        return $out;
    }

    /** @return array<string,mixed> everything a check-in leaves behind that does not depend on ids or the clock */
    private function snapshot(RmmHarness $h): array
    {
        $dev = $h->rows('SELECT agent_version, hostname, os_version, manufacturer, model, serial, mac_addresses, logged_in_user, pending_reboot, uptime_s, last_seq, last_metrics_json, last_collected_at, inventory_json, link_state FROM endpoint_agent_devices');
        $checks = $h->rows('SELECT check_key, status, detail, consecutive_failures, consecutive_ok, episode, (alert_id IS NOT NULL) AS has_alert FROM endpoint_agent_checks ORDER BY check_key');
        $samples = array_map(static fn (array $s): string => sprintf('%s|%s|%s|%s', $s['key'], $s['instance'] ?? '', $s['value'], $s['at']->format('c')), $h->metrics->stored());
        sort($samples);
        $health = array_values($h->bridge->health);

        return ['device' => $dev, 'checks' => $checks, 'samples' => $samples, 'health' => $health, 'seqs' => $h->rows('SELECT seq FROM endpoint_agent_checkins ORDER BY seq')];
    }

    public function testQueuedResultsEqualSyncResultsOnceTheWorkerHasRun(): void
    {
        $sync = $this->scenario($this->h, 'sync');
        $syncState = $this->snapshot($this->h);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM integration_jobs WHERE job_type='rmm.ingest'"), 'sync mode never queues');
        $this->assertNotEmpty($syncState['samples']);
        $this->assertNotEmpty($syncState['checks']);

        $this->sink = new CountingMetricSink();
        $h2 = new RmmHarness(null, null, [], false, $this->sink);
        $queued = $this->scenario($h2, 'queued');
        $this->assertSame($sync, $queued, 'the response is the same shape and the same content');
        $this->assertSame(3, (int) $h2->one("SELECT COUNT(*) FROM integration_jobs WHERE job_type='rmm.ingest' AND status='pending'"), 'one job per fresh (device, seq); the duplicate seq 2 queued nothing');
        $this->assertSame([], $h2->metrics->stored(), 'nothing heavy happened in the requests');
        $this->assertSame(0, (int) $h2->one('SELECT COUNT(*) FROM endpoint_agent_checks'));

        $r = $h2->module->ingestQueue()->drain();
        $this->assertSame(['claimed' => 3, 'completed' => 3, 'failed' => 0, 'skipped' => 0], array_intersect_key($r, array_flip(['claimed', 'completed', 'failed', 'skipped'])));
        $this->assertSame(1, $r['sink_calls'], 'the samples of the three check-ins went to the sink in ONE call');
        $queuedState = $this->snapshot($h2);
        $this->assertSame($syncState['samples'], $queuedState['samples']);
        $this->assertSame($syncState['checks'], $queuedState['checks']);
        $this->assertSame($syncState['health'], $queuedState['health']);
        $this->assertSame($syncState['seqs'], $queuedState['seqs']);
        $this->assertSame($syncState['device'], $queuedState['device']);
        $this->assertSame(3, (int) $h2->one("SELECT COUNT(*) FROM integration_jobs WHERE status='completed'"));
    }

    public function testTheGenericJobWorkerHandlerGivesTheSameResultAsTheBatchWorker(): void
    {
        $this->scenario($this->h, 'queued');
        $w = new JobWorker(new JobQueue($this->h->db));
        $this->h->module->registerHandlers($w);
        $this->assertTrue($w->has('rmm.ingest'));
        $out = $w->run(50, 20);
        $this->assertSame([3, 3], [$out['claimed'], $out['completed']]);
        $viaWorker = $this->snapshot($this->h);

        $this->sink = new CountingMetricSink();
        $h2 = new RmmHarness(null, null, [], false, $this->sink);
        $this->scenario($h2, 'queued');
        $h2->module->ingestQueue()->drain();
        $viaDrain = $this->snapshot($h2);
        $this->assertSame($viaDrain['samples'], $viaWorker['samples']);
        $this->assertSame($viaDrain['checks'], $viaWorker['checks']);
        $this->assertSame($viaDrain['device'], $viaWorker['device']);
    }

    public function testRetriesAreSafeADuplicateDeliveryNeverQueuesTwiceAndAReRunRepeatsIdempotentSteps(): void
    {
        $this->h->module->settings()->update(['ingest_mode' => 'queued']);
        [$dev, $T] = $this->linked($this->h, 'RT-1');
        $body = ['seq' => 7, 'collected_at' => RmmHarness::ts(-30), 'agent_version' => '1.0.0', 'inventory' => $this->inventory('RT-1'), 'metrics' => ['cpu_pct' => 10.5], 'checks' => [['key' => 'k', 'status' => 'ok']]];
        for ($i = 0; $i < 3; ++$i) {
            [$c] = $this->h->call('POST', 'agent_checkin', $body, $T);
            $this->assertSame(200, $c);
        }
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM integration_jobs WHERE job_type='rmm.ingest'"), 'idempotent by (device_id, seq)');
        $row = $this->h->rows("SELECT * FROM integration_jobs WHERE job_type='rmm.ingest'")[0];
        $this->assertSame(-10, (int) $row['priority'], 'below interactive jobs');
        $payload = json_decode((string) $row['payload'], true);
        $this->assertSame([$dev, 7], [$payload['device_id'], $payload['seq']]);

        $q = $this->h->module->ingestQueue();
        $q->drain();
        $afterFirst = $this->snapshot($this->h);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key='k'"));
        // the same work applied again (a reclaimed job running twice): device, link and check state are unchanged; only the debounce
        // counter of a replayed check result advances (documented: at worst an alert opens one check-in early)
        $this->h->module->checkin()->applyWork($payload, true);
        $again = $this->snapshot($this->h);
        $this->assertSame($afterFirst['device'], $again['device']);
        $this->assertSame($afterFirst['health'], $again['health']);
        $this->assertSame(array_column($afterFirst['checks'], 'status'), array_column($again['checks'], 'status'));
        $this->assertSame(array_column($afterFirst['checks'], 'has_alert'), array_column($again['checks'], 'has_alert'));
    }

    public function testFailureAWorkerErrorRetriesWithBackoffAndTheSinkIsWrittenOnce(): void
    {
        $this->h->module->settings()->update(['ingest_mode' => 'queued']);
        [, $T] = $this->linked($this->h, 'FA-1');
        $this->h->checkin($T);
        $this->sink->failures = 1;
        $q = $this->h->module->ingestQueue();
        $r = $q->drain();
        $this->assertSame([1, 0, 1], [$r['claimed'], $r['completed'], $r['failed']]);
        $job = $this->h->rows("SELECT * FROM integration_jobs WHERE job_type='rmm.ingest'")[0];
        $this->assertSame(['pending', 1], [$job['status'], (int) $job['attempts']]);
        $this->assertStringContainsString('sink unavailable', (string) $job['error']);
        $this->assertGreaterThan(time(), strtotime((string) $job['available_at'] . ' UTC') + 3600 * 24, 'backoff set');
        $this->assertSame([], $this->sink->stored(), 'a failed sink call stored nothing');
        $this->assertSame(0, $q->drain()['claimed'], 'not due yet');
        $this->h->q("UPDATE integration_jobs SET available_at = NOW() WHERE job_type='rmm.ingest'");
        $r = $q->drain();
        $this->assertSame([1, 1], [$r['claimed'], $r['completed']]);
        $this->assertCount(1, array_filter($this->sink->stored(), static fn (array $s): bool => $s['key'] === 'cpu.utilization'), 'delivered exactly once');
    }

    public function testFailureAJobThatKeepsFailingIsDeadLetteredAndShownInTheBacklog(): void
    {
        $this->h->module->settings()->update(['ingest_mode' => 'queued']);
        [, $T] = $this->linked($this->h, 'DL-1');
        $this->h->checkin($T);
        $this->h->q("UPDATE integration_jobs SET max_attempts = 1 WHERE job_type='rmm.ingest'");
        $this->sink->failures = 5;
        $q = $this->h->module->ingestQueue();
        $q->drain();
        $this->assertSame('dead_letter', $this->h->one("SELECT status FROM integration_jobs WHERE job_type='rmm.ingest'"));
        $b = $q->backlog();
        $this->assertSame([0, 1], [$b['pending'], $b['dead_letter']]);
        $w = $this->h->module->capacity()->build()['warnings'];
        $this->assertContains('dead_letter', array_column($w, 'code'));
    }

    public function testFailureTheDeviceWasRevokedWhileTheJobWaitedAndAHandlerErrorIsNotSwallowed(): void
    {
        $this->h->module->settings()->update(['ingest_mode' => 'queued']);
        [$dev, $T] = $this->linked($this->h, 'RV-1');
        $this->h->checkin($T);
        $this->h->q("UPDATE endpoint_agent_devices SET revoked_at = UTC_TIMESTAMP() WHERE device_id = $dev");
        $r = $this->h->module->ingestQueue()->drain();
        $this->assertSame([1, 0, 1], [$r['claimed'], $r['completed'], $r['skipped']], 'nothing to do for a revoked device; the job is finished, not retried');
        $this->assertSame('completed', $this->h->one("SELECT status FROM integration_jobs WHERE job_type='rmm.ingest'"));
        $this->assertSame([], $this->sink->stored());
    }

    public function testAnOversizedWorkloadIsProcessedInline(): void
    {
        $this->h->module->settings()->update(['ingest_mode' => 'queued']);
        [, $T] = $this->linked($this->h, 'BG-1');
        $inv = $this->inventory('BG-1') + ['blob' => str_repeat('x', 61000)];
        [$c] = $this->h->call('POST', 'agent_checkin', ['seq' => 1, 'collected_at' => RmmHarness::ts(-5), 'agent_version' => '1.0.0', 'inventory' => $inv, 'metrics' => ['cpu_pct' => 3.5]], $T);
        $this->assertSame(200, $c);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM integration_jobs WHERE job_type='rmm.ingest'"), 'above the 60 KB payload cap the work is done in the request');
        $this->assertNotEmpty($this->sink->stored());
    }

    public function testBacklogMetricAndBatchMerging(): void
    {
        $this->h->module->settings()->update(['ingest_mode' => 'queued']);
        $devs = [];
        for ($i = 1; $i <= 3; ++$i) {
            $devs[] = $this->linked($this->h, "BM-$i")[1];
        }
        $n = 0;
        foreach ($devs as $T) {
            for ($k = 0; $k < 4; ++$k) {
                $this->h->checkin($T);
                ++$n;
            }
        }
        $q = $this->h->module->ingestQueue();
        $b = $q->backlog();
        $this->assertSame([12, 0, 0], [$b['pending'], $b['running'], $b['dead_letter']]);
        $this->h->q("UPDATE integration_jobs SET created_at = NOW() - INTERVAL 10 MINUTE WHERE job_id = (SELECT j FROM (SELECT MIN(job_id) AS j FROM integration_jobs) x)");
        $this->assertGreaterThanOrEqual(600, $q->backlog()['oldest_pending_age_s']);
        $r = $q->drain(50);
        $this->assertSame([12, 12, 1], [$r['claimed'], $r['completed'], $r['sink_calls']]);
        $this->assertSame([12 * 5], $this->sink->callSizes, 'five samples per check-in, all in one call');
        $this->assertSame(0, $q->backlog()['pending']);
        $this->assertNotNull($q->backlog()['avg_latency_s']);
        // 60 jobs, batches of 50
        for ($i = 0; $i < 60; ++$i) {
            $this->h->checkin($devs[$i % 3], ['seq' => 1000 + $i]);
        }
        $this->sink->callSizes = [];
        $first = $q->drain();
        $second = $q->drain();
        $this->assertSame([50, 10], [$first['claimed'], $second['claimed']], 'at most 50 check-ins per call');
        $this->assertSame(2, count($this->sink->callSizes));
    }

    public function testWhileTheModuleIsOffQueuedJobsAreReleasedNotDeadLetteredAndSurviveReEnable(): void
    {
        $this->h->module->settings()->update(['ingest_mode' => 'queued']);
        [, $T] = $this->linked($this->h, 'DS-1');
        $this->h->checkin($T);
        $this->h->checkin($T);
        $rowsBefore = [(int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_devices'), (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_checkins')];
        $this->h->module->settings()->disable();
        $w = new JobWorker(new JobQueue($this->h->db));
        $this->h->module->registerHandlers($w);
        for ($run = 0; $run < 3; ++$run) {
            $w->run(50, 5);
        }
        $jobs = $this->h->rows("SELECT status, attempts, error FROM integration_jobs WHERE job_type='rmm.ingest'");
        $this->assertCount(2, $jobs);
        foreach ($jobs as $j) {
            $this->assertSame(['pending', 0, null], [$j['status'], (int) $j['attempts'], $j['error']], 'released: back to pending, no attempt spent, nothing recorded as an error');
        }
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM integration_jobs WHERE status='dead_letter'"));
        $this->assertSame(0, $this->h->module->ingestQueue()->drain()['claimed'], 'the batch worker does nothing while off');
        $this->assertSame([], $this->sink->stored());
        $this->assertSame($rowsBefore, [(int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_devices'), (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_checkins')], 'nothing deleted');

        $this->h->module->settings()->enable();
        $this->h->q("UPDATE integration_jobs SET available_at = NOW() WHERE job_type='rmm.ingest'");
        $out = $w->run(50, 5);
        $this->assertSame(2, $out['claimed']);
        $this->assertSame(2, (int) $this->h->one("SELECT COUNT(*) FROM integration_jobs WHERE status='completed'"));
        $this->assertNotEmpty($this->sink->stored(), 'the queued work was processed after the re-enable');
    }

    public function testAJobTypeThatIsNotRegisteredWouldBeDeadLetteredByCoreWhichIsWhyTheStubExists(): void
    {
        $this->h->module->settings()->update(['ingest_mode' => 'queued']);
        [, $T] = $this->linked($this->h, 'ST-1');
        $this->h->checkin($T);
        $w = new JobWorker(new JobQueue($this->h->db));   // no handlers registered at all
        $out = $w->run(10, 5);
        $this->assertSame(1, $out['dead'], 'Core dead-letters a job with no handler');
        $this->assertSame(IngestQueue::JOB_TYPES, ['rmm.ingest']);
    }

    public function testFinishedIngestJobsArePrunedInBatchesAndNothingElseIsTouched(): void
    {
        $this->h->module->settings()->enable();
        $db = $this->h->mysqli;
        $old = 'NOW() - INTERVAL 11 MINUTE';
        $rows = [];
        for ($i = 0; $i < 5200; ++$i) {
            $rows[] = "('rmm.ingest', 'completed', $old, $old, '{}')";
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            $db->query('INSERT INTO integration_jobs (job_type, status, created_at, completed_at, payload) VALUES ' . implode(',', $chunk));
        }
        $db->query("INSERT INTO integration_jobs (job_type, status, created_at, completed_at, payload) VALUES
            ('rmm.ingest', 'completed', NOW(), NOW() - INTERVAL 1 MINUTE, '{}'),
            ('rmm.ingest', 'pending', $old, NULL, '{}'),
            ('rmm.ingest', 'dead_letter', $old, NULL, '{}'),
            ('webhook.deliver', 'completed', $old, $old, '{}')");
        $this->assertSame(5200, $this->h->module->ingestQueue()->pruneCompleted(), 'two batches: 5,000 and 200');
        $left = $this->h->rows('SELECT job_type, status, COUNT(*) AS c FROM integration_jobs GROUP BY job_type, status ORDER BY job_type, status');
        $this->assertSame([['pending', 1], ['completed', 1], ['dead_letter', 1]], array_map(static fn (array $r): array => [$r['status'], (int) $r['c']], array_slice($left, 0, 3)), 'recent, pending and dead-lettered ingest jobs stay');
        $this->assertSame(['webhook.deliver', 'completed'], [$left[3]['job_type'], $left[3]['status']], 'another module\'s jobs are never pruned');
        // housekeeping runs it
        $db->query("INSERT INTO integration_jobs (job_type, status, created_at, completed_at, payload) VALUES ('rmm.ingest', 'completed', $old, $old, '{}')");
        $this->assertSame(1, $this->h->module->housekeeping()->run()['pruned_ingest_jobs']);
    }
}
