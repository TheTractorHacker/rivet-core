<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Tests\Support\AutomationTestCase;

/** Policies end to end: assignment by scope, what a check-in delivers, old agents, switches, editing, administration rules. */
final class PoliciesTest extends AutomationTestCase
{
    private int $d1 = 0;
    private string $t1 = '';
    private int $d2 = 0;
    private string $t2 = '';

    protected function setUp(): void
    {
        parent::setUp();
        [$this->d1, $this->t1] = $this->device('PC-1');
        [$this->d2, $this->t2] = $this->device('PC-2');
    }

    /** @param array<string,mixed> $r */
    private static function checkKeys(array $r): array
    {
        return array_column($r['config']['checks'], 'key');
    }

    private function dropVolatile(array $r): array
    {
        unset($r['server_time']);

        return $r;
    }

    public function testWithoutAnyPolicyTheResponseIsExactlyWhatItWasBeforePolicies(): void
    {
        $this->features(['policies' => false]);
        $off = $this->dropVolatile($this->checkin($this->t1, null));
        $this->features([]);
        $onOld = $this->dropVolatile($this->checkin($this->t1, null));
        $onNew = $this->dropVolatile($this->checkin($this->t1));
        $this->assertSame($off, $onOld, 'an old agent sees no difference from the policies switch');
        $this->assertSame($off['config'], array_diff_key($onNew['config'], ['policy' => 1]), 'a new agent gets the same checks and intervals, plus the policy block');
        $this->assertSame(['policies', 'software_inventory'], $onNew['features'], 'and is told the policies feature is on');
        $this->assertFalse($onNew['config']['policy']['applied']);
        $this->assertArrayNotHasKey('features', $off);
    }

    public function testPolicyReachesOnlyTheDevicesItIsAssignedTo(): void
    {
        $p = $this->policy('Servers', ['interval.collect_s' => 45, 'check.svc_ssh' => ['type' => 'service', 'params' => ['name' => 'sshd'], 'interval_s' => 90], 'check.disk_c' => ['mode' => 'disable']]);
        $tag = $this->tagDevice($this->d1, 'server');
        $this->h->module->policyStore()->assign((int) $p['policy_id'], ['scope_type' => 'tag', 'scope_id' => $tag], 1);

        $r1 = $this->checkin($this->t1);
        $r2 = $this->checkin($this->t2);
        $this->assertSame(45, $r1['config']['collect_interval_s']);
        $this->assertSame(['pending_reboot', 'svc_eventlog', 'svc_ssh'], self::checkKeys($r1), 'disk_c disabled, svc_ssh added');
        $this->assertSame(60, $r2['config']['collect_interval_s'], 'another device is untouched');
        $this->assertSame(['disk_c', 'pending_reboot', 'svc_eventlog'], self::checkKeys($r2));
        $this->assertTrue($r1['config']['policy']['applied']);
        $this->assertFalse($r2['config']['policy']['applied']);
        $this->assertNotSame($r1['config']['policy']['version'], $r2['config']['policy']['version']);

        // the delivered checks are signed by the instance key like the global ones
        [, $pub] = $this->h->module->settings()->signingKey();
        foreach ($r1['config']['checks'] as $c) {
            $this->assertTrue(Signer::verify(Signer::checkMessage($c), $c['signature'], $pub), $c['key']);
        }
        $this->assertSame(['name' => 'sshd'], $r1['config']['checks'][2]['params']);
    }

    public function testAnOldAgentGetsThePolicyValuesButNoNewKeys(): void
    {
        $p = $this->policy('Fast', ['interval.collect_s' => 45, 'interval.check_in_s' => 120]);
        $this->h->module->policyStore()->assign((int) $p['policy_id'], ['scope_type' => 'device', 'scope_id' => $this->d1], 1);
        $old = $this->checkin($this->t1, null);
        $this->assertSame([120, 45], [$old['next_check_in_s'], $old['config']['collect_interval_s']]);
        $this->assertSame(['checks', 'collect_interval_s'], array_keys($old['config']), 'no policy block for an agent that did not announce the capability');
        $this->assertArrayNotHasKey('features', $old);
        $new = $this->checkin($this->t1);
        $this->assertSame(['checks', 'collect_interval_s', 'policy'], array_keys($new['config']));
        $this->assertSame(['software_inventory', 'script_checks', 'checks'], array_keys($new['config']['policy']['features']));
    }

