<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Approvals\ApprovalService;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Tests\Support\AutomationTestCase;

/** Two-person approval: scripts flagged requires_approval, runs on more devices than the threshold, the state machine, scope, integrity and expiry. */
final class ApprovalsTest extends AutomationTestCase
{
    /** @var list<int> */
    private array $a = [];
    private int $b = 0;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['A-1', 'A-2', 'A-3'] as $n) {
            [$id, $tok] = $this->device($n, null, 'linux');
            $this->a[] = $id;
            $this->tokens[$id] = $tok;
        }
        $this->b = $this->device('B-1', $this->h->clientB, 'linux')[0];
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function script(array $over = []): array
    {
        $r = $this->h->module->scriptActions()->createScript($this->tech, $over + ['name' => 'Patch box', 'language' => 'bash', 'body' => "echo patch\n", 'requires_approval' => true]);
        $this->assertTrue($r->ok, $r->message);

        return $r->data['script'];
    }

    private function actions(): \RivetCore\Rmm\Technician\ScriptActions
    {
        return $this->h->module->scriptActions();
    }

    /** @return array<string,mixed> */
    private function pending(array $script, ?int $device = null): array
    {
        $r = $this->actions()->runOnDevice($this->tech, $device ?? $this->a[0], ['library_script_id' => $script['script_id']]);
        $this->assertSame([true, 202, 'pending_approval'], [$r->ok, $r->http, $r->code], $r->message);

        return $this->h->module->approvals()->get((int) $r->data['approval_id']) ?? [];
    }

    public function testAScriptThatRequiresApprovalWaitsForASecondPerson(): void
    {
        $s = $this->script();
        $a = $this->pending($s);
        $this->assertSame(['run', 'pending_approval', 1, 10, (int) $s['script_id'], 1], [$a['kind'], $a['state'], $a['device_count'], $a['requested_by'], $a['script_id'], $a['script_version']]);
        $this->assertSame([], $this->jobs($this->a[0]), 'nothing is queued before the approval');
        $e = $this->bus->of(RmmEvent::APPROVAL_REQUESTED);
        $this->assertCount(1, $e);
        $this->assertSame([0, null, $a['approval_id'], 'run', 1, 10], [$e[0]['device_id'], $e[0]['asset_id'], $e[0]['approval_id'], $e[0]['kind'], $e[0]['device_count'], $e[0]['requested_by']]);
        $this->assertStringNotContainsString('echo patch', json_encode($this->bus->published()) ?: '');

        $r = $this->actions()->decide($this->approver, (int) $a['approval_id'], true, 'ok from me');
        $this->assertSame([true, 'approved'], [$r->ok, $r->code]);
        $jobs = $this->jobs($this->a[0]);
        $this->assertCount(1, $jobs);
        $this->assertSame(['shell', 10], [$jobs[0]['type'], (int) $jobs[0]['created_by']], 'queued as the person who asked');
        $x = $this->h->rows("SELECT * FROM rmm_job_extra WHERE job_id = '{$jobs[0]['job_id']}'")[0];
        $this->assertSame([(int) $a['approval_id'], hash('sha256', "approval:{$a['approval_id']}:device:{$this->a[0]}")], [(int) $x['approval_id'], $x['idem_key']]);
        $done = $this->h->module->approvals()->get((int) $a['approval_id']);
        $this->assertSame(['approved', 11, 'ok from me', 1, 1], [$done['state'], $done['decided_by'], $done['decision_note'], $done['result']['created'], $done['result']['device_count']]);
        $d = $this->bus->of(RmmEvent::APPROVAL_DECIDED);
        $this->assertSame(['approved', 11], [$d[0]['state'], $d[0]['decided_by']]);
        $this->assertSame('approval', $this->bus->of(RmmEvent::SCRIPT_RUN)[0]['origin']);
        $text = json_encode($this->h->audit->records()) ?: '';
        $this->assertStringNotContainsString('echo patch', $text);
        $this->assertStringContainsString('Approval Requested', $text);
        $this->assertStringContainsString('Approval Granted', $text);
    }

    public function testNobodyApprovesTheirOwnRequest(): void
    {
        $s = $this->script();
        $r = $this->actions()->runOnDevice($this->admin, $this->a[0], ['library_script_id' => $s['script_id']]);
        $this->assertSame(202, $r->http);
        $id = (int) $r->data['approval_id'];
        $d = $this->actions()->decide($this->admin, $id, true);
        $this->assertSame([403, 'own_request'], [$d->http, $d->code]);
        $this->assertSame([], $this->jobs($this->a[0]));
        $this->assertSame('pending_approval', $this->h->module->approvals()->get($id)['state']);
        $this->assertSame(403, $this->actions()->decide($this->tech, $id, true)->http, 'and a role without the approve grant cannot decide');
        $this->assertSame(403, $this->actions()->decide($this->viewer, $id, true)->http);
        $this->assertTrue($this->actions()->decide($this->approver, $id, true)->ok);
    }

    public function testADecisionIsMadeOnce(): void
    {
        $a = $this->pending($this->script());
        $id = (int) $a['approval_id'];
        $this->assertTrue($this->actions()->decide($this->approver, $id, true)->ok);
        $r = $this->actions()->decide($this->approver2, $id, true);
        $this->assertSame([409, 'conflict'], [$r->http, $r->code]);
        $this->assertSame(409, $this->actions()->decide($this->approver2, $id, false)->http);
        $this->assertCount(1, $this->jobs($this->a[0]), 'approved twice would have queued twice');
        $this->assertSame(404, $this->actions()->decide($this->approver, 99999, true)->http);
    }

    public function testRejectingQueuesNothing(): void
    {
        $a = $this->pending($this->script());
        $r = $this->actions()->decide($this->approver, (int) $a['approval_id'], false, 'not now');
        $this->assertSame([true, 'rejected'], [$r->ok, $r->code]);
        $this->assertSame([], $this->jobs($this->a[0]));
        $row = $this->h->module->approvals()->get((int) $a['approval_id']);
        $this->assertSame(['rejected', 'not now'], [$row['state'], $row['decision_note']]);
        $this->assertSame('rejected', $this->bus->of(RmmEvent::APPROVAL_DECIDED)[0]['state']);
    }

    public function testTheRequesterOrAnAdministratorCancels(): void
    {
        $s = $this->script();
        $a = $this->pending($s);
        $id = (int) $a['approval_id'];
        $this->assertSame(404, $this->actions()->cancel($this->approver, $id)->http, 'someone else cannot even see it as theirs');
        $this->assertTrue($this->actions()->cancel($this->tech, $id)->ok);
        $this->assertSame(409, $this->actions()->cancel($this->tech, $id)->http);
        $this->assertSame('cancelled', $this->h->module->approvals()->get($id)['state']);
        $this->assertSame(409, $this->actions()->decide($this->approver, $id, true)->http);
        $b = $this->pending($s);
        $this->assertTrue($this->actions()->cancel($this->admin, (int) $b['approval_id'])->ok);
    }

    public function testARequestLapsesAndHousekeepingSaysSo(): void
    {
        $a = $this->pending($this->script());
        $id = (int) $a['approval_id'];
        $this->setClock(47 * 3600);
        $this->assertSame(0, $this->h->module->housekeeping()->run()['approvals_expired']);
        $this->setClock(2 * 3600);
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['approvals_expired']);
        $this->assertSame('expired', $this->h->module->approvals()->get($id)['state']);
        $e = $this->bus->of(RmmEvent::APPROVAL_DECIDED);
        $this->assertSame(['expired', null], [$e[0]['state'], $e[0]['decided_by']]);
        $r = $this->actions()->decide($this->approver, $id, true);
        $this->assertSame(409, $r->http);
        $this->assertSame([], $this->jobs($this->a[0]));
        // and deciding a request that lapsed but has not been swept yet is refused all the same
        $b = $this->pending($this->script(['name' => 'Second']));
        $this->setClock(49 * 3600);
        $r = $this->actions()->decide($this->approver, (int) $b['approval_id'], true);
        $this->assertSame([409, 'expired'], [$r->http, $r->code]);
    }

    public function testTheExpiryIsALimit(): void
    {
        $this->h->module->settings()->update(['limits_json' => '{"approval_expiry_h":1}']);
        $a = $this->pending($this->script());
        $this->assertSame(3600, strtotime((string) $a['expires_at']) - strtotime((string) $a['requested_at']));
    }

    public function testARunOnMoreDevicesThanTheThresholdNeedsApproval(): void
    {
        $s = $this->script(['name' => 'Plain', 'requires_approval' => false]);
        $a = $this->actions();
        $in = ['library_script_id' => $s['script_id'], 'target' => ['type' => 'client', 'id' => $this->h->clientA]];
        $this->h->module->settings()->update(['limits_json' => '{"approval_bulk_threshold":3}']);
        $r = $a->runOnTarget($this->tech, $in);
        $this->assertSame([true, 201, 3], [$r->ok, $r->http, $r->data['created']], 'three devices are not MORE than three');
        $this->h->q('DELETE FROM endpoint_agent_jobs');
        $this->h->module->settings()->update(['limits_json' => '{"approval_bulk_threshold":2}']);
        $r = $a->runOnTarget($this->tech, $in);
        $this->assertSame([true, 202, 'pending_approval', 3], [$r->ok, $r->http, $r->code, $r->data['device_count']]);
        $this->assertSame([], $this->h->rows('SELECT * FROM endpoint_agent_jobs'));
        $id = (int) $r->data['approval_id'];
        $d = $a->decide($this->approver, $id, true);
        $this->assertTrue($d->ok);
        $this->assertSame(3, $d->data['result']['created']);
        $this->assertCount(3, $this->h->rows('SELECT * FROM endpoint_agent_jobs'));
        $this->h->module->settings()->update(['limits_json' => '{"approval_bulk_threshold":0}']);
        $this->assertSame(201, $a->runOnTarget($this->tech, $in)->http, '0 switches it off (the default)');
    }

    public function testTheApproverMustBeAbleToApproveForEveryClientTheRunReaches(): void
    {
        $s = $this->script();
        $a = $this->actions();
        $r = $a->runOnTarget($this->admin, ['library_script_id' => $s['script_id'], 'target' => ['type' => 'all']]);
        $this->assertSame(202, $r->http);
        $this->assertSame(4, $r->data['device_count']);
        $id = (int) $r->data['approval_id'];
        $d = $a->decide($this->scoped, $id, true);
        $this->assertSame(403, $d->http, 'restricted to Dept B: the request reaches Dept A too');
        $this->assertSame('pending_approval', $this->h->module->approvals()->get($id)['state']);
        $b = $a->runOnTarget($this->admin, ['library_script_id' => $s['script_id'], 'target' => ['type' => 'client', 'id' => $this->h->clientB]]);
        $this->assertSame(1, $b->data['device_count']);
        $d = $a->decide($this->scoped, (int) $b->data['approval_id'], true);
        $this->assertTrue($d->ok, $d->message);
        $this->assertCount(1, $this->jobs($this->b));
        $this->assertTrue($a->decide($this->approver, $id, true)->ok);
        $this->assertCount(1, $this->jobs($this->a[0]));
    }

    public function testTheRequestersOwnScopeBoundsTheApprovedRun(): void
    {
        // The scoped technician asks for "all": the devices it reaches are the ones they can see (Dept B), so the approver is asked about those only.
        $s = $this->script();
        $r = $this->actions()->runOnTarget($this->scoped, ['library_script_id' => $s['script_id'], 'target' => ['type' => 'all']]);
        $this->assertSame([202, 1], [$r->http, $r->data['device_count']]);
        $this->assertTrue($this->actions()->decide($this->approver, (int) $r->data['approval_id'], true)->ok);
        $this->assertSame([1, 0], [count($this->jobs($this->b)), count($this->jobs($this->a[0]))], 'and nothing outside the requester\'s clients is touched');
    }

    public function testAnEditedRequestIsRefused(): void
    {
        $a = $this->pending($this->script());
        $this->h->q("UPDATE rmm_approvals SET request_json = REPLACE(request_json, '\"id\":{$this->a[0]}', '\"id\":{$this->b}') WHERE approval_id = {$a['approval_id']}");
        $r = $this->actions()->decide($this->approver, (int) $a['approval_id'], true);
        $this->assertSame([409, 'conflict'], [$r->http, $r->code]);
        $this->assertStringContainsString('no longer matches its recorded hash', $r->message);
        $this->assertSame([], $this->jobs($this->b));
    }

    public function testTheApprovedTextIsTheTextThatRuns(): void
    {
        $s = $this->script();
        $a = $this->pending($s);
        // the author publishes version 2 after the request: the approval is for version 1
        $this->h->module->scripts()->update((int) $s['script_id'], ['body' => "echo something else\n"], 10);
        $this->assertTrue($this->actions()->decide($this->approver, (int) $a['approval_id'], true)->ok);
        $this->assertSame("echo patch\n", $this->jobs($this->a[0])[0]['script']);
        // a version edited in the database is not run
        $b = $this->pending($s, $this->a[1]);
        $this->h->q("UPDATE rmm_script_versions SET body = 'echo evil' WHERE script_id = {$s['script_id']} AND version = 2");
        $r = $this->actions()->decide($this->approver, (int) $b['approval_id'], true);
        $this->assertSame(409, $r->http);
        $this->assertSame([], $this->jobs($this->a[1]));
        $this->assertSame('pending_approval', $this->h->module->approvals()->get((int) $b['approval_id'])['state'], 'the decision was not recorded');
    }

    public function testARetiredScriptCannotBeApproved(): void
    {
        $s = $this->script();
        $a = $this->pending($s);
        $this->actions()->retireScript($this->tech, (int) $s['script_id']);
        $this->assertSame(409, $this->actions()->decide($this->approver, (int) $a['approval_id'], true)->http);
    }

    public function testAnApprovedRunThatDidNotFinishIsResumedWithoutDuplicates(): void
    {
        $s = $this->script(['name' => 'Many']);
        $r = $this->actions()->runOnTarget($this->admin, ['library_script_id' => $s['script_id'], 'target' => ['type' => 'client', 'id' => $this->h->clientA]]);
        $id = (int) $r->data['approval_id'];
        $this->assertTrue($this->actions()->decide($this->approver, $id, true)->ok);
        $this->assertCount(3, $this->h->rows('SELECT * FROM endpoint_agent_jobs'));
        // a crash after "approved" but before the result was recorded, with one device already done
        $this->h->q("UPDATE rmm_approvals SET result_json = NULL, decided_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE approval_id = $id");
        $this->h->q("DELETE FROM endpoint_agent_jobs WHERE device_id = {$this->a[2]}");
        $this->h->q("DELETE FROM rmm_job_extra WHERE device_id = {$this->a[2]}");
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['approved_runs_resumed']);
        $this->assertCount(3, $this->h->rows('SELECT * FROM endpoint_agent_jobs'), 'only the missing device was added');
        $this->assertSame(1, $this->h->module->approvals()->get($id)['result']['created']);
        $this->assertSame(0, $this->h->module->housekeeping()->run()['approved_runs_resumed'], 'and once is enough');
    }

    public function testSecretParametersInAnApprovedRunMustBePlaceholders(): void
    {
        $s = $this->script(['name' => 'Tok', 'params' => [['name' => 'token', 'type' => 'secret']]]);
        $r = $this->actions()->runOnDevice($this->tech, $this->a[0], ['library_script_id' => $s['script_id'], 'params' => ['token' => 'literal-value']]);
        $this->assertSame(422, $r->http);
        $this->assertStringContainsString('must be a {{field.name}} placeholder', $r->message);
        $f = $this->h->module->fieldActions()->define($this->admin, ['name' => 'tok', 'scope' => 'client', 'type' => 'secret'])->data['field'];
        $this->h->module->fieldActions()->setValue($this->admin, (int) $f['field_id'], $this->h->clientA, 'real-secret-1');
        $r = $this->actions()->runOnDevice($this->tech, $this->a[0], ['library_script_id' => $s['script_id'], 'params' => ['token' => '{{field.tok}}']]);
        $this->assertSame(202, $r->http);
        $this->assertStringNotContainsString('real-secret-1', json_encode($this->h->rows('SELECT * FROM rmm_approvals')) ?: '');
        $this->assertTrue($this->actions()->decide($this->approver, (int) $r->data['approval_id'], true)->ok);
        [, , $j] = $this->h->call('GET', 'agent_jobs', null, $this->tokens[$this->a[0]]);
        $this->assertSame('real-secret-1', $j['jobs'][0]['params']['token'], 'resolved from the field when the approved run is queued');
    }

    /** @var array<int,string> */
    private array $tokens = [];

    public function testTheApprovalRoutes(): void
    {
        $s = $this->script();
        [$c, $j] = $this->h->tech('POST', [(string) $this->a[0], 'jobs'], $this->tech, ['library_script_id' => $s['script_id']]);
        $this->assertSame([202, 'pending_approval'], [$c, $j['state']]);
        $id = (string) $j['approval_id'];
        [$c, $j] = $this->h->tech('GET', ['approvals'], $this->viewer, null, ['state' => 'pending_approval']);
        $this->assertSame([200, 1], [$c, $j['total']]);
        [$c, $j] = $this->h->tech('GET', ['approvals', $id], $this->viewer);
        $this->assertSame([200, 'run'], [$c, $j['approval']['kind']]);
        [$c] = $this->h->tech('POST', ['approvals', $id, 'approve'], $this->tech);
        $this->assertSame(403, $c);
        [$c, $j] = $this->h->tech('POST', ['approvals', $id, 'approve'], $this->approver, ['note' => 'fine']);
        $this->assertSame([200, 'approved', 1], [$c, $j['state'], $j['result']['created']]);
        [$c] = $this->h->tech('POST', ['approvals', $id, 'cancel'], $this->tech);
        $this->assertSame(409, $c);
        [$c] = $this->h->tech('POST', ['approvals', '9999', 'approve'], $this->approver);
        $this->assertSame(404, $c);
        [$c] = $this->h->tech('GET', ['approvals', 'x'], $this->viewer);
        $this->assertSame(404, $c);
        // a bulk run over the API
        [$c, $j] = $this->h->tech('POST', ['runs'], $this->admin, ['library_script_id' => $s['script_id'], 'target' => ['type' => 'tag', 'id' => 99999]]);
        $this->assertSame(422, $c);
        [$c, $j] = $this->h->tech('POST', ['runs'], $this->admin, ['library_script_id' => $s['script_id'], 'target' => ['type' => 'client', 'id' => $this->h->clientA]]);
        $this->assertSame([202, 'pending_approval', 3], [$c, $j['state'], $j['device_count']]);
        [$c] = $this->h->tech('POST', ['runs'], $this->viewer, ['library_script_id' => $s['script_id'], 'target' => ['type' => 'all']]);
        $this->assertSame(403, $c);
        [$c] = $this->h->tech('GET', ['runs'], $this->admin);
        $this->assertSame(405, $c);
    }

    public function testStateConstants(): void
    {
        $this->assertSame(['pending_approval', 'approved', 'rejected', 'cancelled', 'expired'], [ApprovalService::PENDING, ApprovalService::APPROVED, ApprovalService::REJECTED, ApprovalService::CANCELLED, ApprovalService::EXPIRED]);
    }
}
