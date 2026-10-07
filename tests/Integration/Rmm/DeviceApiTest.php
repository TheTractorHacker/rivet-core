<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\Installer\InstallerStamp;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Testing\InMemoryRmmModuleState;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * The HTTP layer: TLS, methods and routing, body caps, error shapes, the per-device and global rate-limit closure, the token-gated
 * installer download, and the module switch (503 module_disabled, sub-switches) which is the ONLY wire-visible change of the extraction.
 */
final class DeviceApiTest extends RmmTestCase
{
    /** @return array{0:int,1:string,2:string} device id, token, public key */
    private function enrolled(?RmmHarness $h = null): array
    {
        $h ??= $this->h;
        $tok = $h->token(null, 24, 50);
        [, , $j] = $h->enroll($tok, $h::device());

        return [(int) $j['device_id'], $j['device_token'], $j['signing_public_key']];
    }

    public function testJsonResponsesCarryTheFrozenHeadersAndFlags(): void
    {
        [$c, $hd, $j, $r] = $this->h->call('GET', 'agent_enroll');
        $this->assertSame(405, $c);
        $this->assertSame('application/json', $hd['Content-Type']);
        $this->assertSame('no-store', $hd['Cache-Control']);
        $this->assertSame('{"error":"Use POST.","code":"method_not_allowed"}', $r->body);
        $this->assertSame(RmmProtocol::JSON_FLAGS, RmmResponse::JSON_FLAGS);
        $e = RmmResponse::error(new ApiError(400, 'x', 'café / 1.0'));
        $this->assertStringContainsString('café / 1.0', (string) $e->body);
        $this->assertSame([404, 'not_found'], [$this->h->call('GET', 'agent_nothing')[0], $this->h->call('GET', 'agent_nothing')[2]['code']]);
    }

    public function testPlainHttpIsRefusedWithoutTheEditionOverrideOnEveryEndpoint(): void
    {
        $h = new RmmHarness(null, null, ['allow_insecure_http' => false]);
        [$dev, $T] = $this->enrolledSecure($h);
        $this->assertGreaterThan(0, $dev);
        foreach ([['POST', 'agent_enroll'], ['POST', 'agent_checkin'], ['GET', 'agent_jobs'], ['GET', 'agent_update'], ['POST', 'agent_installer']] as [$m, $e]) {
            [$c, , $j] = $h->call($m, $e, $m === 'POST' ? [] : null, $T, [], [], '127.0.0.1', false);
            $this->assertSame([426, 'tls_required'], [$c, $j['code']], $e);
        }
        [$c] = $h->call('POST', 'agent_checkin', ['seq' => 1, 'collected_at' => $h::ts(), 'agent_version' => '1.0.0'], $T, [], [], '127.0.0.1', true);
        $this->assertSame(200, $c, 'the edition decides secureTransport (trusted proxy rule) and Core honours it');
    }

    /** @return array{0:int,1:string} */
    private function enrolledSecure(RmmHarness $h): array
    {
        $tok = $h->token(null, 24, 50);
        [, , $j] = $h->call('POST', 'agent_enroll', ['enrollment_token' => $tok, 'device' => $h::device()], null, [], [], '127.0.0.1', true);

        return [(int) $j['device_id'], $j['device_token']];
    }

    public function testMethodsAreCheckedBeforeBodiesAndAuthenticationOrderIsFixed(): void
    {
        [, $T] = $this->enrolled();
        [$c, $hd] = $this->h->call('GET', 'agent_checkin', null, $T);
        $this->assertSame([405, 'POST'], [$c, $hd['Allow']]);
        [$c] = $this->h->call('GET', 'agent_checkin');
        $this->assertSame(405, $c, 'checkin: the method is checked before the credential');
        [$c] = $this->h->call('PUT', 'agent_jobs');
        $this->assertSame(401, $c, 'jobs: the credential is checked before the method');
        [$c] = $this->h->call('DELETE', 'agent_update', null, $T);
        $this->assertSame(405, $c);
        [$c, $hd] = $this->h->call('GET', 'agent_installer');
        $this->assertSame([405, 'POST'], [$c, $hd['Allow']]);
    }

