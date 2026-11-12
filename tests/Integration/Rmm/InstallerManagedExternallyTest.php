<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Installer\InstallerStamp;
use RivetCore\Rmm\Migration\Migration0015EndpointAgentConverge;
use RivetCore\Rmm\Migration\Migration0049InstallerManagedExternally;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** "Managed by another RMM" on an installer / enrollment token (rc.59): stored by token, applied at enrollment as device_automation_disabled = 1. */
final class InstallerManagedExternallyTest extends RmmTestCase
{
    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(null, null, [], false, null, new AllowUsersPolicy([1 => true, 30 => [\RivetCore\Rmm\Authz\RmmAbility::DEVICE_VIEW, \RivetCore\Rmm\Authz\RmmAbility::DEVICE_MANAGE]]), new \RivetCore\Webhooks\UrlPolicy(true));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->enable();
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test']);
        $this->h->publishBinary('1.0.0', 'amd64', 8192, true, null);
    }

    /** @return array{0:int,1:string} */
    private function enrollWith(string $token, string $name, array $over = []): array
    {
        $this->h->asset(['name' => $name, 'serial' => "SER-$name"]);   // a matching asset: the device is linked, so it is a candidate for scheduled targets
        [$c, , $j] = $this->h->enroll($token, $this->h::device(['hostname' => $name, 'serial' => "SER-$name"] + $over));
        $this->assertSame(201, $c, json_encode($j));

        return [(int) $j['device_id'], $j['device_token']];
    }

    private function disabled(int $dev): int
    {
        return (int) $this->h->one("SELECT device_automation_disabled FROM endpoint_agent_devices WHERE device_id = $dev");
    }

    /** @return list<array<string,mixed>> */
    private function auditRows(): array
    {
        return array_values(array_filter($this->h->audit->records(), static fn (array $r): bool => $r['action'] === 'Device Automation Disabled'));
    }

    /** @return array<string,mixed> */
    private function download(string $token): array
    {
        [$c, , $j, $r] = $this->h->call('POST', 'agent_installer', ['token' => $token, 'arch' => 'amd64']);
        $this->assertSame(200, $c, json_encode($j));
        $parsed = InstallerStamp::read((string) file_get_contents($r->file->path) . (string) $r->file->trailer);
        $this->assertNotNull($parsed);

        return $parsed['data'];
    }

    public function testADeviceEnrolledWithTheOptionStartsWithAutomationOffAndIsLeftOutOfScheduledTargets(): void
    {
        $svc = $this->h->module->installerService();
        $r = $svc->issue($this->h->clientA, 0, 'stable', 24, 5, 'ext', 'amd64', 1, 'Admin', false, 'exe', [], 'auto', true);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['managed_externally']);
        $this->assertSame(1, (int) $this->h->one('SELECT managed_externally FROM endpoint_agent_installer_options'));
        $created = array_values(array_filter($this->h->audit->records(), static fn (array $a): bool => $a['action'] === 'Installer Created'));
        $this->assertStringContainsString('managed by another RMM', $created[0]['description']);

        [$ext, $T] = $this->enrollWith($r['token_plain'], 'EXT-1');
        [$plain] = $this->enrollWith($this->h->token(), 'PLAIN-1');
        $this->assertSame(1, $this->disabled($ext));
        $this->assertSame(0, $this->disabled($plain), 'a token without the option is unchanged');
        $rows = $this->auditRows();
        $this->assertCount(1, $rows, 'one audit row');
        $this->assertStringContainsString("device $ext", $rows[0]['description']);
        $this->assertStringContainsString('managed by another RMM', $rows[0]['description']);

        $ids = array_map(static fn (array $d): int => (int) $d['device_id'], $this->h->module->targets()->devices('all', 0, null, [], 0, 100));
        $this->assertContains($plain, $ids);
        $this->assertNotContains($ext, $ids, 'scheduled script targets skip it');
        $this->assertSame(200, $this->h->checkin($T)[0], 'monitoring is unaffected: it still checks in');
    }

    public function testATokenWithoutTheOptionLeavesNoOptionsRowAndTheInstallerPayloadIsUnchanged(): void
    {
        $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 5, 'plain', 'amd64', 1, 'Admin');
        $this->assertTrue($r['ok']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_installer_options'));
        $this->assertSame(['version', 'installer_id', 'server_url', 'enrollment_token', 'department', 'ca_pem', 'created_at', 'expires_at'], array_keys(json_decode((string) $r['payload'], true)));
        [$dev] = $this->enrollWith($r['token_plain'], 'PL-1');
        $this->assertSame(0, $this->disabled($dev));
        $this->assertSame([], $this->auditRows());
    }

    public function testTheChoiceSurvivesTheTokenGatedDownloadWithoutChangingThePayload(): void
    {
        $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 5, 'ext', 'amd64', 1, 'Admin', false, 'exe', [], 'auto', true);
        $this->assertTrue($r['ok']);
        $data = $this->download($r['token_plain']);
        $this->assertSame(['version', 'installer_id', 'server_url', 'enrollment_token', 'department', 'ca_pem', 'created_at', 'expires_at'], array_keys($data), 'the file carries no new member: the choice stays server-side');
        $this->assertSame(1, (int) $this->h->one('SELECT managed_externally FROM endpoint_agent_installer_options'));
        [$dev] = $this->enrollWith($data['enrollment_token'], 'EXT-DL');
        $this->assertSame(1, $this->disabled($dev), 'a machine enrolled with the downloaded installer starts with automation off');
    }

    public function testIssueDownloadAndTheAdminAndTechnicianActionsCarryTheOption(): void
    {
        $admin = $this->h->principal(1, 'Admin');
        $d = $this->h->module->admin()->downloadInstaller($admin, $this->h->clientA, 0, 'stable', 24, 5, 'dl', 'amd64', false, 'exe', [], 'auto', true);
        $this->assertTrue($d->ok, $d->message);
        $c = $this->h->module->admin()->deploymentCommands($admin, $this->h->clientA, 0, 'stable', 24, 5, 'cmds', 'amd64', false, 'exe', [], 'auto', true);
        $this->assertTrue($c->ok, $c->message);
        $t = $this->h->module->technician()->createToken($admin, $this->h->clientA, 0, 'stable', 24, 5, 'tok', true);
        $this->assertTrue($t->ok, $t->message);
        $this->assertSame(3, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_installer_options WHERE managed_externally = 1'));
        [$dev] = $this->enrollWith($t->data['token'], 'EXT-T');
        $this->assertSame(1, $this->disabled($dev));
        $plain = $this->h->module->technician()->createToken($admin, $this->h->clientA, 0, 'stable', 24, 5, 'tok2');
        [$dev2] = $this->enrollWith($plain->data['token'], 'PLAIN-T');
        $this->assertSame(0, $this->disabled($dev2));
        $this->assertSame(3, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_installer_options'), 'the plain token wrote no options row');
    }

    public function testAReEnrollmentWithTheOptionSwitchesAutomationOffOnceAndNeverBackOn(): void
    {
        [$dev] = $this->enrollWith($this->h->token(), 'RE-1', ['install_id' => $iid = $this->h::uuid(), 'machine_guid' => 'abcdef0123456789']);
        $this->assertSame(0, $this->disabled($dev));
        $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 5, 'ext', 'amd64', 1, 'Admin', false, 'exe', [], 'auto', true);
        [$again] = $this->enrollWith($r['token_plain'], 'RE-1', ['install_id' => $iid, 'machine_guid' => 'abcdef0123456789']);
        $this->assertSame($dev, $again);
        $this->assertSame(1, $this->disabled($dev));
        $this->assertCount(1, $this->auditRows());
        $this->enrollWith($r['token_plain'], 'RE-1', ['install_id' => $iid, 'machine_guid' => 'abcdef0123456789']);
        $this->assertCount(1, $this->auditRows(), 'already off: no second row');
        $this->enrollWith($this->h->token(), 'RE-1', ['install_id' => $iid, 'machine_guid' => 'abcdef0123456789']);
        $this->assertSame(1, $this->disabled($dev), 'a plain token never switches it back on');
    }

    public function testTheMigrationIsIdempotentAndRepairsATableWithoutTheColumn(): void
    {
        $m = new Migration0049InstallerManagedExternally();
        $db = $this->h->db;
        $m->up($db);
        $m->up($db);
        $this->assertTrue(Migration0015EndpointAgentConverge::hasColumn($db, 'endpoint_agent_installer_options', 'managed_externally'));
        $this->h->q('ALTER TABLE endpoint_agent_installer_options DROP COLUMN managed_externally');
        $this->assertFalse(Migration0015EndpointAgentConverge::hasColumn($db, 'endpoint_agent_installer_options', 'managed_externally'));
        $m->up($db);
        $m->up($db);
        $this->assertTrue(Migration0015EndpointAgentConverge::hasColumn($db, 'endpoint_agent_installer_options', 'managed_externally'));
        $this->assertSame('0049_rmm_installer_managed_externally', $m->id());
    }

    public function testWithoutTheColumnTheOptionIsRefusedAndNoLiveTokenIsLeftBehind(): void
    {
        $this->h->q('ALTER TABLE endpoint_agent_installer_options DROP COLUMN managed_externally');
        $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 5, 'ext', 'amd64', 1, 'Admin', false, 'exe', [], 'auto', true);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('database is updated', (string) $r['error']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens WHERE revoked_at IS NULL'));
        // and an ordinary token still enrolls: nothing optional breaks enrollment
        [$dev] = $this->enrollWith($this->h->token(), 'NOCOL');
        $this->assertSame(0, $this->disabled($dev));
    }

    // ------------------------------------------------------------------ bulk switch on existing devices

    public function testBulkSetsAndClearsWithAMixedClientSelectionForALimitedTechnician(): void
    {
        $this->h->tenancy->restrictUser(30, [$this->h->clientA]);
        $tech = new \RivetCore\Rmm\Authz\RmmPrincipal(30, 'Limited');
        [$a1] = $this->enrollWith($this->h->token($this->h->clientA), 'BULK-A1');
        [$a2] = $this->enrollWith($this->h->token($this->h->clientA), 'BULK-A2');
        [$b1] = $this->enrollWith($this->h->token($this->h->clientB), 'BULK-B1');
        $t = $this->h->module->technician();

        $r = $t->setAutomationDisabledBulk($tech, [$a1, $a2, $b1, 99999, $a1], true);
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame([$a1, $a2], $r->data['changed']);
        $this->assertSame([], $r->data['unchanged']);
        $this->assertSame([$b1, 99999], $r->data['skipped'], 'another client\'s device and a missing one look the same');
        $this->assertSame([1, 1, 0], [$this->disabled($a1), $this->disabled($a2), $this->disabled($b1)], 'the other client\'s device is never changed');
        $this->assertCount(2, $this->auditRows(), 'one audit row per actual flip');

        $again = $t->setAutomationDisabledBulk($tech, [$a1, $a2, $b1], true);
        $this->assertSame([[], [$a1, $a2], [$b1]], [$again->data['changed'], $again->data['unchanged'], $again->data['skipped']], 'idempotent re-run');
        $this->assertCount(2, $this->auditRows(), 'no new audit rows');

        $clear = $t->setAutomationDisabledBulk($tech, [$a1], false);
        $this->assertSame([[$a1], 0], [$clear->data['changed'], $this->disabled($a1)]);
        $this->assertSame(1, $this->disabled($a2));
    }

    public function testBulkByClientTouchesOnlyThatClientAndNeedsManageRightsThere(): void
    {
        $this->h->tenancy->restrictUser(30, [$this->h->clientA]);
        $tech = new \RivetCore\Rmm\Authz\RmmPrincipal(30, 'Limited');
        [$a1] = $this->enrollWith($this->h->token($this->h->clientA), 'BC-A1');
        [$b1] = $this->enrollWith($this->h->token($this->h->clientB), 'BC-B1');
        $t = $this->h->module->technician();
        $this->assertSame(403, $t->setAutomationDisabledBulk($tech, [], true, $this->h->clientB)->http);
        $this->assertSame(0, $this->disabled($b1));
        $r = $t->setAutomationDisabledBulk($tech, [$b1], true, $this->h->clientA);
        $this->assertSame([$a1], $r->data['changed'], 'ids are ignored when a client is chosen');
        $this->assertSame([1, 0], [$this->disabled($a1), $this->disabled($b1)]);
        $admin = $this->h->principal(1, 'Admin');
        $this->assertSame([$b1], $t->setAutomationDisabledBulk($admin, [], true, $this->h->clientB)->data['changed']);
    }

    public function testBulkWithoutManageRightsChangesNothingAndTheServiceCapIsEnforced(): void
    {
        [$a1] = $this->enrollWith($this->h->token($this->h->clientA), 'CAP-A1');
        $viewer = new \RivetCore\Rmm\Authz\RmmPrincipal(77, 'Nobody');
        $r = $this->h->module->technician()->setAutomationDisabledBulk($viewer, [$a1], true);
        $this->assertSame([[], [$a1]], [$r->data['changed'] ?? [], $r->data['skipped'] ?? [$a1]]);
        $this->assertSame(0, $this->disabled($a1));

        $admin = $this->h->principal(1, 'Admin');
        $max = \RivetCore\Rmm\Device\DeviceService::BULK_MAX;
        $over = range(100000, 100000 + $max);   // BULK_MAX + 1 distinct ids
        $r = $this->h->module->technician()->setAutomationDisabledBulk($admin, $over, true);
        $this->assertSame([false, 422, 'too_many'], [$r->ok, $r->http, $r->code]);
        $this->assertSame(0, $this->disabled($a1));
        $this->expectException(\InvalidArgumentException::class);
        $this->h->module->deviceService()->setAutomationDisabledBulk($over, true, 1);
    }

    public function testBulkServiceReportsEachDeviceAndIsIdempotent(): void
    {
        [$a1] = $this->enrollWith($this->h->token(), 'SVC-A1');
        $r = $this->h->module->deviceService()->setAutomationDisabledBulk([$a1, 4242, $a1], true, 1);
        $this->assertSame(['changed' => [$a1], 'unchanged' => [], 'not_found' => [4242]], $r);
        $this->assertSame(1, $this->disabled($a1));
        $this->assertSame(['changed' => [], 'unchanged' => [$a1], 'not_found' => []], $this->h->module->deviceService()->setAutomationDisabledBulk([$a1], true, 1));
    }
}
