<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Core security audit 2026-10 (CORE-02, CORE-03): a technician limited to client A must not reach client B through an administrator
 * choice that moves a device, nor change the shared objects (tags, groups, library scripts) that policy scopes and run targets use.
 */
final class TenancyBoundaryAuditTest extends RmmTestCase
{
    protected function makeHarness(): RmmHarness
    {
        $h = new RmmHarness(null, null, [], false, null, new AllowUsersPolicy([1 => true, 2 => true]));
        $h->tenancy->restrictUser(2, [$h->clientA]);

        return $h;
    }

    public function testLimitedTechnicianCannotLinkAPendingDeviceToAnAssetOfAnotherClient(): void
    {
        $h = $this->h;
        $assetB = $h->assets->addAsset(['name' => 'B-CEO-LAPTOP', 'client_id' => $h->clientB, 'serial' => 'B-SERIAL']);
        [, , $a] = $h->enroll($h->token($h->clientA, 24, 5), RmmHarness::device(['hostname' => 'ROGUE-IN-A']));
        $did = (int) $a['device_id'];
        $h->q("UPDATE endpoint_agent_devices SET link_state='pending_approval' WHERE device_id=$did");

        $r = $h->module->technician()->resolvePending($h->principal(2, 'techA'), $did, 'link', $assetB);

        $this->assertFalse($r->ok);
        $this->assertSame(404, $r->http);
        $this->assertSame((string) $h->clientA, (string) $h->one("SELECT client_id FROM endpoint_agent_devices WHERE device_id=$did"), 'the device stays in client A');
        $this->assertSame('pending_approval', $h->one("SELECT link_state FROM endpoint_agent_devices WHERE device_id=$did"));
    }

    public function testAnUnrestrictedAdministratorMayStillLinkAcrossClients(): void
    {
        $h = $this->h;
        $assetB = $h->assets->addAsset(['name' => 'B-LAPTOP', 'client_id' => $h->clientB, 'serial' => 'B-S2']);
        [, , $a] = $h->enroll($h->token($h->clientA, 24, 5), RmmHarness::device(['hostname' => 'MOVE-ME']));
        $did = (int) $a['device_id'];
        $h->q("UPDATE endpoint_agent_devices SET link_state='pending_approval' WHERE device_id=$did");

        $r = $h->module->technician()->resolvePending($h->principal(1, 'admin'), $did, 'link', $assetB);

        $this->assertTrue($r->ok, $r->message);
        $this->assertSame((string) $h->clientB, (string) $h->one("SELECT client_id FROM endpoint_agent_devices WHERE device_id=$did"));
    }

    public function testLimitedTechnicianCannotChangeSharedTagsGroupsOrScripts(): void
    {
        $h = $this->h;
        [, , $b] = $h->enroll($h->token($h->clientB, 24, 5), RmmHarness::device(['hostname' => 'B-PC']));
        $bid = (int) $b['device_id'];
        [, $tj] = $h->tech('POST', [(string) $bid, 'tags'], $h->principal(1), ['tag' => 'prod-b']);
        $tagId = (int) ($tj['tag']['tag_id'] ?? 0);
        $this->assertGreaterThan(0, $tagId);

        [$code] = $h->tech('DELETE', ['tags', (string) $tagId], $h->principal(2, 'techA'));
        $this->assertSame(403, $code);
        $this->assertSame('1', (string) $h->one("SELECT COUNT(*) FROM rmm_device_tags WHERE tag_id=$tagId"), 'client B keeps its tag');

        [$code] = $h->tech('PATCH', ['tags', (string) $tagId], $h->principal(2, 'techA'), ['name' => 'renamed']);
        $this->assertSame(403, $code);

        [$code, $gj] = $h->tech('POST', ['groups'], $h->principal(1), ['name' => 'fleet']);
        $gid = (int) ($gj['group']['group_id'] ?? 0);
        $this->assertGreaterThan(0, $gid);
        [$code] = $h->tech('DELETE', ['groups', (string) $gid], $h->principal(2, 'techA'));
        $this->assertSame(403, $code);
        [$code] = $h->tech('PUT', ['groups', (string) $gid, 'tags'], $h->principal(2, 'techA'), ['tag_ids' => [$tagId]]);
        $this->assertSame(403, $code);

        // the administrator is unaffected
        [$code] = $h->tech('DELETE', ['groups', (string) $gid], $h->principal(1));
        $this->assertSame(200, $code);
    }
}