    public function testEveryDeviceEndpointAuthenticatesBeforeTheSwitchCheck(): void
    {
        $this->h->token();   // enabled
        [$dev, $T] = $this->enrolled();
        $this->h->module->settings()->disable();
        // legacy (no module state): an unknown bearer is 401, a valid one 403, enroll and installer 403 after the method check
        [$c, , $j] = $this->h->checkin(str_repeat('a', 64));
        $this->assertSame([401, 'invalid_token'], [$c, $j['code']]);
        [$c, , $j] = $this->h->checkin($T);
        $this->assertSame([403, 'forbidden'], [$c, $j['code']]);
        [$c] = $this->h->call('GET', 'agent_jobs', null, $T);
        $this->assertSame(403, $c);
        [$c] = $this->h->call('GET', 'agent_update', null, $T, ['arch' => 'amd64', 'version' => '1.0.1']);
        $this->assertSame(403, $c);
        [$c] = $this->h->call('GET', 'agent_enroll');
        $this->assertSame(405, $c);
        [$c, , $j] = $this->h->enroll('rvte1.000000000000.' . str_repeat('0', 40), $this->h::device());
        $this->assertSame([403, 'forbidden'], [$c, $j['code']]);
        [$c] = $this->h->call('POST', 'agent_installer', ['token' => 'x', 'arch' => 'amd64']);
        $this->assertSame(403, $c);
        $this->assertGreaterThan(0, $dev);
    }

    // ------------------------------------------------------------------ module switch

    public function testAMasterOffModuleAnswers503ModuleDisabledOnEveryDeviceEndpoint(): void
    {
        $h = new RmmHarness(null, new InMemoryRmmModuleState(true), [], true);
        [, $T] = $this->enrolled($h);
        $h->module->settings()->disable();
        $before = $h->counting->statements;
        foreach ([['POST', 'agent_enroll', ['enrollment_token' => 'x', 'device' => []], null], ['POST', 'agent_checkin', [], $T], ['GET', 'agent_jobs', null, $T], ['GET', 'agent_update', null, $T], ['POST', 'agent_installer', [], null],
            ['POST', 'agent_checkin', [], null], ['GET', 'agent_enroll', null, null]] as [$m, $e, $b, $t]) {
            [$c, $hd, $j, $r] = $h->call($m, $e, $b, $t);
            $this->assertSame(503, $c, "$m $e");
            $this->assertSame('{"error":"The RMM service is disabled on this server.","code":"module_disabled"}', $r->body);
            $this->assertSame(['3600', 'no-store', 'application/json'], [$hd['Retry-After'], $hd['Cache-Control'], $hd['Content-Type']]);
        }
        $this->assertLessThanOrEqual(1, $h->counting->statements - $before, 'one settings read at most, however many requests (the settings row is cached); no authentication, no writes');
        // switching it back on resumes: nothing was deleted
        $h->module->settings()->enable();
        [$c] = $h->checkin($T);
        $this->assertSame(200, $c);
    }

    public function testTheEditionKillSwitchAlsoDisables(): void
    {
        $state = new InMemoryRmmModuleState(true);
        $h = new RmmHarness(null, $state, [], true);
        [, $T] = $this->enrolled($h);
        $this->assertSame(200, $h->checkin($T)[0]);
        $state = new class () extends InMemoryRmmModuleState {
            public function __construct()
            {
                parent::__construct(false);
            }
        };
        $h2 = new RmmHarness(null, $state, [], true);
        $h2->module->settings()->enable();
        [$c, , $j] = $h2->call('POST', 'agent_checkin', ['seq' => 1], str_repeat('a', 64));
        $this->assertSame([503, 'module_disabled'], [$c, $j['code']]);
        $this->assertFalse($h2->module->enabled());
        $this->assertTrue($h->module->enabled());
    }

    public function testWithoutAModuleStateTheLegacy403IsKept(): void
    {
        $h = new RmmHarness(null, new InMemoryRmmModuleState(false), [], false);
        [$c, , $j] = $h->enroll('rvte1.000000000000.' . str_repeat('0', 40), $h::device());
        $this->assertSame([403, 'forbidden'], [$c, $j['code']], 'the edition kill switch alone does not change the wire unless the state is handed to the API');
    }

