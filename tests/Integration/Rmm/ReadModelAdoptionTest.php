<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\RoleMatrixPolicy;

/** Read-model additions that remove the editions' direct endpoint_agent_* SQL: rich deviceView checks, job(), recentFailedJobs(), arch, and the query counts. */
final class ReadModelAdoptionTest extends RmmTestCase
{
    private int $devA = 0;
    private int $devB = 0;
    private RmmPrincipal $admin;
    private RmmPrincipal $viewer;
    private RmmPrincipal $deptB;

    protected function makeHarness(): RmmHarness
    {
        $p = new RoleMatrixPolicy();
        $p->addUser(1, 3, 3, 1, admin: true);
        $p->addUser(12, 1, 0, 0);          // viewer: device.view only
        $p->addUser(16, 3, 3, 1);          // full tech restricted to client B
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->viewer = new RmmPrincipal(12, 'Viewer');
        $this->deptB = new RmmPrincipal(16, 'DeptB');

        return new RmmHarness(policy: $p);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->tenancy->restrictUser(16, [$this->h->clientB]);
        $this->h->enable();
        [, , $a] = $this->h->enroll($this->h->token($this->h->clientA), $this->h::device(['serial' => 'SN-A', 'hostname' => 'host-a', 'arch' => 'arm64']));
        [, , $b] = $this->h->enroll($this->h->token($this->h->clientB), $this->h::device(['serial' => 'SN-B', 'hostname' => 'host-b']));
        $this->devA = (int) $a['device_id'];
        $this->devB = (int) $b['device_id'];
        $this->h->checkin($a['device_token'], ['checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => '99%']]]);
    }

    private function addJob(int $device, string $state, ?string $output = null, int $ageS = 0): string
    {
        $dev = $this->h->module->devices()->find($device) ?? [];
        $r = $this->h->module->jobs()->create($dev, 'powershell', 'Get-Secret-Script', [], null, false, 1);
        $id = (string) ($r['job_id'] ?? $r);
        $out = $output === null ? 'NULL' : "'" . $this->h->mysqli->real_escape_string($output) . "'";
        $this->h->mysqli->query("UPDATE endpoint_agent_jobs SET state='$state', output=$out, exit_code=1, finished_at='" . gmdate('Y-m-d H:i:s', time() - $ageS) . "' WHERE job_id='$id'");

        return $id;
    }

    public function testDeviceViewReturnsTheRichChecksAndTheJobLimitWhileTheRestShapeStaysFrozen(): void
    {
        $rm = $this->h->module->readModel();
        for ($i = 0; $i < 4; ++$i) {
            $this->addJob($this->devA, 'succeeded', 'ok');
        }
        $v = $rm->deviceView($this->devA, true, 3);
        $this->assertSame(['key', 'status', 'detail', 'consecutive_failures', 'consecutive_ok', 'episode', 'alert_id', 'last_reported_at', 'last_changed_at'], array_keys($v['checks'][0]));
        $this->assertSame('disk_c', $v['checks'][0]['key']);
        $this->assertNotNull($v['checks'][0]['last_changed_at']);
        $this->assertCount(3, $v['jobs'], 'the requested job window is honoured (the union used to keep detail()\'s 20)');
        $this->assertCount(4, $rm->deviceView($this->devA, true, 20)['jobs']);

        $dev = $this->h->module->devices()->find($this->devA) ?? [];
        $d = $rm->detail($dev, true);
        $this->assertSame(['key', 'status', 'detail', 'consecutive_failures', 'last_reported_at'], array_keys($d['checks'][0]), 'detail() (the frozen REST shape) is unchanged');
        $this->assertCount(4, $d['jobs']);
        $this->assertSame(array_keys($d), array_slice(array_keys($v), 0, count($d)), 'deviceView still starts with the detail() keys, in order');
    }

    public function testListDevicesCarriesArchOnlyWithExtras(): void
    {
        $rm = $this->h->module->readModel();
        $with = array_column($rm->listDevices()['items'], 'arch', 'hostname');
        $this->assertSame('arm64', $with['host-a']);
        $this->assertArrayHasKey('host-b', $with);
        $this->assertArrayNotHasKey('arch', $rm->listDevices([], null, 50, 0, false)['items'][0], 'the frozen REST list is unchanged');
    }

    public function testJobIsRedactedAuthorizedAndCostsFewStatements(): void
    {
        $rm = $this->h->module->readModel();
        $id = $this->addJob($this->devA, 'failed', "line one\npassword=hunter2hunter2\nBearer abcdefghijklmnop");
        $before = $this->h->counting->statements;
        $r = $rm->job($this->devA, strtoupper($id), $this->admin);
        $this->assertLessThanOrEqual(3, $this->h->counting->statements - $before, 'device + job (+ at most the settings read) and no per-ability lookups in SQL');
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame($id, $r->data['job_id']);
        $this->assertStringContainsString('line one', $r->data['output']);
        $this->assertStringNotContainsString('hunter2', $r->data['output'], 'output is redacted again on read');
        $this->assertStringNotContainsString('abcdefghijklmnop', $r->data['output']);
        $this->assertSame(['failed', 1, 'host-a', $this->devA], [$r->data['state'], $r->data['exit_code'], $r->data['hostname'], $r->data['device_id']]);
        $this->assertArrayNotHasKey('script', $r->data);

        $viewer = $rm->job($this->devA, $id, $this->viewer);
        $this->assertSame([false, 403, 'forbidden'], [$viewer->ok, $viewer->http, $viewer->code], 'no rmm.job.run_saved: no output');
        $this->assertSame($this->h->module->authorizer()->denial('rmm.job.run_saved'), $viewer->message);
        $this->assertSame(404, $rm->job($this->devA, $id, $this->deptB)->http, 'outside the client scope looks like a missing job');
        $this->assertTrue($rm->job($this->devB, $this->addJob($this->devB, 'failed', 'x'), $this->deptB)->ok);
        $this->assertSame(404, $rm->job($this->devB, $id, $this->admin)->http, 'a job id of another device');
        $this->assertSame(404, $rm->job($this->devA, '00000000-0000-0000-0000-000000000000', $this->admin)->http);
        $this->assertSame(404, $rm->job($this->devA, "' OR 1=1 --", $this->admin)->http);
        $this->assertSame(404, $rm->job(999999, $id, $this->admin)->http);
        $this->assertSame(403, $rm->job($this->devA, $id, new RmmPrincipal(99, 'nobody'))->http, 'a principal without device.view');
        $this->h->module->settings()->disable();
        $this->assertSame(403, $rm->job($this->devA, $id, $this->admin)->http, 'module off');
    }

    public function testRecentFailedJobsIsScopedMetadataOnlyAndOneStatement(): void
    {
        $rm = $this->h->module->readModel();
        $old = $this->addJob($this->devA, 'failed', 'secret-output', 300);
        $new = $this->addJob($this->devA, 'timed_out', 'secret-output', 10);
        $this->addJob($this->devA, 'succeeded', 'fine', 5);
        $b = $this->addJob($this->devB, 'failed', 'b-output', 100);

        $before = $this->h->counting->statements;
        $all = $rm->recentFailedJobs(10, $this->admin);
        $this->assertSame(1, $this->h->counting->statements - $before, 'one statement for the fleet-wide list');
        $this->assertSame([$new, $b, $old], array_column($all, 'job_id'), 'newest first, only failed and timed_out');
        $this->assertSame(['job_id', 'device_id', 'type', 'state', 'reason', 'exit_code', 'at', 'hostname', 'asset_id', 'client_id'], array_keys($all[0]));
        $this->assertStringNotContainsString('secret-output', (string) json_encode($all));
        $this->assertStringNotContainsString('Get-Secret-Script', (string) json_encode($all));
        $this->assertSame([$b], array_column($rm->recentFailedJobs(10, $this->deptB), 'job_id'), 'client-scoped');
        $this->assertCount(2, $rm->recentFailedJobs(2, $this->admin));
        $this->assertCount(1, $rm->recentFailedJobs(0, $this->admin), 'limit is clamped to at least 1');
        $this->assertSame([], $rm->recentFailedJobs(10, new RmmPrincipal(99, 'nobody')));
    }

    public function testReadModelWithoutAnAuthorizerRefusesTheAuthorizedReads(): void
    {
        $bare = new \RivetCore\Rmm\Read\RmmReadModel($this->h->module->sql(), $this->h->module->settings(), $this->h->module->devices(), $this->h->module->updates(), $this->h->module->binaryStore());
        $this->expectException(\LogicException::class);
        $bare->recentFailedJobs(5, $this->admin);
    }
}
