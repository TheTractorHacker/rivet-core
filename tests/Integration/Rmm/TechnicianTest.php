<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Testing\InMemoryRmmModuleState;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** TechnicianActions and TechnicianApi: job submission rules, cancel, the administrative device operations, tokens, routing and shapes. */
final class TechnicianTest extends RmmTestCase
{
    private RmmPrincipal $admin;
    private RmmPrincipal $tech;
    private RmmPrincipal $viewer;
    private RmmPrincipal $scoped;
    private int $dev = 0;
    private string $devToken = '';

    protected function makeHarness(): RmmHarness
    {
        $all = [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED, RmmAbility::JOB_RUN_SCRIPT, RmmAbility::JOB_REBOOT, RmmAbility::REMOTE_LAUNCH];

        return new RmmHarness(policy: new AllowUsersPolicy([1 => true, 10 => $all, 12 => [RmmAbility::DEVICE_VIEW], 16 => [...$all, RmmAbility::DEVICE_MANAGE]]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->tech = new RmmPrincipal(10, 'Tech');
        $this->viewer = new RmmPrincipal(12, 'Viewer');
        $this->scoped = new RmmPrincipal(16, 'Scoped');
        $this->h->tenancy->restrictUser(16, [$this->h->clientB]);
        $this->h->enable();
        [$this->dev, $this->devToken] = $this->linked('T1');
    }

    /** @return array{0:int,1:string} */
    private function linked(string $name, ?int $client = null): array
    {
        $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $client ?? $this->h->clientA]);
        [$c, , $j] = $this->h->enroll($this->h->token($client), $this->h::device(['serial' => "SER-$name", 'hostname' => $name]));
        $this->assertSame(201, $c);
        $this->assertSame('linked', $j['status']);

        return [(int) $j['device_id'], $j['device_token']];
    }

    private function t(): \RivetCore\Rmm\Technician\TechnicianActions
    {
        return $this->h->module->technician();
    }

    // ------------------------------------------------------------------ jobs

    public function testSubmitRules(): void
    {
        $t = $this->t();
        $r = $t->submitJob($this->tech, $this->dev, ['type' => 'format-disk']);
        $this->assertSame([false, 422, 'invalid', 'Unknown job type.'], [$r->ok, $r->http, $r->code, $r->message]);
        $r = $t->submitJob($this->tech, $this->dev, ['type' => 'reboot']);
        $this->assertSame([422, 'confirmation_required'], [$r->http, $r->code], 'a reboot needs an explicit confirmation');
        $r = $t->submitJob($this->tech, $this->dev, ['type' => 'powershell', 'script' => 'Get-Date', 'destructive' => true]);
        $this->assertSame('confirmation_required', $r->code, 'any destructive job does');
        $r = $t->submitJob($this->tech, $this->dev, ['type' => 'reboot', 'confirm' => true, 'params' => ['delay_s' => 10]]);
        $this->assertSame([true, 201, 'queued'], [$r->ok, $r->http, $r->code]);
        $job = $this->h->rows("SELECT * FROM endpoint_agent_jobs WHERE job_id='{$r->data['job_id']}'")[0];
        $this->assertSame([1, 10, 'reboot'], [(int) $job['destructive'], (int) $job['created_by'], $job['type']]);
        $this->assertStringContainsString('"delay_s":10', (string) $job['params_json']);
        $r = $t->submitJob($this->tech, $this->dev, ['type' => 'reboot', 'confirm' => true, 'params' => ['delay_s' => 2]]);
        $this->assertSame([422, 'invalid'], [$r->http, $r->code]);
        $r = $t->submitJob($this->tech, $this->dev, ['type' => 'powershell']);
        $this->assertSame(422, $r->http);
        $this->assertStringContainsString('needs a script', $r->message);
        $r = $t->submitJob($this->tech, $this->dev, ['type' => 'collect', 'timeout_s' => 999999]);
        $this->assertStringContainsString('Timeout must be', $r->message);
        $r = $t->submitJob($this->tech, $this->dev, ['type' => 'collect', 'timeout_s' => '90']);
        $this->assertTrue($r->ok, 'numeric strings are accepted for the timeout');
        $this->assertSame(90, (int) $this->h->one("SELECT timeout_s FROM endpoint_agent_jobs WHERE job_id='{$r->data['job_id']}'"));
    }

