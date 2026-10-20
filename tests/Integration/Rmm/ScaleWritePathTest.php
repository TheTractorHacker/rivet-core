<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\RmmTestCase;

/**
 * Scale item S3: the cheaper write path of a check-in. The response bytes are pinned by the golden replay (GoldenReplayTest); these tests pin what
 * the path COSTS (statements) and that batching the check rows changed nothing observable.
 */
final class ScaleWritePathTest extends RmmTestCase
{
    private int $dev = 0;
    private string $T = '';

    private function device(): void
    {
        $tok = $this->h->token(null, 24, 100);
        $asset = $this->h->asset(['name' => 'Desk', 'serial' => 'SW-1', 'make' => 'Dell']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SW-1']));
        $this->dev = (int) $j['device_id'];
        $this->T = $j['device_token'];
        $this->assertSame('linked', $j['status']);
        $this->assertGreaterThan(0, $asset);
    }

    /** @param list<array<string,mixed>> $checks */
    private function checkin(array $checks, array $over = []): array
    {
        [$c, , $r] = $this->h->checkin($this->T, ['checks' => $checks] + $over);
        $this->assertSame(200, $c, json_encode($r));

        return $r;
    }

    private function statementsOf(callable $f): int
    {
        $before = $this->h->counting->statements;
        $f();

        return $this->h->counting->statements - $before;
    }

    public function testASteadyCheckinCostsAtMostElevenStatementsThroughTheCountingWrapper(): void
    {
        $this->device();
        $checks = [['key' => 'disk_c', 'status' => 'ok', 'detail' => 'C: 61% used'], ['key' => 'pending_reboot', 'status' => 'ok', 'detail' => ''], ['key' => 'svc_eventlog', 'status' => 'ok', 'detail' => 'running']];
        $this->checkin($checks);   // warm up: the check rows exist now
        $this->h->module->settings()->get(true);
        $n = $this->statementsOf(fn () => $this->checkin($checks));
        // settings, token lookup, transaction (counted as one), device FOR UPDATE, checkin INSERT, device UPDATE, check rows SELECT, check rows upsert, jobs probe,
        // releases (the sink and the link are in-memory stand-ins here and cost nothing).
        $this->assertLessThanOrEqual(11, $n, "a steady check-in cost $n statements");
    }

    public function testTheCheckRowsOfAMultiCheckCheckinAreOneReadAndOneWrite(): void
    {
        $this->device();
        $checks = [];
        for ($i = 0; $i < 12; ++$i) {
            $checks[] = ['key' => "c$i", 'status' => 'ok', 'detail' => "d$i"];
        }
        $this->checkin([]);   // warm-up: the very first check-in of a device also records its delivered check set (a one-off, rc.24)
        $first = $this->statementsOf(fn () => $this->checkin($checks));
        $second = $this->statementsOf(fn () => $this->checkin($checks));
        $three = $this->statementsOf(fn () => $this->checkin(array_slice($checks, 0, 3)));
        $this->assertGreaterThanOrEqual($second, $first);
        $this->assertLessThanOrEqual($second + 1, $first, 'twelve new check rows cost no more than one statement over twelve existing ones');
        $this->assertSame($second, $three, 'and as three');
    }

    public function testABufferedAndACurrentResultOfOneKeyAreEvaluatedInOrder(): void
    {
        $this->device();
        $this->h->module->settings()->update(['failure_debounce' => 2]);
        $old = gmdate('Y-m-d\TH:i:s\Z', time() - 120);
        $this->checkin([['key' => 'svc', 'status' => 'fail', 'detail' => 'now down']], ['buffered' => [
            ['collected_at' => $old, 'metrics' => null, 'checks' => [['key' => 'svc', 'status' => 'fail', 'detail' => 'was down']]],
        ]]);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_checks WHERE device_id={$this->dev} AND check_key='svc'")[0];
        $this->assertSame('fail', $row['status']);
        $this->assertSame('now down', $row['detail']);
        $this->assertSame('2', (string) $row['consecutive_failures'], 'the buffered result counted first, then the current one');
        $this->assertSame('1', (string) $row['episode'], 'the second failure crossed the debounce and opened exactly one alert');
        $this->assertNotNull($row['alert_id']);
        $this->checkin([['key' => 'svc', 'status' => 'ok', 'detail' => 'back']]);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_checks WHERE device_id={$this->dev} AND check_key='svc'")[0];
        $this->assertSame(['ok', '0', '1'], [$row['status'], (string) $row['consecutive_failures'], (string) $row['consecutive_ok']]);
        $this->assertNotNull($row['last_changed_at']);
    }

    public function testTheDeviceRowAfterACheckinHoldsEverythingTheOldTwoUpdatesWrote(): void
    {
        $this->device();
        $this->checkin([], ['agent_version' => '1.2.3', 'metrics' => ['cpu_pct' => 33.0, 'mem_pct' => 44.0, 'disk' => [], 'net_rx_bps' => 1.0, 'net_tx_bps' => 2.0]]);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_devices WHERE device_id={$this->dev}")[0];
        $this->assertSame('1.2.3', $row['agent_version']);
        $this->assertNotNull($row['last_checkin_at']);
        $this->assertNotNull($row['last_collected_at']);
        $this->assertSame('127.0.0.1', $row['last_ip']);
        $this->assertSame((string) $this->h->seqCounter, (string) $row['last_seq']);
        $this->assertStringContainsString('33', (string) $row['last_metrics_json']);
        // a duplicate delivery still moves the liveness
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at='2000-01-01 00:00:00' WHERE device_id={$this->dev}");
        [$c] = $this->h->call('POST', 'agent_checkin', ['seq' => $this->h->seqCounter, 'collected_at' => $this->h::ts(), 'agent_version' => '1.2.3', 'checks' => [], 'buffered' => []], $this->T);
        $this->assertSame(200, $c);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_devices WHERE device_id={$this->dev}")[0];
        $this->assertGreaterThan('2000-01-01 00:00:00', $row['last_checkin_at']);
    }

    public function testTheOptInTouchIntervalSkipsAnUnchangedCheckRowButNeverAChange(): void
    {
        $this->device();
        $this->assertSame([], $this->h->module->settings()->update(['limits_json' => ['check_touch_s' => 3600]]));
        $ok = [['key' => 'svc', 'status' => 'ok', 'detail' => 'up']];
        for ($i = 0; $i < 4; ++$i) {
            $this->checkin($ok);
        }
        $stamp = '2001-02-03 04:05:06';
        $this->h->q("UPDATE endpoint_agent_checks SET last_reported_at='$stamp' WHERE device_id={$this->dev}");
        $before = $this->h->rows("SELECT * FROM endpoint_agent_checks WHERE device_id={$this->dev}")[0];
        // the stamp is old, so the window (3600 s) has passed and the row is refreshed ...
        $this->checkin($ok);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_checks WHERE device_id={$this->dev}")[0];
        $this->assertGreaterThan($stamp, $row['last_reported_at']);
        // ... and now a second unchanged result inside the window writes nothing at all
        $this->h->q("UPDATE endpoint_agent_checks SET last_reported_at=UTC_TIMESTAMP() - INTERVAL 5 SECOND WHERE device_id={$this->dev}");
        $marker = $this->h->one("SELECT last_reported_at FROM endpoint_agent_checks WHERE device_id={$this->dev}");
        $n = $this->statementsOf(fn () => $this->checkin($ok));
        $this->assertSame($marker, $this->h->one("SELECT last_reported_at FROM endpoint_agent_checks WHERE device_id={$this->dev}"));
        $this->assertLessThanOrEqual(10, $n, "9 before rc.24 plus the one primary-key read of the delivered check set");
        // a change is always written
        $this->checkin([['key' => 'svc', 'status' => 'warn', 'detail' => 'slow']]);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_checks WHERE device_id={$this->dev}")[0];
        $this->assertSame(['warn', 'slow', '1'], [$row['status'], $row['detail'], (string) $row['consecutive_failures']]);
        $this->assertNotSame($before['status'], $row['status']);
    }

    public function testWithoutTheOptInEveryCheckinRefreshesTheStamp(): void
    {
        $this->device();
        $ok = [['key' => 'svc', 'status' => 'ok', 'detail' => 'up']];
        $this->checkin($ok);
        $this->h->q("UPDATE endpoint_agent_checks SET last_reported_at='2001-02-03 04:05:06' WHERE device_id={$this->dev}");
        $this->checkin($ok);
        $this->assertGreaterThan('2001-02-03 04:05:06', $this->h->one("SELECT last_reported_at FROM endpoint_agent_checks WHERE device_id={$this->dev}"));
        $this->assertSame('2', (string) $this->h->one("SELECT consecutive_ok FROM endpoint_agent_checks WHERE device_id={$this->dev}"), 'the ok counter keeps counting while the opt-in is off');
    }

    public function testADeviceWithNoJobsCostsOneProbeAndADeviceWithAnExpiredJobStillSweeps(): void
    {
        $this->device();
        $this->checkin([]);
        $n0 = $this->statementsOf(fn () => $this->h->module->jobs()->pendingCount($this->dev));
        $this->assertSame(1, $n0);
        $this->h->q("INSERT INTO endpoint_agent_jobs (job_id, device_id, asset_id, client_id, type, script, params_json, state, destructive, timeout_s, created_at, issued_at, expires_at, offered_count)
            VALUES ('" . $this->h::uuid() . "', {$this->dev}, NULL, 0, 'powershell', 'x', NULL, 'queued', 0, 60, UTC_TIMESTAMP() - INTERVAL 2 HOUR, UTC_TIMESTAMP() - INTERVAL 2 HOUR, UTC_TIMESTAMP() - INTERVAL 1 HOUR, 0)");
        $this->assertSame(0, $this->h->module->jobs()->pendingCount($this->dev));
        $this->assertSame('expired', $this->h->one("SELECT state FROM endpoint_agent_jobs WHERE device_id={$this->dev}"));
    }
}
