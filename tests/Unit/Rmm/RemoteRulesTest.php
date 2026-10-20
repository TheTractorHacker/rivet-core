<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Job\JobTypeRegistry;
use RivetCore\Rmm\Logs\LogRuleService;
use RivetCore\Rmm\Logs\LogService;
use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Rmm\Remote\PathPolicy;
use RivetCore\Rmm\Remote\RemoteJobTypes;
use RivetCore\Rmm\Remote\RemoteProtocol;

/**
 * The strict allow-lists of the remote tools (Phase 6) and the log pipeline (Phase 8): hostile names, process ids, paths and rule fields. These are the same
 * kind of tests as the Phase 3 command-parameter security fixes: every value that could become an option, a path escape or a command fragment is refused.
 */
final class RemoteRulesTest extends TestCase
{
    /** @return iterable<string,array{mixed}> */
    public static function hostileServiceNames(): iterable
    {
        foreach (['-rf', '--now', '-', '.hidden', 'a b', "a\nb", "a\0b", 'x;reboot', '$(id)', '`id`', 'a|b', 'a&b', 'a>b', 'a"b', "a'b", '../etc/passwd', 'a/b', 'a\\b',
            '', ' ', 'caf' . "\xc3\xa9", 'ａｂｃ', str_repeat('a', 201), '*', 'a=b', '@x', '$x', ':x'] as $i => $v) {
            yield "string #$i " . json_encode($v) => [$v];
        }
        yield 'int' => [5];
        yield 'null' => [null];
        yield 'array' => [['a']];
        yield 'bool' => [true];
    }

    #[DataProvider('hostileServiceNames')]
    public function testHostileServiceNamesAreRefusedOnBothPlatforms(mixed $name): void
    {
        $this->assertNull(RemoteProtocol::serviceName($name, 'linux'));
        $this->assertNull(RemoteProtocol::serviceName($name, 'windows'));
        [, $err] = RemoteJobTypes::serviceControl(['name' => $name, 'action' => 'restart']);
        $this->assertNotNull($err);
    }

    public function testGoodServiceNamesAreNormalised(): void
    {
        $this->assertSame('ssh.service', RemoteProtocol::serviceName('ssh', 'linux'));
        $this->assertSame('ssh.service', RemoteProtocol::serviceName('ssh.service', 'linux'));
        $this->assertSame('getty@tty1.service', RemoteProtocol::serviceName('getty@tty1', 'linux'));
        $this->assertSame('systemd-resolved.service', RemoteProtocol::serviceName('systemd-resolved', 'linux'));
        $this->assertNull(RemoteProtocol::serviceName('cron.socket', 'linux'), 'only .service units are managed');
        $this->assertNull(RemoteProtocol::serviceName('.service', 'linux'));
        $this->assertSame('Spooler', RemoteProtocol::serviceName('Spooler', 'windows'));
        $this->assertSame('wuauserv', RemoteProtocol::serviceName('wuauserv', 'windows'));
        $this->assertSame('MSSQL$SQLEXPRESS', RemoteProtocol::serviceName('MSSQL$SQLEXPRESS', 'windows'));
    }

    public function testServiceControlParametersAreExactlyNameAndAction(): void
    {
        $this->assertSame([['name' => 'Spooler', 'action' => 'restart'], null], RemoteJobTypes::serviceControl(['action' => 'restart', 'name' => 'Spooler']));
        foreach ([['name' => 'a', 'action' => 'restart', 'extra' => 1], ['name' => 'a'], ['action' => 'stop'], ['name' => 'a', 'action' => 'RESTART'], ['name' => 'a', 'action' => 'kill'],
            ['name' => 'a', 'action' => ['stop']], ['name' => 'a', 'action' => 'stop; reboot'], []] as $bad) {
            $this->assertNotNull(RemoteJobTypes::serviceControl($bad)[1], json_encode($bad));
        }
        $this->assertNotNull(RemoteJobTypes::serviceList(['x' => 1])[1]);
        $this->assertNull(RemoteJobTypes::serviceList([])[1]);
    }

