<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\AutomationTestCase;

/** Every Phase 2 read that touches device-bound data is scoped to the caller's clients; out of scope looks exactly like missing. */
final class AutomationScopeTest extends AutomationTestCase
{
    private int $a = 0;
    private int $b = 0;
    private int $scriptId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a] = $this->device('A-1', null, 'linux');
        [$this->b] = $this->device('B-1', $this->h->clientB, 'linux');
        $r = $this->h->module->scriptActions()->createScript($this->tech, ['name' => 'S', 'language' => 'bash', 'body' => "echo x\n"]);
        $this->scriptId = (int) $r->data['script']['script_id'];
        $run = $this->h->module->scriptActions();
        $run->runOnDevice($this->tech, $this->a, ['library_script_id' => $this->scriptId]);
        $run->runOnDevice($this->tech, $this->b, ['library_script_id' => $this->scriptId]);
    }

    public function testScriptRunsListsOnlyDevicesInTheCallersClients(): void
    {
        [$c, $j] = $this->h->tech('GET', ['scripts', (string) $this->scriptId, 'runs'], $this->scoped);
        $this->assertSame([200, [$this->b]], [$c, array_column($j['data'], 'device_id')], 'Dept B only');
        $this->assertArrayNotHasKey('client_id', $j['data'][0]);
        [, $j] = $this->h->tech('GET', ['scripts', (string) $this->scriptId, 'runs'], $this->tech);
        $this->assertEqualsCanonicalizing([$this->a, $this->b], array_column($j['data'], 'device_id'));
    }

    public function testTheLimitCountsOnlyWhatTheCallerMaySee(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->h->module->scriptActions()->runOnDevice($this->tech, $this->a, ['library_script_id' => $this->scriptId]);
        }
        $this->h->module->scriptActions()->runOnDevice($this->tech, $this->b, ['library_script_id' => $this->scriptId]);
        // newest four are on A; the restricted caller still gets both of their rows with limit 2
        [, $j] = $this->h->tech('GET', ['scripts', (string) $this->scriptId, 'runs'], $this->scoped, null, ['limit' => '2']);
        $this->assertSame([$this->b, $this->b], array_column($j['data'], 'device_id'));
    }

    public function testTheRunGrantIsCheckedPerClient(): void
    {
        // user 16 has run_saved; a viewer-only grant on a client hides that client's runs
        $this->h->policy->grant(16, [\RivetCore\Rmm\Authz\RmmAbility::DEVICE_VIEW]);
        $this->h->module->authorizer()->forget();
        [$c, $j] = $this->h->tech('GET', ['scripts', (string) $this->scriptId, 'runs'], $this->scoped);
        $this->assertSame(403, $c, 'no run grant at all');
        $this->h->policy->grant(16, true);
        $this->h->module->authorizer()->forget();
        [, $j] = $this->h->tech('GET', ['scripts', (string) $this->scriptId, 'runs'], $this->scoped);
        $this->assertSame([$this->b], array_column($j['data'], 'device_id'));
    }

    public function testScheduleHistoryIsScopedAndADirectDeviceFilterIs404(): void
    {
        $s = $this->h->module->scriptActions()->saveSchedule($this->admin, null, ['name' => 'N', 'library_script_id' => $this->scriptId, 'target' => ['type' => 'all'], 'kind' => 'interval', 'interval_s' => 3600, 'start_in_s' => 0]);
        $sid = (string) $s->data['schedule']['schedule_id'];
        $this->h->module->housekeeping()->run();
        [, $j] = $this->h->tech('GET', ['schedules', $sid, 'history'], $this->scoped);
        $this->assertSame([$this->b], array_column($j['data'], 'device_id'));
        [$c] = $this->h->tech('GET', ['schedules', $sid, 'history'], $this->scoped, null, ['device_id' => (string) $this->a]);
        $this->assertSame(404, $c);
        [$c] = $this->h->tech('GET', ['schedules', $sid, 'history'], $this->scoped, null, ['device_id' => '99999']);
        $this->assertSame(404, $c, 'the same as a device that is out of scope');
        [, $j] = $this->h->tech('GET', ['schedules', $sid, 'history'], $this->scoped, null, ['limit' => '1']);
        $this->assertCount(1, $j['data'], 'the limit applies after scoping');
    }

    public function testApprovalsOfOtherClientsAreInvisible(): void
    {
        $sec = $this->h->module->scriptActions()->createScript($this->tech, ['name' => 'Gated', 'language' => 'bash', 'body' => 'echo', 'requires_approval' => true])->data['script'];
        $mine = $this->h->module->scriptActions()->runOnDevice($this->tech, $this->b, ['library_script_id' => $sec['script_id']])->data['approval_id'];
        $theirs = $this->h->module->scriptActions()->runOnDevice($this->tech, $this->a, ['library_script_id' => $sec['script_id']])->data['approval_id'];
        $own = $this->h->module->scriptActions()->runOnDevice($this->scoped, $this->b, ['library_script_id' => $sec['script_id']])->data['approval_id'];
        [, $j] = $this->h->tech('GET', ['approvals'], $this->scoped);
        $this->assertEqualsCanonicalizing([$mine, $own], array_column($j['data'], 'approval_id'));
        $this->assertSame(2, $j['total']);
        [$c] = $this->h->tech('GET', ['approvals', (string) $theirs], $this->scoped);
        $this->assertSame(404, $c);
        [$c] = $this->h->tech('GET', ['approvals', '99999'], $this->scoped);
        $this->assertSame(404, $c);
        [$c] = $this->h->tech('POST', ['approvals', (string) $theirs, 'approve'], $this->scoped);
        $this->assertSame(404, $c, 'and it cannot be decided either');
        [$c] = $this->h->tech('GET', ['approvals', (string) $mine], $this->scoped);
        $this->assertSame(200, $c);
        [, $j] = $this->h->tech('GET', ['approvals'], $this->tech);
        $this->assertSame(3, $j['total']);
    }

    public function testPolicyAssignmentsOfOtherClientsAndDevicesAreHidden(): void
    {
        $p = $this->policy('P', ['interval.collect_s' => 45]);
        $store = $this->h->module->policyStore();
        $store->assign((int) $p['policy_id'], ['scope_type' => 'client', 'scope_id' => $this->h->clientA], 1);
        $store->assign((int) $p['policy_id'], ['scope_type' => 'client', 'scope_id' => $this->h->clientB], 1);
        $store->assign((int) $p['policy_id'], ['scope_type' => 'device', 'scope_id' => $this->a], 1);
        $store->assign((int) $p['policy_id'], ['scope_type' => 'device', 'scope_id' => $this->b], 1);
        $store->assign((int) $p['policy_id'], ['scope_type' => 'global'], 1);
        [, $j] = $this->h->tech('GET', ['policies', (string) $p['policy_id']], $this->scoped);
        $got = array_map(static fn (array $a): string => $a['scope_type'] . ':' . $a['scope_id'], $j['assignments']);
        sort($got);
        $this->assertSame(['client:' . $this->h->clientB, 'device:' . $this->b, 'global:0'], $got);
        [, $j] = $this->h->tech('GET', ['policies', (string) $p['policy_id']], $this->tech);
        $this->assertCount(5, $j['assignments']);
    }

    public function testDeviceBoundReadsAnswerTheSame404(): void
    {
        foreach ([[(string) $this->a, 'policy'], [(string) $this->a, 'fields'], [(string) $this->a, 'jobs'], ['99999', 'policy'], ['99999', 'fields']] as [$id, $what]) {
            [$c] = $this->h->tech('GET', [$id, $what], $this->scoped);
            $this->assertSame(404, $c, "$id/$what");
        }
        [$c] = $this->h->tech('POST', ['runs'], $this->scoped, ['library_script_id' => $this->scriptId, 'target' => ['type' => 'device', 'id' => $this->a]]);
        $this->assertSame(422, $c, 'a bulk target outside the caller\'s clients reaches no device');
        $this->assertSame(1, count($this->jobs($this->a)), 'and nothing was queued on it');
    }
}