    public function testASwitchedOffFeatureAnswers503FeatureDisabledOnItsEndpoint(): void
    {
        $h = new RmmHarness(null, new InMemoryRmmModuleState(true), [], true);
        [, $T] = $this->enrolled($h);
        $h->module->settings()->set(['features_json' => '{"monitoring":true,"metrics":true,"updates":false,"jobs":false}']);
        foreach ([['GET', 'agent_jobs', null], ['POST', 'agent_jobs', ['job_id' => 'x']], ['GET', 'agent_update', null]] as [$m, $e, $b]) {
            [$c, $hd, $j, $r] = $h->call($m, $e, $b, $T);
            $this->assertSame(503, $c);
            $this->assertSame('{"error":"This feature is disabled on this server.","code":"feature_disabled"}', $r->body);
            $this->assertSame('3600', $hd['Retry-After']);
        }
        [$c] = $h->call('GET', 'agent_jobs');
        $this->assertSame(503, $c, 'answered before authentication: no database lookup for a disabled feature');
        $this->assertSame(200, $h->checkin($T)[0], 'check-in keeps working so devices stay online for what remains');
        $h->module->settings()->set(['features_json' => null]);
        $this->assertSame(200, $h->call('GET', 'agent_jobs', null, $T)[0]);
    }

    // ------------------------------------------------------------------ rate limits (closure)

    public function testThePerDeviceBucketsAndTheirBudgetsAreFrozen(): void
    {
        [$dev, $T] = $this->enrolled();
        $this->h->checkin($T);
        $this->h->call('GET', 'agent_jobs', null, $T);
        $this->h->call('GET', 'agent_update', null, $T);
        $this->assertSame([40, 60], $this->h->rateArgs["agent_checkin:$dev"]);
        $this->assertSame([120, 60], $this->h->rateArgs["agent_jobs:$dev"]);
        $this->assertSame([60, 60], $this->h->rateArgs["agent_update:$dev"]);
    }