    /** @return iterable<string,array{mixed,bool}> */
    public static function pids(): iterable
    {
        foreach ([[2, true], [4242, true], [4194304, true], ['4242', true], [0, false], [1, false], [-1, false], [4194305, false], [99999999999, false], ['0', false], ['01', false],
            ['4242 ', false], [' 4242', false], ['1e3', false], [4.0, false], [true, false], [null, false], ['abc', false], ['-5', false], [[2], false], ["4\n2", false]] as $i => [$v, $ok]) {
            yield "#$i " . json_encode($v) => [$v, $ok];
        }
    }

    #[DataProvider('pids')]
    public function testProcessIds(mixed $pid, bool $ok): void
    {
        $this->assertSame($ok, RemoteProtocol::pid($pid) !== null);
    }

    public function testProcessKillParameters(): void
    {
        $this->assertSame([['pid' => 4242, 'name' => 'Code Helper (Renderer)', 'force' => false], null], RemoteJobTypes::processKill(['pid' => 4242, 'name' => 'Code Helper (Renderer)']));
        foreach ([['pid' => '4242', 'name' => 'x'], ['pid' => 4242], ['pid' => 4242, 'name' => ''], ['pid' => 4242, 'name' => ' x'], ['pid' => 4242, 'name' => 'x;y'], ['pid' => 4242, 'name' => "x\ny"],
            ['pid' => 4242, 'name' => 'x', 'force' => 'yes'], ['pid' => 4242, 'name' => 'x', 'signal' => 9], ['pid' => 1, 'name' => 'init'], ['pid' => 4242, 'name' => str_repeat('a', 129)],
            ['pid' => 4242, 'name' => '$(id)'], ['pid' => 4242, 'name' => '/bin/sh']] as $bad) {
            $this->assertNotNull(RemoteJobTypes::processKill($bad)[1], json_encode($bad));
        }
        $this->assertSame([['limit' => 200, 'sort' => 'cpu'], null], RemoteJobTypes::processList([]));
        foreach ([['limit' => 0], ['limit' => 501], ['limit' => '5'], ['sort' => 'name'], ['sort' => ['cpu']], ['x' => 1]] as $bad) {
            $this->assertNotNull(RemoteJobTypes::processList($bad)[1], json_encode($bad));
        }
    }

    public function testFileJobParametersAreExact(): void
    {
        $tid = '0b1c2d3e-0000-4000-8000-000000000001';
        $tok = str_repeat('a', 64);
        $get = ['transfer_id' => $tid, 'token' => $tok, 'path' => '/srv/data/a.txt', 'max_bytes' => 1000, 'allow' => '/srv/data'];
        [$norm, $err] = RemoteJobTypes::fileGet($get);
        $this->assertNull($err);
        $this->assertSame(RemoteProtocol::TRANSFER_CHUNK_MAX, $norm['chunk']);
        foreach ([['transfer_id' => 'x'], ['token' => 'short'], ['path' => ''], ['path' => "a\0b"], ['max_bytes' => 0], ['max_bytes' => RemoteProtocol::TRANSFER_HARD_MAX_BYTES + 1], ['max_bytes' => '5'],
            ['allow' => 5], ['chunk' => 10], ['extra' => 1]] as $mut) {
            $this->assertNotNull(RemoteJobTypes::fileGet($mut + $get)[1], json_encode($mut));
        }
        $put = ['transfer_id' => $tid, 'token' => $tok, 'path' => '/srv/data/a.txt', 'size' => 10, 'sha256' => str_repeat('b', 64), 'overwrite' => false, 'allow' => '/srv/data'];
        [$norm, $err] = RemoteJobTypes::filePut($put);
        $this->assertNull($err);
        $this->assertSame('0640', $norm['mode']);
        foreach ([['sha256' => str_repeat('B', 64)], ['sha256' => 'abc'], ['size' => -1], ['size' => RemoteProtocol::TRANSFER_HARD_MAX_BYTES + 1], ['overwrite' => 'true'], ['mode' => '0999'], ['mode' => '06400'],
            ['mode' => 'rwx'], ['unknown' => true]] as $mut) {
            $this->assertNotNull(RemoteJobTypes::filePut($mut + $put)[1], json_encode($mut));
        }
    }

