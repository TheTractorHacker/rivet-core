<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Job\JobExtras;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Tests\Support\AutomationTestCase;

/** The script library: versions and signatures, running a library script, parameters, custom field placeholders, secrets, capability filtering. */
final class ScriptLibraryTest extends AutomationTestCase
{
    private int $win = 0;
    private string $winT = '';
    private int $lin = 0;
    private string $linT = '';

    protected function setUp(): void
    {
        parent::setUp();
        [$this->win, $this->winT] = $this->device('WIN-1');
        [$this->lin, $this->linT] = $this->device('LIN-1', null, 'linux');
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function script(string $name = 'Clean temp', array $over = []): array
    {
        $r = $this->h->module->scriptActions()->createScript($this->tech, $over + ['name' => $name, 'language' => 'bash', 'body' => "echo cleaning\n", 'params' => []]);
        $this->assertTrue($r->ok, $r->message);

        return $r->data['script'];
    }

    /** @return array<string,mixed> */
    private function jobRow(string $id): array
    {
        return $this->h->rows("SELECT * FROM endpoint_agent_jobs WHERE job_id = '$id'")[0];
    }

    // ------------------------------------------------------------------ versions and signatures

    public function testVersionsAreImmutableAndSigned(): void
    {
        $s = $this->script();
        $id = (int) $s['script_id'];
        $svc = $this->h->module->scripts();
        $this->assertSame(1, $s['current_version']);
        $u = $svc->update($id, ['body' => "echo v2\n"], 10);
        $this->assertSame(2, $u['current_version']);
        $this->assertSame("echo cleaning\n", $svc->version($id, 1)['body'], 'version 1 is still there, unchanged');
        $same = $svc->update($id, ['body' => "echo v2\n", 'description' => 'same text, new words'], 10);
        $this->assertSame([2, 'same text, new words'], [$same['current_version'], $same['description']], 'no new version without a change of text or parameters');
        $p = $svc->update($id, ['params' => [['name' => 'dir', 'type' => 'string']]], 10);
        $this->assertSame(3, $p['current_version'], 'a parameter change is a new version');
        $this->assertSame("echo v2\n", $svc->version($id, 3)['body'], 'with the text carried over');
        $this->assertSame([3, 2, 1], array_column($svc->versions($id), 'version'));
        // every version verifies against the instance key
        [, $pub, $keyId] = $this->h->module->settings()->signingKey();
        foreach ($this->h->rows("SELECT * FROM rmm_script_versions WHERE script_id = $id") as $v) {
            $msg = \RivetCore\Rmm\Scripts\ScriptService::signedMessage($id, (int) $v['version'], 'bash', 'linux', hash('sha256', (string) $v['body']), \RivetCore\Rmm\Scripts\ScriptService::schemaHash(\RivetCore\Rmm\Scripts\ScriptService::decodeSchema($v['params_schema_json'])));
            $this->assertTrue(Signer::verify($msg, (string) $v['signature'], $pub));
            $this->assertSame($keyId, $v['signing_key_id']);
        }
        $this->assertNotNull($svc->load($id)[0]);
    }

    public function testAScriptEditedInTheDatabaseIsNotRun(): void
    {
        $s = $this->script();
        $id = (int) $s['script_id'];
        $svc = $this->h->module->scripts();
        $this->h->q("UPDATE rmm_script_versions SET body = 'rm -rf /' WHERE script_id = $id");
        [$loaded, $err] = $svc->load($id);
        $this->assertNull($loaded);
        $this->assertStringContainsString('does not match its recorded hash', (string) $err);
        // an attacker who also fixes the hash still cannot forge the signature
        $this->h->q("UPDATE rmm_script_versions SET body_sha256 = '" . hash('sha256', 'rm -rf /') . "' WHERE script_id = $id");
        [$loaded, $err] = $svc->load($id);
        $this->assertNull($loaded);
        $this->assertStringContainsString('signature does not verify', (string) $err);
        $r = $this->h->module->scriptActions()->runOnDevice($this->tech, $this->lin, ['library_script_id' => $id]);
        $this->assertSame([422, 'invalid'], [$r->http, $r->code]);
        $this->assertSame([], $this->jobs($this->lin), 'nothing was queued');
    }

    public function testAfterAKeyRotationAnAdministratorReSigns(): void
    {
        $s = $this->script();
        $id = (int) $s['script_id'];
        $this->h->module->settings()->generateSigningKey();
        $this->assertNull($this->h->module->scripts()->load($id)[0], 'signed by the old key');
        $a = $this->h->module->scriptActions();
        $this->assertSame(403, $a->resignScript($this->tech, $id)->http);
        $this->assertTrue($a->resignScript($this->admin, $id)->ok);
        $this->assertNotNull($this->h->module->scripts()->load($id)[0]);
    }

    public function testCapsAndValidation(): void
    {
        $a = $this->h->module->scriptActions();
        $base = ['name' => 'X', 'language' => 'bash', 'body' => 'echo x'];
        foreach ([
            [['language' => 'ruby'] + $base, 'language must be'],
            [['body' => ''] + $base, 'needs text'],
            [['body' => "a\0b"] + $base, 'needs text'],
            [['body' => str_repeat('x', 102401)] + $base, 'at most 102400'],
            [['language' => 'powershell', 'body' => str_repeat('x', 10001)] + $base, 'PowerShell script is at most 10000'],
            [['name' => ''] + $base, 'needs a name'],
            [['timeout_s' => 999999] + $base, 'timeout_s'],
            [['tags' => ['bad tag!!']] + $base, 'A tag is'],
            [['params' => [['name' => 'path']]] + $base, 'reserved'],
        ] as [$in, $needle]) {
            $r = $a->createScript($this->tech, $in);
            $this->assertSame(422, $r->http, $needle);
            $this->assertStringContainsString($needle, $r->message);
        }
        $this->assertTrue($a->createScript($this->tech, $base)->ok);
        $this->assertSame(422, $a->createScript($this->tech, $base)->http, 'duplicate name');
        $this->assertSame(403, $a->createScript($this->viewer, ['name' => 'Y'] + $base)->http, 'the library is written by whoever may run free-form text');
        $this->assertSame(403, $a->createScript($this->approver, ['name' => 'Y'] + $base)->http);
    }

    public function testRetiredScriptsCannotRunOrBeScheduledAndCanBeRestored(): void
    {
        $s = $this->script();
        $id = (int) $s['script_id'];
        $a = $this->h->module->scriptActions();
        $this->assertTrue($a->retireScript($this->tech, $id)->ok);
        $this->assertSame(409, $a->retireScript($this->tech, $id)->http, 'already retired');
        $this->assertSame(422, $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $id])->http);
        $this->assertSame(422, $a->updateScript($this->tech, $id, ['body' => 'x'])->http);
        $this->assertSame([], $this->h->module->scripts()->all()['items']);
        $this->assertCount(1, $this->h->module->scripts()->all(['include_retired' => true])['items']);
        $this->assertTrue($a->retireScript($this->tech, $id, false)->ok);
        $this->assertTrue($a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $id])->ok);
    }

    public function testListFilters(): void
    {
        $this->script('Alpha', ['tags' => ['disk', 'cleanup'], 'description' => 'frees space']);
        $this->script('Beta', ['language' => 'powershell', 'body' => 'Get-Date', 'tags' => ['time']]);
        $svc = $this->h->module->scripts();
        $this->assertSame(['Alpha', 'Beta'], array_column($svc->all()['items'], 'name'));
        $this->assertSame(['Beta'], array_column($svc->all(['language' => 'powershell'])['items'], 'name'));
        $this->assertSame(['Alpha'], array_column($svc->all(['tag' => 'disk'])['items'], 'name'));
        $this->assertSame(['Alpha'], array_column($svc->all(['q' => 'space'])['items'], 'name'));
        $this->assertSame([], $svc->all(['q' => '%'])['items'], 'LIKE wildcards in a search are literal');
        $this->assertSame(2, $svc->all(['limit' => 1])['total']);
    }

    // ------------------------------------------------------------------ running

    public function testRunningPicksTheJobTypeFromTheLanguageAndChecksThePlatform(): void
    {
        $bash = $this->script('Bash one');
        $ps = $this->script('PS one', ['language' => 'powershell', 'body' => 'Get-Date']);
        $a = $this->h->module->scriptActions();

        $r = $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $bash['script_id']]);
        $this->assertSame([true, 201, 'queued'], [$r->ok, $r->http, $r->code]);
        $row = $this->jobRow((string) $r->data['job_id']);
        $this->assertSame(['shell', "echo cleaning\n", 10, 0, 'SYSTEM'], [$row['type'], $row['script'], (int) $row['created_by'], (int) $row['destructive'], $row['run_as']]);
        $x = $this->h->rows("SELECT * FROM rmm_job_extra WHERE job_id = '{$row['job_id']}'")[0];
        $this->assertSame([(int) $bash['script_id'], 1, null], [(int) $x['script_id'], (int) $x['script_version'], $x['schedule_id']]);

        $r = $a->runOnDevice($this->tech, $this->win, ['library_script_id' => $ps['script_id']]);
        $this->assertSame('powershell', $this->jobRow((string) $r->data['job_id'])['type']);

        $r = $a->runOnDevice($this->tech, $this->win, ['library_script_id' => $bash['script_id']]);
        $this->assertSame([422, 'invalid'], [$r->http, $r->code]);
        $this->assertStringContainsString('bash script cannot run on a windows device', $r->message);
        $r = $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $ps['script_id']]);
        $this->assertStringContainsString('powershell script cannot run on a linux device', $r->message);
        $this->assertSame(404, $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => 99999])->http);
        $this->assertSame(422, $a->runOnDevice($this->tech, $this->lin, [])->http);

        // submitJob (what an edition's web handler calls) takes the same input
        $r = $this->h->module->technician()->submitJob($this->tech, $this->lin, ['library_script_id' => $bash['script_id']]);
        $this->assertSame([true, 'queued'], [$r->ok, $r->code]);
    }

    public function testWhoMayRunAndWhere(): void
    {
        $s = $this->script();
        $a = $this->h->module->scriptActions();
        [$other] = $this->device('B-1', $this->h->clientB, 'linux');
        $this->assertSame(403, $a->runOnDevice($this->viewer, $this->lin, ['library_script_id' => $s['script_id']])->http, 'view only');
        $this->assertSame(404, $a->runOnDevice($this->scoped, $this->lin, ['library_script_id' => $s['script_id']])->http, 'a device of another client looks missing');
        $this->assertTrue($a->runOnDevice($this->scoped, $other, ['library_script_id' => $s['script_id']])->ok);
        $this->assertSame(404, $a->runOnDevice($this->tech, 99999, ['library_script_id' => $s['script_id']])->http);
        $this->assertNotEmpty(array_filter($this->h->audit->records(), static fn (array $r): bool => $r['action'] === 'Job Denied'), 'a denied run is audited');
    }

    public function testDestructiveScriptsNeedConfirmation(): void
    {
        $s = $this->script('Wipe', ['destructive' => true]);
        $a = $this->h->module->scriptActions();
        $r = $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id']]);
        $this->assertSame([422, 'confirmation_required'], [$r->http, $r->code]);
        $r = $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id'], 'confirm' => true]);
        $this->assertTrue($r->ok);
        $this->assertSame(1, (int) $this->jobRow((string) $r->data['job_id'])['destructive'], 'a destructive job is offered at most once');
    }

    public function testTheAuditLineNamesTheScriptNeverItsText(): void
    {
        $s = $this->script('Secretive', ['body' => "echo TOP-SECRET-BODY-TEXT\n"]);
        $a = $this->h->module->scriptActions();
        $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id']]);
        $all = json_encode($this->h->audit->records()) ?: '';
        $this->assertStringNotContainsString('TOP-SECRET-BODY-TEXT', $all);
        $this->assertStringContainsString(substr(hash('sha256', "echo TOP-SECRET-BODY-TEXT\n"), 0, 16), $all);
        $this->assertStringContainsString('Secretive', $all);
        $e = $this->bus->of(RmmEvent::SCRIPT_RUN);
        $this->assertCount(1, $e);
        $this->assertSame([$this->lin, 'Secretive', 1, 'manual'], [$e[0]['device_id'], $e[0]['script_name'], $e[0]['script_version'], $e[0]['origin']]);
        $this->assertStringNotContainsString('TOP-SECRET', json_encode($this->bus->published()) ?: '');
    }

    public function testParametersAreValidatedAndDefaultsApplied(): void
    {
        $s = $this->script('Param', ['params' => [
            ['name' => 'dir', 'type' => 'string', 'required' => true, 'max_length' => 30],
            ['name' => 'days', 'type' => 'int', 'min' => 1, 'max' => 90, 'default' => 7],
            ['name' => 'force', 'type' => 'bool', 'default' => false],
        ]]);
        $a = $this->h->module->scriptActions();
        $r = $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id'], 'params' => ['dir' => '/var/tmp']]);
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame(['dir' => '/var/tmp', 'days' => 7, 'force' => false], json_decode((string) $this->jobRow((string) $r->data['job_id'])['params_json'], true));
        foreach ([[[], 'dir is required'], [['dir' => 'x', 'days' => 100], 'outside'], [['dir' => 'x', 'nope' => 1], 'Unknown parameter nope'], [['dir' => str_repeat('x', 31)], 'longer than 30'],
            [['dir' => ['a']], 'text, a whole number'], [['dir' => 1.5], 'text, a whole number']] as [$params, $needle]) {
            $r = $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id'], 'params' => $params]);
            $this->assertSame(422, $r->http, $needle);
            $this->assertStringContainsString($needle, $r->message);
        }
        $this->assertCount(1, $this->jobs($this->lin), 'only the valid run was queued');
    }

    // ------------------------------------------------------------------ custom fields as parameters

    private function field(string $name, string $scope, string $type, array $over = []): array
    {
        $r = $this->h->module->fieldActions()->define($this->admin, ['name' => $name, 'scope' => $scope, 'type' => $type] + $over);
        $this->assertTrue($r->ok, $r->message);

        return $r->data['field'];
    }

    public function testFieldPlaceholdersAreFilledPerDevice(): void
    {
        $f = $this->field('smtp_host', 'client', 'text');
        $d = $this->field('rack', 'device', 'text', ['default' => 'unracked']);
        $fa = $this->h->module->fieldActions();
        $this->assertTrue($fa->setValue($this->admin, (int) $f['field_id'], $this->h->clientA, 'mail.a.example')->ok);
        $this->assertTrue($fa->setValue($this->admin, (int) $f['field_id'], $this->h->clientB, 'mail.b.example')->ok);
        $this->assertTrue($fa->setValue($this->admin, (int) $d['field_id'], $this->lin, 'R-12')->ok);
        $s = $this->script('Mail', ['params' => [['name' => 'server', 'type' => 'string'], ['name' => 'comment', 'type' => 'string']]]);
        [$otherB] = $this->device('B-2', $this->h->clientB, 'linux');
        $run = $this->h->module->scriptActions();
        $in = ['library_script_id' => $s['script_id'], 'params' => ['server' => '{{field.smtp_host}}', 'comment' => 'rack {{field.rack}} on {{ field.smtp_host }}']];
        $r = $run->runOnDevice($this->tech, $this->lin, $in);
        $this->assertSame(['server' => 'mail.a.example', 'comment' => 'rack R-12 on mail.a.example'], json_decode((string) $this->jobRow((string) $r->data['job_id'])['params_json'], true));
        $r = $this->h->module->scriptActions()->runOnTarget($this->admin, $in + ['target' => ['type' => 'client', 'id' => $this->h->clientB]]);
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame(['server' => 'mail.b.example', 'comment' => 'rack unracked on mail.b.example'], json_decode((string) $this->jobs($otherB)[0]['params_json'], true), 'another client, another value, the default for the unset field');
    }

    public function testAMissingOrUnknownFieldStopsTheRunWithAClearMessage(): void
    {
        $this->field('region', 'client', 'text');
        $s = $this->script('R', ['params' => [['name' => 'r', 'type' => 'string']]]);
        $a = $this->h->module->scriptActions();
        $r = $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id'], 'params' => ['r' => '{{field.region}}']]);
        $this->assertSame(422, $r->http);
        $this->assertStringContainsString('"region" has no value for this device', $r->message);
        $r = $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id'], 'params' => ['r' => '{{field.nothing}}']]);
        $this->assertStringContainsString('does not exist', $r->message);
        $this->assertSame([], $this->jobs($this->lin));
    }

    public function testASecretFieldOnlyFillsASecretParameterAlone(): void
    {
        $f = $this->field('api_key', 'client', 'secret');
        $this->h->module->fieldActions()->setValue($this->admin, (int) $f['field_id'], $this->h->clientA, 'sk-live-0123456789');
        $plain = $this->script('Plain', ['params' => [['name' => 'p', 'type' => 'string']]]);
        $sec = $this->script('Sec', ['params' => [['name' => 'token', 'type' => 'secret']]]);
        $a = $this->h->module->scriptActions();
        $r = $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $plain['script_id'], 'params' => ['p' => '{{field.api_key}}']]);
        $this->assertStringContainsString('can only fill a secret parameter', $r->message);
        $r = $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $sec['script_id'], 'params' => ['token' => 'Bearer {{field.api_key}}']]);
        $this->assertStringContainsString('can only fill a secret parameter, alone', $r->message);
        $this->assertSame([], $this->jobs($this->lin));
    }

    // ------------------------------------------------------------------ secrets

    public function testSecretParametersNeverReachTheJobRowTheAuditOrTheOutput(): void
    {
        $f = $this->field('api_key', 'client', 'secret');
        $this->h->module->fieldActions()->setValue($this->admin, (int) $f['field_id'], $this->h->clientA, 'sk-live-0123456789');
        $s = $this->script('Call API', ['params' => [['name' => 'token', 'type' => 'secret'], ['name' => 'mode', 'type' => 'string', 'default' => 'x']]]);
        $a = $this->h->module->scriptActions();

        $r = $a->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id'], 'params' => ['token' => '{{field.api_key}}']]);
        $this->assertTrue($r->ok, $r->message);
        $jobId = (string) $r->data['job_id'];
        $row = $this->jobRow($jobId);
        $this->assertSame(['token' => JobExtras::SECRET_MARK, 'mode' => 'x'], json_decode((string) $row['params_json'], true));
        $sealed = (string) $this->h->one("SELECT secret_params_enc FROM rmm_job_extra WHERE job_id = '$jobId'");
        $this->assertNotSame('', $sealed);
        $this->assertStringNotContainsString('sk-live', $sealed, 'sealed with the edition secret box');
        $this->assertStringNotContainsString('sk-live', json_encode($this->h->audit->records()) ?: '');
        $this->assertStringNotContainsString('sk-live', json_encode($this->bus->published()) ?: '');

        // the device receives the real value, inside a signed job
        [$c, , $j] = $this->h->call('GET', 'agent_jobs', null, $this->linT);
        $this->assertSame(200, $c);
        $job = $j['jobs'][0];
        $this->assertSame('sk-live-0123456789', $job['params']['token']);
        [, $pub] = $this->h->module->settings()->signingKey();
        $this->assertTrue(Signer::verify(Signer::jobMessage($job), $job['signature'], $pub));
        $this->assertSame(JobExtras::SECRET_MARK, json_decode((string) $this->jobRow($jobId)['params_json'], true)['token'], 'offering does not write it back');

        // the technician's views never show it
        $detail = json_encode($this->h->module->readModel()->jobs($this->lin, 10, true)) ?: '';
        $this->assertStringNotContainsString('sk-live', $detail);
        [$c, $api] = $this->h->tech('GET', [(string) $this->lin, 'jobs'], $this->tech);
        $this->assertStringNotContainsString('sk-live', json_encode($api) ?: '');

        // a script that echoes its secret does not store it
        $this->h->call('POST', 'agent_jobs', ['job_id' => $jobId, 'attempt' => 1, 'state' => 'running'], $this->linT);
        $this->assertNotSame('', (string) $this->h->one("SELECT secret_params_enc FROM rmm_job_extra WHERE job_id = '$jobId'"), 'still needed while it runs and could be retried');
        [$c] = $this->h->call('POST', 'agent_jobs', ['job_id' => $jobId, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0,
            'output' => "calling with sk-live-0123456789\nb64 " . base64_encode('sk-live-0123456789') . "\nurl sk-live-0123456789&x=1"], $this->linT);
        $this->assertSame(200, $c);
        $out = (string) $this->jobRow($jobId)['output'];
        $this->assertStringNotContainsString('sk-live', $out);
        $this->assertStringNotContainsString(base64_encode('sk-live-0123456789'), $out);
        $this->assertStringContainsString('calling with [redacted]', $out);
        $this->assertNull($this->h->one("SELECT secret_params_enc FROM rmm_job_extra WHERE job_id = '$jobId'"), 'wiped once the job has its result');
    }

    public function testALiteralSecretIsSealedToo(): void
    {
        $s = $this->script('Lit', ['params' => [['name' => 'token', 'type' => 'secret']]]);
        $r = $this->h->module->scriptActions()->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id'], 'params' => ['token' => 'literal-secret-value']]);
        $this->assertTrue($r->ok);
        $this->assertStringNotContainsString('literal-secret', json_encode($this->h->rows('SELECT * FROM endpoint_agent_jobs')) ?: '');
        $this->assertStringNotContainsString('literal-secret', json_encode($this->h->rows('SELECT * FROM rmm_job_extra')) ?: '');
        [, , $j] = $this->h->call('GET', 'agent_jobs', null, $this->linT);
        $this->assertSame('literal-secret-value', $j['jobs'][0]['params']['token']);
    }

    public function testAJobWhoseSealedValuesCannotBeReadStaysQueued(): void
    {
        $s = $this->script('Lit', ['params' => [['name' => 'token', 'type' => 'secret']]]);
        $r = $this->h->module->scriptActions()->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id'], 'params' => ['token' => 'abcdefgh']]);
        $this->h->q("UPDATE rmm_job_extra SET secret_params_enc = NULL WHERE job_id = '{$r->data['job_id']}'");
        [, , $j] = $this->h->call('GET', 'agent_jobs', null, $this->linT);
        $this->assertSame([], $j['jobs'], 'it is never sent with the placeholder in place of the value');
    }

    public function testHousekeepingWipesSecretsOfJobsThatNeverReported(): void
    {
        $s = $this->script('Lit', ['params' => [['name' => 'token', 'type' => 'secret']]]);
        $r = $this->h->module->scriptActions()->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id'], 'params' => ['token' => 'abcdefgh']]);
        $id = (string) $r->data['job_id'];
        $this->h->module->jobs()->cancel($id, $this->lin, 10);
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['job_secrets_wiped']);
        $this->assertNull($this->h->one("SELECT secret_params_enc FROM rmm_job_extra WHERE job_id = '$id'"));
    }

    // ------------------------------------------------------------------ capability negotiation

    public function testAJobIsOnlyOfferedToADeviceThatAnnouncedItsType(): void
    {
        $s = $this->script();
        $this->h->module->scriptActions()->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id']]);
        $r = $this->checkin($this->linT, ['check:disk', 'job:collect', 'job:reboot']);
        $this->assertSame(0, $r['jobs_pending'], 'and not counted as pending either: an agent told jobs are pending would poll at its shortest interval until the job expires');
        [, , $j] = $this->h->call('GET', 'agent_jobs', null, $this->linT);
        $this->assertSame([], $j['jobs'], 'announced job types without shell: not offered');
        $r = $this->checkin($this->linT, ['job:collect', 'job:shell']);
        $this->assertSame(1, $r['jobs_pending']);
        [, , $j] = $this->h->call('GET', 'agent_jobs', null, $this->linT);
        $this->assertCount(1, $j['jobs']);
        $this->assertSame('shell', $j['jobs'][0]['type']);
    }

    public function testAnOldAgentIsStillOfferedEveryJob(): void
    {
        $this->h->module->technician()->submitJob($this->tech, $this->win, ['type' => 'powershell', 'script' => 'Get-Date']);
        $this->checkin($this->winT, null);
        [, , $j] = $this->h->call('GET', 'agent_jobs', null, $this->winT);
        $this->assertCount(1, $j['jobs']);
    }

    public function testFreeFormJobsAreCheckedAgainstThePlatformToo(): void
    {
        $t = $this->h->module->technician();
        $r = $t->submitJob($this->tech, $this->win, ['type' => 'shell', 'script' => 'echo hi']);
        $this->assertSame(422, $r->http);
        $this->assertStringContainsString('cannot run on a windows device', $r->message);
        $this->assertTrue($t->submitJob($this->tech, $this->lin, ['type' => 'shell', 'script' => 'echo hi', 'params' => ['name' => 'x']])->ok);
        $this->assertTrue($t->submitJob($this->tech, $this->lin, ['type' => 'python', 'script' => 'print(1)'])->ok);
        $rb = $t->submitJob($this->admin, $this->lin, ['type' => 'reboot', 'confirm' => true]);
        $this->assertTrue($rb->ok, 'reboot and collect run on Linux: ' . $rb->message);
        $this->assertTrue($t->submitJob($this->tech, $this->lin, ['type' => 'collect'])->ok);
        $this->assertSame(422, $t->submitJob($this->tech, $this->lin, ['type' => 'powershell', 'script' => 'Get-Date'])->http);
        $this->assertSame(422, $t->submitJob($this->tech, $this->lin, ['type' => 'shell', 'script' => 'echo hi', 'params' => ['v' => "a\0b"]])->http, 'a NUL cannot be an environment value');
    }

    public function testLibraryJobsShowWhereTheyCameFromInTheJobList(): void
    {
        $s = $this->script('Origin');
        $this->h->module->scriptActions()->runOnDevice($this->tech, $this->lin, ['library_script_id' => $s['script_id']]);
        $this->h->module->technician()->submitJob($this->tech, $this->lin, ['type' => 'collect']);
        $jobs = $this->h->module->readModel()->jobs($this->lin, 10, false);
        $by = [];
        foreach ($jobs as $j) {
            $by[$j['type']] = $j;
        }
        $this->assertSame(['script_id' => (int) $s['script_id'], 'script_name' => 'Origin', 'script_version' => 1, 'schedule_id' => null, 'approval_id' => null], $by['shell']['library']);
        $this->assertArrayNotHasKey('library', $by['collect'], 'a job from no library keeps the shape it always had');
    }

    // ------------------------------------------------------------------ the REST API

    public function testTheScriptRoutes(): void
    {
        [$c, $j] = $this->h->tech('POST', ['scripts'], $this->tech, ['name' => 'Api', 'language' => 'bash', 'body' => "echo api\n", 'params' => [['name' => 'x', 'type' => 'string']], 'tags' => ['a']]);
        $this->assertSame(201, $c);
        $id = (string) $j['script']['script_id'];
        $this->assertSame("echo api\n", $j['script']['current']['body'], 'the author gets the text back');
        [$c, $j] = $this->h->tech('GET', ['scripts'], $this->viewer);
        $this->assertSame([200, 'Api', 1], [$c, $j['data'][0]['name'], $j['total']]);
        $this->assertArrayNotHasKey('current', $j['data'][0]);
        [$c, $j] = $this->h->tech('GET', ['scripts', $id], $this->viewer, null, ['body' => '1']);
        $this->assertSame([200, false], [$c, isset($j['script']['current']['body'])], 'a viewer does not get the text');
        [$c, $j] = $this->h->tech('GET', ['scripts', $id], $this->tech, null, ['body' => '1']);
        $this->assertSame([200, "echo api\n"], [$c, $j['script']['current']['body']]);
        [$c] = $this->h->tech('GET', ['scripts', $id, 'versions', '1'], $this->viewer);
        $this->assertSame(403, $c);
        [$c, $j] = $this->h->tech('GET', ['scripts', $id, 'versions', '1'], $this->tech);
        $this->assertSame([200, 1], [$c, $j['version']['version']]);
        [$c] = $this->h->tech('GET', ['scripts', $id, 'versions', '9'], $this->tech);
        $this->assertSame(404, $c);
        [$c, $j] = $this->h->tech('PATCH', ['scripts', $id], $this->tech, ['body' => "echo api2\n"]);
        $this->assertSame([200, 2], [$c, $j['script']['current_version']]);
        [$c, $j] = $this->h->tech('POST', [(string) $this->lin, 'jobs'], $this->tech, ['library_script_id' => (int) $id, 'params' => ['x' => 'hi']]);
        $this->assertSame([201, 'queued'], [$c, $j['state']]);
        [$c, $j] = $this->h->tech('GET', ['scripts', $id, 'runs'], $this->tech);
        $this->assertSame([200, 'queued', 2], [$c, $j['data'][0]['state'], $j['data'][0]['script_version']]);
        [$c] = $this->h->tech('GET', ['scripts', $id, 'runs'], $this->viewer);
        $this->assertSame(403, $c);
        [$c] = $this->h->tech('POST', ['scripts', $id, 'resign'], $this->tech);
        $this->assertSame(403, $c);
        [$c, $j] = $this->h->tech('POST', ['scripts', $id, 'resign'], $this->admin);
        $this->assertSame([200, 2], [$c, $j['versions']]);
        [$c] = $this->h->tech('DELETE', ['scripts', $id], $this->tech);
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('POST', ['scripts', $id, 'restore'], $this->tech);
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('GET', ['scripts', '99999'], $this->tech);
        $this->assertSame(404, $c);
        [$c] = $this->h->tech('POST', ['scripts'], $this->viewer, ['name' => 'No', 'language' => 'bash', 'body' => 'x']);
        $this->assertSame(403, $c);
        [$c] = $this->h->tech('GET', ['scripts'], null);
        $this->assertSame(401, $c);
    }
}