    public function testPrecedenceThroughTheDatabase(): void
    {
        $store = $this->h->module->policyStore();
        $tag = $this->tagDevice($this->d1, 'edge');
        $group = $this->h->module->groups()->create('Floor 2', '', 1);
        $this->h->module->groups()->addDevices((int) $group['group_id'], [$this->d1]);
        $this->h->q("UPDATE endpoint_agent_devices SET location_id = 77 WHERE device_id = {$this->d1}");
        $levels = [['global', 0, 600], ['client', $this->h->clientA, 500], ['site', 77, 400], ['group', (int) $group['group_id'], 300], ['tag', $tag, 200], ['device', $this->d1, 100]];
        $ids = [];
        foreach ($levels as [$type, $scope, $value]) {
            $p = $this->policy("P-$type", ['interval.collect_s' => $value]);
            $ids[$type] = $store->assign((int) $p['policy_id'], ['scope_type' => $type, 'scope_id' => $scope], 1)['assignment_id'];
        }
        $seen = [];
        foreach (['device', 'tag', 'group', 'site', 'client', 'global'] as $type) {
            $seen[$type] = $this->checkin($this->t1)['config']['collect_interval_s'];
            $store->unassign((int) $ids[$type]);
        }
        $this->assertSame(['device' => 100, 'tag' => 200, 'group' => 300, 'site' => 400, 'client' => 500, 'global' => 600], $seen, 'each level wins until it is removed');
        $this->assertSame(60, $this->checkin($this->t1)['config']['collect_interval_s'], 'and the instance value is back');
        // the other device (no tag, no group, no site) only ever saw global and client
        $this->assertSame(60, $this->checkin($this->t2)['config']['collect_interval_s']);
    }

    public function testEnforceAndPriorityAndOverrides(): void
    {
        $store = $this->h->module->policyStore();
        $tag = $this->tagDevice($this->d1, 'edge');
        $corp = $this->policy('Corporate', ['interval.collect_s' => 300, 'feature.script_checks' => false]);
        $edge = $this->policy('Edge', ['interval.collect_s' => 50, 'feature.script_checks' => true]);
        $store->assign((int) $corp['policy_id'], ['scope_type' => 'client', 'scope_id' => $this->h->clientA, 'enforce' => true], 1);
        $assign = $store->assign((int) $edge['policy_id'], ['scope_type' => 'tag', 'scope_id' => $tag], 1);
        $r = $this->checkin($this->t1);
        $this->assertSame(300, $r['config']['collect_interval_s'], 'an enforced client policy cannot be overridden by a tag');
        $this->assertFalse($r['config']['policy']['features']['script_checks']);
        // an assignment-level override can still change what the policy says for its OWN scope
        $store->assign((int) $edge['policy_id'], ['scope_type' => 'tag', 'scope_id' => $tag, 'overrides' => ['interval.collect_s' => ['mode' => 'inherit']]], 1);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policy_assignments WHERE scope_type = "tag"'));
        $this->assertSame((int) $assign['assignment_id'], (int) $this->h->one('SELECT assignment_id FROM rmm_policy_assignments WHERE scope_type = "tag"'), 'assigning again updates in place');
        $store->unassign((int) $this->h->one('SELECT assignment_id FROM rmm_policy_assignments WHERE scope_type = "client"'));
        $r = $this->checkin($this->t1);
        $this->assertSame(60, $r['config']['collect_interval_s'], 'the tag policy no longer sets the interval: it was cancelled for this assignment');
        $this->assertTrue($r['config']['policy']['features']['script_checks']);
    }