    public function testTheCheckinBudgetIs40PerMinuteThenA429WithRetryAfter(): void
    {
        [, $T] = $this->enrolled();
        for ($i = 1; $i <= 40; ++$i) {
            $this->assertSame(200, $this->h->checkin($T)[0], "check-in $i");
        }
        [$c, $hd, $j] = $this->h->checkin($T);
        $this->assertSame([429, 'rate_limited', '60'], [$c, $j['code'], $hd['Retry-After']]);
        $this->assertSame(40, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_checkins'), 'a refused call is not processed');
    }

    public function testJobsAndUpdateBudgets(): void
    {
        [, $T] = $this->enrolled();
        for ($i = 1; $i <= 120; ++$i) {
            $this->assertSame(200, $this->h->call('GET', 'agent_jobs', null, $T)[0]);
        }
        [$c, $hd] = $this->h->call('GET', 'agent_jobs', null, $T);
        $this->assertSame([429, '60'], [$c, $hd['Retry-After']]);
        for ($i = 1; $i <= 60; ++$i) {
            $this->assertSame(422, $this->h->call('GET', 'agent_update', null, $T)[0]);
        }
        [$c, $hd] = $this->h->call('GET', 'agent_update', null, $T);
        $this->assertSame([429, '60'], [$c, $hd['Retry-After']]);
    }

    public function testTheRateLimitIsCheckedBeforeTheBodyIsRead(): void
    {
        [$dev, $T] = $this->enrolled();
        $this->h->rateOverride["agent_checkin:$dev"] = 1;
        $this->h->checkin($T);
        $big = $this->h->request('POST', 'agent_checkin', str_repeat('x', 2000000), $T);
        $this->assertSame(429, $this->h->api->handle($big)->status, 'a limited device does not make the server read a big body');
    }

    public function testTheGlobalCheckinLimitAsksTheFleetToRetryWith503(): void
    {
        [, $T] = $this->enrolled();
        $this->h->module->settings()->update(['limits_json' => '{"max_checkins_per_min":2,"retry_after_min_s":30,"retry_after_max_s":120}']);
        $this->assertSame(200, $this->h->checkin($T)[0]);
        $this->assertSame(200, $this->h->checkin($T)[0]);
        [$c, $hd, $j] = $this->h->checkin($T);
        $this->assertSame([503, 'unavailable'], [$c, $j['code']]);
        $this->assertGreaterThanOrEqual(30, (int) $hd['Retry-After']);
        $this->assertLessThanOrEqual(120, (int) $hd['Retry-After']);
        $this->assertSame([2, 60], $this->h->rateArgs['rmm_checkins_global']);
        $this->assertSame(2, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_checkins'));
        // off (0) means no global bucket is consulted at all
        $this->h->module->settings()->update(['limits_json' => null]);
        $this->h->rateCalls = [];
        $this->h->rateOverride = [];
        $this->h->checkin($T);
        $this->assertArrayNotHasKey('rmm_checkins_global', $this->h->rateCalls);
    }

    public function testAnExceptionFromTheRateLimitClosureIsAGeneric500(): void
    {
        [, $T] = $this->enrolled();
        $api = $this->h->module->deviceApi(static function (): bool {
            throw new \RuntimeException('redis is down');
        }, false);
        $r = $api->handle($this->h->request('POST', 'agent_checkin', '{}', $T));
        $this->assertSame(500, $r->status);
        $this->assertSame('{"error":"Internal error.","code":"internal"}', $r->body);
        $closed = $this->h->module->deviceApi(static fn (): bool => false, false);
        $this->assertSame(429, $closed->handle($this->h->request('POST', 'agent_checkin', '{}', $T))->status, 'a closure that refuses (fail closed) is a 429');
    }

    // ------------------------------------------------------------------ installer download

    /** @return array{0:string,1:array<string,mixed>} the stamped body and its parsed payload */
    private function installerOk(string $token, string $arch = 'amd64', string $kind = 'json'): array
    {
        $h = $this->h;
        $body = match ($kind) {
            'json' => $h->call('POST', 'agent_installer', ['token' => $token, 'arch' => $arch]),
            'form' => $h->call('POST', 'agent_installer', http_build_query(['token' => $token, 'arch' => $arch]), null, [], ['content-type' => 'application/x-www-form-urlencoded']),
            default => $h->call('POST', 'agent_installer', ['arch' => $arch], $token),
        };
        $this->assertSame(200, $body[0], json_encode($body[2]));
        $resp = $body[3];
        $this->assertNotNull($resp->file);
        $bytes = file_get_contents($resp->file->path) . (string) $resp->file->trailer;
        $parsed = InstallerStamp::read($bytes);
        $this->assertNotNull($parsed);

        return [$bytes, $parsed['data']];
    }

    private function installerSetup(): array
    {
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test']);
        $b = $this->h->publishBinary('1.0.0', 'amd64', 8192, true, null);
        $tok = $this->h->token($this->h->clientA, 24, 500);

        return [$tok, $b];
    }

    public function testTheInstallerIsTheCurrentBinaryStampedForTheTokensClient(): void
    {
        [$tok, $b] = $this->installerSetup();
        foreach (['json', 'form', 'bearer'] as $kind) {
            [$bytes, $payload] = $this->installerOk($tok, 'amd64', $kind);
            $this->assertSame($b['bytes'], substr($bytes, 0, 8192), "$kind: the original executable");
            $this->assertSame($tok, $payload['enrollment_token']);
            $this->assertSame('Dept A', $payload['department']);
            $this->assertSame('https://rmm.example.test', $payload['server_url']);
            $this->assertNull($payload['ca_pem']);
        }
        $r = $this->h->call('POST', 'agent_installer', ['token' => $tok, 'arch' => 'amd64'])[3];
        $this->assertSame('application/octet-stream', $r->headers['Content-Type']);
        $this->assertSame('attachment; filename="RivetIT-Agent-Setup-dept-a-x64.exe"', $r->headers['Content-Disposition']);
        $this->assertNotNull($r->file);
        $this->assertSame(8192 + strlen((string) $r->file->trailer), $r->file->totalLength());
        // the CA certificate configured for the instance is embedded
        $pem = "-----BEGIN CERTIFICATE-----\nMIIBhzCCAS2gAwIBAgIUQ0Wf\n-----END CERTIFICATE-----\n";
        $this->h->module->settings()->set(['ca_pem' => $pem]);
        [, $payload] = $this->installerOk($tok);
        $this->assertSame(trim($pem), trim((string) $payload['ca_pem']));
        // two downloads of the same token carry different installer ids
        [, $p1] = $this->installerOk($tok);
        [, $p2] = $this->installerOk($tok);
        $this->assertNotSame($p1['installer_id'], $p2['installer_id']);
        $this->assertSame(0, (int) $this->h->one('SELECT use_count FROM endpoint_agent_enrollment_tokens'), 'a download is not an enrollment: no use is consumed');
    }

    public function testInstallerRefusalsAreGenericAndStatusCodesFixed(): void
    {
        [$tok] = $this->installerSetup();
        [$c, , $j] = $this->h->call('POST', 'agent_installer', ['token' => $tok, 'arch' => 'arm64']);
        $this->assertSame([409, 'unavailable'], [$c, $j['code']], 'no arm64 binary published');
        [$c, , $j] = $this->h->call('POST', 'agent_installer', ['token' => $tok, 'arch' => 'sparc']);
        $this->assertSame([422, 'invalid'], [$c, $j['code']]);
        [$c] = $this->h->call('POST', 'agent_installer', '');
        $this->assertSame(422, $c);
        [$c] = $this->h->call('POST', 'agent_installer', str_repeat('x', 5000));
        $this->assertSame(413, $c);
        [$c, , $j] = $this->h->call('POST', 'agent_installer', ['arch' => 'amd64'], null, ['token' => $tok]);
        $this->assertSame([400, 'token_in_url'], [$c, $j['code']]);
        $this->h->module->settings()->set(['service_url' => '']);
        $this->h->publishBinary('1.0.1', 'arm64', 4096, true, null);
        [$c, , $j] = $this->h->call('POST', 'agent_installer', ['token' => $tok, 'arch' => 'arm64']);
        $this->assertSame(409, $c);
        $this->assertStringContainsString('service URL', $j['error']);
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test']);
        $svc = $this->h->module->enrollment();
        $rev = $svc->createToken($this->h->clientA, 0, 'stable', 1, 5, 'r', 1);
        $svc->revokeToken($rev['token_id'], 1);
        $exp = $svc->createToken($this->h->clientA, 0, 'stable', 1, 5, 'e', 1)['token'];
        $this->h->q("UPDATE endpoint_agent_enrollment_tokens SET expires_at='" . gmdate('Y-m-d H:i:s', time() - 5) . "' WHERE label='e'");
        $used = $svc->createToken($this->h->clientA, 0, 'stable', 1, 1, 'u', 1)['token'];
        $this->h->q("UPDATE endpoint_agent_enrollment_tokens SET use_count=1 WHERE label='u'");
        $bad = ['garbage', 'rvte1.' . str_repeat('c', 12) . '.' . str_repeat('d', 40), 'rvte1.' . explode('.', $tok)[1] . '.' . str_repeat('e', 40), $rev['token'], $exp, $used];
        foreach ($bad as $t) {
            [$c, , $j] = $this->h->call('POST', 'agent_installer', ['token' => $t, 'arch' => 'amd64']);
            $this->assertSame([404, 'not_found', 'Not found.'], [$c, $j['code'], $j['error']]);
        }
        $reasons = array_column($this->h->rows('SELECT reason FROM endpoint_agent_enroll_attempts ORDER BY attempt_id'), 'reason');
        $this->assertSame(['installer_malformed', 'installer_invalid_token', 'installer_invalid_token', 'installer_revoked', 'installer_expired', 'installer_exhausted'], $reasons);
        $this->assertCount(6, array_filter($this->h->audit->records(), static fn (array $r): bool => $r['action'] === 'Installer Download Rejected'));
    }

    public function testInstallerRateLimitsAreDatabaseBacked(): void
    {
        [$tok] = $this->installerSetup();
        for ($i = 1; $i <= 10; ++$i) {
            $this->assertSame(404, $this->h->call('POST', 'agent_installer', ['token' => 'garbage', 'arch' => 'amd64'])[0]);
        }
        [$c, $hd, $j] = $this->h->call('POST', 'agent_installer', ['token' => 'garbage', 'arch' => 'amd64']);
        $this->assertSame([429, 'rate_limited', '600'], [$c, $j['code'], $hd['Retry-After']]);
        [$c] = $this->h->call('POST', 'agent_installer', ['token' => $tok, 'arch' => 'amd64']);
        $this->assertSame(429, $c, 'a valid token from a limited address is limited too');
        // the enrollment bucket is a different bucket
        $this->assertSame(201, $this->h->enroll($tok, $this->h::device())[0]);
        // a clean address: 30 downloads per token per window, then 429
        $this->h->q('DELETE FROM endpoint_agent_enroll_attempts');
        for ($i = 1; $i <= 30; ++$i) {
            $ip = '10.1.' . intdiv($i, 250) . '.' . ($i % 250 + 1);
            $this->assertSame(200, $this->h->call('POST', 'agent_installer', ['token' => $tok, 'arch' => 'amd64'], null, [], [], $ip)[0], "download $i");
        }
        [$c] = $this->h->call('POST', 'agent_installer', ['token' => $tok, 'arch' => 'amd64'], null, [], [], '10.2.0.1');
        $this->assertSame(429, $c, 'per-token download budget');
        // per-selector failures: 20 wrong secrets for a known selector, from different addresses
        $this->h->q('DELETE FROM endpoint_agent_enroll_attempts');
        $wrong = 'rvte1.' . explode('.', $tok)[1] . '.' . str_repeat('9', 40);
        for ($i = 1; $i <= 20; ++$i) {
            $this->assertSame(404, $this->h->call('POST', 'agent_installer', ['token' => $wrong, 'arch' => 'amd64'], null, [], [], "10.3.0.$i")[0]);
        }
        $this->assertSame(429, $this->h->call('POST', 'agent_installer', ['token' => $wrong, 'arch' => 'amd64'], null, [], [], '10.3.1.1')[0]);
        $this->assertSame(429, $this->h->call('POST', 'agent_installer', ['token' => $tok, 'arch' => 'amd64'], null, [], [], '10.3.1.2')[0], 'the real token is limited by its selector too');
    }

    public function testTheInstallerWorksForTheOtherArchitectureAndNamesTheClientSlug(): void
    {
        [$tok] = $this->installerSetup();
        $b = $this->h->publishBinary('1.0.0', 'arm64', 4096, true, null);
        $c2 = $this->h->tenancy->addClient("Caf\u{e9} & Sons, Inc.");
        $t2 = $this->h->module->enrollment()->createToken($c2, 0, 'pilot', 1, 5, 'x', 1)['token'];
        $r = $this->h->call('POST', 'agent_installer', ['token' => $t2, 'arch' => 'arm64'])[3];
        $this->assertSame('attachment; filename="RivetIT-Agent-Setup-cafe-sons-inc-arm64.exe"', $r->headers['Content-Disposition']);
        $this->assertNotNull($r->file);
        $this->assertSame(4096, $r->file->length);
        $this->assertGreaterThan(0, $b['binary_id']);
        $this->assertNotSame('', $tok);
    }

    // ------------------------------------------------------------------ misc

    public function testTheEnrollBodyIsReadWithinItsCapAndBodylessRequestsAre422(): void
    {
        $this->h->token();
        $r = $this->h->api->enroll($this->h->request('POST', 'agent_enroll', null));
        $this->assertSame(422, $r->status);
        $r = $this->h->api->enroll($this->h->request('POST', 'agent_enroll', str_repeat(' ', RmmProtocol::ENROLL_MAX_BODY) . '{}'));
        $this->assertSame(413, $r->status);
        $r = $this->h->api->enroll($this->h->request('POST', 'agent_enroll', '{}', null, [], [], '127.0.0.1', true, 99999));
        $this->assertSame(413, $r->status, 'the declared length alone is enough to refuse');
    }

    public function testNoRmmClassTouchesSuperglobalsHeadersOrExit(): void
    {
        $dir = dirname(__DIR__, 3) . '/src/Rmm';
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
            if (!$f instanceof \SplFileInfo || $f->getExtension() !== 'php') {
                continue;
            }
            $tokens = token_get_all((string) file_get_contents($f->getPathname()));
            foreach ($tokens as $i => $t) {
                if (!is_array($t)) {
                    continue;
                }
                $k = $i - 1;
                while ($k > 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    --$k;
                }
                $prev = $tokens[$k] ?? null;
                $isMethod = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_FUNCTION], true);
                $isCall = !$isMethod && ($tokens[$i + 1] ?? null) === '(' && in_array($t[1], ['header', 'http_response_code', 'setcookie', 'session_start'], true);
                if ($t[0] === T_EXIT || ($t[0] === T_VARIABLE && (str_starts_with($t[1], '$_') || $t[1] === '$GLOBALS')) || ($isCall && $f->getFilename() !== 'SapiEmitter.php')) {
                    $bad[] = $f->getFilename() . ':' . $t[2];
                }
            }
        }
        $this->assertSame([], $bad);
    }
}
