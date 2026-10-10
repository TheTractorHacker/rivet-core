<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmEventsInterface;
use RivetCore\Testing\InMemoryRmmEvents;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Webhooks\EventCatalog;

/** The rmm.* events: what raises each one, the payload, delivery after commit, a failing bus, and the cost of having no bus. */
final class EventsTest extends RmmTestCase
{
    private InMemoryRmmEvents $bus;

    protected function makeHarness(): RmmHarness
    {
        $this->bus = new InMemoryRmmEvents();

        return new RmmHarness(null, null, ['allow_linux' => true], false, null, null, null, $this->bus);
    }

    /** @return array{0:int,1:string,2:int} */
    private function linked(string $serial = 'EV-1'): array
    {
        $tok = $this->h->token(null, 24, 20);
        $asset = $this->h->asset(['name' => $serial, 'serial' => $serial]);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => $serial, 'hostname' => 'HOST-' . $serial]));

        return [(int) $j['device_id'], $j['device_token'], $asset];
    }

    public function testEveryEventIdIsInTheCatalogWithItsPayloadFields(): void
    {
        foreach (RmmEvent::all() as $id) {
            $e = EventCatalog::get($id);
            $this->assertNotNull($e, $id);
            $this->assertSame('rmm', $e->group);
            $this->assertNull($e->since, "$id is emitted by Core");
            $paths = array_column($e->payloadFields, 'path');
            foreach (['device_id', 'asset_id', 'client_id', 'hostname', 'occurred_at'] as $common) {
                $this->assertContains($common, $paths, "$id carries $common");
            }
            $this->assertGreaterThan(5, count($paths), "$id documents its own fields");
        }
        $this->assertCount(9, EventCatalog::matchPattern('rmm.*'));
        $this->assertCount(2, EventCatalog::matchPattern('rmm.software.*'));
    }

    public function testEnrollmentRaisesDeviceEnrolled(): void
    {
        [$dev, , $asset] = $this->linked();
        $e = $this->bus->of(RmmEvent::DEVICE_ENROLLED);
        $this->assertCount(1, $e);
        $this->assertSame([$dev, $asset, $this->h->clientA, 'HOST-EV-1', 'linked', 'windows', 'enrolled'],
            [$e[0]['device_id'], $e[0]['asset_id'], $e[0]['client_id'], $e[0]['hostname'], $e[0]['link_state'], $e[0]['os'], $e[0]['outcome']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $e[0]['occurred_at']);
        // an unmatched device waits for approval, and says so
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 5), $this->h::device(['serial' => 'NO-MATCH-9', 'hostname' => 'STRANGER']));
        $e = $this->bus->of(RmmEvent::DEVICE_ENROLLED);
        $this->assertSame(['pending_approval', null], [$e[1]['link_state'], $e[1]['asset_id']]);
        // a rejected enrollment raises nothing
        $before = count($this->bus->published());
        $this->h->call('POST', 'agent_enroll', ['enrollment_token' => 'rvte1.bad', 'device' => []]);
        $this->assertSame($before, count($this->bus->published()));
        $this->assertGreaterThan(0, (int) $j['device_id']);
    }

    public function testChecksRaiseFailedAfterTheDebounceAndRecoveredAfterTheRecoveryDebounce(): void
    {
        [$dev, $tok] = $this->linked();
        $fail = fn () => $this->h->checkin($tok, ['checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => '97% used']]]);
        $ok = fn () => $this->h->checkin($tok, ['checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => '']]]);
        $fail();
        $fail();
        $this->assertSame([], $this->bus->of(RmmEvent::CHECK_FAILED), 'two failures: still inside the debounce (3)');
        $fail();
        $f = $this->bus->of(RmmEvent::CHECK_FAILED);
        $this->assertCount(1, $f);
        $this->assertSame(['disk_c', 'fail', '97% used', 1, $dev], [$f[0]['check_key'], $f[0]['status'], $f[0]['detail'], $f[0]['episode'], $f[0]['device_id']]);
        $this->assertGreaterThan(0, $f[0]['alert_id']);
        $fail();
        $this->assertCount(1, $this->bus->of(RmmEvent::CHECK_FAILED), 'one event per episode');
        $ok();
        $this->assertSame([], $this->bus->of(RmmEvent::CHECK_RECOVERED));
        $ok();
        $r = $this->bus->of(RmmEvent::CHECK_RECOVERED);
        $this->assertCount(1, $r);
        $this->assertSame([$f[0]['alert_id'], 1], [$r[0]['alert_id'], $r[0]['episode']]);
    }

    public function testJobsRaiseCompletedOrFailed(): void
    {
        [$dev, $tok] = $this->linked();
        $admin = new RmmPrincipal(1, 'Admin');
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $r = $this->h->module->technician()->submitJob($admin, $dev, ['type' => 'collect']);
            $this->assertTrue($r->ok);
            $ids[] = $r->data['job_id'];
        }
        $this->h->call('GET', 'agent_jobs', null, $tok);
        $report = fn (string $id, string $state, ?int $exit) => $this->h->call('POST', 'agent_jobs', ['job_id' => $id, 'attempt' => 1, 'state' => $state, 'exit_code' => $exit, 'output' => 'x'], $tok)[0];
        $this->assertSame(200, $report($ids[0], 'running', null));
        $this->assertSame([], array_filter($this->bus->published(), static fn (array $e): bool => str_starts_with($e['event'], 'rmm.job.')), 'a running report is not an outcome');
        $this->assertSame(200, $report($ids[0], 'succeeded', 0));
        $this->assertSame(200, $report($ids[0], 'succeeded', 0), 'an idempotent replay');
        $this->assertSame(200, $report($ids[1], 'failed', 3));
        $this->assertSame(200, $report($ids[2], 'timed_out', null));
        $ok = $this->bus->of(RmmEvent::JOB_COMPLETED);
        $this->assertCount(1, $ok, 'the replay did not raise it twice');
        $this->assertSame([$ids[0], 'collect', 0, $dev], [$ok[0]['job_id'], $ok[0]['job_type'], $ok[0]['exit_code'], $ok[0]['device_id']]);
        $bad = $this->bus->of(RmmEvent::JOB_FAILED);
        $this->assertSame([[$ids[1], 'failed', 3], [$ids[2], 'timed_out', null]], array_map(static fn (array $e): array => [$e['job_id'], $e['state'], $e['exit_code']], $bad));
    }

    public function testOfflineIsRaisedOncePerOfflinePeriodAndOnlineWhenItReturns(): void
    {
        [$dev, $tok] = $this->linked();
        $this->h->checkin($tok);
        $this->h->module->settings()->set(['offline_after_s' => 900]);
        $this->assertSame(0, $this->h->module->housekeeping()->run()['offline_events']);
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at='" . gmdate('Y-m-d H:i:s', time() - 2000) . "' WHERE device_id=$dev");
        $this->assertSame(1, $this->h->module->housekeeping()->run()['offline_events']);
        $this->assertSame(0, $this->h->module->housekeeping()->run()['offline_events'], 'not again while it stays offline');
        $off = $this->bus->of(RmmEvent::DEVICE_OFFLINE);
        $this->assertCount(1, $off);
        $this->assertSame([$dev, 'HOST-EV-1'], [$off[0]['device_id'], $off[0]['hostname']]);
        $this->assertNotNull($off[0]['last_checkin_at']);
        $this->assertSame([], $this->bus->of(RmmEvent::DEVICE_ONLINE));
        $this->h->checkin($tok, ['capabilities' => ['job:shell']]);
        $on = $this->bus->of(RmmEvent::DEVICE_ONLINE);
        $this->assertCount(1, $on);
        $this->assertNotNull($on[0]['offline_since']);
        $this->h->checkin($tok);
        $this->assertCount(1, $this->bus->of(RmmEvent::DEVICE_ONLINE), 'only the return is an event');
        // and a second offline period is a second event
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at='" . gmdate('Y-m-d H:i:s', time() - 3000) . "' WHERE device_id=$dev");
        $this->assertSame(1, $this->h->module->housekeeping()->run()['offline_events']);
        $this->assertCount(2, $this->bus->of(RmmEvent::DEVICE_OFFLINE));
    }

    public function testDevicesSilentForLongerThanTheStaleWindowAreRecordedWithoutAnEvent(): void
    {
        [$dev, $tok] = $this->linked();
        $this->h->checkin($tok);
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at='" . gmdate('Y-m-d H:i:s', time() - 86400 * 30) . "' WHERE device_id=$dev");
        $this->assertSame(0, $this->h->module->housekeeping()->run()['offline_events']);
        $this->assertSame('offline', $this->h->one("SELECT presence FROM rmm_device_state WHERE device_id = $dev"));
        $this->assertSame([], $this->bus->of(RmmEvent::DEVICE_OFFLINE));
        $this->h->checkin($tok);
        $this->assertCount(1, $this->bus->of(RmmEvent::DEVICE_ONLINE), 'but its return is announced');
    }

    public function testABusThatThrowsNeverFailsACheckinAJobReportOrAHousekeepingRun(): void
    {
        $boom = new class implements RmmEventsInterface {
            public int $calls = 0;

            public function publish(string $event, array $payload): void
            {
                ++$this->calls;
                throw new \RuntimeException('bus down');
            }
        };
        $h = new RmmHarness(null, null, ['allow_linux' => true], false, null, null, null, null);
        // build a module with the throwing bus on the same scratch database
        $module = new \RivetCore\Rmm\RmmModule($h->counting, $h->clock, $h->tenancy, $h->assets, $h->bridge, $h->box, $h->audit, $h->metrics, new \RivetCore\Testing\InMemoryRmmModuleState(true),
            ['allow_insecure_http' => true, 'allow_linux' => true], null, $h->policy, null, $boom);
        $h->enable();
        $h->asset(['name' => 'B', 'serial' => 'BOOM-1']);
        [$c, , $j] = $h->enroll($h->token(null, 24, 5), $h::device(['serial' => 'BOOM-1']));
        $this->assertSame(201, $c, 'the h module has no bus; now the throwing one');
        $api = $module->deviceApi(static fn (): bool => true);
        $tok = $j['device_token'];
        for ($i = 1; $i <= 3; ++$i) {
            $req = $h->request('POST', 'agent_checkin', (string) json_encode(['seq' => $i, 'collected_at' => $h::ts(), 'agent_version' => '1.0.0', 'inventory' => null, 'metrics' => null,
                'checks' => [['key' => 'k', 'status' => 'fail', 'detail' => '']], 'buffered' => []]), $tok);
            $this->assertSame(200, $api->handle($req)->status);
        }
        $this->assertGreaterThan(0, $boom->calls, 'the bus was called');
        $this->assertSame(3, (int) $h->one('SELECT consecutive_failures FROM endpoint_agent_checks'), 'and the check-in still committed');
        $module->housekeeping()->run();
    }

    public function testAnEventHeldInATransactionIsDroppedWhenItRollsBack(): void
    {
        [$dev, $tok] = $this->linked();
        $pub = $this->h->module->eventPublisher();
        $pub->hold();
        $pub->emit(RmmEvent::DEVICE_ONLINE, ['device_id' => $dev, 'client_id' => 1, 'hostname' => 'x']);
        $this->assertSame([], $this->bus->of(RmmEvent::DEVICE_ONLINE), 'held until commit');
        $pub->discard();
        $this->assertSame([], $this->bus->of(RmmEvent::DEVICE_ONLINE));
        $pub->hold();
        $pub->hold();
        $pub->emit(RmmEvent::DEVICE_ONLINE, ['device_id' => $dev, 'client_id' => 1, 'hostname' => 'x']);
        $pub->release();
        $this->assertSame([], $this->bus->of(RmmEvent::DEVICE_ONLINE), 'an inner release does not deliver');
        $pub->release();
        $this->assertCount(1, $this->bus->of(RmmEvent::DEVICE_ONLINE));
        $this->assertGreaterThan(0, strlen($tok));
    }

    public function testWithoutABusNoExtraWorkIsDone(): void
    {
        $h = new RmmHarness(null, null, ['allow_linux' => true]);
        $h->enable();
        $h->asset(['name' => 'N', 'serial' => 'NOBUS-1']);
        [, , $j] = $h->enroll($h->token(null, 24, 5), $h::device(['serial' => 'NOBUS-1']));
        $h->checkin($j['device_token']);
        $h->counting->statements = 0;
        $h->checkin($j['device_token']);
        $without = $h->counting->statements;
        $h->counting->statements = 0;
        $h->checkin($j['device_token'], ['capabilities' => ['job:shell']]);
        $this->assertLessThanOrEqual($without + 3, $h->counting->statements, 'announcing capabilities costs at most a read and a write');
        $this->assertSame(0, (int) $h->one("SELECT COUNT(*) FROM rmm_device_state WHERE presence IS NOT NULL"));
        $this->assertFalse($h->module->eventPublisher()->enabled());
        $this->assertSame(0, $h->module->housekeeping()->run()['pruned_checkins'] + ($h->module->housekeeping()->run()['offline_events'] ?? 0));
    }
}
