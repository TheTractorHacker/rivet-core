<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\RmmTestCase;

/** The administrator operations on a device: rotate, revoke, allow re-enroll, transfer, retire. Port of the tail of endpoint_agent_checkin.php. */
final class DeviceLifecycleTest extends RmmTestCase
{
    private int $dev = 0;
    private string $T = '';
    private int $asset = 0;
    /** @var array<string,mixed> */
    private array $d = [];

    protected function setUp(): void
    {
        parent::setUp();
        $tok = $this->h->token(null, 24, 100);
        $this->asset = $this->h->asset(['name' => 'Front desk', 'serial' => 'LC-1']);
        $this->d = $this->h::device(['serial' => 'LC-1']);
        [, , $j] = $this->h->enroll($tok, $this->d);
        $this->dev = (int) $j['device_id'];
        $this->T = $j['device_token'];
    }

    private function svc(): \RivetCore\Rmm\Device\DeviceService
    {
        return $this->h->module->deviceService();
    }

    public function testRotationBlocksCheckinAndJobsAndReEnrollmentKeepsTheDevice(): void
    {
        $this->h->checkin($this->T);
        [$c, , $r] = $this->h->call('GET', 'agent_jobs', null, $this->T);
        $this->assertSame(200, $c);
        $this->assertTrue($this->svc()->rotate($this->dev, 1));
        $this->assertFalse($this->svc()->rotate(99999, 1));
        [$c, , $r] = $this->h->checkin($this->T);
        $this->assertSame([401, 'invalid_token'], [$c, $r['code']]);
        $this->assertSame(401, $this->h->call('GET', 'agent_jobs', null, $this->T)[0]);
        [$c, , $j2] = $this->h->enroll($this->h->token(null, 24, 10), $this->d);
        $this->assertSame([201, $this->dev], [$c, $j2['device_id']]);
        $this->assertSame(200, $this->h->checkin($j2['device_token'])[0]);
    }

    public function testRevocationAndAllowReEnroll(): void
    {
        $this->assertTrue($this->svc()->revoke($this->dev, 'test', 1));
        $this->assertFalse($this->svc()->revoke($this->dev, 'again', 1));
        [$c, , $r] = $this->h->checkin($this->T);
        $this->assertSame([401, 'revoked'], [$c, $r['code']]);
        [$c, , $r] = $this->h->call('GET', 'agent_jobs', null, $this->T);
        $this->assertSame([401, 'revoked'], [$c, $r['code']]);
        $this->assertSame(401, $this->h->call('POST', 'agent_jobs', ['job_id' => $this->h::uuid(), 'attempt' => 1, 'state' => 'running'], $this->T)[0]);
        $this->assertSame(403, $this->h->enroll($this->h->token(null, 24, 10), $this->d)[0], 'a revoked device cannot simply re-enroll');
        $this->assertTrue($this->svc()->allowReenroll($this->dev, 1));
        $this->assertFalse($this->svc()->allowReenroll($this->dev, 1), 'nothing left to allow');
        [$c, , $j3] = $this->h->enroll($this->h->token(null, 24, 10), $this->d);
        $this->assertSame([201, $this->dev], [$c, $j3['device_id']]);
        $this->assertSame(200, $this->h->checkin($j3['device_token'])[0]);
    }

    public function testApproveAndRejectDelegateToTheEnrollmentDecision(): void
    {
        $tok = $this->h->token(null, 24, 20);
        $a = $this->h->asset(['name' => 'Target', 'serial' => 'TARGET-1']);
        [, , $p1] = $this->h->enroll($tok, $this->h::device(['serial' => 'NOT-MATCHING-1']));
        [, , $p2] = $this->h->enroll($tok, $this->h::device(['serial' => 'NOT-MATCHING-2']));
        [, , $p3] = $this->h->enroll($tok, $this->h::device(['serial' => 'NOT-MATCHING-3']));
        $this->assertTrue($this->svc()->approve((int) $p1['device_id'], $a, 1)['ok']);
        $this->assertFalse($this->svc()->approve((int) $p1['device_id'], $a, 1)['ok'], 'no longer pending');
        $this->assertFalse($this->svc()->approve((int) $p2['device_id'], $a, 1)['ok'], 'the asset already belongs to another live device');
        $this->assertTrue($this->svc()->approveWithNewAsset((int) $p2['device_id'], 1)['ok']);
        $this->assertTrue($this->svc()->reject((int) $p3['device_id'], 1)['ok']);
        $this->assertSame(['linked', 'linked', 'rejected'], array_column($this->h->rows('SELECT link_state FROM endpoint_agent_devices WHERE device_id IN (' . implode(',', [$p1['device_id'], $p2['device_id'], $p3['device_id']]) . ') ORDER BY device_id'), 'link_state'));
        $this->assertSame(401, $this->h->checkin($p3['device_token'])[0]);
        $this->assertSame(200, $this->h->checkin($p1['device_token'])[0]);
    }

