<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Tests\Support\RmmTestCase;

/** The settings row: validation, the module switch columns, the signing key, the integration, the delivered checks. */
final class SettingsTest extends RmmTestCase
{
    private function s(): RmmSettings
    {
        return $this->h->module->settings();
    }

    public function testDefaultsMatchTheSchemaAndTheMasterSwitchIsOff(): void
    {
        $c = $this->s()->get();
        $this->assertSame([0, 300, 60, 900, 604800, 3, 2, 30, 180], [(int) $c['enabled'], (int) $c['check_in_interval_s'], (int) $c['collect_interval_s'], (int) $c['offline_after_s'], (int) $c['stale_after_s'],
            (int) $c['failure_debounce'], (int) $c['recovery_debounce'], (int) $c['retention_days'], (int) $c['job_retention_days']]);
        $this->assertSame([65536, 300, 3600, 3600, 120, 3, 72, 'approval'], [(int) $c['job_output_max_bytes'], (int) $c['job_default_timeout_s'], (int) $c['job_max_timeout_s'], (int) $c['job_expiry_s'],
            (int) $c['job_ack_timeout_s'], (int) $c['job_max_attempts'], (int) $c['enroll_max_ttl_h'], $c['unmatched_policy']]);
        $this->assertSame([null, null, 0, 'sync', 0], [$c['features_json'], $c['limits_json'], (int) $c['shed_level'], $c['ingest_mode'], (int) $c['max_devices']]);
        $this->assertFalse($this->s()->enabled());
    }

    public function testTheRowIsRecreatedIfItGoesMissing(): void
    {
        $this->h->q('DELETE FROM endpoint_agent_settings');
        $this->s()->refresh();
        $this->assertSame(1, (int) $this->s()->get()['id']);
    }

    public function testUpdateClampsNumbersAndNormalisesTheRest(): void
    {
        $errors = $this->s()->update([
            'check_in_interval_s' => 5, 'collect_interval_s' => 99999, 'offline_after_s' => '10', 'stale_after_s' => 1, 'failure_debounce' => 100, 'recovery_debounce' => 0,
            'retention_days' => 9999, 'job_retention_days' => 'x', 'job_output_max_bytes' => 10, 'job_default_timeout_s' => 1, 'job_max_timeout_s' => 1, 'job_expiry_s' => 1,
            'job_ack_timeout_s' => 1, 'job_max_attempts' => 99, 'enroll_max_ttl_h' => 99999, 'unmatched_policy' => 'whatever', 'max_devices' => -4, 'mesh_token_ttl_s' => 5,
            'coexistence_policy' => str_repeat('p', 5000), 'service_url' => 'https://rmm.example.test',
        ]);
        $this->assertSame([], $errors);
        $c = $this->s()->get();
        $this->assertSame([60, 3600, 60, 3600, 20, 1, 400], [(int) $c['check_in_interval_s'], (int) $c['collect_interval_s'], (int) $c['offline_after_s'], (int) $c['stale_after_s'],
            (int) $c['failure_debounce'], (int) $c['recovery_debounce'], (int) $c['retention_days']]);
        $this->assertSame(180, (int) $c['job_retention_days'], 'a non-number keeps the stored value');
        $this->assertSame([1024, 5, 30, 60, 30, 10, 720, 'approval', 0, 30, 4000], [(int) $c['job_output_max_bytes'], (int) $c['job_default_timeout_s'], (int) $c['job_max_timeout_s'], (int) $c['job_expiry_s'],
            (int) $c['job_ack_timeout_s'], (int) $c['job_max_attempts'], (int) $c['enroll_max_ttl_h'], $c['unmatched_policy'], (int) $c['max_devices'], (int) $c['mesh_token_ttl_s'], strlen((string) $c['coexistence_policy'])]);
        $this->assertSame('https://rmm.example.test', $c['service_url']);
    }

