<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Rmm\Remote\ConsoleLink;
use RivetCore\Rmm\Remote\ConsoleRedeemService;
use RivetCore\Rmm\Remote\DbBackgroundModeConfig;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Tests\Support\ConsoleTestCase;

/**
 * The redeem half of the Rivet Console contract on the scratch database and MockMeshServer: the success body and the cookie, the identical 400, 403 approval_required, 426, 429,
 * single use (also under a real race), the gate re-run at redeem time, no secret in any log, audit row or event, and tenant isolation.
 */
final class ConsoleRedeemTest extends ConsoleTestCase
{
    // ------------------------------------------------------------------ success

    public function testRedeemReturnsTheContractBody(): void
    {
        [$code, $sid, $fallback] = $this->launch($this->tech, $this->devA);
        $r = $this->redeemCode($code);
        $this->assertSame(200, $r->status, (string) $r->body);
        $b = $this->json($r);
        $this->assertSame(['ok', 'session_id', 'node', 'kind', 'technician', 'mesh', 'notice', 'expires_in', 'report', 'bg_authorization'], array_keys($b));
        $this->assertTrue($b['ok']);
        $this->assertSame($sid, $b['session_id'], 'the same id as the launch and its Remote Session audit row');
        $this->assertSame('node//' . str_repeat('B', 24) . $this->devA, $b['node']);
        $this->assertMatchesRegularExpression(ConsoleLink::NODE_RE, $b['node']);
        $this->assertSame('desktop', $b['kind']);
        $this->assertSame(['username' => 'rv-18', 'display' => 'Tess'], $b['technician']);
        $this->assertSame(['server', 'domain', 'cookie', 'userid'], array_keys($b['mesh']));
        $this->assertSame([$this->mesh->url(), '', 'user//rv-18'], [$b['mesh']['server'], $b['mesh']['domain'], $b['mesh']['userid']]);
        $this->assertNull($b['notice']);
        $this->assertSame(180, $b['expires_in']);
        $this->assertNull($b['bg_authorization']);
        $this->assertSame(['url', 'token', 'paths'], array_keys($b['report']));
        $this->assertSame(self::ORIGIN . '/api/v1/endpoint_console/events', $b['report']['url']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $b['report']['token']);
        $this->assertFalse($b['report']['paths']);
        $this->assertSame('application/json', $r->headers['Content-Type']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertSame([], array_filter(array_keys($r->headers), static fn (string $h): bool => stripos($h, 'access-control') === 0), 'no CORS headers');
        $this->assertStringNotContainsString($fallback, (string) $r->body, 'the stock cookie URL is not in the redeem body');
    }

    public function testTheCookieHasOnceAndNoIpOrXClaimAndItsUserIdIsTheResponsesUserid(): void
    {
        $b = $this->session();
        $c = MeshCookie::decode($b['mesh']['cookie'], $this->loginKey);
        $this->assertNotNull($c, 'decodes with the Mesh login key');
        $this->assertSame(['u', 'a', 'time', 'once'], array_keys($c));
        $this->assertArrayNotHasKey('ip', $c);
        $this->assertArrayNotHasKey('x', $c);
        $this->assertSame($b['mesh']['userid'], $c['u'], 'the cookie u claim is mesh.userid, byte for byte');
        $this->assertSame(3, $c['a']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $c['once']);
        $age = time() - $c['time'];
        $this->assertGreaterThanOrEqual(119, $age);
        $this->assertLessThanOrEqual(124, $age, 'back-dated 120 s');
        $other = $this->session();
        $this->assertNotSame($c['once'], MeshCookie::decode($other['mesh']['cookie'], $this->loginKey)['once'], 'a fresh once claim every time');
    }

    public function testTheStockFallbackCookieNeverCarriesOnce(): void
    {
        [, , $fallback] = $this->launch($this->tech, $this->devA);
        $login = urldecode((string) preg_replace('/^.*[?&]login=([^&]+).*$/', '$1', $fallback));
        $c = MeshCookie::decode($login, $this->loginKey);
        $this->assertSame(['u', 'a', 'time'], array_keys($c));
    }

    public function testTheCookieOnceClaimCanBeSwitchedOff(): void
    {
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->admin, ['console_cookie_once' => false])->ok);
        $b = $this->session();
        $this->assertSame(['u', 'a', 'time'], array_keys(MeshCookie::decode($b['mesh']['cookie'], $this->loginKey)));
    }

    public function testADomainIsConsistentAcrossNodeCookieAndUserid(): void
    {
        $this->assertTrue($this->h->module->admin()->saveMesh($this->admin, ['mesh_enabled' => 1, 'mesh_url' => $this->mesh->url(), 'mesh_domain' => 'dom1', 'mesh_account_template' => 'rv-{user_id}'])->ok);
        $b = $this->session();
        $this->assertSame('dom1', $b['mesh']['domain']);
        $this->assertSame('user/dom1/rv-18', $b['mesh']['userid']);
        $this->assertSame('node/dom1/' . str_repeat('B', 24) . $this->devA, $b['node']);
        $this->assertMatchesRegularExpression(ConsoleLink::NODE_RE, $b['node']);
        $this->assertSame('user/dom1/rv-18', MeshCookie::decode($b['mesh']['cookie'], $this->loginKey)['u']);
        $this->assertSame('dom1', ConsoleLink::validNode($b['node']) ? explode('/', $b['node'])[1] : '');
    }

    public function testKindsComeBackAsLaunched(): void
    {
        foreach ([['terminal', 'terminal-ps'], ['terminal', null], ['files', null], ['desktop', null]] as [$view, $kind]) {
            $b = $this->session($this->tech, $this->devA, $view, $kind);
            $this->assertSame($kind ?? $view, $b['kind']);
        }
    }

    public function testNotifyPolicyGivesTheNoticeAndNoneGivesNull(): void
    {
        $cfg = $this->h->module->backgroundConfig();
        $this->assertTrue($cfg->setPolicyMode('per_client', 1));
        $this->assertNull($this->session()['notice'], 'no stored policy: no notice, and the session is not refused');
        $this->assertTrue($cfg->setClientPolicy($this->h->clientA, 'allow', 1));
        $this->assertNull($this->session()['notice']);
        $this->assertTrue($cfg->setClientPolicy($this->h->clientA, 'notify', 1));
        $this->assertSame('notify', $this->session()['notice']);
        $this->assertTrue($cfg->setPolicyMode('internal', 1));
        $this->assertNull($this->session()['notice'], 'the per-client notice applies in per_client mode only');
    }

    // ------------------------------------------------------------------ single use and the identical 400

    public function testAReplayFailsEvenInsideTheTtl(): void
    {
        [$code] = $this->launch($this->tech, $this->devA);
        $this->assertSame(200, $this->redeemCode($code)->status);
        $again = $this->redeemCode($code);
        $this->assertSame(400, $again->status);
        $this->assertSame('{"ok":false,"error":"invalid_or_expired"}', $again->body);
    }

    public function testEveryFailureCauseIsTheSameBytes(): void
    {
        $answers = [];
        // unknown and malformed
        $answers['unknown'] = $this->redeemCode(ConsoleLink::newCode());
        $answers['malformed short'] = $this->redeemCode('abc');
        $answers['malformed chars'] = $this->redeemCode(str_repeat('!', 43));
        $answers['empty'] = $this->redeemCode('');
        $answers['no body'] = $this->api->handle($this->req('POST', 'redeem', '', ['content-type' => 'application/json']));
        $answers['not json'] = $this->api->handle($this->req('POST', 'redeem', 'nope{', ['content-type' => 'application/json']));
        $answers['code not a string'] = $this->redeem('{"code":12345}');
        $answers['oversized body'] = $this->redeem('{"code":"' . str_repeat('A', 5000) . '"}');
        // expired
        [$exp] = $this->launch($this->tech, $this->devA);
        $this->advance(61);
        $answers['expired'] = $this->redeemCode($exp);
        // used
        [$used] = $this->launch($this->tech, $this->devA);
        $this->assertSame(200, $this->redeemCode($used)->status);
        $answers['used'] = $this->redeemCode($used);
        // technician revoked since the launch
        [$rev] = $this->launch($this->tech, $this->devA);
        $this->policy->deactivate(18);
        $this->h->module->authorizer()->forget();
        $answers['technician revoked'] = $this->redeemCode($rev);
        $this->policy->activate(18);
        $this->h->module->authorizer()->forget();
        // switch off at redeem time
        [$sw] = $this->launch($this->tech, $this->devA);
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->admin, ['features' => ['remote_console' => false]])->ok);
        $answers['switch off'] = $this->redeemCode($sw);
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->admin, ['features' => ['remote_console' => true]])->ok);
        // ability lost (a launch-only technician cannot hold a terminal code: take manage away by deactivating the grant)
        [$ab] = $this->launch($this->tech, $this->devA, 'terminal');
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->admin, ['features' => ['remote_tools' => false]])->ok);
        $answers['remote tools off for a terminal'] = $this->redeemCode($ab);
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->admin, ['features' => ['remote_tools' => true]])->ok);
        // scope removed after the launch
        [$sc] = $this->launch($this->tech, $this->devA);
        $this->h->tenancy->restrictUser(18, [$this->h->clientB]);
        $this->h->module->authorizer()->forget();
        $answers['scope removed'] = $this->redeemCode($sc);
        $this->h->tenancy->restrictUser(18, null);
        $this->h->module->authorizer()->forget();
        // device retired after the launch
        [$re] = $this->launch($this->tech, $this->devA);
        $this->h->module->sql()->run('UPDATE endpoint_agent_devices SET revoked_at = ? WHERE device_id = ?', [$this->h->module->sql()->utcNow(), $this->devA]);
        $answers['device revoked'] = $this->redeemCode($re);
        $this->h->module->sql()->run('UPDATE endpoint_agent_devices SET revoked_at = NULL WHERE device_id = ?', [$this->devA]);
        // device re-mapped to another node after the launch
        [$mp] = $this->launch($this->tech, $this->devA);
        $this->assertTrue($this->h->module->devices()->setMeshNode($this->devA, 'node//' . str_repeat('C', 30), 1));
        $answers['device remapped'] = $this->redeemCode($mp);

        $this->assertGreaterThanOrEqual(15, count($answers));
        foreach ($answers as $why => $r) {
            $this->assertSame(400, $r->status, $why);
            $this->assertSame('{"ok":false,"error":"invalid_or_expired"}', $r->body, "byte-identical for: $why");
            $this->assertSame(['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], $r->headers, "identical headers for: $why");
        }
    }

    public function testApprovalRequiredIsReturnedOnlyForAValidCode(): void
    {
        $cfg = $this->h->module->backgroundConfig();
        $this->assertTrue($cfg->setPolicyMode('per_client', 1));
        $this->assertTrue($cfg->setClientPolicy($this->h->clientA, 'approve', 1));
        [$code] = $this->launch($this->tech, $this->devA);
        $r = $this->redeemCode($code);
        $this->assertSame(403, $r->status);
        $this->assertSame('{"ok":false,"error":"approval_required","message":"This client requires approval before a remote session can start."}', $r->body);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertSame(0, (int) $this->h->module->sql()->val('SELECT COUNT(*) FROM rmm_console_sessions'), 'no session, no cookie');
        // a guess gets the plain 400: nothing leaks to someone without a code
        $this->assertSame('{"ok":false,"error":"invalid_or_expired"}', $this->redeemCode(ConsoleLink::newCode())->body);
        // the code is spent
        $this->assertSame(400, $this->redeemCode($code)->status);
        // another client (policy allow) is unaffected
        $this->assertSame(200, $this->redeemCode($this->launch($this->tech, $this->devB)[0])->status);
    }

    // ------------------------------------------------------------------ 405 415 426 429

    public function testMethodAndContentTypeAndTransport(): void
    {
        $get = $this->api->handle($this->req('GET', 'redeem', null, ['content-type' => 'application/json']));
        $this->assertSame([405, 'POST'], [$get->status, $get->headers['Allow']]);
        $this->assertSame('{"ok":false,"error":"method_not_allowed"}', $get->body);
        foreach (['PUT', 'DELETE', 'OPTIONS', 'HEAD'] as $m) {
            $this->assertSame(405, $this->api->handle($this->req($m, 'redeem', null))->status, $m);
        }
        $form = $this->api->handle($this->req('POST', 'redeem', 'code=x', ['content-type' => 'application/x-www-form-urlencoded']));
        $this->assertSame(415, $form->status);
        $this->assertSame(415, $this->api->handle($this->req('POST', 'redeem', '{}', []))->status, 'a missing content type');
        $this->assertSame(415, $this->api->handle($this->req('POST', 'redeem', '{}', ['content-type' => 'text/plain']))->status);
        $this->assertSame(200, $this->api->handle($this->req('POST', 'redeem', (string) json_encode(['code' => $this->launch($this->tech, $this->devA)[0]]), ['content-type' => 'Application/JSON; charset=utf-8']))->status);
        $strict = $this->h->module->consoleApi(static fn (): bool => true, false);
        $plain = $strict->handle($this->req('POST', 'redeem', '{}', ['content-type' => 'application/json'], '203.0.113.50', false));
        $this->assertSame([403, '{"ok":false,"error":"tls_required"}'], [$plain->status, $plain->body], 'not over TLS');
        $this->assertSame(401, $strict->handle($this->req('POST', 'events', '{}', ['content-type' => 'application/json'], '203.0.113.50', true))->status);
        $this->assertSame(404, $this->api->handle($this->req('POST', 'nope', '{}', ['content-type' => 'application/json']))->status);
    }

    public function testEveryResponseIsNoStoreAndCarriesNoCorsHeader(): void
    {
        [$code] = $this->launch($this->tech, $this->devA);
        $all = [$this->redeemCode($code), $this->redeemCode($code), $this->redeemCode('x'), $this->api->handle($this->req('GET', 'redeem', null)),
            $this->api->handle($this->req('POST', 'redeem', '{}', [])), $this->api->handle($this->req('POST', 'events', '{}', ['content-type' => 'application/json'])),
            $this->api->handle($this->req('GET', 'events', null)), $this->api->handle($this->req('POST', 'zzz', '{}', ['content-type' => 'application/json']))];
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->admin, ['min_console_version' => '9.0.0'])->ok);
        $all[] = $this->redeemCode($code);
        $this->limitOverride['console_redeem_ip:' . substr(\RivetCore\Rmm\Remote\ConsoleStore::ipHash('203.0.113.77'), 0, 32)] = 1;
        $this->redeemCode('x', '203.0.113.77');
        $all[] = $this->redeemCode('x', '203.0.113.77');
        $this->assertSame(429, end($all)->status);
        foreach ($all as $i => $r) {
            $this->assertSame('no-store', $r->headers['Cache-Control'] ?? null, "response $i ({$r->status})");
            $this->assertSame([], array_filter(array_keys($r->headers), static fn (string $h): bool => stripos($h, 'access-control') === 0), "response $i");
        }
    }

    public function testClientTooOldIs426OnlyWhenAMinimumIsSetAndSaysNothingAboutTheCode(): void
    {
        [$code] = $this->launch($this->tech, $this->devA);
        $ra = $this->h->module->remoteActions();
        $this->assertSame(200, $this->redeemCode($this->launch($this->tech, $this->devA)[0], '203.0.113.51', ['app' => 'x', 'version' => '0.0.1'])->status, 'not enforced while empty');
        $this->assertTrue($ra->updateSettings($this->admin, ['min_console_version' => '0.2.0'])->ok);
        foreach ([['version' => '0.1.9'], ['version' => '0.1.0'], ['version' => 'abc'], ['app' => 'x'], []] as $cl) {
            $good = $this->redeemCode($code, '203.0.113.52', $cl);
            $bad = $this->redeemCode(ConsoleLink::newCode(), '203.0.113.52', $cl);
            $this->assertSame(426, $good->status, json_encode($cl));
            $this->assertSame('{"ok":false,"error":"client_too_old","min_version":"0.2.0"}', $good->body);
            $this->assertSame($good->body, $bad->body, 'identical for a valid and an unknown code');
        }
        $this->assertSame(426, $this->api->handle($this->req('POST', 'redeem', (string) json_encode(['code' => $code]), ['content-type' => 'application/json']))->status, 'no client block at all');
        // the 426 did not burn the code
        foreach (['0.2.0', '0.2.1', '1.0.0', '0.10.0', '0.2.0-rc1'] as $v) {
            [$c] = $this->launch($this->tech, $this->devA);
            $this->assertSame(200, $this->redeemCode($c, '203.0.113.53', ['version' => $v])->status, $v);
        }
        $this->assertSame(200, $this->redeemCode($code, '203.0.113.54', ['version' => '0.2.0'])->status);
    }

    public function testPerIpLimitAppliesToEveryRequestAndPerTechnicianLimitToValidCodes(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->assertSame(400, $this->redeemCode(ConsoleLink::newCode(), '203.0.113.60')->status, "request $i");
        }
        $r = $this->redeemCode(ConsoleLink::newCode(), '203.0.113.60');
        $this->assertSame(429, $r->status);
        $this->assertSame('{"ok":false,"error":"rate_limited"}', $r->body);
        $this->assertSame('60', $r->headers['Retry-After']);
        [$code] = $this->launch($this->tech, $this->devA);
        $this->assertSame(429, $this->redeemCode($code, '203.0.113.60')->status, 'the limit is per address, even for a valid code');
        $this->assertSame(200, $this->redeemCode($code, '203.0.113.61')->status, 'another address is not affected, and the 429 did not burn the code');
        // ten per technician and minute, from different addresses
        $codes = [];
        for ($i = 0; $i < 11; ++$i) {
            $codes[] = $this->launch($this->techB, $this->devA)[0];
        }
        $statuses = [];
        foreach ($codes as $i => $c) {
            $statuses[] = $this->redeemCode($c, '198.51.100.' . (100 + $i))->status;
        }
        $this->assertSame(array_merge(array_fill(0, 10, 200), [429]), $statuses);
        $this->assertSame(200, $this->redeemCode($this->launch($this->tech, $this->devA)[0], '198.51.100.200')->status, 'another technician has their own budget');
    }

    public function testTheTechnicianLimitDoesNotBurnTheCode(): void
    {
        $key = 'console_redeem_user:19';
        $this->limitOverride[$key] = 1;
        [$a] = $this->launch($this->techB, $this->devA);
        [$b] = $this->launch($this->techB, $this->devA);
        $this->assertSame(200, $this->redeemCode($a)->status);
        $this->assertSame(429, $this->redeemCode($b)->status);
        $this->limitOverride[$key] = 0;
        $this->assertSame(200, $this->redeemCode($b)->status, 'still redeemable once the budget is back');
    }

    // ------------------------------------------------------------------ concurrency

    public function testParallelRedeemsOfOneCodeExactlyOneSucceeds(): void
    {
        [$code] = $this->launch($this->tech, $this->devA);
        $hash = ConsoleLink::hashCode($code);
        $racers = 8;
        $startAt = microtime(true) + 1.5;
        $procs = [];
        $pipes = [];
        for ($i = 0; $i < $racers; ++$i) {
            $procs[$i] = proc_open([PHP_BINARY, __DIR__ . '/../../Support/console_consume_worker.php', $hash, sprintf('%.6F', $startAt)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            $this->assertIsResource($procs[$i]);
        }
        $out = [];
        foreach ($procs as $i => $p) {
            $out[] = trim((string) stream_get_contents($pipes[$i][1]));
            $err = trim((string) stream_get_contents($pipes[$i][2]));
            $this->assertSame('', $err);
            proc_close($p);
        }
        sort($out);
        $this->assertSame(array_merge(array_fill(0, $racers - 1, '0'), ['1']), $out, 'exactly one racer consumed the code');
        $this->assertSame(400, $this->redeemCode($code)->status, 'and it is gone for everyone else');
    }

    public function testTheConsumeStepIsOneConditionalUpdateAndCannotRevive(): void
    {
        [$code] = $this->launch($this->tech, $this->devA);
        $store = $this->h->module->consoleStore();
        $h = ConsoleLink::hashCode($code);
        $this->assertTrue($store->consume($h));
        $this->assertFalse($store->consume($h));
        [$late] = $this->launch($this->tech, $this->devA);
        $this->advance(61);
        $this->assertFalse($store->consume(ConsoleLink::hashCode($late)), 'an expired code cannot be consumed');
    }

    // ------------------------------------------------------------------ the gate at redeem time

    public function testALauncherOnlyTechnicianCannotRedeemATerminalCode(): void
    {
        $r = $this->h->module->technician()->launchRemote($this->launcher, $this->devA, false, null, null, 'terminal', 'standard', true);
        $this->assertSame(403, $r->http, 'launch refuses it: terminal needs remote.manage');
        $this->assertSame(0, (int) $this->h->module->sql()->val('SELECT COUNT(*) FROM rmm_console_codes'));
        $this->assertSame(200, $this->redeemCode($this->launch($this->launcher, $this->devA)[0])->status, 'desktop is fine with remote.launch');
    }

    public function testACodeBoundToATerminalCannotBeTurnedIntoAnotherKind(): void
    {
        [$code] = $this->launch($this->tech, $this->devA, 'terminal');
        $b = $this->json($this->redeem(['code' => $code, 'kind' => 'desktop', 'node' => 'node//' . str_repeat('Z', 24), 'user' => 1, 'device_id' => $this->devB]));
        $this->assertSame(['terminal', 'node//' . str_repeat('B', 24) . $this->devA, 'rv-18'], [$b['kind'], $b['node'], $b['technician']['username']], 'only what Core stored is returned, never what the caller claims');
    }

    public function testBackgroundIsNeverIssuedAndABackgroundRowIsRefusedAtRedeem(): void
    {
        $b = $this->session();
        $this->assertNull($b['bg_authorization']);
        [$code] = $this->launch($this->tech, $this->devA);
        $this->h->module->sql()->run("UPDATE rmm_console_codes SET mode = 'background' WHERE code_hash = ?", [ConsoleLink::hashCode($code)]);
        $this->assertSame('{"ok":false,"error":"invalid_or_expired"}', $this->redeemCode($code)->body);
        [$code2] = $this->launch($this->tech, $this->devA);
        $this->h->module->sql()->run("UPDATE rmm_console_codes SET kind = 'chat' WHERE code_hash = ?", [ConsoleLink::hashCode($code2)]);
        $this->assertSame(400, $this->redeemCode($code2)->status);
    }

    public function testDefaultDenyWhenNothingIsConfigured(): void
    {
        [$code] = $this->launch($this->tech, $this->devA);
        // the module itself off
        $this->h->module->settings()->update(['enabled' => 0]);
        $this->h->module->authorizer()->forget();
        $this->assertSame(400, $this->redeemCode($code)->status);
        // an unknown technician id in a row denies
        $this->h->module->settings()->update(['enabled' => 1]);
        [$c2] = $this->launch($this->tech, $this->devA);
        $this->h->module->sql()->run('UPDATE rmm_console_codes SET user_id = 999 WHERE code_hash = ?', [ConsoleLink::hashCode($c2)]);
        $this->assertSame(400, $this->redeemCode($c2)->status);
    }

    // ------------------------------------------------------------------ tenant isolation (focused: TenantIsolationMatrixTest is not on this branch)

    public function testACodeOnlyEverYieldsItsOwnTechnicianAndDevice(): void
    {
        [$a, $sa] = $this->launch($this->tech, $this->devA);
        [$b, $sb] = $this->launch($this->techB, $this->devB);
        $rb = $this->json($this->redeemCode($b));
        $ra = $this->json($this->redeemCode($a));
        $this->assertSame(['rv-18', 'node//' . str_repeat('B', 24) . $this->devA, $sa], [$ra['technician']['username'], $ra['node'], $ra['session_id']]);
        $this->assertSame(['rv-19', 'node//' . str_repeat('B', 24) . $this->devB, $sb], [$rb['technician']['username'], $rb['node'], $rb['session_id']]);
        $this->assertSame('user//rv-18', MeshCookie::decode($ra['mesh']['cookie'], $this->loginKey)['u']);
        $this->assertSame('user//rv-19', MeshCookie::decode($rb['mesh']['cookie'], $this->loginKey)['u']);
        $s = $this->h->module->sql();
        $this->assertSame([18, $this->devA, $this->h->clientA], array_map('intval', array_values((array) $s->one('SELECT user_id, device_id, client_id FROM rmm_console_sessions WHERE session_id = ?', [$sa]))));
        $this->assertSame([19, $this->devB, $this->h->clientB], array_map('intval', array_values((array) $s->one('SELECT user_id, device_id, client_id FROM rmm_console_sessions WHERE session_id = ?', [$sb]))));
    }

    public function testATechnicianRestrictedToClientACanNeverMintForClientB(): void
    {
        $this->h->tenancy->restrictUser(18, [$this->h->clientA]);
        $this->h->module->authorizer()->forget();
        $t = $this->h->module->technician();
        $r = $t->launchRemote($this->tech, $this->devB, false, null, null, 'desktop', 'standard', true);
        $this->assertSame([false, 404], [$r->ok, $r->http], 'the same 404 as a missing device');
        $missing = $t->launchRemote($this->tech, 999999, false, null, null, 'desktop', 'standard', true);
        $this->assertSame([$missing->http, $missing->code, $missing->message], [$r->http, $r->code, $r->message], 'no existence oracle');
        foreach (['terminal', 'files'] as $view) {
            $this->assertSame(404, $t->launchRemote($this->tech, $this->devB, false, null, null, $view, 'standard', true)->http);
        }
        $this->assertSame(0, (int) $this->h->module->sql()->val('SELECT COUNT(*) FROM rmm_console_codes'));
        $this->assertSame([404], array_unique([$this->h->tech('POST', [(string) $this->devB, 'remote'], $this->tech, ['console' => true])[0]]));
        $this->assertTrue($t->launchRemote($this->tech, $this->devA, false, null, null, 'desktop', 'standard', true)->ok, 'client A still works');
    }

    public function testACodeMintedBeforeAScopeChangeDoesNotSurviveIt(): void
    {
        [$code] = $this->launch($this->tech, $this->devA);
        $this->h->tenancy->restrictUser(18, [$this->h->clientB]);
        $this->h->module->authorizer()->forget();
        $this->assertSame(400, $this->redeemCode($code)->status);
    }

    // ------------------------------------------------------------------ no secret anywhere

    public function testNoLogAuditRowEventOrTableHoldsTheCodeItsHashTheCookieOrTheToken(): void
    {
        $secrets = [];
        $bodies = [];
        for ($i = 0; $i < 3; ++$i) {
            [$code] = $this->launch($this->tech, $this->devA);
            $r = $this->redeemCode($code);
            $b = $this->json($r);
            $secrets = array_merge($secrets, [$code, ConsoleLink::hashCode($code), $b['mesh']['cookie'], $b['report']['token'], hash('sha256', $b['report']['token'])]);
            $this->assertSame(200, $this->report($b['report']['token'], $b['session_id'], [['type' => 'session_start', 'ts' => '2026-11-12T10:00:00Z', 'result' => 'ok']])->status);
            $this->assertSame(200, $this->report($b['report']['token'], $b['session_id'], [['type' => 'session_end', 'ts' => '2026-11-12T10:05:00Z', 'result' => 'ok']])->status);
            $bodies[] = (string) $r->body;
        }
        // failures too: replay, unknown, rate limited, a bad token, a throwing dependency
        $distinct = 'DISTINCTIVECODE' . str_repeat('x', 28);
        $this->assertSame(43, strlen($distinct));
        $this->assertSame(400, $this->redeemCode($distinct)->status);
        $this->assertSame(400, $this->redeemCode($secrets[0])->status);
        $this->assertSame(401, $this->report('Z' . substr($secrets[3], 1), 'abcdef0123456789', [])->status);
        $this->assertSame(401, $this->report($secrets[3], 'abcdef0123456789', [], '203.0.113.50', null, 'Bearer ' . $distinct)->status);
        $secrets[] = $distinct;
        $throwing = $this->h->module->consoleApi(static function () use ($distinct): bool {
            throw new \RuntimeException('boom ' . $distinct);
        });
        $r = $throwing->redeem($this->req('POST', 'redeem', (string) json_encode(['code' => $distinct]), ['content-type' => 'application/json']));
        $this->assertSame([500, '{"ok":false,"error":"internal"}'], [$r->status, $r->body]);
        $written = $this->everythingWritten();
        foreach ($secrets as $s) {
            $this->assertStringNotContainsString($s, $written, 'a secret reached a log, audit row or event');
        }
        $this->assertStringContainsString('rivet console: RuntimeException', (string) file_get_contents($this->errorLog), 'the failure is logged as a class name only');
        // the tables: no cookie, no code, no plaintext token; hashes only in the two hash columns
        foreach (['rmm_console_codes', 'rmm_console_sessions', 'rmm_console_settings'] as $t) {
            $dump = json_encode($this->h->module->sql()->all("SELECT * FROM `$t`"));
            foreach ($secrets as $s) {
                if (preg_match('/^[0-9a-f]{64}$/', $s) === 1) {
                    continue;   // the hashes are meant to be there
                }
                $this->assertStringNotContainsString($s, (string) $dump, "$t holds a secret");
            }
        }
        $this->assertSame(['code_hash', 'token_hash'], array_values(array_filter(
            array_merge($this->columns('rmm_console_codes'), $this->columns('rmm_console_sessions')),
            static fn (string $c): bool => str_contains($c, 'hash') && $c !== 'ip_hash',
        )));
        foreach (array_merge($this->columns('rmm_console_codes'), $this->columns('rmm_console_sessions')) as $c) {
            $this->assertDoesNotMatchRegularExpression('/cookie|secret|password|login|url/', $c);
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map(static fn (array $r): string => (string) $r['COLUMN_NAME'], $this->h->module->sql()->all('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]));
    }

    public function testRedeemAuditRowsAndEventsAreCompleteAndSecretFree(): void
    {
        [$code, $sid] = $this->launch($this->tech, $this->devA);
        $b = $this->json($this->redeemCode($code, '203.0.113.99'));
        $rows = $this->auditOf('Console Link Redeemed');
        $this->assertCount(1, $rows);
        $this->assertSame("user 18 (Tess) redeemed a Rivet Console link (desktop) for session $sid on device {$this->devA} from 203.0.113.99 (client reported: rivet-console 0.1.0 win32)", $rows[0]['description']);
        $this->assertSame([$this->h->clientA], [$rows[0]['client_id']]);
        $ev = $this->bus->of(RmmEvent::CONSOLE_LINK_REDEEMED);
        $this->assertCount(1, $ev);
        $this->assertSame([$sid, 18, 'Tess', 'desktop', $this->devA, $this->h->clientA], [$ev[0]['session_id'], $ev[0]['user_id'], $ev[0]['user_name'], $ev[0]['kind'], $ev[0]['device_id'], $ev[0]['client_id']]);
        $this->assertSame(['device_id', 'asset_id', 'client_id', 'hostname', 'occurred_at', 'session_id', 'user_id', 'user_name', 'kind'], array_keys($ev[0]));
        $blob = json_encode([$rows, $ev]);
        foreach ([$code, ConsoleLink::hashCode($code), $b['mesh']['cookie'], $b['report']['token']] as $s) {
            $this->assertStringNotContainsString($s, (string) $blob);
        }
        // the technician name comes from Core's own record, never from the request
        $this->redeem(['code' => $this->launch($this->tech, $this->devA)[0], 'technician' => ['username' => 'root'], 'user' => 'root']);
        $this->assertStringNotContainsString('root', (string) json_encode($this->auditOf('Console Link Redeemed')));
    }

    public function testDeniedRowsAreAggregatedPerAddressAndNameNoTechnicianForAnUnknownCode(): void
    {
        for ($i = 0; $i < 15; ++$i) {
            $this->redeemCode(ConsoleLink::newCode(), '203.0.113.70');
        }
        $rows = $this->auditOf('Console Link Denied');
        $this->assertCount(ConsoleRedeemService::DENIED_LOG_PER_MIN, $rows, 'the flood is capped per address');
        $this->assertStringNotContainsString('user ', $rows[0]['description']);
        $this->assertStringContainsString('203.0.113.70', $rows[0]['description']);
        $this->redeemCode(ConsoleLink::newCode(), '203.0.113.71');
        $this->assertCount(ConsoleRedeemService::DENIED_LOG_PER_MIN + 1, $this->auditOf('Console Link Denied'), 'another address has its own allowance');
        // after a valid code the refusal names the technician and the device
        [$code] = $this->launch($this->tech, $this->devA);
        $this->policy->deactivate(18);
        $this->h->module->authorizer()->forget();
        $this->redeemCode($code, '203.0.113.72');
        $rows = $this->auditOf('Console Link Denied');
        $last = end($rows);
        $this->assertStringContainsString("user 18 (Tess) on device {$this->devA} (desktop): forbidden", $last['description']);
        $this->assertCount(1, array_filter($this->bus->of(RmmEvent::CONSOLE_LINK_DENIED), static fn (array $e): bool => $e['user_id'] === 18));
        $unknown = array_values(array_filter($this->bus->of(RmmEvent::CONSOLE_LINK_DENIED), static fn (array $e): bool => $e['user_id'] === null));
        $this->assertNotSame([], $unknown);
        $this->assertSame([null, null, 0], [$unknown[0]['user_id'], $unknown[0]['kind'], $unknown[0]['device_id']]);
    }

    public function testTheAuditCannotBeDisabledByAnySetting(): void
    {
        $ra = $this->h->module->remoteActions();
        foreach (['console_audit' => false, 'audit' => false, 'console_log' => false, 'console_audit_enabled' => false] as $k => $v) {
            $r = $ra->updateSettings($this->admin, [$k => $v]);
            $this->assertSame([false, 422], [$r->ok, $r->http], "$k is not a setting");
        }
        foreach ([[false, false, ''], [true, true, '9.9.9'], [false, true, '']] as [$once, $paths, $min]) {
            $this->assertTrue($ra->updateSettings($this->admin, ['console_cookie_once' => $once, 'console_log_file_paths' => $paths, 'min_console_version' => $min])->ok);
            $before = count($this->auditOf('Console Link Redeemed')) + count($this->auditOf('Console Link Denied'));
            [$code] = $this->launch($this->tech, $this->devA);
            $this->redeemCode($code, '203.0.113.' . random_int(100, 200), ['version' => '99.0.0']);
            $this->redeemCode(ConsoleLink::newCode(), '203.0.113.' . random_int(100, 200), ['version' => '99.0.0']);
            $this->assertSame($before + 2, count($this->auditOf('Console Link Redeemed')) + count($this->auditOf('Console Link Denied')));
        }
        $this->assertCount(3, $this->auditOf('Remote Session'));
    }

    public function testAnAuditFailureMeansNoCookieLeaves(): void
    {
        [$code] = $this->launch($this->tech, $this->devA);
        $audit = new class () extends \RivetCore\Testing\InMemoryRmmAudit {
            public function record(string $action, string $description, int $clientId, int $entityId): void
            {
                throw new \RuntimeException('audit down');
            }
        };
        $mod = new \RivetCore\Rmm\RmmModule($this->h->db, $this->h->clock, $this->h->tenancy, $this->h->assets, $this->h->bridge, $this->h->box, $audit, $this->h->metrics, new \RivetCore\Testing\InMemoryRmmModuleState(true), ['allow_insecure_http' => true], null, $this->policy);
        $api = $mod->consoleApi(static fn (): bool => true);
        $r = $api->redeem($this->req('POST', 'redeem', (string) json_encode(['code' => $code]), ['content-type' => 'application/json']));
        $this->assertSame([500, '{"ok":false,"error":"internal"}'], [$r->status, $r->body]);
        $this->assertStringNotContainsString('cookie', (string) $r->body);
    }
}