    public function testRejectedDevicesGoBackToPendingWhenAllowed(): void
    {
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 10), $this->h::device(['serial' => 'NOPE-NO-MATCH']));
        $id = (int) $j['device_id'];
        $this->h->module->enrollment()->resolvePending($id, 'reject', null, 1);
        $this->assertSame('rejected', $this->h->one("SELECT link_state FROM endpoint_agent_devices WHERE device_id=$id"));
        $this->svc()->allowReenroll($id, 1);
        $this->assertSame('pending_approval', $this->h->one("SELECT link_state FROM endpoint_agent_devices WHERE device_id=$id"));
    }

    public function testTransferMovesDeviceAssetAndOpenAlerts(): void
    {
        $this->h->bridge->openAlert($this->h->integrationId(), 'agent:x', $this->asset, $this->h->clientA, 'error', 'm', []);
        $this->h->bridge->openAlert($this->h->integrationId(), 'agent:resolved', $this->asset, $this->h->clientA, 'error', 'm', []);
        $this->assertFalse($this->svc()->transfer($this->dev, 999, 0, 1), 'unknown client');
        $this->assertFalse($this->svc()->transfer(99999, $this->h->clientB, 0, 1), 'unknown device');
        $this->assertTrue($this->svc()->transfer($this->dev, $this->h->clientB, 0, 1));
        $this->assertSame($this->h->clientB, (int) $this->h->one("SELECT client_id FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
        $this->assertSame($this->h->clientB, $this->h->assets->row($this->asset)['client_id']);
        $keys = $this->h->bridge->alertKeys();
        $this->assertCount(2, $keys);
        foreach ([1, 2] as $id) {
            $this->assertSame($this->h->clientB, $this->h->bridge->alert($id)['client_id'] ?? $this->h->clientB);
        }
        $this->assertSame(200, $this->h->checkin($this->T)[0], 'the device keeps reporting after the transfer');
        $this->assertSame(1, $this->h->bridge->linkCount($this->h->integrationId()));
        // re-enrolling with a client-A token does not move a transferred device back
        $this->h->enroll($this->h->token(null, 24, 10), $this->d);
        $this->assertSame($this->h->clientB, (int) $this->h->one("SELECT client_id FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
        $this->assertContains('Device Transferred', array_column($this->h->audit->records(), 'action'));
    }

    public function testATransferToAnUnrelatedLocationFallsBackToNoLocation(): void
    {
        $locA = $this->h->tenancy->addLocation($this->h->clientA);
        $this->assertTrue($this->svc()->transfer($this->dev, $this->h->clientB, $locA, 1));
        $this->assertSame(0, (int) $this->h->one("SELECT location_id FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
        $locB = $this->h->tenancy->addLocation($this->h->clientB);
        $this->assertTrue($this->svc()->transfer($this->dev, $this->h->clientB, $locB, 1));
        $this->assertSame($locB, (int) $this->h->one("SELECT location_id FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
    }

    public function testRetireStopsMonitoringKeepsTheAssetAndResolvesAlerts(): void
    {
        $this->h->module->settings()->set(['failure_debounce' => 1]);
        $this->h->checkin($this->T, ['checks' => [['key' => 'disk_c', 'status' => 'fail']]]);
        $alert = (int) $this->h->one("SELECT alert_id FROM endpoint_agent_checks WHERE device_id={$this->dev}");
        $this->assertSame('new', $this->h->bridge->alert($alert)['status']);
        $jobId = (string) $this->h->module->jobs()->create($this->h->module->devices()->find($this->dev) ?? [], 'collect', null, [], null, false, 1)['job_id'];
        $this->assertTrue($this->svc()->retire($this->dev, 1));
        $this->assertFalse($this->svc()->retire($this->dev, 1));
        $this->assertNull($this->h->bridge->link($this->h->integrationId(), "rivetit:{$this->dev}"), 'monitoring link removed');
        $this->assertNotNull($this->h->assets->row($this->asset), 'the asset is kept');
        $this->assertSame('resolved', $this->h->bridge->alert($alert)['status']);
        $this->assertSame(1, count($this->h->bridge->autoClosedAlerts()));
        $this->assertNull($this->h->one("SELECT alert_id FROM endpoint_agent_checks WHERE device_id={$this->dev}"));
        $this->assertSame(['cancelled', 'device_retired'], [$this->h->one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jobId'"), $this->h->one("SELECT reason FROM endpoint_agent_jobs WHERE job_id='$jobId'")]);
        [$c, , $r] = $this->h->checkin($this->T);
        $this->assertSame([401, 'revoked'], [$c, $r['code']]);
        $this->assertSame(403, $this->h->enroll($this->h->token(null, 24, 10), $this->d)[0], 'a retired device must be allowed to re-enroll first');
        $this->assertTrue($this->svc()->allowReenroll($this->dev, 1));
        [$c, , $j] = $this->h->enroll($this->h->token(null, 24, 10), $this->d);
        $this->assertSame(201, $c);
        $this->assertSame(200, $this->h->checkin($j['device_token'])[0]);
        $this->assertNotNull($this->h->bridge->link($this->h->integrationId(), "rivetit:{$this->dev}"), 'the first check-in of a re-enrolled device re-creates its link');
    }

    public function testStatusUsesTheConfiguredThresholds(): void
    {
        $this->h->module->settings()->set(['offline_after_s' => 100, 'stale_after_s' => 1000]);
        $repo = $this->h->module->devices();
        $now = time();
        $cases = [[50, 'online'], [100, 'online'], [101, 'offline'], [1000, 'offline'], [1001, 'stale']];
        foreach ($cases as [$age, $want]) {
            $st = $repo->status(['last_checkin_at' => gmdate('Y-m-d H:i:s', $now - $age)]);
            $this->assertSame($want, $st['state'], "age $age");
        }
        $st = $repo->status(['last_checkin_at' => gmdate('Y-m-d H:i:s', $now - 500)]);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $now - 500 + 100), $st['offline_since']);
    }

    public function testAuthenticationOutcomes(): void
    {
        $repo = $this->h->module->devices();
        $this->assertSame($this->dev, (int) $repo->authenticate('Bearer ' . $this->T)['device_id']);
        foreach ([null, '', 'Bearer', 'Bearer ' . substr($this->T, 1), 'bearer ' . $this->T, 'Bearer ' . $this->T . 'x', 'Basic ' . $this->T] as $bad) {
            try {
                $repo->authenticate($bad);
                $this->fail('should not authenticate: ' . var_export($bad, true));
            } catch (\RivetCore\Rmm\Http\ApiError $e) {
                $this->assertSame('invalid_token', $e->errCode);
            }
        }
        // a stored hash of a revoked device still says "revoked", an emptied hash (rotated) says "invalid"
        $this->svc()->revoke($this->dev, 'x', 1);
        try {
            $repo->authenticate('Bearer ' . $this->T);
            $this->fail('revoked');
        } catch (\RivetCore\Rmm\Http\ApiError $e) {
            $this->assertSame('revoked', $e->errCode);
        }
        $this->svc()->allowReenroll($this->dev, 1);
        try {
            $repo->authenticate('Bearer ' . $this->T);
            $this->fail('cleared');
        } catch (\RivetCore\Rmm\Http\ApiError $e) {
            $this->assertSame('invalid_token', $e->errCode);
        }
        $this->assertSame(1, $repo->liveCount());
    }
}