    public function testAnEditReachesTheDeviceAtItsNextCheckinAndIsVersioned(): void
    {
        $p = $this->policy('Tune', ['interval.collect_s' => 45]);
        $store = $this->h->module->policyStore();
        $store->assign((int) $p['policy_id'], ['scope_type' => 'device', 'scope_id' => $this->d1], 1);
        $v1 = $this->checkin($this->t1)['config']['policy']['version'];
        $this->assertSame(45, $this->checkin($this->t1)['config']['collect_interval_s']);
        $u = $store->update((int) $p['policy_id'], ['settings' => ['interval.collect_s' => 90]], 2);
        $this->assertSame(2, $u['version']);
        $r = $this->checkin($this->t1);
        $this->assertSame(90, $r['config']['collect_interval_s']);
        $this->assertNotSame($v1, $r['config']['policy']['version']);
        $same = $store->update((int) $p['policy_id'], ['settings' => ['interval.collect_s' => 90], 'description' => 'now with words'], 2);
        $this->assertSame(2, $same['version'], 'an edit that changes no setting is not a new version');
        $this->assertSame([2, 1], array_column($store->versions((int) $p['policy_id']), 'version'));
        $this->assertSame(['interval.collect_s' => ['mode' => 'override', 'value' => 45]], $store->versions((int) $p['policy_id'], true)[1]['settings']);
        // switched off: it reaches nobody, and the assignment is kept
        $store->update((int) $p['policy_id'], ['enabled' => false], 2);
        $this->assertSame(60, $this->checkin($this->t1)['config']['collect_interval_s']);
        $store->update((int) $p['policy_id'], ['enabled' => true], 2);
        $this->assertSame(90, $this->checkin($this->t1)['config']['collect_interval_s']);
        // deleted: the devices fall back
        $this->assertTrue($store->delete((int) $p['policy_id']));
        $this->assertSame(60, $this->checkin($this->t1)['config']['collect_interval_s']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policy_assignments'));
    }

    public function testIntervalsStayInsideTheGlobalClamps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('from 30 to 3600');
        $this->policy('Too fast', ['interval.collect_s' => 5]);
    }

    public function testFeatureTogglesAndTheSoftwareOffer(): void
    {
        $this->assertSame(['policies', 'software_inventory'], $this->checkin($this->t1)['features']);
        $p = $this->policy('No software', ['feature.software_inventory' => false, 'feature.script_checks' => false]);
        $this->h->module->policyStore()->assign((int) $p['policy_id'], ['scope_type' => 'device', 'scope_id' => $this->d1], 1);
        $r = $this->checkin($this->t1);
        $this->assertSame(['policies'], $r['features'], 'the software report is not asked of this device');
        $this->assertSame(['policies', 'software_inventory'], $this->checkin($this->t2)['features']);
    }

    public function testChecksOfATypeTheAgentCannotRunAreNotOffered(): void
    {
        $p = $this->policy('Cert', ['check.cert' => ['type' => 'script', 'params' => ['script' => 'exit 0'], 'interval_s' => 600]]);
        $this->h->module->policyStore()->assign((int) $p['policy_id'], ['scope_type' => 'global'], 1);
        $this->assertContains('cert', self::checkKeys($this->checkin($this->t1)));
        $r = $this->checkin($this->t1, ['check:disk', 'check:service', 'policies']);
        $this->assertSame(['disk_c', 'svc_eventlog'], self::checkKeys($r), 'pending_reboot and the script check are left out for an agent that announced only disk and service');
        $this->assertSame(['disk_c', 'pending_reboot', 'svc_eventlog', 'cert'], self::checkKeys($this->checkin($this->t2, null)), 'an old agent is sent everything');
    }

    public function testRingPinning(): void
    {
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test']);
        $this->h->publishBinary('2.0.0', 'amd64', 8192, false, 'pilot', 100);
        $before = $this->checkin($this->t1);
        $this->assertNull($before['update'] ?? null, 'a stable device is not offered a pilot release');
        $p = $this->policy('Pilot', ['agent.ring' => 'pilot']);
        $this->h->module->policyStore()->assign((int) $p['policy_id'], ['scope_type' => 'device', 'scope_id' => $this->d1], 1);
        $r = $this->checkin($this->t1);
        $this->assertSame('2.0.0', $r['update']['version'] ?? null, 'the policy pins the device to the pilot ring');
        $this->assertSame('stable', $this->h->one("SELECT ring FROM endpoint_agent_devices WHERE device_id = {$this->d1}"), 'the stored ring is not changed');
    }