    public function testAJobNeedsALinkedDevice(): void
    {
        $this->h->asset(['name' => 'X', 'serial' => 'SER-X']);
        [, , $j] = $this->h->enroll($this->h->token(), $this->h::device(['serial' => 'SER-UNKNOWN', 'hostname' => 'PENDING']));
        $this->assertSame('pending_approval', $j['status']);
        $r = $this->t()->submitJob($this->tech, (int) $j['device_id'], ['type' => 'collect']);
        $this->assertSame([false, 409, 'conflict'], [$r->ok, $r->http, $r->code]);
        $this->assertStringContainsString('Approve it first', $r->message);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_jobs'));
    }

    public function testSavedLibraryScriptsNeedOnlyTheLowerGrant(): void
    {
        $ok = $this->h->bridge->addScript('Get-Process');
        $bad = $this->h->bridge->addScript('Get-Process', false);
        $off = $this->h->bridge->addScript('Get-Date', true, false);
        $blank = $this->h->bridge->addScript("   \n");
        $limited = new RmmPrincipal(11, 'RebootOnly');
        $this->h->policy instanceof AllowUsersPolicy && $this->h->policy->grant(11, [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED]);
        $r = $this->t()->submitJob($limited, $this->dev, ['type' => 'powershell', 'script_id' => $ok]);
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame('Get-Process', $this->h->one("SELECT script FROM endpoint_agent_jobs WHERE job_id='{$r->data['job_id']}'"), 'the stored body comes from the library, never from the request');
        $this->assertSame(403, $this->t()->submitJob($limited, $this->dev, ['type' => 'powershell', 'script' => 'Get-Process'])->http, 'free-form text needs the higher grant');
        foreach ([$bad, $off, $blank, 9999] as $id) {
            $r = $this->t()->submitJob($this->tech, $this->dev, ['type' => 'powershell', 'script_id' => $id]);
            $this->assertSame([422, 'invalid'], [$r->http, $r->code], "script $id");
            $this->assertStringContainsString('not a usable PowerShell script', $r->message);
        }
        $r = $this->t()->submitJob($this->tech, $this->dev, ['type' => 'powershell', 'script_id' => $ok, 'script' => 'Remove-Item C:\\ -Recurse']);
        $this->assertSame('Get-Process', $this->h->one("SELECT script FROM endpoint_agent_jobs WHERE job_id='{$r->data['job_id']}'"), 'a library id wins over any posted text');
    }

    public function testSubmitIsAuditedWithAScriptHashNeverTheScript(): void
    {
        $r = $this->t()->submitJob($this->tech, $this->dev, ['type' => 'powershell', 'script' => 'Write-Host "super-secret-body"']);
        $this->assertTrue($r->ok);
        $a = array_values(array_filter($this->h->audit->records(), static fn (array $x): bool => $x['action'] === 'Job Submitted'))[0];
        $this->assertStringContainsString('Tech submitted powershell job ' . $r->data['job_id'], $a['description']);
        $this->assertStringContainsString('script sha256 ' . substr(hash('sha256', 'Write-Host "super-secret-body"'), 0, 16), $a['description']);
        $this->assertStringNotContainsString('super-secret-body', $a['description']);
        $this->assertSame($this->h->clientA, $a['client_id']);
        $d = $this->t()->submitJob($this->viewer, $this->dev, ['type' => 'powershell', 'script' => 'x']);
        $this->assertSame(403, $d->http);
        $denied = array_values(array_filter($this->h->audit->records(), static fn (array $x): bool => $x['action'] === 'Job Denied'));
        $this->assertCount(1, $denied);
        $this->assertStringContainsString('User 12 denied powershell job', $denied[0]['description']);
    }

