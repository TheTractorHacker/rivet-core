<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Contracts\ScheduleGateInterface;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Tests\Support\AutomationTestCase;

/** Scheduled scripts: due slots become jobs once, offline-safe, capacity-aware, pinned to an approved version, with a gate for maintenance windows. */
final class SchedulesTest extends AutomationTestCase
{
    private int $d1 = 0;
    private string $t1 = '';
    private int $d2 = 0;
    /** @var array<string,mixed> */
    private array $script = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->d1, $this->t1] = $this->device('L-1', null, 'linux');
        [$this->d2] = $this->device('L-2', null, 'linux');
        $this->script = $this->makeScript('Rotate logs');
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function makeScript(string $name, array $over = []): array
    {
        $r = $this->h->module->scriptActions()->createScript($this->tech, $over + ['name' => $name, 'language' => 'bash', 'body' => "echo rotate\n", 'params' => []]);
        $this->assertTrue($r->ok, $r->message);

        return $r->data['script'];
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function schedule(array $over = []): array
    {
        $r = $this->h->module->scriptActions()->saveSchedule($this->admin, null, $over + ['name' => 'Nightly', 'library_script_id' => $this->script['script_id'], 'target' => ['type' => 'client', 'id' => $this->h->clientA],
            'kind' => 'interval', 'interval_s' => 3600, 'start_in_s' => 0]);
        $this->assertTrue($r->ok, $r->message);

        return $r->data['schedule'];
    }

    /** @return array<string,int> */
    private function hk(): array
    {
        return $this->h->module->housekeeping()->run();
    }

    private function jobCount(): int
    {
        return (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_jobs');
    }

    /** @return list<int> device ids inserted directly (a big fleet without enrolling each) */
    private function fleet(int $n, ?int $client = null): array
    {
        $ids = [];
        for ($i = 0; $i < $n; ++$i) {
            $this->h->q(sprintf("INSERT INTO endpoint_agent_devices (install_id, hostname, os, arch, agent_version, asset_id, client_id, link_state, first_seen_at, last_checkin_at)
                VALUES (UUID(), 'F-%d', 'linux', 'amd64', '1.0.0', %d, %d, 'linked', UTC_TIMESTAMP(), UTC_TIMESTAMP())", $i, 9000 + $i, $client ?? $this->h->clientA));
            $ids[] = (int) $this->h->mysqli->insert_id;
        }

        return $ids;
    }

    public function testADueSlotQueuesOneJobPerDeviceOnceAndMovesOn(): void
    {
        $s = $this->schedule();
        $this->assertSame(['interval', 3600, true, 'skip'], [$s['kind'], $s['interval_s'], $s['approved'], $s['overlap']]);
        $out = $this->hk();
        $this->assertSame([1, 1, 2], [$out['schedule_due'], $out['schedule_runs_opened'], $out['schedule_jobs_created']]);
        $this->assertSame(2, $this->jobCount());
        $job = $this->jobs($this->d1)[0];
        $this->assertSame(['shell', "echo rotate\n", 300], [$job['type'], $job['script'], (int) $job['timeout_s']]);
        $x = $this->h->rows("SELECT * FROM rmm_job_extra WHERE job_id = '{$job['job_id']}'")[0];
        $this->assertSame([(int) $s['schedule_id'], 1, (int) $this->script['script_id']], [(int) $x['schedule_id'], (int) $x['script_version'], (int) $x['script_id']]);
        $this->assertNotNull($x['run_id']);
        // the same pass again, and the same slot after a crash that lost nothing: no more jobs
        $this->assertSame(0, $this->hk()['schedule_due']);
        $this->assertSame(2, $this->jobCount());
        $next = $this->h->module->schedules()->get((int) $s['schedule_id']);
        $this->assertGreaterThan(time() + 3000, strtotime((string) $next['next_run_at']));
        $run = $this->h->module->schedules()->runs((int) $s['schedule_id'])[0];
        $this->assertSame(['done', 2], [$run['state'], $run['jobs_created']]);
        $this->assertSame('schedule', $this->bus->of(RmmEvent::SCRIPT_RUN)[0]['origin']);
        // an hour later: the next slot, a new pair of jobs (the old ones are unfinished, but overlap=skip applies per device below)
        $this->h->q('UPDATE endpoint_agent_jobs SET state = "succeeded", finished_at = UTC_TIMESTAMP()');
        $this->setClock(3601);
        $this->assertSame(2, $this->hk()['schedule_jobs_created']);
        $this->assertSame(4, $this->jobCount());
    }

    public function testDeviceAutomationDisabledIsExcludedFromEveryTarget(): void
    {
        $this->h->q("UPDATE endpoint_agent_devices SET device_automation_disabled = 1 WHERE device_id = {$this->d1}");
        $this->schedule();
        $out = $this->hk();
        $this->assertSame(1, $out['schedule_jobs_created'], 'only d2, d1 is excluded');
        $this->assertCount(0, $this->jobs($this->d1));
        $this->assertCount(1, $this->jobs($this->d2));
    }

    public function testAnIdempotencyKeyStopsAReplayedSlot(): void
    {
        $s = $this->schedule();
        $this->hk();
        $this->assertSame(2, $this->jobCount());
        // simulate the process dying after the jobs were queued but before the schedule moved on: the slot is due again
        $slot = (string) $this->h->one("SELECT slot_at FROM rmm_schedule_runs WHERE schedule_id = {$s['schedule_id']}");
        $this->h->q("UPDATE rmm_schedules SET next_run_at = '$slot' WHERE schedule_id = {$s['schedule_id']}");
        $out = $this->hk();
        $this->assertSame(1, $out['schedule_due']);
        $this->assertSame(2, $this->jobCount(), 'the same slot cannot queue a device twice');
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_schedule_runs'));
        // and even a lost run record cannot: the per-device key is the guard
        $this->h->q('DELETE FROM rmm_schedule_runs');
        $this->h->q("UPDATE rmm_schedules SET next_run_at = '$slot' WHERE schedule_id = {$s['schedule_id']}");
        $this->hk();
        $this->assertSame(2, $this->jobCount());
    }

    public function testOverlapSkipAndQueue(): void
    {
        $skip = $this->schedule(['name' => 'Skip', 'overlap' => 'skip', 'interval_s' => 600, 'expires_s' => 86400]);
        $this->hk();
        $this->assertSame(2, $this->jobCount());
        $this->setClock(601);
        $out = $this->hk();
        $this->assertSame(0, $out['schedule_jobs_created']);
        $this->assertSame(2, $out['schedule_skipped'], 'the previous run is still queued on both devices');
        $this->assertSame(2, $this->h->module->schedules()->runs((int) $skip['schedule_id'])[0]['skipped_overlap']);
        $this->h->module->schedules()->delete((int) $skip['schedule_id']);
        $this->h->q('DELETE FROM endpoint_agent_jobs');
        $this->h->q('DELETE FROM rmm_job_extra');
        $this->schedule(['name' => 'Queue', 'overlap' => 'queue', 'interval_s' => 600, 'expires_s' => 86400]);
        $this->hk();
        $this->setClock(601);
        $this->assertSame(2, $this->hk()['schedule_jobs_created']);
        $this->assertSame(4, $this->jobCount());
    }

    public function testAnOfflineDeviceGetsItsJobWhenItReturnsAndRunsItOnce(): void
    {
        $this->schedule(['expires_s' => 86400]);
        $this->hk();
        $this->setClock(7200);   // two hours offline
        $this->h->module->jobs()->sweep();
        $this->assertSame('queued', $this->jobs($this->d1)[0]['state'], 'a job of the global one-hour expiry would be gone by now');
        $this->checkin($this->t1);
        [, , $j] = $this->h->call('GET', 'agent_jobs', null, $this->t1);
        $this->assertCount(1, $j['jobs']);
        $id = $j['jobs'][0]['job_id'];
        $this->h->call('POST', 'agent_jobs', ['job_id' => $id, 'attempt' => 1, 'state' => 'running'], $this->t1);
        $this->h->call('POST', 'agent_jobs', ['job_id' => $id, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => 'rotated'], $this->t1);
        [, , $again] = $this->h->call('GET', 'agent_jobs', null, $this->t1);
        $this->assertSame([], $again['jobs']);
        $this->assertSame(1, count($this->jobs($this->d1)), 'the next slot has not come, so the device has had exactly one job');
    }

    public function testAJobExpiresWithTheScheduleWindow(): void
    {
        $this->schedule(['expires_s' => 600]);
        $this->hk();
        $this->setClock(700);
        $this->h->module->jobs()->sweep();
        $this->assertSame('expired', $this->jobs($this->d1)[0]['state']);
    }

    public function testDowntimeIsNotMadeUpForWithABurst(): void
    {
        $s = $this->schedule(['interval_s' => 86400, 'expires_s' => 3600]);
        $this->setClock(3 * 86400 + 600);   // the cron did not run for three days
        $out = $this->hk();
        $this->assertSame([1, 1], [$out['schedule_due'], $out['schedule_runs_missed']]);
        $this->assertSame(0, $this->jobCount());
        $this->assertSame('missed', $this->h->module->schedules()->runs((int) $s['schedule_id'])[0]['state']);
        $next = strtotime((string) $this->h->module->schedules()->get((int) $s['schedule_id'])['next_run_at']);
        $this->assertGreaterThan(time() + 3 * 86400 + 600 - 86400, $next, 'the next slot is after now, not three slots of backlog');
        $this->assertSame(0, $this->hk()['schedule_due']);
    }

    public function testCronSchedules(): void
    {
        $s = $this->schedule(['kind' => 'cron', 'cron' => '*/10 * * * *', 'interval_s' => null, 'name' => 'Cron']);
        $this->assertSame('*/10 * * * *', $s['cron']);
        $next = strtotime((string) $s['next_run_at']);
        $this->assertSame(0, $next % 600);
        $this->assertGreaterThan(time(), $next);
        $this->assertSame(0, $this->hk()['schedule_due']);
        $this->setClock(601);
        $this->assertSame(2, $this->hk()['schedule_jobs_created']);
        $r = $this->h->module->scriptActions()->saveSchedule($this->admin, (int) $s['schedule_id'], ['cron' => '0 25 * * *']);
        $this->assertSame(422, $r->http);
    }

    public function testJitterSpreadsTheDevicesOverTheWindow(): void
    {
        $this->fleet(30);
        $s = $this->schedule(['jitter_s' => 600, 'expires_s' => 7200]);
        $out = $this->hk();
        $first = $out['schedule_jobs_created'];
        $this->assertLessThan(32, $first);
        $this->assertSame('running', $this->h->module->schedules()->runs((int) $s['schedule_id'])[0]['state'], 'the run stays open for the whole jitter window');
        $this->setClock(300);
        $mid = $this->hk()['schedule_jobs_created'];
        $this->setClock(301);
        $late = $this->hk()['schedule_jobs_created'];
        $this->assertSame(32, $first + $mid + $late, 'every device gets exactly one job');
        $this->assertGreaterThan(0, $mid, 'and not all of them at once');
        $this->assertSame(32, $this->jobCount());
        $this->hk();
        $this->assertSame('done', $this->h->module->schedules()->runs((int) $s['schedule_id'])[0]['state']);
        $this->assertSame(32, $this->jobCount());
    }

    public function testBigFleetsAreWorkedInBatches(): void
    {
        $this->h->module->settings()->update(['limits_json' => '{"schedule_batch":40}']);
        $this->fleet(98);
        $s = $this->schedule();
        $this->assertSame(40, $this->hk()['schedule_jobs_created']);
        $this->assertSame('running', $this->h->module->schedules()->runs((int) $s['schedule_id'])[0]['state']);
        $this->assertSame(40, $this->hk()['schedule_jobs_created']);
        $this->assertSame(20, $this->hk()['schedule_jobs_created']);
        $this->assertSame('done', $this->h->module->schedules()->runs((int) $s['schedule_id'])[0]['state']);
        $this->assertSame(100, $this->jobCount());
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) - COUNT(DISTINCT device_id) FROM endpoint_agent_jobs'), 'one job per device');
    }

    public function testLoadSheddingSlowsAndThenPausesTheScheduler(): void
    {
        // The runner is called directly: housekeeping re-evaluates the level from the real load signals first, which a test cannot hold at a level.
        $this->h->module->settings()->update(['limits_json' => '{"schedule_batch":40}']);
        $this->fleet(78);
        $s = $this->schedule();
        $run = fn (): array => $this->h->module->scheduleRunner()->run();
        $this->h->module->settings()->set(['shed_level' => 3]);
        $out = $run();
        $this->assertSame([1, 1], [$out['runs_opened'], $out['deferred_by_load']]);
        $this->assertSame(0, $this->jobCount(), 'level 3 queues nothing');
        $this->h->module->settings()->set(['shed_level' => 2]);
        $this->assertSame(10, $run()['jobs_created'], 'level 2: a quarter of the batch');
        $this->h->module->settings()->set(['shed_level' => 1]);
        $this->assertSame(20, $run()['jobs_created'], 'level 1: half');
        $this->h->module->settings()->set(['shed_level' => 0]);
        $this->assertSame(40, $run()['jobs_created']);
        $this->assertSame(10, $run()['jobs_created']);
        $this->assertSame('done', $this->h->module->schedules()->runs((int) $s['schedule_id'])[0]['state']);
    }

    public function testTheScriptsSwitchAndTheJobsSwitchGateTheScheduler(): void
    {
        $this->schedule();
        $this->features(['scripts' => false]);
        $out = $this->hk();
        $this->assertArrayNotHasKey('schedule_due', $out);
        $this->assertSame(0, $this->jobCount());
        $this->features(['jobs' => false]);
        $this->assertSame(0, $this->hk()['schedule_jobs_created']);
        $this->features([]);
        $this->assertSame(2, $this->hk()['schedule_jobs_created']);
    }

    public function testTheMaintenanceGateCanSkipAndDefer(): void
    {
        $d1 = $this->d1;
        $gate = new class($d1) implements ScheduleGateInterface {
            public string $mode = 'skip';

            public function __construct(private readonly int $device)
            {
            }

            public function decide(array $device, array $schedule, int $slotTimestamp): string
            {
                return (int) $device['device_id'] === $this->device ? $this->mode : self::RUN;
            }
        };
        $this->h->module->setScheduleGate($gate);
        $s = $this->schedule(['expires_s' => 600]);
        $out = $this->hk();
        $this->assertSame([1, 1], [$out['schedule_jobs_created'], $out['schedule_skipped']]);
        $this->assertSame([], $this->jobs($this->d1), 'skipped for this slot');
        $this->assertSame(1, $this->h->module->schedules()->runs((int) $s['schedule_id'])[0]['skipped_gate']);

        // deferred: nothing now, the run stays open, and it is asked again
        $this->h->module->schedules()->delete((int) $s['schedule_id']);
        $this->h->q('DELETE FROM rmm_job_extra');
        $this->h->q('DELETE FROM endpoint_agent_jobs');
        $gate->mode = 'defer';
        $s = $this->schedule(['name' => 'Deferred', 'expires_s' => 600]);
        $this->hk();
        $this->assertSame([], $this->jobs($this->d1));
        $this->assertCount(1, $this->jobs($this->d2));
        $this->assertSame('running', $this->h->module->schedules()->runs((int) $s['schedule_id'])[0]['state']);
        $gate->mode = 'run';
        $this->hk();
        $this->assertCount(1, $this->jobs($this->d1), 'the maintenance window ended: it runs');
        $this->assertSame('done', $this->h->module->schedules()->runs((int) $s['schedule_id'])[0]['state']);
        // a deferral that never ends is dropped when the run window closes
        $gate->mode = 'defer';
        $this->h->module->schedules()->delete((int) $s['schedule_id']);
        $this->h->q('DELETE FROM rmm_job_extra');
        $this->h->q('DELETE FROM endpoint_agent_jobs');
        $s = $this->schedule(['name' => 'Never', 'expires_s' => 600]);
        $this->hk();
        $this->setClock(700);
        $this->hk();
        $this->assertSame('expired', $this->h->module->schedules()->runs((int) $s['schedule_id'])[0]['state']);
        $this->assertSame([], $this->jobs($this->d1));
    }

    // ------------------------------------------------------------------ what a schedule is pinned to

    public function testALaterEditOfTheScriptDoesNotChangeWhatTheScheduleRuns(): void
    {
        $this->schedule();
        $this->h->module->scripts()->update((int) $this->script['script_id'], ['body' => "echo version two\n"], 10);
        $this->hk();
        $this->assertSame("echo rotate\n", $this->jobs($this->d1)[0]['script']);
        $this->assertSame(1, (int) $this->h->one('SELECT script_version FROM rmm_job_extra LIMIT 1'));
    }

    public function testAScriptEditedUnderAScheduleStopsIt(): void
    {
        $s = $this->schedule();
        $this->h->q("UPDATE rmm_script_versions SET body = 'rm -rf /', body_sha256 = '" . hash('sha256', 'rm -rf /') . "' WHERE script_id = {$this->script['script_id']}");
        $this->hk();
        $this->assertSame(0, $this->jobCount());
        $this->assertFalse($this->h->module->schedules()->get((int) $s['schedule_id'])['enabled']);
        $this->assertSame('failed', $this->h->module->schedules()->runs((int) $s['schedule_id'])[0]['state']);
        $this->assertNotEmpty(array_filter($this->h->audit->records(), static fn (array $r): bool => $r['action'] === 'Schedule Stopped'));
    }

    public function testARetiredScriptStopsItsSchedule(): void
    {
        $s = $this->schedule();
        $this->h->module->scriptActions()->retireScript($this->tech, (int) $this->script['script_id']);
        $this->hk();
        $this->assertSame(0, $this->jobCount());
        $this->assertFalse($this->h->module->schedules()->get((int) $s['schedule_id'])['enabled']);
    }

    public function testAScheduleForAScriptThatNeedsApprovalWaitsForIt(): void
    {
        $sec = $this->makeScript('Needs OK', ['requires_approval' => true]);
        $r = $this->h->module->scriptActions()->saveSchedule($this->admin, null, ['name' => 'Gated', 'library_script_id' => $sec['script_id'], 'target' => ['type' => 'client', 'id' => $this->h->clientA],
            'kind' => 'interval', 'interval_s' => 3600, 'start_in_s' => 0]);
        $this->assertSame([true, 201, 'pending_approval'], [$r->ok, $r->http, $r->code]);
        $sid = (int) $r->data['schedule']['schedule_id'];
        $aid = (int) $r->data['approval_id'];
        $this->assertFalse($r->data['schedule']['approved']);
        $this->assertSame(0, $this->hk()['schedule_due'], 'an unapproved schedule is not even due');
        $this->assertSame(0, $this->jobCount());
        $this->assertSame('schedule', $this->h->module->approvals()->get($aid)['kind']);
        $this->assertSame(403, $this->h->module->scriptActions()->decide($this->admin, $aid, true)->http, 'the requester cannot approve it');
        $d = $this->h->module->scriptActions()->decide($this->approver, $aid, true);
        $this->assertSame([true, 'approved'], [$d->ok, $d->code]);
        $this->assertTrue($this->h->module->schedules()->get($sid)['approved']);
        $this->assertSame(2, $this->hk()['schedule_jobs_created']);
        // changing what it runs withdraws the approval
        $r = $this->h->module->scriptActions()->saveSchedule($this->admin, $sid, ['target' => ['type' => 'device', 'id' => $this->d1]]);
        $this->assertSame('pending_approval', $r->code);
        $this->assertFalse($this->h->module->schedules()->get($sid)['approved']);
        // renaming it does not
        $d = $this->h->module->scriptActions()->decide($this->approver, (int) $r->data['approval_id'], true);
        $this->assertTrue($d->ok);
        $r = $this->h->module->scriptActions()->saveSchedule($this->admin, $sid, ['name' => 'Gated, renamed', 'jitter_s' => 30]);
        $this->assertSame('ok', $r->code);
        $this->assertTrue($this->h->module->schedules()->get($sid)['approved']);
    }

    public function testABigTargetNeedsApprovalToo(): void
    {
        $this->h->module->settings()->update(['limits_json' => '{"approval_bulk_threshold":1}']);
        $r = $this->h->module->scriptActions()->saveSchedule($this->admin, null, ['name' => 'Big', 'library_script_id' => $this->script['script_id'], 'target' => ['type' => 'all'], 'kind' => 'interval', 'interval_s' => 3600]);
        $this->assertSame('pending_approval', $r->code);
        $this->assertSame(2, $r->data['device_count']);
    }

    public function testValidationAndWhoMayManageSchedules(): void
    {
        $a = $this->h->module->scriptActions();
        $base = ['name' => 'V', 'library_script_id' => $this->script['script_id'], 'target' => ['type' => 'all'], 'kind' => 'interval', 'interval_s' => 3600];
        $this->assertSame(403, $a->saveSchedule($this->tech, null, $base)->http);
        $this->assertSame(403, $a->saveSchedule($this->approver, null, $base)->http);
        foreach ([
            [['kind' => 'weekly'], 'kind must be'],
            [['interval_s' => 10], 'interval_s is'],
            [['interval_s' => 'x'], 'whole number'],
            [['kind' => 'cron', 'cron' => 'nonsense'], 'five fields'],
            [['jitter_s' => 99999], 'jitter_s'],
            [['expires_s' => 5], 'expires_s'],
            [['overlap' => 'both'], 'overlap'],
            [['timeout_s' => 0], 'timeout_s'],
            [['target' => ['type' => 'tag', 'id' => 9999]], 'tag does not exist'],
            [['target' => ['type' => 'moon']], 'target.type must be'],
            [['library_script_id' => 9999], 'Script not found'],
            [['name' => ''], 'needs a name'],
            [['params' => ['nope' => 1]], 'Unknown parameter'],
        ] as [$over, $needle]) {
            $r = $a->saveSchedule($this->admin, null, $over + $base);
            $this->assertSame(422, $r->http, json_encode($over) ?: '');
            $this->assertStringContainsString($needle, $r->message);
        }
        $this->assertTrue($a->saveSchedule($this->admin, null, $base)->ok);
        $this->assertSame(422, $a->saveSchedule($this->admin, null, $base)->http, 'duplicate name');
        $this->assertSame(404, $a->saveSchedule($this->admin, 9999, ['name' => 'x'])->http);
        $this->assertSame(403, $a->deleteSchedule($this->tech, 1)->http);
    }

    public function testADestructiveScriptNeedsAnExplicitConfirmation(): void
    {
        $w = $this->makeScript('Wipe', ['destructive' => true]);
        $a = $this->h->module->scriptActions();
        $in = ['name' => 'Wipe it', 'library_script_id' => $w['script_id'], 'target' => ['type' => 'all'], 'kind' => 'interval', 'interval_s' => 3600, 'start_in_s' => 0];
        $this->assertSame(422, $a->saveSchedule($this->admin, null, $in)->http);
        $this->assertTrue($a->saveSchedule($this->admin, null, $in + ['confirm' => true])->ok);
        $this->hk();
        $this->assertSame(1, (int) $this->jobs($this->d1)[0]['destructive']);
    }

    public function testParametersAndFieldPlaceholdersAreResolvedPerDeviceAtRunTime(): void
    {
        $f = $this->h->module->fieldActions()->define($this->admin, ['name' => 'site_code', 'scope' => 'device', 'type' => 'text'])->data['field'];
        $this->h->module->fieldActions()->setValue($this->admin, (int) $f['field_id'], $this->d1, 'NYC');
        $sc = $this->makeScript('Tag it', ['params' => [['name' => 'code', 'type' => 'string', 'required' => true]]]);
        $this->schedule(['library_script_id' => $sc['script_id'], 'params' => ['code' => '{{field.site_code}}']]);
        $out = $this->hk();
        $this->assertSame([1, 1], [$out['schedule_jobs_created'], $out['schedule_skipped']], 'the device without a value is skipped, the other one runs');
        $this->assertSame(['code' => 'NYC'], json_decode((string) $this->jobs($this->d1)[0]['params_json'], true));
        $this->assertSame([], $this->jobs($this->d2));
        $this->assertSame(1, $this->h->module->schedules()->runs((int) $this->h->module->schedules()->all()[0]['schedule_id'])[0]['skipped_other']);
    }

    public function testASecretParameterOfAScheduleMustBeAPlaceholder(): void
    {
        $sc = $this->makeScript('Tok', ['params' => [['name' => 'token', 'type' => 'secret']]]);
        $r = $this->h->module->scriptActions()->saveSchedule($this->admin, null, ['name' => 'T', 'library_script_id' => $sc['script_id'], 'params' => ['token' => 'literal'], 'target' => ['type' => 'all'], 'kind' => 'interval', 'interval_s' => 3600]);
        $this->assertSame(422, $r->http);
        $this->assertStringContainsString('must be a {{field.name}} placeholder', $r->message);
    }

    public function testPerDeviceHistory(): void
    {
        $s = $this->schedule();
        $this->hk();
        foreach ([$this->d1 => 0, $this->d2 => 3] as $dev => $exit) {
            $job = $this->jobs($dev)[0];
            $this->h->q("UPDATE endpoint_agent_jobs SET state = '" . ($exit === 0 ? 'succeeded' : 'failed') . "', exit_code = $exit, started_at = UTC_TIMESTAMP(), finished_at = UTC_TIMESTAMP() WHERE job_id = '{$job['job_id']}'");
        }
        $this->setClock(3601);
        $this->hk();
        $h = $this->h->module->schedules()->history((int) $s['schedule_id']);
        $this->assertCount(4, $h);
        $mine = $this->h->module->schedules()->history((int) $s['schedule_id'], $this->d2);
        $this->assertCount(2, $mine);
        $this->assertSame(['queued', 'failed'], array_column($mine, 'state'));
        $this->assertSame([null, 3], array_column($mine, 'exit_code'));
        $this->assertSame('L-2', $mine[0]['hostname']);
        [$c, $j] = $this->h->tech('GET', ['schedules', (string) $s['schedule_id'], 'history'], $this->viewer, null, ['device_id' => (string) $this->d2]);
        $this->assertSame([200, 2], [$c, count($j['data'])]);
    }

    public function testScopedViewersOnlySeeHistoryOfTheirOwnDevices(): void
    {
        $s = $this->schedule(['target' => ['type' => 'all']]);
        [$b] = $this->device('B-9', $this->h->clientB, 'linux');
        $this->hk();
        [$c, $j] = $this->h->tech('GET', ['schedules', (string) $s['schedule_id'], 'history'], $this->scoped);
        $this->assertSame([200, [$b]], [$c, array_column($j['data'], 'device_id')]);
    }

    public function testTheScheduleRoutes(): void
    {
        [$c, $j] = $this->h->tech('POST', ['schedules'], $this->admin, ['name' => 'Api', 'library_script_id' => $this->script['script_id'], 'target' => ['type' => 'device', 'id' => $this->d1], 'kind' => 'interval', 'interval_s' => 7200]);
        $this->assertSame([201, 1], [$c, $j['device_count']]);
        $id = (string) $j['schedule']['schedule_id'];
        [$c, $j] = $this->h->tech('GET', ['schedules'], $this->viewer);
        $this->assertSame([200, 'Api'], [$c, $j['data'][0]['name']]);
        [$c, $j] = $this->h->tech('GET', ['schedules', $id], $this->viewer);
        $this->assertSame([200, 'Rotate logs'], [$c, $j['schedule']['script_name']]);
        [$c] = $this->h->tech('POST', ['schedules'], $this->tech, ['name' => 'X']);
        $this->assertSame(403, $c);
        [$c, $j] = $this->h->tech('PATCH', ['schedules', $id], $this->admin, ['enabled' => false]);
        $this->assertSame([200, false], [$c, $j['schedule']['enabled']]);
        [$c, $j] = $this->h->tech('GET', ['schedules', $id, 'history'], $this->viewer);
        $this->assertSame([200, []], [$c, $j['data']]);
        [$c] = $this->h->tech('DELETE', ['schedules', $id], $this->admin);
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('GET', ['schedules', $id], $this->viewer);
        $this->assertSame(404, $c);
        [$c] = $this->h->tech('GET', ['schedules', $id, 'history'], $this->viewer);
        $this->assertSame(404, $c);
    }

    public function testOldRunsAndSidecarsArePruned(): void
    {
        $this->schedule();
        $this->hk();
        $this->setClock(400 * 86400);
        $out = $this->hk();
        $this->assertGreaterThan(0, $out['schedule_rows_pruned']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_job_extra WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 200 DAY)'));
    }
}