    public function testTheRegistryCarriesTheCapabilityFlagsAndAbilities(): void
    {
        $r = JobTypeRegistry::withDefaults();
        $this->assertSame(RemoteProtocol::CAP_SERVICES, $r->get('svc_list')?->capability);
        $this->assertSame(RemoteProtocol::CAP_SERVICES, $r->get('svc_control')?->capability);
        $this->assertSame(RemoteProtocol::CAP_PROCESSES, $r->get('proc_list')?->capability);
        $this->assertSame(RemoteProtocol::CAP_PROCESSES, $r->get('proc_kill')?->capability);
        $this->assertSame(RemoteProtocol::CAP_FILES, $r->get('file_get')?->capability);
        $this->assertSame(RemoteProtocol::CAP_FILES, $r->get('file_put')?->capability);
        $this->assertTrue($r->get('proc_kill')?->destructive);
        $this->assertTrue($r->get('file_put')?->destructive);
        $this->assertFalse($r->get('svc_list')?->destructive);
        $this->assertSame('rmm.remote.manage', $r->get('svc_control')?->ability);
        $this->assertSame('rmm.admin', $r->get('file_put')?->ability, 'sending a file to a device is administrator-only');
        foreach (RemoteProtocol::JOB_TYPES as $t) {
            $this->assertLessThanOrEqual(20, strlen($t));
            $this->assertNull($r->get('powershell')?->capability);
            $this->assertNotNull(RemoteProtocol::capabilityFor($t));
        }
        $this->assertNull(RemoteProtocol::capabilityFor('reboot'));
    }

    // ------------------------------------------------------------------ path policy

