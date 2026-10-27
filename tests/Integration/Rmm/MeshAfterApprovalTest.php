<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\RemoteTestCase;
use RivetCore\Tests\Support\RmmHarness;

/**
 * 1.0.0-rc.27: the installer tick-box means "install the Mesh agent once this device is linked", pushed by the server as the sealed `mesh_install` job. Pending devices get
 * nothing; approval (or an automatic match) queues exactly one job; devices whose token did not ask never get one; the group id never reaches a job row, the audit or any installer text.
 */
final class MeshAfterApprovalTest extends RemoteTestCase
{
    private const GROUP = '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';
    private const GROUP_URL = 'ShssPU5fYHGCk6S1xtfo@QobLD1OX2BxgpOktcbX6PkKGyw9Tl9gcYKTpLXG1@j5';

    protected function makeHarness(): RmmHarness
    {
        $this->transferDir = sys_get_temp_dir() . '/rmm_xfer_' . bin2hex(random_bytes(4));

        return new RmmHarness(null, null, ['allow_linux' => true, 'transfer_dir' => $this->transferDir], false, null, null, new \RivetCore\Webhooks\UrlPolicy(true));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test']);
        $this->h->publishBinary('1.0.0', 'amd64', 8192, true, null);
        $this->switchOn(['remote_tools']);
        $this->h->module->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.com']);
        $this->assertSame([], $this->h->module->meshAgentSettings()->update(['mesh_group_id' => self::GROUP], 1));
    }

    /** @return list<string> */
    private static function caps(bool $mesh = true): array
    {
        return array_merge(['job:collect', 'job:powershell'], $mesh ? ['job:mesh_install', 'job:mesh_uninstall'] : []);
    }

    private function issue(bool $mesh = true): string
    {
        $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 20, 'aa', 'amd64', 1, 'Admin', $mesh);
        $this->assertTrue($r['ok'], json_encode($r));