    public function testCancel(): void
    {
        $q = $this->t()->submitJob($this->tech, $this->dev, ['type' => 'powershell', 'script' => 'Start-Sleep 600']);
        $id = (string) $q->data['job_id'];
        $this->assertSame(403, $this->t()->cancelJob($this->viewer, $this->dev, $id)->http, 'cancelling needs the run grant');
        $r = $this->t()->cancelJob($this->tech, $this->dev, $id);
        $this->assertSame([true, 200, 'cancelled'], [$r->ok, $r->http, $r->code]);
        $this->assertSame('cancelled', $this->h->one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$id'"));
        $again = $this->t()->cancelJob($this->tech, $this->dev, $id);
        $this->assertSame([false, 409], [$again->ok, $again->http], 'only a queued job can be cancelled');
        // a running job cannot be cancelled either
        $q2 = $this->t()->submitJob($this->tech, $this->dev, ['type' => 'collect']);
        $this->h->mysqli->query("UPDATE endpoint_agent_jobs SET state='running' WHERE job_id='{$q2->data['job_id']}'");
        $this->assertSame(409, $this->t()->cancelJob($this->tech, $this->dev, (string) $q2->data['job_id'])->http);
        // a job id from another device cannot be cancelled through this one
        [$other] = $this->linked('T2');
        $q3 = $this->t()->submitJob($this->admin, $other, ['type' => 'collect']);
        $this->assertSame(409, $this->t()->cancelJob($this->tech, $this->dev, (string) $q3->data['job_id'])->http);
        $this->assertSame('queued', $this->h->one("SELECT state FROM endpoint_agent_jobs WHERE job_id='{$q3->data['job_id']}'"));
        $this->assertSame(404, $this->t()->cancelJob($this->tech, 999999, $id)->http);
        $this->assertCount(1, array_filter($this->h->audit->records(), static fn (array $a): bool => $a['action'] === 'Job Cancelled'));
    }

    // ------------------------------------------------------------------ visibility

    public function testMissingAndOutOfScopeAreTheSame404ForEveryAction(): void
    {
        $t = $this->t();
        $missing = 999999;
        $outOfScope = $this->dev;   // client A; $scoped sees only client B
        foreach ([
            'submit' => fn (int $d) => $t->submitJob($this->scoped, $d, ['type' => 'collect']),
            'cancel' => fn (int $d) => $t->cancelJob($this->scoped, $d, 'x'),
            'remote' => fn (int $d) => $t->launchRemote($this->scoped, $d),
            'revoke' => fn (int $d) => $t->revoke($this->scoped, $d),
            'mesh' => fn (int $d) => $t->setMeshNode($this->scoped, $d, 'node//' . str_repeat('A', 20)),
            'ring' => fn (int $d) => $t->setRing($this->scoped, $d, 'pilot'),
            'transfer' => fn (int $d) => $t->transfer($this->scoped, $d, $this->h->clientB),
        ] as $name => $call) {
            $a = $call($outOfScope);
            $b = $call($missing);
            $this->assertSame([404, 'not_found', 'Device not found.'], [$a->http, $a->code, $a->message], $name);
            $this->assertEquals($b, $a, "$name: no existence oracle");
        }
        $this->assertNull($t->visibleDevice(16, $outOfScope));
        $this->assertNull($t->visibleDevice(16, $missing));
        $this->assertNotNull($t->visibleDevice(10, $outOfScope));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_jobs'));
    }

    // ------------------------------------------------------------------ administrative operations

    public function testPendingApprovalDecisions(): void
    {
        $t = $this->t();
        $tok = $this->h->token(null, 24, 10);
        [, , $a] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-A', 'hostname' => 'PEND-A']));
        [, , $b] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-B', 'hostname' => 'PEND-B']));
        [, , $c] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-C', 'hostname' => 'PEND-C']));
        $asset = $this->h->asset(['name' => 'Chosen', 'serial' => 'SER-CHOSEN']);
        $this->assertSame(403, $t->resolvePending($this->tech, (int) $a['device_id'], 'link', $asset)->http, 'approval is administrative');
        $r = $t->resolvePending($this->admin, (int) $a['device_id'], 'link', $asset);
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame('linked', $this->h->one("SELECT link_state FROM endpoint_agent_devices WHERE device_id={$a['device_id']}"));
        $this->assertSame($asset, (int) $this->h->one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id={$a['device_id']}"));
        $this->assertNotNull($this->h->bridge->link($this->h->integrationId(), 'rivetit:' . $a['device_id']), 'the edition link row exists');
        $r = $t->resolvePending($this->admin, (int) $b['device_id'], 'create_asset');
        $this->assertTrue($r->ok);
        $this->assertSame('linked', $this->h->one("SELECT link_state FROM endpoint_agent_devices WHERE device_id={$b['device_id']}"));
        $r = $t->resolvePending($this->admin, (int) $b['device_id'], 'reject');
        $this->assertSame([false, 409], [$r->ok, $r->http], 'an already linked device is not waiting for approval');
        $r = $t->resolvePending($this->admin, (int) $c['device_id'], 'reject');
        $this->assertTrue($r->ok);
        $this->assertSame('rejected', $this->h->one("SELECT link_state FROM endpoint_agent_devices WHERE device_id={$c['device_id']}"));
        [$code] = $this->h->checkin($c['device_token']);
        $this->assertSame(401, $code, 'a rejected device lost its credential');
        $this->assertFalse($t->resolvePending($this->admin, (int) $a['device_id'], 'link', 99999)->ok);
    }

    public function testRevokeRotateRetireAllowReenrollRingAndTransfer(): void
    {
        $t = $this->t();
        $this->assertSame(403, $t->revoke($this->tech, $this->dev)->http);
        $this->assertSame(403, $t->retire($this->viewer, $this->dev)->http);
        $this->assertSame(403, $t->setRing($this->tech, $this->dev, 'pilot')->http);
        $q = $this->t()->submitJob($this->tech, $this->dev, ['type' => 'collect']);
        $this->assertTrue($t->setRing($this->admin, $this->dev, 'pilot')->ok);
        $this->assertSame('pilot', $this->h->one("SELECT ring FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
        $this->assertSame([422, 'invalid'], [$t->setRing($this->admin, $this->dev, 'canary')->http, $t->setRing($this->admin, $this->dev, 'canary')->code]);
        $r = $t->revoke($this->admin, $this->dev);
        $this->assertTrue($r->ok);
        $this->assertSame('revoked by Admin', $this->h->one("SELECT revoked_reason FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
        $this->assertSame('cancelled', $this->h->one("SELECT state FROM endpoint_agent_jobs WHERE job_id='{$q->data['job_id']}'"), 'queued jobs are cancelled');
        $this->assertSame(409, $t->revoke($this->admin, $this->dev)->http, 'already revoked: nothing changed');
        $this->assertSame(401, $this->h->checkin($this->devToken)[0]);
        $this->assertTrue($t->allowReenroll($this->admin, $this->dev)->ok);
        $this->assertNull($this->h->one("SELECT revoked_at FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
        $this->assertSame(409, $t->allowReenroll($this->admin, $this->dev)->http);
        $this->assertTrue($t->rotateCredential($this->admin, $this->dev)->ok);
        $this->assertSame('', $this->h->one("SELECT token_hash FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
        $this->assertTrue($t->retire($this->admin, $this->dev)->ok);
        $this->assertNull($this->h->bridge->link($this->h->integrationId(), 'rivetit:' . $this->dev), 'monitoring stops: the link is removed');
        $this->assertSame(409, $t->retire($this->admin, $this->dev)->http);
        // transfer: asset and alerts follow; the target client must exist and be inside the caller's scope
        [$d2] = $this->linked('MOVER');
        $assetId = (int) $this->h->one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id=$d2");
        $this->assertSame(422, $t->transfer($this->admin, $d2, 9999)->http);
        $this->assertTrue($t->transfer($this->admin, $d2, $this->h->clientB)->ok);
        $this->assertSame([$this->h->clientB], [(int) $this->h->one("SELECT client_id FROM endpoint_agent_devices WHERE device_id=$d2")]);
        $this->assertSame($this->h->clientB, $this->h->assets->find($assetId)['client_id']);
        // a scoped administrator may not move a device into a client they cannot see
        $this->assertSame(404, $t->transfer($this->scoped, $this->dev, $this->h->clientA)->http, 'the device itself is out of scope');
        $this->assertSame(403, $t->transfer($this->scoped, $d2, $this->h->clientA)->http, 'and the target client must be in scope too');
        $this->assertTrue($t->transfer($this->scoped, $d2, $this->h->clientB)->ok);
    }

    public function testAdministrationWorksWhileTheModuleIsOffButJobsDoNot(): void
    {
        $this->h->module->settings()->disable();
        $this->assertTrue($this->t()->setRing($this->admin, $this->dev, 'pilot')->ok);
        $this->assertTrue($this->t()->revoke($this->admin, $this->dev)->ok);
        $r = $this->t()->submitJob($this->admin, $this->dev, ['type' => 'collect']);
        $this->assertSame([403, 'forbidden', 'The endpoint agent is not enabled.'], [$r->http, $r->code, $r->message]);
        $this->assertSame(403, $this->t()->launchRemote($this->admin, $this->dev)->http);
    }

    public function testClearUpdateFailuresAndMeshNodeAudit(): void
    {
        $this->h->mysqli->query("UPDATE endpoint_agent_devices SET update_state_json='{\"failed_versions\":[\"1.2.0\"]}' WHERE device_id={$this->dev}");
        $this->assertSame(403, $this->t()->clearUpdateFailures($this->tech, $this->dev)->http);
        $this->assertTrue($this->t()->clearUpdateFailures($this->admin, $this->dev)->ok);
        $this->assertNull($this->h->one("SELECT update_state_json FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
    }

    // ------------------------------------------------------------------ tokens

    public function testEnrollmentTokens(): void
    {
        $t = $this->t();
        $this->assertSame(403, $t->createToken($this->tech, $this->h->clientA, 0, 'stable', 1, 1, 'x')->http);
        $this->assertSame([422, 'invalid'], [$t->createToken($this->admin, 9999, 0, 'stable', 1, 1, 'x')->http, $t->createToken($this->admin, 9999, 0, 'stable', 1, 1, 'x')->code]);
        $r = $t->createToken($this->admin, $this->h->clientA, 12345, 'weird', 99999, 99999, '  Front desk  ');
        $this->assertSame([true, 201, 'created'], [$r->ok, $r->http, $r->code]);
        $this->assertMatchesRegularExpression('/^rvte1\.[0-9a-f]{12}\.[0-9a-f]{40}$/', $r->data['token']);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_id={$r->data['token_id']}")[0];
        $this->assertSame(['stable', 5000, 0, 'Front desk'], [$row['ring'], (int) $row['max_uses'], (int) $row['location_id'], $row['label']], 'ring defaults, uses and the unknown location are normalised');
        $this->assertEqualsWithDelta(time() + 72 * 3600, strtotime($row['expires_at'] . ' UTC'), 30, 'the lifetime is clamped to enroll_max_ttl_h');
        $this->assertSame(hash('sha256', explode('.', $r->data['token'])[2]), $row['token_hash']);
        $this->assertStringNotContainsString(explode('.', $r->data['token'])[2], (string) json_encode($this->h->audit->records()));
        $this->assertSame(403, $t->revokeToken($this->tech, (int) $r->data['token_id'])->http);
        $this->assertTrue($t->revokeToken($this->admin, (int) $r->data['token_id'])->ok);
        $this->assertSame(404, $t->revokeToken($this->admin, (int) $r->data['token_id'])->http, 'already revoked');
        $this->assertSame(404, $t->revokeToken($this->admin, 99999)->http);
        $actions = array_column($this->h->audit->records(), 'action');
        $this->assertContains('Enrollment Token Created', $actions);
        $this->assertContains('Enrollment Token Revoked', $actions);
        [$c] = $this->h->enroll($r->data['token'], $this->h::device());
        $this->assertSame(401, $c, 'a revoked token enrolls nothing');
    }

    // ------------------------------------------------------------------ the REST API: routing, shapes, bodies

    public function testRoutingAndStatusCodes(): void
    {
        $d = (string) $this->dev;
        $this->assertSame(404, $this->h->tech('GET', ['abc'], $this->admin)[0]);
        $this->assertSame(404, $this->h->tech('POST', [], $this->admin)[0], 'list is GET only');
        $this->assertSame(404, $this->h->tech('GET', [$d, 'nope'], $this->admin)[0]);
        $this->assertSame(404, $this->h->tech('GET', [$d, 'remote'], $this->admin)[0], 'remote is POST only');
        $this->assertSame(404, $this->h->tech('GET', [$d, 'jobs', 'someid', 'cancel'], $this->admin)[0]);
        $this->assertSame(404, $this->h->tech('POST', [$d, 'jobs', 'someid'], $this->admin)[0]);
        [$c, $b] = $this->h->tech('POST', [$d], $this->admin, []);
        $this->assertSame([405, 'method_not_allowed'], [$c, $b['code']]);
        [$c, $b] = $this->h->tech('GET', [$d, 'jobs'], $this->admin);
        $this->assertSame([200, []], [$c, $b['data']]);
        $q = $this->t()->submitJob($this->admin, $this->dev, ['type' => 'collect']);
        [$c, $b] = $this->h->tech('POST', [$d, 'jobs', (string) $q->data['job_id'], 'cancel'], $this->admin, []);
        $this->assertSame([200, ['ok' => true]], [$c, $b]);
        [$c, $b] = $this->h->tech('POST', [$d, 'jobs', (string) $q->data['job_id'], 'cancel'], $this->admin, []);
        $this->assertSame([409, 'conflict'], [$c, $b['code']]);
    }

    public function testResponsesCarryOnlyAContentTypeAndPlainJson(): void
    {
        $this->h->mysqli->query("UPDATE endpoint_agent_devices SET model='a/b <i>', logged_in_user='" . "caf\xc3\xa9" . "' WHERE device_id={$this->dev}");
        [, , $r] = $this->h->tech('GET', [(string) $this->dev], $this->admin);
        $this->assertSame(['Content-Type' => 'application/json'], $r->headers, 'no Cache-Control, no CORS: those are the edition front controller\'s');
        $this->assertStringContainsString('a\/b <i>', (string) $r->body, 'plain json_encode: slashes escaped, as api_response() emitted them');
        $this->assertStringContainsString('caf\u00e9', (string) $r->body);
        [, , $e] = $this->h->tech('GET', ['999999'], $this->admin);
        $this->assertSame('application/json', $e->headers['Content-Type']);
    }

    public function testRequestBodiesAreBoundedAndValidated(): void
    {
        $d = (string) $this->dev;
        [$c, $b] = $this->h->tech('POST', [$d, 'jobs'], $this->admin, 'not json');
        $this->assertSame([400, ['error' => 'Request body must be a JSON object']], [$c, $b]);
        [$c] = $this->h->tech('POST', [$d, 'jobs'], $this->admin, '"a string"');
        $this->assertSame(400, $c);
        [$c, $b] = $this->h->tech('POST', [$d, 'jobs'], $this->admin, json_encode(['type' => 'powershell', 'script' => str_repeat('a', 1048576)]));
        $this->assertSame([413, 'too_large'], [$c, $b['code']], 'the original read the whole body unbounded');
        [$c] = $this->h->tech('POST', [$d, 'jobs'], $this->admin, json_encode(['type' => 'powershell', 'script' => str_repeat('a', 102401)]));
        $this->assertSame(422, $c, 'a script over 100 KiB is refused by the job rules');
        [$c, $b] = $this->h->tech('POST', [$d, 'jobs'], $this->admin);
        $this->assertSame([422, 'Unknown job type.'], [$c, $b['error']], 'an empty body is an empty object');
    }

    public function testListQueryParametersAndTotal(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->linked("L$i");
        }
        [$c, $b] = $this->h->tech('GET', [], $this->admin, null, ['limit' => '2', 'offset' => '1']);
        $this->assertSame(200, $c);
        $this->assertCount(2, $b['data']);
        $this->assertSame(5, $b['total'], 'total is the number of matching devices, not the page size');
        [, $b] = $this->h->tech('GET', [], $this->admin, null, ['limit' => '0']);
        $this->assertCount(1, $b['data'], 'the limit is clamped to at least 1');
        [, $b] = $this->h->tech('GET', [], $this->admin, null, ['limit' => '99999']);
        $this->assertCount(5, $b['data']);
        [, $b] = $this->h->tech('GET', [], $this->admin, null, ['status' => 'pending_approval']);
        $this->assertSame([], $b['data']);
        [, $b] = $this->h->tech('GET', [], $this->admin, null, ['status' => 'never']);
        $this->assertSame([5, 5], [$b['total'], count($b['data'])], 'a device that never checked in is "never"');
        [, $b] = $this->h->tech('GET', [], $this->admin, null, ['status' => 'never', 'limit' => '2', 'offset' => '4']);
        $this->assertSame([5, 1], [$b['total'], count($b['data'])], 'the status filter is applied before the page is cut');
        [, $b] = $this->h->tech('GET', [], $this->admin, null, ['status' => 'online']);
        $this->assertSame(0, $b['total']);
        $this->h->checkin($this->devToken);
        [, $b] = $this->h->tech('GET', [], $this->admin, null, ['status' => 'online', 'limit' => '1']);
        $this->assertSame([1, 1], [count($b['data']), $b['total']]);
    }

    public function testJobOutputIsOnlyForThoseWhoMayRunJobs(): void
    {
        $q = $this->t()->submitJob($this->tech, $this->dev, ['type' => 'powershell', 'script' => 'Get-Date']);
        $this->h->mysqli->query("UPDATE endpoint_agent_jobs SET state='succeeded', output='secret output', finished_at=UTC_TIMESTAMP() WHERE job_id='{$q->data['job_id']}'");
        foreach ([[[(string) $this->dev, 'jobs'], 'data'], [[(string) $this->dev], 'jobs']] as [$seg, $key]) {
            [, $with] = $this->h->tech('GET', $seg, $this->tech);
            [, $without] = $this->h->tech('GET', $seg, $this->viewer);
            $jobs = $key === 'data' ? $with['data'] : $with['jobs'];
            $jobsNo = $key === 'data' ? $without['data'] : $without['jobs'];
            $this->assertSame('secret output', $jobs[0]['output']);
            $this->assertArrayNotHasKey('output', $jobsNo[0]);
            $this->assertArrayNotHasKey('output_truncated', $jobsNo[0]);
        }
    }

    public function testDisabledModuleAndEditionKillSwitch(): void
    {
        $this->h->module->settings()->disable();
        [$c, $b] = $this->h->tech('GET', [], $this->admin);
        $this->assertSame([404, 'disabled'], [$c, $b['code']]);
        [$c] = $this->h->tech('GET', [], null);
        $this->assertSame(401, $c, 'authentication comes first');
        $this->h->module->settings()->enable();
        [$c] = $this->h->tech('GET', [], $this->admin);
        $this->assertSame(200, $c);
        $off = new RmmHarness(state: new InMemoryRmmModuleState(false), policy: new AllowUsersPolicy([1 => true]));
        $off->module->settings()->enable();
        [$c, $b] = $off->tech('GET', [], $this->admin);
        $this->assertSame([404, 'disabled'], [$c, $b['code']], 'the edition kill switch disables the technician API too');
        $this->assertSame(401, $off->tech('GET', [], new RmmPrincipal(0, 'x'))[0], 'a principal without a user id is unauthenticated');
    }

    public function testInternalErrorsAreAGeneric500(): void
    {
        // a storage failure in the middle of a request (here: a missing table) is a generic 500; the details go to the PHP log only
        $this->h->mysqli->query('DROP TABLE IF EXISTS endpoint_agent_checks_tmp');
        $this->h->mysqli->query('RENAME TABLE endpoint_agent_checks TO endpoint_agent_checks_tmp');
        try {
            [$c, $b] = $this->h->tech('GET', [(string) $this->dev], $this->admin);
        } finally {
            $this->h->mysqli->query('RENAME TABLE endpoint_agent_checks_tmp TO endpoint_agent_checks');
        }
        $this->assertSame([500, ['error' => 'Internal error.', 'code' => 'internal']], [$c, $b], 'details go to the log, never to the caller');
    }
}