    public function testNothingIsWrittenWhenAnythingIsInvalid(): void
    {
        $before = $this->s()->get(true);
        $errors = $this->s()->update(['check_in_interval_s' => 120, 'service_url' => 'ftp://nope', 'checks_json' => '{"not":"a list"}', 'features_json' => '{"warp":true}', 'limits_json' => '{"bogus":1}', 'ingest_mode' => 'turbo']);
        $this->assertCount(5, $errors);
        $this->assertSame($before, $this->s()->get(true));
        $this->assertSame('The service URL must be an https:// address that agents can reach.', $errors[0]);
    }

    /** @return iterable<string,array{0:string,1:bool}> */
    public static function urls(): iterable
    {
        yield 'https' => ['https://rmm.example.test', true];
        yield 'https with port and path' => ['https://rmm.example.test:8443/rmm', true];
        yield 'http' => ['http://rmm.example.test', false];
        yield 'userinfo' => ['https://u:p@rmm.example.test', false];
        yield 'no host' => ['https://', false];
        yield 'garbage' => ['not a url', false];
        yield 'too long' => ['https://' . 'a.com/' . 'x', true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('urls')]
    public function testServiceUrlRules(string $url, bool $ok): void
    {
        $this->assertSame($ok, RmmSettings::serviceUrlOk($url, false));
        $this->assertFalse(RmmSettings::serviceUrlOk('https://' . str_repeat('a', 600) . '.test', false));
        $this->assertTrue(RmmSettings::serviceUrlOk('http://127.0.0.1:8080', true));
    }

    public function testUpdateWithEnabledMintsTheKeyAndIntegrationAndDisableKeepsEverything(): void
    {
        $this->assertSame([], $this->s()->update(['enabled' => true]));
        $this->assertTrue($this->s()->enabled());
        $this->assertNotSame('', $this->s()->get()['signing_public_key']);
        $this->assertGreaterThan(0, (int) $this->s()->get()['integration_id']);
        $kid = $this->s()->get()['signing_key_id'];
        $this->assertSame([], $this->s()->update(['enabled' => false]));
        $this->assertFalse($this->s()->enabled());
        $this->assertSame($kid, $this->s()->get(true)['signing_key_id'], 'disabling keeps the key');
        $this->assertSame([], $this->s()->update(['enabled' => true]));
        $this->assertSame($kid, $this->s()->get(true)['signing_key_id'], 'and re-enabling does not mint a new one');
    }

    public function testChecksAreValidatedStoredAndDeliveredSigned(): void
    {
        $this->s()->enable();
        $this->assertCount(3, $this->s()->checks(), 'the defaults');
        $errors = $this->s()->update(['checks_json' => json_encode([['key' => 'c1', 'type' => 'service', 'params' => ['name' => 'Spooler', 'expect' => 'running'], 'interval_s' => 60], ['key' => 'c2', 'type' => 'pending_reboot', 'interval_s' => 600]])]);
        $this->assertSame([], $errors);
        $signed = $this->s()->signedChecks();
        $this->assertSame(['c1', 'c2'], array_column($signed, 'key'));
        [, $pub] = $this->s()->signingKey();
        foreach ($signed as $check) {
            $this->assertTrue(Signer::verify(Signer::checkMessage($check), $check['signature'], $pub));
        }
        $this->assertStringContainsString('"params":{}', (string) json_encode($signed[1]), 'empty params travel as {}');
        $this->assertNotSame([], $this->s()->update(['checks_json' => '[{"key":"x","type":"nope","interval_s":60}]']));
        $this->assertSame([], $this->s()->update(['checks_json' => '']), 'blank restores the defaults');
        $this->assertNull($this->s()->get()['checks_json']);
        $this->assertCount(3, $this->s()->checks());
    }

    public function testFeaturesDefaultToLegacyBehaviourAndRemoteFollowsMesh(): void
    {
        $f = $this->s()->features();
        $this->assertSame(['monitoring' => true, 'metrics' => true, 'jobs' => true, 'remote' => false, 'updates' => true], array_intersect_key($f, array_flip(['monitoring', 'metrics', 'jobs', 'remote', 'updates'])));
        foreach (['inventory_software', 'policies', 'patching', 'software', 'logs', 'reports'] as $later) {
            $this->assertFalse($f[$later], "$later is off by default");
        }
        $this->s()->set(['mesh_enabled' => 1]);
        $this->assertTrue($this->s()->featureOn('remote'));
        $this->assertSame([], $this->s()->update(['features_json' => ['monitoring' => true, 'updates' => true]]));
        $f = $this->s()->features();
        $this->assertSame([true, false, false, false, true], [$f['monitoring'], $f['metrics'], $f['jobs'], $f['remote'], $f['updates']], 'a stored list names exactly what is on');
        $this->assertFalse($this->s()->featureOn('unknown'));
        $this->assertSame([], $this->s()->update(['features_json' => null]));
        $this->assertTrue($this->s()->featureOn('jobs'));
        $this->assertSame([null, 'Features must be a JSON object of feature name to true or false.'], RmmSettings::validateFeatures('[1]'));
        $this->assertSame([null, 'Unknown feature warp.'], RmmSettings::validateFeatures('{"warp":true}'));
        $this->assertSame([null, 'Feature jobs must be true or false.'], RmmSettings::validateFeatures('{"jobs":1}'));
        $this->assertSame(['{}', null], RmmSettings::validateFeatures('{}'));
    }

    public function testLimitsAreValidatedAndDefaultsFilledIn(): void
    {
        $this->assertSame(['max_checkins_per_min' => 0, 'retry_after_min_s' => 30, 'retry_after_max_s' => 120, 'shed_retry_min_s' => 60, 'shed_retry_max_s' => 300], $this->s()->limits());
        $this->assertSame([], $this->s()->update(['limits_json' => '{"max_checkins_per_min":200,"retry_after_min_s":10}']));
        $l = $this->s()->limits();
        $this->assertSame([200, 10, 120], [$l['max_checkins_per_min'], $l['retry_after_min_s'], $l['retry_after_max_s']]);
        foreach (['{"max_checkins_per_min":-1}', '{"max_checkins_per_min":1.5}', '{"max_checkins_per_min":"5"}', '{"retry_after_min_s":500,"retry_after_max_s":100}', '{"shed_retry_min_s":400}', '[5]', '"x"'] as $bad) {
            $this->assertNotNull(RmmSettings::validateLimits($bad)[1], $bad);
        }
        $this->s()->set(['limits_json' => '{"max_checkins_per_min":"junk"}']);
        $this->assertSame(0, $this->s()->limits()['max_checkins_per_min'], 'a damaged stored value falls back to the default');
    }

    public function testTheSigningKeyIsSealedAndRotationChangesTheId(): void
    {
        $this->s()->enable();
        [$sec, $pub, $kid] = $this->s()->signingKey();
        $this->assertSame(Signer::keyId($pub), $kid);
        $this->assertSame(16, strlen($kid));
        $row = $this->h->rows('SELECT signing_private_key_enc FROM endpoint_agent_settings')[0];
        $this->assertStringNotContainsString($sec, (string) $row['signing_private_key_enc']);
        $new = $this->s()->generateSigningKey();
        $this->assertNotSame($kid, $new);
        $this->assertSame($new, $this->s()->signingKey()[2]);
        $this->assertNotNull($this->h->one('SELECT signing_key_created_at FROM endpoint_agent_settings'));
        // an unreadable key is a clear error, never a silent unsigned response
        $this->h->q("UPDATE endpoint_agent_settings SET signing_private_key_enc='garbage'");
        $this->s()->refresh();
        $this->expectException(\RuntimeException::class);
        $this->s()->signingKey();
    }

    public function testTheIntegrationRowIsEnsuredOnceAndRecoveredIfItVanishes(): void
    {
        $id = $this->s()->integrationId();
        $this->assertSame($id, $this->s()->integrationId());
        $this->assertSame($id, (int) $this->s()->get()['integration_id']);
        $this->s()->set(['integration_id' => 99999]);
        $this->assertSame($id, $this->s()->integrationId(), 'a stale id is replaced by the bridge\'s row');
        $this->assertSame($id, (int) $this->s()->get()['integration_id']);
    }

    public function testSetRefusesColumnsItDoesNotOwn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->s()->set(['signing_private_key_enc' => 'x']);
    }
}