    public function testNothingIsReadFromThePolicyTablesWhileTheSwitchIsOff(): void
    {
        $p = $this->policy('Fast', ['interval.collect_s' => 45]);
        $this->h->module->policyStore()->assign((int) $p['policy_id'], ['scope_type' => 'global'], 1);
        $this->features(['policies' => false]);
        $this->assertSame(60, $this->checkin($this->t1)['config']['collect_interval_s']);
        $this->features([]);
        $this->assertSame(45, $this->checkin($this->t1)['config']['collect_interval_s']);
    }

    public function testAssignmentValidation(): void
    {
        $p = $this->policy('X', ['interval.collect_s' => 45]);
        $id = (int) $p['policy_id'];
        $store = $this->h->module->policyStore();
        foreach ([
            [['scope_type' => 'planet', 'scope_id' => 1], 'scope_type must be'],
            [['scope_type' => 'tag', 'scope_id' => 9999], 'tag does not exist'],
            [['scope_type' => 'group'], 'scope_id is required'],
            [['scope_type' => 'device', 'scope_id' => 99999], 'device does not exist'],
            [['scope_type' => 'client', 'scope_id' => 99999], 'client does not exist'],
            [['scope_type' => 'global', 'priority' => -1], 'priority'],
            [['scope_type' => 'global', 'overrides' => ['nope' => 1]], 'Unknown setting'],
        ] as [$in, $needle]) {
            try {
                $store->assign($id, $in, 1);
                $this->fail('accepted ' . json_encode($in));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $store->create(['name' => 'X', 'settings' => []], 1);
    }

    public function testOrphanAssignmentsAreCleanedUp(): void
    {
        $p = $this->policy('X', ['interval.collect_s' => 45]);
        $tag = $this->tagDevice($this->d1, 'gone');
        $this->h->module->policyStore()->assign((int) $p['policy_id'], ['scope_type' => 'tag', 'scope_id' => $tag], 1);
        $this->h->module->tags()->delete($tag);
        $this->assertSame(1, $this->h->module->policyStore()->dropOrphanAssignments());
    }

    // ------------------------------------------------------------------ administration, API, audit and events

    public function testOnlyAnAdministratorMayManagePolicies(): void
    {
        $a = $this->h->module->policyActions();
        foreach ([$this->tech, $this->viewer, $this->approver] as $who) {
            $r = $a->create($who, ['name' => 'Nope', 'settings' => []]);
            $this->assertSame([403, 'forbidden'], [$r->http, $r->code], $who->userName);
        }
        $r = $a->create($this->admin, ['name' => 'Fleet', 'settings' => ['interval.collect_s' => 45]]);
        $this->assertSame([true, 201], [$r->ok, $r->http]);
        $id = (int) $r->data['policy']['policy_id'];
        $this->assertSame(403, $a->update($this->tech, $id, ['enabled' => false])->http);
        $this->assertSame(403, $a->assign($this->tech, $id, ['scope_type' => 'global'])->http);
        $this->assertSame(403, $a->delete($this->tech, $id)->http);
        $this->assertSame(404, $a->update($this->admin, 9999, ['enabled' => false])->http);
        $this->assertSame(422, $a->create($this->admin, ['name' => 'Fleet', 'settings' => []])->http, 'duplicate name');
        $this->assertSame(422, $a->create($this->admin, ['name' => 'Bad', 'settings' => ['interval.collect_s' => 1]])->http);
    }

    public function testAssignmentsAreAuditedAndPublishedAsEvents(): void
    {
        $a = $this->h->module->policyActions();
        $id = (int) $a->create($this->admin, ['name' => 'Fleet', 'settings' => ['interval.collect_s' => 45]])->data['policy']['policy_id'];
        $r = $a->assign($this->admin, $id, ['scope_type' => 'client', 'scope_id' => $this->h->clientA, 'enforce' => true]);
        $this->assertTrue($r->ok);
        $e = $this->bus->of(RmmEvent::POLICY_ASSIGNED);
        $this->assertCount(1, $e);
        $this->assertSame([0, null, $this->h->clientA, '', $id, 'Fleet', 'client', $this->h->clientA, 'assigned'],
            [$e[0]['device_id'], $e[0]['asset_id'], $e[0]['client_id'], $e[0]['hostname'], $e[0]['policy_id'], $e[0]['policy_name'], $e[0]['scope_type'], $e[0]['scope_id'], $e[0]['action']]);
        $this->assertTrue($a->unassign($this->admin, (int) $r->data['assignment']['assignment_id'])->ok);
        $this->assertSame('unassigned', $this->bus->of(RmmEvent::POLICY_ASSIGNED)[1]['action']);
        $this->assertNotEmpty(array_filter($this->h->audit->records(), static fn (array $x): bool => str_contains((string) ($x['action'] ?? ''), 'Policy Assigned')));
    }

    public function testTheTechnicianApiRoutes(): void
    {
        [$c, $j] = $this->h->tech('POST', ['policies'], $this->admin, ['name' => 'Api', 'settings' => ['interval.collect_s' => 70]]);
        $this->assertSame(201, $c);
        $id = $j['policy']['policy_id'];
        [$c, $j] = $this->h->tech('POST', ['policies', (string) $id, 'assignments'], $this->admin, ['scope_type' => 'device', 'scope_id' => $this->d1]);
        $this->assertSame(200, $c);
        $aid = $j['assignment']['assignment_id'];
        [$c, $j] = $this->h->tech('GET', ['policies'], $this->tech);
        $this->assertSame([200, 'Api', 1], [$c, $j['data'][0]['name'], $j['data'][0]['assignments']], 'a technician may read');
        [$c, $j] = $this->h->tech('GET', ['policies', (string) $id], $this->tech);
        $this->assertSame([200, 1], [$c, count($j['assignments'])]);
        [$c] = $this->h->tech('POST', ['policies'], $this->tech, ['name' => 'Nope', 'settings' => []]);
        $this->assertSame(403, $c);
        [$c, $j] = $this->h->tech('GET', [(string) $this->d1, 'policy'], $this->tech);
        $this->assertSame(200, $c);
        $this->assertSame([70, true, 'device'], [$j['collect_interval_s'], $j['applied'], $j['layers'][0]['scope_type']]);
        $this->assertSame('Api', $j['layers'][0]['policy']);
        $this->assertSame('device', $j['sources']['interval.collect_s']['scope_type']);
        [$c, $j] = $this->h->tech('GET', [(string) $this->d2, 'policy'], $this->tech);
        $this->assertSame([200, false], [$c, $j['applied']]);
        [$c] = $this->h->tech('GET', [(string) $this->d1, 'policy'], null);
        $this->assertSame(401, $c);
        [$c, $j] = $this->h->tech('PATCH', ['policies', (string) $id], $this->admin, ['settings' => ['interval.collect_s' => 80]]);
        $this->assertSame([200, 2], [$c, $j['policy']['version']]);
        [$c, $j] = $this->h->tech('GET', ['policies', (string) $id, 'versions'], $this->tech);
        $this->assertSame([200, 2], [$c, count($j['data'])]);
        [$c] = $this->h->tech('DELETE', ['policies', (string) $id, 'assignments', (string) ($aid + 100)], $this->admin);
        $this->assertSame(404, $c);
        [$c] = $this->h->tech('DELETE', ['policies', (string) $id, 'assignments', (string) $aid], $this->admin);
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('DELETE', ['policies', (string) $id], $this->admin);
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('GET', ['policies', (string) $id], $this->tech);
        $this->assertSame(404, $c);
    }

    public function testAClientScopedTechnicianCannotReadAnotherClientsDevicePolicy(): void
    {
        [$other] = $this->device('B-1', $this->h->clientB);
        [$c] = $this->h->tech('GET', [(string) $this->d1, 'policy'], $this->scoped);
        $this->assertSame(404, $c, 'same answer as a missing device');
        [$c] = $this->h->tech('GET', [(string) $other, 'policy'], $this->scoped);
        $this->assertSame(200, $c);
    }
}
