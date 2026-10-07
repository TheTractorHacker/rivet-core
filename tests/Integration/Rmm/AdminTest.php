<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Crypto\CanonicalJson;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** RmmAdmin: the validated administration operations behind the settings page (port of the settings parts of the RivetIT handlers). */
final class AdminTest extends RmmTestCase
{
    private RmmPrincipal $admin;
    private RmmPrincipal $tech;

    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(policy: new AllowUsersPolicy([1 => true, 10 => [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED]]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->tech = new RmmPrincipal(10, 'Tech');
    }

    private function row(): array
    {
        return $this->h->rows('SELECT * FROM endpoint_agent_settings WHERE id = 1')[0];
    }

    public function testSwitchingOnMintsTheKeyAndTheIntegrationAndAuditsIt(): void
    {
        $a = $this->h->module->admin();
        $this->assertSame('', $this->row()['signing_public_key']);
        $r = $a->saveSettings($this->admin, ['enabled' => 1, 'service_url' => 'https://rmm.example.com']);
        $this->assertTrue($r->ok, $r->message);
        $this->assertTrue($r->data['enabled']);
        $row = $this->row();
        $this->assertSame([1, 'https://rmm.example.com'], [(int) $row['enabled'], $row['service_url']]);
        $this->assertNotSame('', $row['signing_public_key']);
        $this->assertGreaterThan(0, (int) $row['integration_id']);
        $this->assertStringContainsString('(on)', $this->h->audit->records()[0]['description']);
        $this->assertTrue($a->disable($this->admin)->ok);
        $this->assertSame(0, (int) $this->row()['enabled']);
        $this->assertNotSame('', $this->row()['signing_public_key'], 'nothing is deleted when the module is switched off');
        $this->assertTrue($a->enable($this->admin)->ok);
        $this->assertSame(1, (int) $this->row()['enabled']);
    }

    public function testValidationRefusesTheWholeSaveAndWritesNothing(): void
    {
        $a = $this->h->module->admin();
        $r = $a->saveSettings($this->admin, ['enabled' => 1, 'service_url' => 'ftp://insecure.example.com', 'failure_debounce' => 7]);
        $this->assertSame([false, 422, 'invalid'], [$r->ok, $r->http, $r->code]);
        $this->assertStringContainsString('https://', $r->message);
        $this->assertSame([0, 3, ''], [(int) $this->row()['enabled'], (int) $this->row()['failure_debounce'], $this->row()['service_url']], 'nothing was written');
        $r = $a->saveSettings($this->admin, ['checks_json' => '[{"key":"x","type":"nope","interval_s":60}]']);
        $this->assertFalse($r->ok);
        $this->assertNull($this->row()['checks_json'], 'invalid check definitions are rejected');
        $r = $a->saveSettings($this->admin, ['ingest_mode' => 'turbo']);
        $this->assertFalse($r->ok);
        $r = $a->saveSettings($this->admin, ['features_json' => '{"time_travel":true}']);
        $this->assertFalse($r->ok);
        $r = $a->saveSettings($this->admin, ['limits_json' => '{"retry_after_min_s":500,"retry_after_max_s":100}']);
        $this->assertFalse($r->ok);
        $this->assertCount(1, $r->data['errors']);
    }

    public function testNumbersAreClampedNotRefused(): void
    {
        $a = $this->h->module->admin();
        $this->assertTrue($a->saveSettings($this->admin, ['failure_debounce' => 99, 'check_in_interval_s' => 5, 'retention_days' => 0, 'job_max_attempts' => '4', 'max_devices' => 12])->ok);
        $row = $this->row();
        $this->assertSame([20, 60, 1, 4, 12], [(int) $row['failure_debounce'], (int) $row['check_in_interval_s'], (int) $row['retention_days'], (int) $row['job_max_attempts'], (int) $row['max_devices']]);
    }

    public function testSettingsAndSignedChecksPersistAndVerifyAgainstTheInstanceKey(): void
    {
        $a = $this->h->module->admin();
        $checks = '[{"key":"svc_spooler","type":"service","params":{"name":"Spooler"},"interval_s":120},{"key":"scr","type":"script","params":{"script":"(Get-Date).Year","timeout_s":10},"interval_s":600}]';
        $this->assertTrue($a->saveSettings($this->admin, ['enabled' => 1, 'failure_debounce' => 5, 'unmatched_policy' => 'approval', 'checks_json' => $checks])->ok);
        $s = $this->h->module->settings();
        $this->assertSame(5, (int) $s->get(true)['failure_debounce']);
        $this->assertCount(2, $s->checks());
        $sc = $s->signedChecks();
        $this->assertCount(2, $sc);
        $this->assertSame('script', $sc[1]['type']);
        $obj = $sc[1];
        $sig = $obj['signature'];
        unset($obj['signature']);
        $this->assertTrue(Signer::verify(CanonicalJson::encode(json_decode((string) json_encode($obj))), $sig, $s->get()['signing_public_key']), 'a delivered check definition verifies against the instance public key');
        // an empty checks text restores the defaults
        $this->assertTrue($a->saveSettings($this->admin, ['checks_json' => ''])->ok);
        $this->assertNull($this->row()['checks_json']);
        $this->assertSame(RmmHarnessChecks::DEFAULT_KEYS, array_column($s->checks(), 'key'));
    }

    public function testFeaturePresetsAndLegacyDefaults(): void
    {
        $a = $this->h->module->admin();
        $s = $this->h->module->settings();
        $this->assertTrue($a->applyFeaturePreset($this->admin, 'light')->ok);
        $this->assertSame(['monitoring' => true, 'metrics' => false, 'jobs' => false, 'remote' => false, 'updates' => true], array_intersect_key($s->features(), array_flip(['monitoring', 'metrics', 'jobs', 'remote', 'updates'])));
        $this->assertTrue($a->applyFeaturePreset($this->admin, 'standard')->ok);
        $this->assertTrue($s->features()['remote']);
        $this->assertTrue($s->features()['jobs']);
        $this->assertTrue($a->applyFeaturePreset($this->admin, 'legacy')->ok);
        $this->assertNull($this->row()['features_json']);
        $this->assertFalse($s->features()['remote'], 'legacy: remote follows the MeshCentral switch (off)');
        $this->assertSame(422, $a->applyFeaturePreset($this->admin, 'everything')->http);
    }

    public function testStateFollowsTheSwitchInTheSameRequest(): void
    {
        $dir = sys_get_temp_dir() . '/rmm_state_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            $h = new RmmHarness(state: new \RivetCore\Testing\InMemoryRmmModuleState(true, $dir), policy: new AllowUsersPolicy([1 => true]));
            $a = $h->module->admin();
            $this->assertTrue($a->saveSettings($this->admin, ['enabled' => 1, 'service_url' => 'https://rmm.example.com'])->ok);
            $this->assertTrue($h->module->enabled());
            $a->disable($this->admin);
            $this->assertFalse($h->module->enabled(), 'the module switch reads the state the admin operation just wrote');
            $this->assertTrue($a->applyFeaturePreset($this->admin, 'standard')->ok, 'administration works while the module is off');
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    // ------------------------------------------------------------------ MeshCentral

    public function testMeshSettingsValidation(): void
    {
        $a = $this->h->module->admin();
        $key = MeshCookie::newLoginKey();
        foreach (['ftp://evil', 'https://u:p@host', 'https://host/?q=1', 'https://host/#frag'] as $bad) {
            $r = $a->saveMesh($this->admin, ['mesh_enabled' => 1, 'mesh_url' => $bad]);
            $this->assertFalse($r->ok, $bad);
        }
        $this->assertSame(0, (int) $this->row()['mesh_enabled'], 'an invalid MeshCentral address changes nothing');
        // plain http is accepted only when the module is told it runs on a loopback test server
        $this->assertTrue($a->saveMesh($this->admin, ['mesh_url' => 'http://127.0.0.1:8080'])->ok);
        $prod = new RmmHarness(options: ['allow_insecure_http' => false], policy: new AllowUsersPolicy([1 => true]));
        $r = $prod->module->admin()->saveMesh($this->admin, ['mesh_url' => 'http://plain.example.com']);
        $this->assertFalse($r->ok);
        $this->assertFalse($prod->module->admin()->saveSettings($this->admin, ['service_url' => 'http://rmm.example.com'])->ok);
        $this->assertSame(['', ''], [$prod->one('SELECT mesh_url FROM endpoint_agent_settings'), $prod->one('SELECT service_url FROM endpoint_agent_settings')], 'the failed saves wrote nothing');
        $this->h = new RmmHarness(policy: new AllowUsersPolicy([1 => true]));
        $a = $this->h->module->admin();
        foreach ([['mesh_domain' => 'a b'], ['mesh_account_template' => 'x;y'], ['mesh_account_template' => str_repeat('a', 101)]] as $bad) {
            $this->assertFalse($a->saveMesh($this->admin, ['mesh_url' => 'https://mesh.example.com'] + $bad)->ok, json_encode($bad));
        }
        $r = $a->saveMesh($this->admin, ['mesh_url' => 'https://mesh.example.com', 'mesh_login_key' => 'not hex']);
        $this->assertFalse($r->ok);
        $this->assertStringContainsString('loginTokenKey', $r->message);
        $this->assertTrue($a->saveMesh($this->admin, ['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.com/', 'mesh_domain' => 'corp', 'mesh_account_template' => 'rivet-{username}', 'mesh_policy' => 'attended', 'mesh_token_ttl_s' => 9999, 'mesh_login_key' => strtoupper($key)])->ok);
        $row = $this->row();
        $this->assertSame([1, 'https://mesh.example.com', 'corp', 'rivet-{username}', 'attended', 3600], [(int) $row['mesh_enabled'], $row['mesh_url'], $row['mesh_domain'], $row['mesh_account_template'], $row['mesh_policy'], (int) $row['mesh_token_ttl_s']]);
        $this->assertNotSame($key, $row['mesh_login_key_enc'], 'stored encrypted');
        $this->assertSame($key, $this->h->box->decrypt($row['mesh_login_key_enc']), 'lower-cased before it is sealed');
        // leaving the key empty keeps it
        $this->assertTrue($a->saveMesh($this->admin, ['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.com', 'mesh_login_key' => ''])->ok);
        $this->assertSame($key, $this->h->box->decrypt($this->row()['mesh_login_key_enc']));
        $summary = json_encode($this->h->module->readModel()->settingsSummary());
        $this->assertStringNotContainsString($key, (string) $summary);
        $this->assertStringNotContainsString($this->row()['mesh_login_key_enc'], (string) $summary);
        $this->assertTrue($this->h->module->readModel()->settingsSummary()['mesh_login_key_set']);
        $this->assertStringContainsString('login key replaced', (string) json_encode($this->h->audit->records()));
    }

    // ------------------------------------------------------------------ signing key

    public function testRotatingTheSigningKeyWarnsAndCountsTheDevicesThatMustReEnroll(): void
    {
        $a = $this->h->module->admin();
        $this->h->enable();
        $facts = $a->keyFacts();
        $this->assertTrue($facts['set']);
        $this->assertSame('ed25519', $facts['algorithm']);
        $old = $facts['key_id'];
        $this->h->asset(['name' => 'K1', 'serial' => 'SER-K1']);
        [, , $j] = $this->h->enroll($this->h->token(), $this->h::device(['serial' => 'SER-K1']));
        $this->assertSame($facts['public_key'], $j['signing_public_key']);
        $r = $a->rotateSigningKey($this->admin);
        $this->assertTrue($r->ok);
        $this->assertSame('rotated', $r->code);
        $this->assertStringContainsString('every device must re-enroll', $r->message);
        $this->assertSame(1, $r->data['devices_to_reenroll']);
        $this->assertNotSame($old, $r->data['key_id']);
        $this->assertSame($r->data['key_id'], $a->keyFacts()['key_id']);
        $this->assertNotSame($facts['public_key'], $a->keyFacts()['public_key'], 'an enrolled agent still pins the OLD public key');
        $this->assertStringContainsString($r->data['key_id'], (string) json_encode($this->h->audit->records()));
        $this->assertStringNotContainsString('private', strtolower((string) json_encode($a->keyFacts())));
    }

    public function testNoSecretIsEverPartOfAReadModel(): void
    {
        $this->h->enable();
        $this->h->module->admin()->saveMesh($this->admin, ['mesh_url' => 'https://mesh.example.com', 'mesh_login_key' => MeshCookie::newLoginKey()]);
        $row = $this->row();
        $dump = (string) json_encode([$this->h->module->readModel()->settingsSummary(), $this->h->module->admin()->keyFacts()]);
        $this->assertStringNotContainsString($row['signing_private_key_enc'], $dump);
        $this->assertStringNotContainsString($row['mesh_login_key_enc'], $dump);
        $this->assertStringNotContainsString('signing_private_key_enc', $dump);
    }
}

/** The default check keys (RmmSettings::defaultChecks), spelled out so the test does not depend on the code under test. */
final class RmmHarnessChecks
{
    public const DEFAULT_KEYS = ['disk_c', 'pending_reboot', 'svc_eventlog'];
}
