<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Enrollment\EnrollmentService;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** CORE-17 approval path: coexisting pending reinstalls and their cap, what "link" will do, one-transaction retire-and-link, tenant isolation. */
final class PendingReinstallTest extends RmmTestCase
{
    private int $asset = 0;
    private int $dev = 0;
    private string $real = '';
    private string $tok = '';
    /** @var array<string,mixed> */
    private array $victim = [];

    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(policy: new AllowUsersPolicy([1 => true, 16 => [RmmAbility::DEVICE_VIEW, RmmAbility::DEVICE_MANAGE]]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->tenancy->restrictUser(16, [$this->h->clientB]);
        $this->h->enable();
        $this->tok = $this->h->token(null, 24, 60);
        $this->asset = $this->h->asset(['name' => 'Victim PC', 'serial' => 'SER-PR-1']);
        $this->victim = $this->h::device(['hostname' => 'VICTIM', 'serial' => 'SER-PR-1']);
        [, , $j] = $this->h->enroll($this->tok, $this->victim);
        $this->dev = (int) $j['device_id'];
        $this->real = (string) $j['device_token'];
    }

    /** @return array{0:int,1:string} */
    private function reinstall(string $host, string $ip = '127.0.0.1'): array
    {
        [$c, , $j] = $this->h->enroll($this->tok, $this->h::device(['machine_guid' => $this->victim['machine_guid'], 'serial' => null, 'hostname' => $host]), $ip);
        $this->assertSame(201, $c);

        return [(int) $j['device_id'], (string) $j['device_token']];
    }

    private function state(int $id): string
    {
        return (string) $this->h->one("SELECT link_state FROM endpoint_agent_devices WHERE device_id=$id");
    }

    public function testPendingReinstallsCoexistUpToTheCapAndApprovalRejectsTheRest(): void
    {
        [$a] = $this->reinstall('RI-A', '10.0.0.1');
        [$b] = $this->reinstall('RI-B', '10.0.0.2');
        [$c] = $this->reinstall('RI-C', '10.0.0.3');
        $this->assertSame(['pending_approval', 'pending_approval', 'pending_approval'], [$this->state($a), $this->state($b), $this->state($c)], 'three coexist');
        $list = $this->h->module->readModel()->deviceView($this->dev, false);
        $this->assertSame([$a, $b, $c], array_column($list['pending_reinstalls'], 'device_id'));
        $this->assertSame(['10.0.0.1', '10.0.0.2', '10.0.0.3'], array_column($list['pending_reinstalls'], 'source_ip'));
        $this->assertNotEmpty($list['pending_reinstalls'][0]['first_seen_at']);
        // the approval queue carries each one with its source address and the consequence of "link"
        $q = array_column($this->h->module->readModel()->pendingApprovals(), null, 'device_id');
        $this->assertSame('10.0.0.2', $q[$b]['source_ip']);
        $this->assertSame($this->dev, $q[$b]['replaces_device_id']);
        $this->assertSame("Linking to asset #{$this->asset} will retire device {$this->dev} (VICTIM) and revoke its credential; its queued jobs are cancelled and its other pending reinstalls are rejected.", $q[$b]['link_consequence']);
        // two more fill the cap, the sixth drops the oldest
        [$d] = $this->reinstall('RI-D');
        [$e] = $this->reinstall('RI-E');
        $this->assertSame('pending_approval', $this->state($a));
        [$f] = $this->reinstall('RI-F');
        $this->assertSame(['rejected', 'pending_approval'], [$this->state($a), $this->state($f)], 'the oldest is dropped when the sixth arrives');
        $this->assertStringContainsString('dropped', (string) $this->h->one("SELECT revoked_reason FROM endpoint_agent_devices WHERE device_id=$a"));
        $this->assertStringContainsString('Reinstall Dropped', json_encode($this->h->audit->records()));
        $this->assertSame(5, count($this->h->module->readModel()->deviceView($this->dev, false)['pending_reinstalls']));
        // approving one rejects the others of the same device
        $r = $this->h->module->deviceService()->approve($c, $this->asset, 1);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(['linked', 'rejected', 'rejected', 'rejected', 'rejected'], [$this->state($c), $this->state($b), $this->state($d), $this->state($e), $this->state($f)]);
        $this->assertNotNull($this->h->one("SELECT retired_at FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
        $this->assertSame([], $this->h->module->readModel()->deviceView($c, false)['pending_reinstalls']);
        $this->assertNull($this->h->module->readModel()->deviceView($c, false)['link_consequence']);
    }

    public function testOnlyAnExplicitLinkToTheReplacedDevicesAssetRetiresIt(): void
    {
        [$n] = $this->reinstall('RI-N');
        $other = $this->h->asset(['name' => 'Other PC', 'serial' => 'SER-PR-OTHER']);
        $r = $this->h->module->deviceService()->approve($n, $other, 1);
        $this->assertTrue($r['ok']);
        $this->assertSame('linked', $this->state($this->dev), 'another asset retires nothing');
        $this->assertNull($this->h->one("SELECT retired_at FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
        [$m] = $this->reinstall('RI-M');
        $this->assertTrue($this->h->module->deviceService()->approveWithNewAsset($m, 1)['ok']);
        $this->assertNull($this->h->one("SELECT retired_at FROM endpoint_agent_devices WHERE device_id={$this->dev}"), 'create_asset retires nothing');
        $this->assertSame(200, $this->h->checkin($this->real)[0]);
    }

    /** One transaction: a failure while linking the newcomer leaves the old device exactly as it was. */
    public function testAFailureWhileLinkingRollsTheWholeApprovalBack(): void
    {
        $jobId = (string) $this->h->module->jobs()->create($this->h->module->devices()->find($this->dev) ?? [], 'collect', null, [], null, false, 1)['job_id'];
        [$a, $ta] = $this->reinstall('RI-A');
        [$b] = $this->reinstall('RI-B');
        $this->h->q("CREATE TRIGGER rc_fail_link BEFORE UPDATE ON endpoint_agent_devices FOR EACH ROW BEGIN IF NEW.match_reason LIKE 'approved_by_%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced'; END IF; END");
        try {
            $threw = false;
            try {
                $this->h->module->deviceService()->approve($a, $this->asset, 1);
            } catch (\Throwable) {
                $threw = true;
            }
            $this->assertTrue($threw, 'the forced failure surfaced');
        } finally {
            $this->h->q('DROP TRIGGER IF EXISTS rc_fail_link');
        }
        $this->assertSame('linked', $this->state($this->dev));
        $this->assertNull($this->h->one("SELECT retired_at FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
        $this->assertSame('queued', $this->h->one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jobId'"));
        $this->assertSame(['pending_approval', 'pending_approval'], [$this->state($a), $this->state($b)], 'the newcomer and the others are untouched');
        $this->assertSame(200, $this->h->call('GET', 'agent_jobs', null, $this->real)[0], 'the old credential is still valid');
        $this->assertNotNull($this->h->bridge->link($this->h->integrationId(), 'rivetit:' . $this->dev), 'its link is still there');
        $this->assertSame(0, substr_count(json_encode($this->h->audit->records()), 'Device Retired'), 'no audit row for something that did not happen');
        // and it works once the failure is gone
        $this->assertTrue($this->h->module->deviceService()->approve($a, $this->asset, 1)['ok']);
        $this->assertSame(['rejected', 'linked'], [$this->state($b), $this->state($a)]);
    }

    /** A client-restricted technician reaches nothing of another client's pending reinstall, and unknown looks the same. */
    public function testAClientRestrictedTechnicianCannotResolveAnotherClientsPendingReinstall(): void
    {
        [$n] = $this->reinstall('RI-N');
        $who = new RmmPrincipal(16, 'Scoped');
        $t = $this->h->module->technician();
        $answers = [];
        foreach ([['link', $this->asset], ['create_asset', null], ['reject', null]] as [$action, $asset]) {
            $real = $t->resolvePending($who, $n, $action, $asset);
            $none = $t->resolvePending($who, 99999999, $action, $asset);
            $this->assertSame([404, $none->http], [$real->http, $real->http], "$action: not found, same as unknown");
            $this->assertSame($none->message, $real->message, "$action: identical answers");
            $answers[] = $real->http;
        }
        $this->assertSame('pending_approval', $this->state($n));
        $this->assertSame('linked', $this->state($this->dev));
        $this->assertSame([], array_filter($this->h->module->readModel()->pendingApprovals([$this->h->clientB]), static fn (array $r): bool => $r['device_id'] === $n));
        $this->assertSame(404 === $answers[0], true);
        // the same call by an administrator works
        $this->assertTrue($t->resolvePending($this->h->principal(), $n, 'link', $this->asset)->ok);
        $this->assertSame(EnrollmentService::REINSTALL_PENDING_MAX, 5);
    }
}