        return $r['token_plain'];
    }

    /** @return array{0:int,1:string,2:string} device id, device token, link status; an asset with the serial is created first when $match */
    private function enrollDevice(string $enrollToken, string $name, bool $match): array
    {
        if ($match) {
            $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $this->h->clientA]);
        }
        [$c, , $j] = $this->h->enroll($enrollToken, $this->h::device(['serial' => "SER-$name", 'hostname' => $name]));
        $this->assertSame(201, $c, json_encode($j));

        return [(int) $j['device_id'], (string) $j['device_token'], (string) $j['status']];
    }

    private function jobs(int $dev): array
    {
        return $this->h->rows("SELECT * FROM endpoint_agent_jobs WHERE device_id=$dev AND type='mesh_install'");
    }

    private function line(int $dev): ?string
    {
        return $this->h->module->meshAfterApproval()->view($dev)['label'] ?? null;
    }

    private function approve(int $dev): void
    {
        $this->assertTrue($this->h->module->enrollment()->resolvePending($dev, 'create_asset', null, 1)['ok']);
    }

    private function nothingLeaksTheGroupId(): void
    {
        foreach (['endpoint_agent_jobs', 'rmm_mesh_after_approval', 'endpoint_agent_job_secrets'] as $t) {
            try {
                $dump = json_encode($this->h->rows("SELECT * FROM `$t`"));
            } catch (\Throwable) {
                continue;
            }
            $this->assertStringNotContainsString(self::GROUP, (string) $dump, $t);
            $this->assertStringNotContainsString(self::GROUP_URL, (string) $dump, $t);
        }
        $audit = json_encode($this->h->audit->records());
        $this->assertStringNotContainsString(self::GROUP, (string) $audit);
        $this->assertStringNotContainsString(self::GROUP_URL, (string) $audit);
    }

    public function testAPendingDeviceGetsNoJobAndApprovalQueuesExactlyOneSealedJob(): void
    {
        [$d, $tok, $status] = $this->enrollDevice($this->issue(), 'P1', false);
        $this->assertSame('pending_approval', $status);
        $this->announce($tok, self::caps());
        $this->announce($tok, self::caps());
        $this->assertSame([], $this->jobs($d), 'nothing before approval, however often it checks in');
        $this->assertSame('Mesh agent: waiting for approval', $this->line($d));
        $this->assertSame('waiting_approval', $this->h->module->readModel()->detail($this->h->module->devices()->find($d), false)['mesh_after_approval']['state'] ?? null, 'the read model carries the state line');

        $this->approve($d);
        $jobs = $this->jobs($d);
        $this->assertCount(1, $jobs);
        $this->assertSame('queued', $jobs[0]['state']);
        $this->assertSame(0, (int) $jobs[0]['created_by'], 'the system principal');
        $this->assertStringNotContainsString(self::GROUP, (string) $jobs[0]['params_json']);
        $this->assertSame('Mesh agent: queued', $this->line($d));
        $this->assertStringContainsString("Mesh agent install queued after approval for device $d (installer option)", json_encode($this->h->audit->records()));

        // idempotent: more check-ins, a second approval attempt, a second plan
        $this->announce($tok, self::caps());
        $this->assertFalse($this->h->module->enrollment()->resolvePending($d, 'create_asset', null, 1)['ok'], 'no longer pending');
        $this->h->module->meshAfterApproval()->advance($this->h->module->devices()->find($d));
        $this->assertCount(1, $this->jobs($d));
        $this->nothingLeaksTheGroupId();

        // the device receives the signed job with the group id put back only there
        [, , $j] = $this->h->checkin($tok, ['capabilities' => self::caps(), 'platform' => 'windows']);
        $this->assertGreaterThanOrEqual(1, $j['jobs_pending']);
    }

    public function testAnAutoLinkedDeviceGetsTheJobAtItsFirstCheckinThatAnnouncesTheCapability(): void
    {
        [$d, $tok, $status] = $this->enrollDevice($this->issue(), 'A1', true);
        $this->assertSame('linked', $status);
        $this->assertSame([], $this->jobs($d), 'the agent has not announced job:mesh_install yet');
        $this->assertSame('Mesh agent: waiting for the agent to announce Mesh install support', $this->line($d));
        $this->announce($tok, self::caps());
        $this->assertCount(1, $this->jobs($d));
        $this->announce($tok, self::caps());
        $this->assertCount(1, $this->jobs($d));
    }

    public function testCapabilityNotAnnouncedIsRetriedWithBackoffAndThenGivesUp(): void
    {
        [$d, $tok] = $this->enrollDevice($this->issue(), 'R1', true);
        $this->announce($tok, self::caps(false));
        $this->assertSame([], $this->jobs($d));
        $a1 = (int) $this->h->one("SELECT attempts FROM rmm_mesh_after_approval WHERE device_id=$d");
        $this->assertGreaterThanOrEqual(1, $a1);
        $this->announce($tok, self::caps(false));
        $this->assertSame($a1, (int) $this->h->one("SELECT attempts FROM rmm_mesh_after_approval WHERE device_id=$d"), 'backoff: no second try within the minute');
        $this->h->mysqli->query("UPDATE rmm_mesh_after_approval SET updated_at = '2000-01-01 00:00:00' WHERE device_id=$d");
        $this->announce($tok, self::caps(false));
        $this->assertGreaterThan($a1, (int) $this->h->one("SELECT attempts FROM rmm_mesh_after_approval WHERE device_id=$d"));
        $this->h->mysqli->query("UPDATE rmm_mesh_after_approval SET updated_at = '2000-01-01 00:00:00', planned_at = '2000-01-01 00:00:00' WHERE device_id=$d");
        $this->announce($tok, self::caps(false));
        $this->assertStringContainsString('failed: the agent never announced', (string) $this->line($d));
        $this->announce($tok, self::caps());
        $this->assertSame([], $this->jobs($d), 'a failed plan is final until a new installer asks again');
    }

    public function testAfterAnUpdateTheCapabilityArrivesAndTheJobIsQueued(): void
    {
        [$d, $tok] = $this->enrollDevice($this->issue(), 'R2', true);
        $this->announce($tok, self::caps(false));
        $this->h->mysqli->query("UPDATE rmm_mesh_after_approval SET updated_at = '2000-01-01 00:00:00' WHERE device_id=$d");
        $this->announce($tok, self::caps());
        $this->assertCount(1, $this->jobs($d));
    }

    public function testADeviceEnrolledWithoutTheOptionNeverGetsAJob(): void
    {
        $plain = $this->issue(false);
        [$d, $tok] = $this->enrollDevice($plain, 'N1', false);
        $this->announce($tok, self::caps());
        $this->approve($d);
        $this->announce($tok, self::caps());
        $this->assertSame([], $this->jobs($d));
        $this->assertNull($this->line($d));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_mesh_after_approval'));
    }

    public function testRemoteToolsOffRecordsASkipAndQueuesNothing(): void
    {
        [$d, $tok] = $this->enrollDevice($this->issue(), 'T1', false);
        $this->announce($tok, self::caps());
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true]]));
        $this->approve($d);
        $this->assertSame([], $this->jobs($d));
        $this->assertSame('Mesh agent: skipped: remote tools are off', $this->line($d));
        $this->switchOn(['remote_tools']);
        $this->announce($tok, self::caps());
        $this->assertSame([], $this->jobs($d), 'a recorded skip is final');
    }

    public function testIncompleteMeshSettingsRecordASkipWithTheReason(): void
    {
        [$d, $tok] = $this->enrollDevice($this->issue(), 'S1', false);
        $this->announce($tok, self::caps());
        $this->h->module->settings()->set(['mesh_enabled' => 0]);
        $this->approve($d);
        $this->assertSame([], $this->jobs($d));
        $this->assertStringContainsString('Mesh agent: skipped: MeshCentral is not switched on', (string) $this->line($d));
        $this->nothingLeaksTheGroupId();
    }

    public function testRejectedDeviceNeverGetsOneAndALaterInstalledReportShowsInstalled(): void
    {
        [$d, $tok] = $this->enrollDevice($this->issue(), 'X1', false);
        $this->announce($tok, self::caps());
        $this->assertTrue($this->h->module->enrollment()->resolvePending($d, 'reject', null, 1)['ok']);
        $this->assertSame([], $this->jobs($d));

        [$e, $tok2] = $this->enrollDevice($this->issue(), 'X2', true);
        $this->announce($tok2, self::caps());
        $this->assertCount(1, $this->jobs($e));
        $this->h->checkin($tok2, ['mesh_agent' => ['installed' => true, 'version' => '1.1.0']]);
        $this->assertSame('Mesh agent: installed', $this->line($e));
    }

    public function testAnAlreadyInstalledAgentIsNotInstalledAgain(): void
    {
        [$d, $tok] = $this->enrollDevice($this->issue(), 'I1', false);
        $this->h->checkin($tok, ['capabilities' => self::caps(), 'platform' => 'windows', 'mesh_agent' => ['installed' => true, 'version' => '1.1.0']]);
        $this->approve($d);
        $this->assertSame([], $this->jobs($d));
        $this->assertSame('Mesh agent: installed', $this->line($d));
    }

    /** rc.38 (security review RC36 M3): the Mesh install after approval is automation too and waits while the device is switched out of it. */
    public function testDeviceAutomationDisabledHoldsBackTheMeshInstallUntilItIsTurnedOffAgain(): void
    {
        [$d, $tok] = $this->enrollDevice($this->issue(), 'H1', false);
        $this->announce($tok, self::caps());
        $this->assertTrue($this->h->module->deviceService()->setAutomationDisabled($d, true, 1));
        $this->approve($d);
        $this->announce($tok, self::caps());
        $this->assertSame([], $this->jobs($d), 'nothing is pushed to a device another tool manages');
        $this->assertTrue($this->h->module->deviceService()->setAutomationDisabled($d, false, 1));
        $this->announce($tok, self::caps());
        $this->assertCount(1, $this->jobs($d), 'and the plan goes on once it is turned off again');
    }
}