    /** @return iterable<string,array{string,string,list<string>,?string}> */
    public static function paths(): iterable
    {
        $lin = ['/srv/data', '/var/log'];
        $win = ['C:\\Data', 'D:\\Shares\\Public'];
        yield 'ok linux' => ['/srv/data/report.csv', 'linux', $lin, null];
        yield 'ok linux deep' => ['/srv/data/a/b/c.txt', 'linux', $lin, null];
        yield 'ok log' => ['/var/log/syslog', 'linux', $lin, null];
        yield 'relative' => ['srv/data/a', 'linux', $lin, PathPolicy::NOT_ABSOLUTE];
        yield 'dotdot' => ['/srv/data/../../etc/hostname', 'linux', $lin, PathPolicy::TRAVERSAL];
        yield 'dotdot end' => ['/srv/data/..', 'linux', $lin, PathPolicy::TRAVERSAL];
        yield 'dot' => ['/srv/data/./a', 'linux', $lin, PathPolicy::TRAVERSAL];
        yield 'double slash' => ['/srv//data/a', 'linux', $lin, PathPolicy::TRAVERSAL];
        yield 'sibling prefix' => ['/srv/data2/a', 'linux', $lin, PathPolicy::NOT_ALLOWED];
        yield 'root itself ok' => ['/srv/data', 'linux', $lin, null];
        yield 'outside' => ['/etc/hostname', 'linux', $lin, PathPolicy::NOT_ALLOWED];
        yield 'shadow outside' => ['/etc/shadow', 'linux', $lin, PathPolicy::NOT_ALLOWED];
        yield 'nul' => ["/srv/data/a\0.txt", 'linux', $lin, PathPolicy::INVALID];
        yield 'newline' => ["/srv/data/a\nb", 'linux', $lin, PathPolicy::INVALID];
        yield 'empty' => ['', 'linux', $lin, PathPolicy::INVALID];
        yield 'too long' => ['/srv/data/' . str_repeat('a', 1000), 'linux', $lin, PathPolicy::INVALID];
        yield 'no roots' => ['/srv/data/a', 'linux', [], PathPolicy::NO_ALLOWED_ROOTS];
        yield 'private key in root' => ['/srv/data/id_rsa', 'linux', $lin, PathPolicy::DENY_LIST];
        yield 'pem in root' => ['/srv/data/site.PEM', 'linux', $lin, PathPolicy::DENY_LIST];
        yield 'ssh dir in root' => ['/srv/data/.ssh/authorized_keys', 'linux', $lin, PathPolicy::DENY_LIST];
        yield 'kdbx' => ['/srv/data/vault.kdbx', 'linux', $lin, PathPolicy::DENY_LIST];
        yield 'agent state' => ['/var/lib/rivetit-agent/state.json', 'linux', ['/var/lib/rivetit-agent'], PathPolicy::DENY_LIST];
        yield 'shadow in an allowed etc' => ['/etc/shadow', 'linux', ['/etc/nginx'], PathPolicy::NOT_ALLOWED];
        yield 'ok windows' => ['C:\\Data\\report.csv', 'windows', $win, null];
        yield 'windows forward slash is refused, the agent would have to rewrite it' => ['C:/Data/report.csv', 'windows', $win, PathPolicy::INVALID];
        yield 'windows case' => ['c:\\data\\Report.csv', 'windows', $win, null];
        yield 'windows other drive' => ['E:\\Data\\a', 'windows', $win, PathPolicy::NOT_ALLOWED];
        yield 'windows unc' => ['\\\\server\\share\\a', 'windows', $win, PathPolicy::NOT_ABSOLUTE];
        yield 'windows device' => ['\\\\.\\C:\\Data\\a', 'windows', $win, PathPolicy::NOT_ABSOLUTE];
        yield 'windows traversal' => ['C:\\Data\\..\\Windows\\win.ini', 'windows', $win, PathPolicy::TRAVERSAL];
        yield 'windows ads' => ['C:\\Data\\a.txt:secret', 'windows', $win, PathPolicy::INVALID];
        yield 'windows short name' => ['C:\\Data\\PROGRA~1\\a', 'windows', $win, PathPolicy::INVALID];
        yield 'windows trailing dot' => ['C:\\Data\\a.', 'windows', $win, PathPolicy::INVALID];
        yield 'windows wildcard' => ['C:\\Data\\*.txt', 'windows', $win, PathPolicy::INVALID];
        yield 'windows sam' => ['C:\\Windows\\System32\\config\\SAM', 'windows', ['C:\\Windows'], PathPolicy::DENY_LIST];
        yield 'windows ntds' => ['C:\\Windows\\NTDS\\ntds.dit', 'windows', ['C:\\Windows'], PathPolicy::DENY_LIST];
        yield 'windows pfx' => ['C:\\Data\\site.pfx', 'windows', $win, PathPolicy::DENY_LIST];
        yield 'windows agent dir' => ['C:\\ProgramData\\RivetIT\\state.json', 'windows', ['C:\\ProgramData'], PathPolicy::DENY_LIST];
        yield 'windows pagefile' => ['C:\\Data\\pagefile.sys', 'windows', $win, PathPolicy::DENY_LIST];
    }

    /** @param list<string> $roots */
    #[DataProvider('paths')]
    public function testPathPolicy(string $path, string $os, array $roots, ?string $want): void
    {
        $this->assertSame($want, PathPolicy::check($path, $os, $roots));
    }

