<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\RmmTestCase;

/** Device type for new assets (rc.29): the installer's choice by token, the detected type, match-existing fills blanks only, the read model, the audit. */
final class DeviceTypeTest extends RmmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->h->enable();
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test', 'unmatched_policy' => 'auto_create']);
        $this->h->publishBinary('1.0.0', 'amd64', 8192, true, null);
    }

    /** @return array{0:string,1:int} token and token id */
    private function issue(string $type = 'auto'): array
    {
        $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 20, 't', 'amd64', 1, 'Admin', false, 'exe', [], $type);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));

        return [$r['token_plain'], (int) $r['token']['token_id']];
    }

    /** @return array{0:array<string,mixed>,1:int} */
    private function enrollType(string $tok, array $over): array
    {
        [$c, , $j] = $this->h->enroll($tok, $this->h::device($over));
        $this->assertSame(201, $c);

        return [$j, (int) $j['device_id']];
    }

    private function assetType(array $j): string
    {
        return (string) $this->h->assets->row((int) $j['matched_asset_id'])['type'];
    }

    public function testAutoUsesTheDetectedType(): void
    {
        [$tok] = $this->issue();
        [$j] = $this->enrollType($tok, ['os_version' => 'Windows 11', 'chassis_type' => 'desktop', 'has_battery' => false, 'is_virtual' => false, 'virtualization' => 'none']);
        $this->assertSame('Desktop', $this->assetType($j));
        $this->assertSame('Desktop', $this->h->one('SELECT detected_type FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']));
        [$j] = $this->enrollType($tok, ['os' => 'windows', 'os_version' => 'Windows 11', 'chassis_type' => 'other', 'is_virtual' => true, 'virtualization' => 'vmware']);
        $this->assertSame('Virtual Machine', $this->assetType($j));
        [$j] = $this->enrollType($tok, ['os_version' => 'Windows Server 2022', 'role_hints' => ['hypervisor_host']]);
        $this->assertSame('Hypervisor', $this->assetType($j));
        [$j] = $this->enrollType($tok, ['os_version' => 'Windows 11 23H2']);
        $this->assertSame('Laptop', $this->assetType($j), 'an old agent that sends no facts keeps the old default');
    }

    public function testTheInstallersChoiceBeatsDetection(): void
    {
        [$tok, $id] = $this->issue('Server');
        [$j] = $this->enrollType($tok, ['os_version' => 'Windows 11', 'chassis_type' => 'laptop', 'has_battery' => true]);
        $this->assertSame('Server', $this->assetType($j));
        $this->assertSame('Laptop', $this->h->one('SELECT detected_type FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']), 'the detection is still recorded');
        $this->assertSame('Server', $this->h->one("SELECT device_type FROM endpoint_agent_installer_options WHERE token_id=$id"));
        [$tok2] = $this->issue('Virtual Machine');
        [$j] = $this->enrollType($tok2, ['os_version' => 'Windows Server 2022']);
        $this->assertSame('Virtual Machine', $this->assetType($j));
    }

    public function testHostileOrUnknownFactsAreDroppedNotRejected(): void
    {
        [$tok] = $this->issue();
        [$j] = $this->enrollType($tok, ['os_version' => 'Windows 11', 'chassis_type' => '<script>', 'has_battery' => 'yes', 'is_virtual' => 'true', 'virtualization' => str_repeat('x', 5000), 'role_hints' => ['root', ['hypervisor_host']]]);
        $this->assertSame('Laptop', $this->assetType($j));
    }

    public function testAnUnknownDeviceTypeIsRefusedAndLeavesNoToken(): void
    {
        $before = (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens');
        foreach (['Toaster', 'auto; DROP TABLE x', 'laptop', ''] as $bad) {
            $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 5, 't', 'amd64', 1, 'Admin', false, 'exe', [], $bad);
            $this->assertFalse($r['ok'], "type '$bad'");
        }
        $this->assertSame($before, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens'));
    }

    public function testMatchingAnExistingAssetOnlyFillsABlankType(): void
    {
        $this->h->module->settings()->set(['unmatched_policy' => 'approval']);
        [$tok] = $this->issue('Server');
        $kept = $this->h->asset(['name' => 'Typed', 'serial' => 'SER-KEEP-1', 'client_id' => $this->h->clientA]);   // type 'Laptop' set by a human
        [$j] = $this->enrollType($tok, ['serial' => 'SER-KEEP-1', 'os_version' => 'Windows 11', 'chassis_type' => 'desktop']);
        $this->assertSame($kept, $j['matched_asset_id']);
        $this->assertSame('Laptop', $this->h->assets->row($kept)['type'], 'never overwritten, hint or not');

        $blank = $this->h->asset(['name' => 'Blank', 'serial' => 'SER-BLANK-1', 'client_id' => $this->h->clientA, 'type' => '']);
        [$j] = $this->enrollType($tok, ['serial' => 'SER-BLANK-1', 'os_version' => 'Windows 11', 'chassis_type' => 'desktop']);
        $this->assertSame($blank, $j['matched_asset_id']);
        $this->assertSame('Server', $this->h->assets->row($blank)['type'], 'a blank type takes the installer choice');

        [$tokAuto] = $this->issue();
        $blank2 = $this->h->asset(['name' => 'Blank2', 'serial' => 'SER-BLANK-2', 'client_id' => $this->h->clientA, 'type' => '']);
        $this->enrollType($tokAuto, ['serial' => 'SER-BLANK-2', 'os_version' => 'Windows 11', 'chassis_type' => 'tablet']);
        $this->assertSame('Tablet', $this->h->assets->row($blank2)['type'], 'a blank type takes the detected type');
    }

    public function testApprovalCreateAssetUsesTheHintThenTheDetectedType(): void
    {
        $this->h->module->settings()->set(['unmatched_policy' => 'approval']);
        [$tokHint] = $this->issue('Hypervisor');
        [$tokAuto] = $this->issue();
        [$a, $ida] = $this->enrollType($tokHint, ['os_version' => 'Windows 11', 'chassis_type' => 'laptop']);
        [$b, $idb] = $this->enrollType($tokAuto, ['os_version' => 'Windows 11', 'chassis_type' => 'tower', 'has_battery' => false]);
        $pending = array_column($this->h->module->readModel()->pendingApprovals(null), 'suggested_type', 'device_id');
        $this->assertSame('Hypervisor', $pending[$ida]);
        $this->assertSame('Desktop', $pending[$idb]);
        $svc = $this->h->module->enrollment();
        $this->assertTrue($svc->resolvePending($ida, 'create_asset', null, 1)['ok']);
        $this->assertTrue($svc->resolvePending($idb, 'create_asset', null, 1)['ok']);
        $assetOf = fn (int $id): array => $this->h->assets->row((int) $this->h->one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id=$id"));
        $this->assertSame('Hypervisor', $assetOf($ida)['type']);
        $this->assertSame('Desktop', $assetOf($idb)['type']);
    }

    public function testAnEditionWithoutTheNewTypesFallsBack(): void
    {
        $this->h->assets->typeNames = ['Laptop', 'Server'];
        [$tok] = $this->issue();
        [$j] = $this->enrollType($tok, ['os_version' => 'Windows 11', 'role_hints' => ['hypervisor_host']]);
        $this->assertSame('Server', $this->assetType($j));
        [$j] = $this->enrollType($tok, ['os_version' => 'Windows 11', 'chassis_type' => 'desktop']);
        $this->assertSame('Laptop', $this->assetType($j));
    }

    public function testCheckinInventoryRefreshesTheDetectionAndFillsABlankAssetType(): void
    {
        $this->h->module->settings()->set(['unmatched_policy' => 'approval']);
        [$tok] = $this->issue();
        $blank = $this->h->asset(['name' => 'B3', 'serial' => 'SER-BLANK-3', 'client_id' => $this->h->clientA, 'type' => '']);
        [$j] = $this->enrollType($tok, ['serial' => 'SER-BLANK-3', 'os_version' => 'Windows 11']);
        $this->assertSame('Laptop', $this->h->assets->row($blank)['type']);
        $this->h->assets->addAsset([]);   // unrelated asset: nothing else changes
        $inv = ['hostname' => 'X', 'os' => 'windows', 'os_version' => 'Windows 11', 'chassis_type' => 'rack_server', 'has_battery' => false, 'role_hints' => ['domain_controller', 'bogus']];
        [$c] = $this->h->checkin($j['device_token'], ['inventory' => $inv]);
        $this->assertSame(200, $c);
        $this->assertSame('Server', $this->h->one('SELECT detected_type FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']));
        $this->assertSame('Laptop', $this->h->assets->row($blank)['type'], 'already filled at link time: not overwritten');
        $stored = json_decode((string) $this->h->one('SELECT inventory_json FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']), true);
        $this->assertSame(['domain_controller'], $stored['role_hints']);
    }

    public function testTheReadModelAndTheAuditShowTheChoice(): void
    {
        [, $id] = $this->issue('Desktop');
        [, $id2] = $this->issue();
        $types = array_column($this->h->module->readModel()->tokens(), 'device_type', 'token_id');
        $this->assertSame('Desktop', $types[$id]);
        $this->assertSame('auto', $types[$id2]);
        $texts = array_column($this->h->audit->records(), 'description');
        $this->assertCount(1, array_filter($texts, static fn (string $t): bool => str_contains($t, 'new assets get the type "Desktop"')));
        $this->assertCount(1, array_filter($texts, static fn (string $t): bool => str_contains($t, 'new assets get the type') === false && str_contains($t, 'created installer')));
    }

    public function testTheAdminActionsTakeTheChoice(): void
    {
        $a = $this->h->module->admin();
        $r = $a->deploymentCommands($this->h->principal(), $this->h->clientA, 0, 'stable', 24, 5, 'w', 'amd64', false, 'exe', [], 'Tablet');
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame('Tablet', $this->h->one('SELECT device_type FROM endpoint_agent_installer_options WHERE token_id=' . $r->data['token']['token_id']));
        $this->assertFalse($a->deploymentCommands($this->h->principal(), $this->h->clientA, 0, 'stable', 24, 5, 'w', 'amd64', false, 'exe', [], 'Nope')->ok);
        $this->assertStringNotContainsString('Tablet', $r->data['commands']['powershell'], 'the choice lives server-side by token, not in the commands');
    }
}
