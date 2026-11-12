<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Rmm\Migration\ConsoleSchema;
use RivetCore\Rmm\Migration\Migration0051ConsoleSessions;
use RivetCore\Rmm\Remote\ConsoleRetention;
use RivetCore\Rmm\Remote\ConsoleSettings;
use RivetCore\Tests\Support\ConsoleTestCase;

/** The four console settings through RemoteActions::updateSettings, migration 0051, the retention of codes and sessions (and Housekeeping's wiring) and scripts/mint-test-cookie.php. */
final class ConsoleSettingsAndRetentionTest extends ConsoleTestCase
{
    private function ra(): \RivetCore\Rmm\Remote\RemoteActions
    {
        return $this->h->module->remoteActions();
    }

    /** @return array<string,mixed> */
    private function view(): array
    {
        return array_intersect_key($this->ra()->settings($this->admin)->data, array_flip(ConsoleSettings::KEYS));
    }

    public function testDefaultsAreUnconfiguredNoMinimumNoPathsAndCookieOnce(): void
    {
        $this->h->module->sql()->run('DELETE FROM rmm_console_settings');
        $this->h->module->consoleSettings()->refresh();
        $this->h->module->remoteActions()->settings($this->admin);
        $this->assertSame(['console_public_url' => '', 'min_console_version' => '', 'console_log_file_paths' => false, 'console_cookie_once' => true], $this->view());
    }

    public function testSettingsAreSavedReadBackAndAudited(): void
    {
        $r = $this->ra()->updateSettings($this->admin, ['console_public_url' => 'https://Console.Example.com:8443/', 'min_console_version' => '0.3.1', 'console_log_file_paths' => true, 'console_cookie_once' => false]);
        $this->assertTrue($r->ok, $r->message);
        $want = ['console_public_url' => 'https://console.example.com:8443', 'min_console_version' => '0.3.1', 'console_log_file_paths' => true, 'console_cookie_once' => false];
        $this->assertSame($want, $this->view());
        $this->assertSame($want, array_intersect_key($r->data, $want), 'the save answers with the read-back');
        $this->h->module->consoleSettings()->refresh();
        $this->assertSame($want, $this->view(), 'and it is in the database');
        $rows = $this->auditOf('Remote Settings Changed');
        $d = end($rows)['description'];
        foreach (['console_public_url set to https://console.example.com:8443', 'min_console_version set to 0.3.1', 'console_log_file_paths ON', 'console_cookie_once off'] as $needle) {
            $this->assertStringContainsString($needle, $d);
        }
        $this->assertTrue($this->ra()->updateSettings($this->admin, ['console_public_url' => '', 'min_console_version' => ''])->ok);
        $this->assertSame(['', '', true, false], array_values($this->view()), 'empty clears the URL and the minimum; the other two stay');
        $rows = $this->auditOf('Remote Settings Changed');
        $this->assertStringContainsString('console_public_url cleared, min_console_version cleared', end($rows)['description']);
    }