    public function testRootsMustBeDeepAndNotACredentialFolder(): void
    {
        foreach (['/srv/shared', '/var/log', '/home/alice', '/opt/app/data'] as $r) {
            $this->assertTrue(PathPolicy::validRoot($r, 'linux'), $r);
        }
        foreach (['/', '/etc', '/root', '/home', '/var', '/etc/ssh/ssh_host_rsa_key', '/proc/1', '/srv/../etc', 'srv/data', '', "/srv/a\0", '/srv//x', '/dev/shm', '/root/.ssh'] as $r) {
            $this->assertFalse(PathPolicy::validRoot($r, 'linux'), json_encode($r));
        }
        foreach (['C:\\Data', 'D:\\Shares\\Public', 'C:\\Users\\Public'] as $r) {
            $this->assertTrue(PathPolicy::validRoot($r, 'windows'), $r);
        }
        foreach (['C:\\', 'C:', '\\\\server\\share', 'C:\\Windows\\System32\\config', 'C:\\Data\\..\\Windows', 'C:\\Data:ads', 'Data'] as $r) {
            $this->assertFalse(PathPolicy::validRoot($r, 'windows'), $r);
        }
    }

    // ------------------------------------------------------------------ logs

    public function testLogEntriesAreCleanedBoundedAndDeduplicated(): void
    {
        $now = 1_800_000_000;
        $ok = ['ts' => gmdate('Y-m-d\TH:i:s\Z', $now - 60), 'level' => 'error', 'source' => 'sshd.service', 'event_id' => 4625, 'message' => "Failed password for root\nfrom 10.0.0.9 password=hunter2"];
        $c = LogService::cleanEvent($ok, $now, $now - 86400);
        $this->assertNotNull($c);
        $this->assertSame(2, $c['level']);
        $this->assertStringNotContainsString("\n", $c['message']);
        $this->assertStringNotContainsString('hunter2', $c['message'], 'credentials in log text are redacted before storage');
        $this->assertSame(32, strlen($c['hash']));
        $this->assertSame($c['hash'], LogService::cleanEvent($ok, $now, $now - 86400)['hash'] ?? null, 'the same entry always has the same key');
        $this->assertNotSame($c['hash'], LogService::cleanEvent(['message' => 'other'] + $ok, $now, $now - 86400)['hash'] ?? null);
        $long = LogService::cleanEvent(['message' => str_repeat('é', 5000)] + $ok, $now, $now - 86400);
        $this->assertLessThanOrEqual(RemoteProtocol::LOG_MESSAGE_MAX, mb_strlen($long['message'] ?? ''));
        $this->assertSame(1, LogService::cleanEvent(['level' => 'critical'] + $ok, $now, $now - 86400)['level'] ?? null);
        $this->assertSame(5, LogService::cleanEvent(['level' => 'verbose'] + $ok, $now, $now - 86400)['level'] ?? null);
        $this->assertSame($now + 900, LogService::cleanEvent(['ts' => gmdate('Y-m-d\TH:i:s\Z', $now + 99999)] + $ok, $now, $now - 86400)['ts'] ?? null, 'a clock in the future is clamped');
        foreach ([['ts' => 'yesterday'], ['ts' => gmdate('Y-m-d\TH:i:s\Z', $now - 10 * 86400)], ['level' => 'fatal'], ['level' => 3], ['message' => ['x']], ['ts' => null]] as $bad) {
            $this->assertNull(LogService::cleanEvent($bad + $ok, $now, $now - 86400), json_encode($bad));
        }
        $this->assertNull(LogService::cleanEvent([], $now, $now - 86400));
        $weird = LogService::cleanEvent(['source' => "ba\0d\x01name" . str_repeat('x', 300), 'event_id' => 'x'] + $ok, $now, $now - 86400);
        $this->assertNotNull($weird);
        $this->assertNull($weird['code']);
        $this->assertLessThanOrEqual(RemoteProtocol::LOG_SOURCE_MAX, mb_strlen($weird['source']));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f]/', $weird['source']);
        $badUtf = LogService::cleanEvent(['message' => "ok \xff\xfe bytes"] + $ok, $now, $now - 86400);
        $this->assertNotNull($badUtf);
        $this->assertTrue(mb_check_encoding($badUtf['message'], 'UTF-8'));
    }

    public function testLogRuleValidationAndMatching(): void
    {
        [$v, $err] = LogRuleService::validate(['name' => 'Failed logons', 'min_level' => 'error', 'contains' => 'failed password', 'threshold' => 5, 'window_min' => 10, 'severity' => 'fail'], null);
        $this->assertSame([], $err);
        $this->assertEqualsCanonicalizing(['name' => 'Failed logons', 'min_level' => 2, 'threshold' => 5, 'window_min' => 10, 'severity' => 'fail', 'client_id' => 0], array_intersect_key($v, array_flip(['name', 'min_level', 'threshold', 'window_min', 'severity', 'client_id'])));
        foreach ([['name' => ''], ['name' => 'x', 'min_level' => 'loud'], ['name' => 'x', 'threshold' => 0], ['name' => 'x', 'threshold' => 1001], ['name' => 'x', 'window_min' => 1441],
            ['name' => 'x', 'severity' => 'crit'], ['name' => 'x', 'regex' => '.*'], ['name' => 'x', 'contains' => str_repeat('a', 201)], ['name' => 'x', 'code' => 'abc'], ['name' => 'x', 'client_id' => -1],
            ['name' => "x\0"], ['name' => 'x', 'min_level' => 'info'], ['name' => 'x', 'enabled' => 'yes']] as $bad) {
            $this->assertNotSame([], LogRuleService::validate($bad, null)[1], json_encode($bad));
        }
        [, $err] = LogRuleService::validate(['name' => 'x', 'min_level' => 'info', 'contains' => 'panic'], null);
        $this->assertSame([], $err, 'an info rule is fine when it names a phrase');

        $rule = ['min_level' => 3, 'source_contains' => 'sshd', 'code' => null, 'contains' => 'FAILED password'];
        $e = ['level' => 3, 'source' => 'sshd.service', 'code' => null, 'message' => 'Failed password for root'];
        $this->assertTrue(LogRuleService::matches($rule, $e), 'case-insensitive substring');
        $this->assertTrue(LogRuleService::matches($rule, ['level' => 1] + $e), 'worse than the minimum matches');
        $this->assertFalse(LogRuleService::matches($rule, ['level' => 4] + $e));
        $this->assertFalse(LogRuleService::matches($rule, ['source' => 'cron'] + $e));
        $this->assertFalse(LogRuleService::matches($rule, ['message' => 'accepted'] + $e));
        $this->assertFalse(LogRuleService::matches(['code' => 4625] + $rule, $e));
        $this->assertTrue(LogRuleService::matches(['code' => 4625] + $rule, ['code' => 4625] + $e));
        $this->assertTrue(LogRuleService::matches(['contains' => '.*'] + $rule, ['message' => 'a.*b'] + $e), 'a pattern is a phrase, never a regular expression');
        $this->assertFalse(LogRuleService::matches(['contains' => '.*'] + $rule, ['message' => 'plain'] + $e));
    }

    public function testMeshViewModes(): void
    {
        $base = 'https://mesh.example';
        $this->assertStringEndsWith('&viewmode=11', MeshCookie::launchUrl($base, '', 'c', 'node//x'));
        $this->assertStringEndsWith('&viewmode=12', MeshCookie::launchUrl($base, '', 'c', 'node//x', 12));
        $this->assertStringEndsWith('&viewmode=13', MeshCookie::launchUrl($base, '', 'c', 'node//x', 13));
        $this->assertStringEndsWith('&viewmode=11', MeshCookie::launchUrl($base, '', 'c', 'node//x', 99), 'an unknown view falls back to the desktop');
        $this->assertSame(['desktop' => 11, 'terminal' => 12, 'files' => 13], \RivetCore\Rmm\Mesh\MeshService::VIEWMODES);
    }
}