    public function testAPartialUpdateTouchesOnlyWhatItNames(): void
    {
        $this->assertTrue($this->ra()->updateSettings($this->admin, ['console_log_file_paths' => true])->ok);
        $this->assertSame([self::ORIGIN, true, true], [$this->view()['console_public_url'], $this->view()['console_log_file_paths'], $this->view()['console_cookie_once']]);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function invalid(): array
    {
        return ['path' => [['console_public_url' => 'https://rmm.example.com/x']], 'query' => [['console_public_url' => 'https://rmm.example.com/?a=1']],
            'userinfo' => [['console_public_url' => 'https://u:p@rmm.example.com']], 'fragment' => [['console_public_url' => 'https://rmm.example.com#a']], 'not a string' => [['console_public_url' => 5]],
            'host header style' => [['console_public_url' => 'rmm.example.com']], 'javascript' => [['console_public_url' => 'javascript:alert(1)']],
            'version words' => [['min_console_version' => 'latest']], 'version two parts' => [['min_console_version' => '1.2']], 'version four parts' => [['min_console_version' => '1.2.3.4']], 'version v prefix' => [['min_console_version' => 'v1.2.3']],
            'version leading zero' => [['min_console_version' => '01.2.3']], 'version not a string' => [['min_console_version' => 1.2]],
            'paths string' => [['console_log_file_paths' => 'yes']], 'paths int' => [['console_log_file_paths' => 1]], 'once string' => [['console_cookie_once' => 'false']], 'once null' => [['console_cookie_once' => null]]];
    }

    /** @param array<string,mixed> $in */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidValuesAreRefusedAndNothingIsWritten(array $in): void
    {
        $before = $this->view();
        $r = $this->ra()->updateSettings($this->admin, $in + ['features' => ['filetransfer' => false]]);
        $this->assertSame([false, 422], [$r->ok, $r->http]);
        $this->assertSame($before, $this->view());
    }

    public function testHttpIsRefusedUnlessTheEditionAllowsInsecureHttp(): void
    {
        $strict = new ConsoleSettings($this->h->module->sql(), false);
        [$vals, $err] = $strict->validate(['console_public_url' => 'http://rmm.example.com']);
        $this->assertSame([[], 1], [$vals, count($err)]);
        [$vals, $err] = $strict->validate(['console_public_url' => 'https://rmm.example.com']);
        $this->assertSame([['console_public_url' => 'https://rmm.example.com'], []], [$vals, $err]);
        $loose = new ConsoleSettings($this->h->module->sql(), true);
        $this->assertSame([], $loose->validate(['console_public_url' => 'http://127.0.0.1:9'])[1], 'loopback test servers only');
    }

    public function testOneBadValueMakesTheWholeRequestStoreNothing(): void
    {
        $before = $this->view();
        $r = $this->ra()->updateSettings($this->admin, ['console_log_file_paths' => true, 'console_public_url' => 'https://nope.example.com/path', 'features' => ['logs' => false]]);
        $this->assertFalse($r->ok);
        $this->assertSame($before, $this->view());
    }

    public function testOnlyAdministratorsAndTheDenialIsAudited(): void
    {
        $before = $this->view();
        $r = $this->ra()->updateSettings($this->tech, ['console_public_url' => 'https://evil.example.com']);
        $this->assertSame([false, 403], [$r->ok, $r->http]);
        $this->assertSame($before, $this->view());
        $this->assertCount(1, $this->auditOf('Remote Settings Denied'));
        $this->assertSame(403, $this->ra()->settings($this->tech)->http, 'and they cannot read them through the settings call either');
    }

    public function testChangingTheUrlTakesEffectOnTheNextLaunchAndUnsettingItRefusesLaunches(): void
    {
        $this->assertTrue($this->ra()->updateSettings($this->admin, ['console_public_url' => 'https://other.example.org'])->ok);
        $r = $this->h->module->technician()->launchRemote($this->tech, $this->devA, false, null, null, 'desktop', 'standard', true);
        $this->assertStringStartsWith('rivetconsole://connect?v=1&origin=https%3A%2F%2Fother.example.org&code=', $r->data['console']['url']);
        $this->assertTrue($this->ra()->updateSettings($this->admin, ['console_public_url' => ''])->ok);
        $this->assertSame(409, $this->h->module->technician()->launchRemote($this->tech, $this->devA, false, null, null, 'desktop', 'standard', true)->http);
        $this->assertSame(409, $this->h->module->technician()->launchRemote($this->tech, $this->devA, false, null, null, 'desktop', 'standard', true)->http);
    }

    public function testTheOriginInTheLinkIsNeverTheRequestHost(): void
    {
        [$code] = $this->launch($this->tech, $this->devA);
        $r = $this->api->handle($this->req('POST', 'redeem', (string) json_encode(['code' => $code]), ['content-type' => 'application/json', 'host' => 'evil.example.net', 'x-forwarded-host' => 'evil.example.net']));
        $this->assertSame(200, $r->status);
        $this->assertSame(self::ORIGIN . '/api/v1/endpoint_console/events', json_decode((string) $r->body, true)['report']['url']);
    }

    // ------------------------------------------------------------------ migration

    public function testMigration0051IsAdditiveAndIdempotent(): void
    {
        foreach (ConsoleSchema::tables() as $name => $ddl) {
            $this->assertStringStartsWith("CREATE TABLE IF NOT EXISTS `$name`", $ddl);
            $this->assertDoesNotMatchRegularExpression('/\b(ALTER|DROP|UPDATE|DELETE|TRUNCATE)\b/i', $ddl);
        }
        $this->assertSame(['rmm_console_settings', 'rmm_console_codes', 'rmm_console_sessions'], array_keys(ConsoleSchema::tables()));
        $m = new Migration0051ConsoleSessions();
        $this->assertSame('0051_rmm_console_sessions', $m->id());
        $this->h->module->sql()->run("UPDATE rmm_console_settings SET public_url = 'https://keep.example.com' WHERE id = 1");
        $m->up($this->h->db);
        $m->up($this->h->db);
        $this->assertSame('https://keep.example.com', $this->h->module->sql()->val('SELECT public_url FROM rmm_console_settings WHERE id = 1'), 'a second run keeps the stored value');
        $this->assertSame(1, (int) $this->h->module->sql()->val('SELECT COUNT(*) FROM rmm_console_settings'));
    }

    // ------------------------------------------------------------------ retention

    public function testRetentionPrunesOldCodesAndSessionsButNotRecentOrActiveOnesOrTheAudit(): void
    {
        $s = $this->h->module->sql();
        $old = $s->utcAt(-400 * 86400);
        $recent = $s->utcAt(-3600);
        foreach ([['old_used', $s->utcAt(-3 * 86400), $old], ['old_unused', $s->utcAt(-3 * 86400), null], ['recent', $s->utcAt(-30), null], ['fresh_valid', $s->utcAt(30), null]] as [$n, $exp, $used]) {
            $s->run("INSERT INTO rmm_console_codes (code_hash, user_id, device_id, node_id, kind, session_id, created_at, expires_at, used_at) VALUES (?, 18, ?, 'node//x', 'desktop', 'aaaaaaaaaaaaaaaa', ?, ?, ?)", [hash('sha256', $n), $this->devA, $exp, $exp, $used]);
        }
        $ins = static fn (string $id, ?string $ended, string $hard): array => [$id, hash('sha256', 't' . $id), $ended, $hard];
        foreach ([$ins('0000000000000001', $old, $old), $ins('0000000000000002', null, $old), $ins('0000000000000003', $recent, $s->utcAt(40000)), $ins('0000000000000004', null, $s->utcAt(40000)), $ins('0000000000000005', null, $recent)] as [$id, $th, $ended, $hard]) {
            $s->run("INSERT INTO rmm_console_sessions (session_id, token_hash, user_id, device_id, node_id, kind, started_at, ended_at, token_expires_at, hard_expires_at) VALUES (?, ?, 18, ?, 'node//x', 'desktop', ?, ?, ?, ?)", [$id, $th, $this->devA, $old, $ended, $hard, $hard]);
        }
        $auditBefore = count($this->h->audit->records());
        $out = (new ConsoleRetention($s))->prune(365);
        $this->assertSame(['codes' => 2, 'sessions' => 2], $out);
        $kept = $this->names($s->all('SELECT code_hash FROM rmm_console_codes ORDER BY code_hash'), ['fresh_valid', 'recent', 'old_used', 'old_unused']);
        sort($kept);
        $this->assertSame(['fresh_valid', 'recent'], $kept);
        $this->assertSame(['0000000000000003', '0000000000000004', '0000000000000005'], array_column($s->all('SELECT session_id FROM rmm_console_sessions ORDER BY session_id'), 'session_id'), 'ended long ago, and unended past the cap long ago, are gone; the rest stay');
        $this->assertSame($auditBefore, count($this->h->audit->records()), 'the audit log is not touched');
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $names @return list<string> */
    private function names(array $rows, array $names): array
    {
        $map = [];
        foreach ($names as $n) {
            $map[hash('sha256', $n)] = $n;
        }

        return array_map(static fn (array $r): string => $map[$r['code_hash']] ?? '?', $rows);
    }

    public function testHousekeepingRunsTheConsoleRetentionWithItsOwnLimit(): void
    {
        $s = $this->h->module->sql();
        $limits = $this->h->module->remoteSettings()->limits();
        $this->assertSame(365, $limits['console_session_retention_days'], 'default');
        $this->assertTrue($this->ra()->updateSettings($this->admin, ['limits' => ['console_session_retention_days' => 30]])->ok);
        $this->assertSame(30, $this->h->module->remoteSettings()->limits()['console_session_retention_days']);
        $this->assertFalse($this->ra()->updateSettings($this->admin, ['limits' => ['console_session_retention_days' => 29]])->ok, 'bounded');
        $s->run("INSERT INTO rmm_console_sessions (session_id, token_hash, user_id, device_id, node_id, kind, started_at, ended_at, token_expires_at, hard_expires_at) VALUES ('00000000000000aa', ?, 18, ?, 'node//x', 'desktop', ?, ?, ?, ?)",
            [hash('sha256', 'x'), $this->devA, $s->utcAt(-40 * 86400), $s->utcAt(-35 * 86400), $s->utcAt(-35 * 86400), $s->utcAt(-35 * 86400)]);
        $s->run("INSERT INTO rmm_console_codes (code_hash, user_id, device_id, node_id, kind, session_id, created_at, expires_at) VALUES (?, 18, ?, 'node//x', 'desktop', 'aaaaaaaaaaaaaaaa', ?, ?)", [hash('sha256', 'c'), $this->devA, $s->utcAt(-3 * 86400), $s->utcAt(-3 * 86400)]);
        $run = $this->h->module->housekeeping()->run();
        $this->assertSame([1, 1], [$run['pruned_console_sessions'], $run['pruned_console_codes']]);
        $this->assertSame(0, (int) $s->val('SELECT COUNT(*) FROM rmm_console_sessions WHERE session_id = ?', ['00000000000000aa']));
    }

    // ------------------------------------------------------------------ scripts/mint-test-cookie.php

    /**
     * @param list<string> $args
     * @return array{0:int,1:string,2:string}
     */
    private function script(array $args, ?string $key): array
    {
        $env = array_filter(getenv(), static fn ($v, $k): bool => $k !== 'MESH_LOGIN_KEY', ARRAY_FILTER_USE_BOTH);
        if ($key !== null) {
            $env['MESH_LOGIN_KEY'] = $key;
        }
        $p = proc_open([PHP_BINARY, __DIR__ . '/../../../scripts/mint-test-cookie.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($p), trim($out), $err];
    }

    public function testMintTestCookieReadsTheKeyFromTheEnvironmentOnly(): void
    {
        $key = MeshCookie::newLoginKey();
        [$code, $out, $err] = $this->script(['dom1', 'rv-7'], $key);
        $this->assertSame([0, ''], [$code, $err]);
        $c = MeshCookie::decode($out, $key);
        $this->assertSame(['u', 'a', 'time', 'once'], array_keys($c));
        $this->assertSame(['user/dom1/rv-7', 3], [$c['u'], $c['a']]);
        $this->assertEqualsWithDelta(time() - 120, $c['time'], 5);
        $this->assertStringNotContainsString($key, $out . $err);
        [, $out2] = $this->script(['-', 'rv-7'], $key);
        $this->assertSame('user//rv-7', MeshCookie::decode($out2, $key)['u'], '"-" is the default domain');
        [, $out3] = $this->script([], $key);
        $this->assertSame('user//rivetit-support', MeshCookie::decode($out3, $key)['u']);
        $this->assertNotSame($out, $this->script(['dom1', 'rv-7'], $key)[1], 'a fresh once claim each time');
    }

    public function testMintTestCookieRefusesAKeyOnTheCommandLineAndWorksWithoutOne(): void
    {
        $key = MeshCookie::newLoginKey();
        foreach ([[$key], ['dom1', $key], ['--key', $key], ['--key=' . $key]] as $args) {
            [$code, $out, $err] = $this->script($args, null);
            $this->assertSame([2, ''], [$code, $out]);
            $this->assertStringNotContainsString($key, $err, 'the key is not echoed back');
        }
        [$code, $out, $err] = $this->script(['dom1', 'rv-7'], null);
        $this->assertSame([2, ''], [$code, $out]);
        $this->assertStringContainsString('MESH_LOGIN_KEY', $err);
        [$code] = $this->script(['dom1', 'rv-7'], 'zz-not-hex');
        $this->assertSame(1, $code);
        [$code] = $this->script(['dom1', 'bad account!'], $key);
        $this->assertSame(2, $code);
    }
}
