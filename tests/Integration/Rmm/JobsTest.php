<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Crypto\Redactor;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Job\JobType;
use RivetCore\Rmm\Job\JobTypeRegistry;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Port of RivetIT tests/endpoint_agent_jobs.php: signing, lifecycle, lost acknowledgements, destructive rules, timeout, expiry,
 * cancel, device isolation, redaction, caps. Jobs are queued through JobService::create (the technician layer is not part of the
 * device module).
 */
final class JobsTest extends RmmTestCase
{
    private int $A = 0;
    private int $B = 0;
    private string $TA = '';
    private string $TB = '';
    private string $pub = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tok = $this->h->token(null, 24, 20);
        foreach (['A' => 'JOB-A', 'B' => 'JOB-B'] as $k => $name) {
            $this->h->asset(['name' => $name, 'serial' => "SER-$name"]);
            [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => "SER-$name", 'hostname' => $name]));
            $this->{$k} = (int) $j['device_id'];
            $this->{'T' . $k} = $j['device_token'];
            $this->pub = $j['signing_public_key'];
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array{ok:bool,error?:string,job_id?:string}
     */
    private function submit(int $dev, string $type, ?string $script = null, array $params = [], ?int $timeout = null, bool $destructive = false): array
    {
        $row = $this->h->module->devices()->find($dev);
        $this->assertNotNull($row);

        return $this->h->module->jobs()->create($row, $type, $script, $params, $timeout, $destructive, 1);
    }

    private function queue(int $dev, string $script = 'Get-Date'): string
    {
        $r = $this->submit($dev, 'powershell', $script);
        $this->assertTrue($r['ok']);

        return (string) $r['job_id'];
    }

    /** @return array{0:int,1:mixed} */
    private function fetch(string $t, array $query = []): array
    {
        [$c, , $j] = $this->h->call('GET', 'agent_jobs', null, $t, $query);

        return [$c, $j];
    }

    /** @param array<string,mixed> $b */
    private function report(string $t, array $b): int
    {
        return $this->h->call('POST', 'agent_jobs', $b, $t)[0];
    }

    private function state(string $id): ?string
    {
        $v = $this->h->one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$id'");

        return $v === null ? null : (string) $v;
    }

    private function reason(string $id): ?string
    {
        $v = $this->h->one("SELECT reason FROM endpoint_agent_jobs WHERE job_id='$id'");

        return $v === null ? null : (string) $v;
    }

    private function ago(string $id, string $col, int $s = 600): void
    {
        $this->h->q("UPDATE endpoint_agent_jobs SET $col='" . gmdate('Y-m-d H:i:s', time() - $s) . "' WHERE job_id='$id'");
    }

    public function testASignedJobReachesOnlyItsDevice(): void
    {
        $jobA = (string) $this->submit($this->A, 'powershell', 'Get-Date', ['Name' => 'x', 'Count' => 3], 120)['job_id'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $jobA);
        [, , $ck] = $this->h->checkin($this->TA);
        $this->assertSame(1, $ck['jobs_pending']);
        [$c, $r] = $this->fetch($this->TA);
        $job = $r['jobs'][0];
        $this->assertSame([200, 1, $jobA], [$c, count($r['jobs']), $job['job_id']]);
        $keys = array_keys($job);
        sort($keys);
        $this->assertSame(['attempt', 'device_id', 'expires_at', 'issued_at', 'job_id', 'max_output_bytes', 'params', 'script', 'signature', 'timeout_s', 'type'], $keys);
        $this->assertSame([$this->A, 1, 'powershell', 'Get-Date', 120, ['Name' => 'x', 'Count' => 3]], [$job['device_id'], $job['attempt'], $job['type'], $job['script'], $job['timeout_s'], $job['params']]);
        $this->assertTrue(Signer::verify(Signer::jobMessage($job), $job['signature'], $this->pub));
        $tampered = $job;
        $tampered['script'] = 'Remove-Item C:\\ -Recurse';
        $this->assertFalse(Signer::verify(Signer::jobMessage($tampered), $job['signature'], $this->pub));
        $tampered = $job;
        $tampered['attempt'] = 9;
        $this->assertFalse(Signer::verify(Signer::jobMessage($tampered), $job['signature'], $this->pub), 'the signature covers the attempt');
        // wire shape: params is {} when empty, script null for a script-less job
        $collect = (string) $this->submit($this->A, 'collect')['job_id'];
        $raw = $this->h->call('GET', 'agent_jobs', null, $this->TA)[3]->body;
        $this->assertStringContainsString('"params":{}', (string) $raw);
        $this->assertStringContainsString('"script":null', (string) $raw);
        $this->assertGreaterThan(0, strlen($collect));
        // isolation both ways
        [$c, $rb] = $this->fetch($this->TB);
        $this->assertSame([200, []], [$c, $rb['jobs']]);
        $this->assertSame(404, $this->report($this->TB, ['job_id' => $jobA, 'attempt' => 1, 'state' => 'running']));
        $jobB = $this->queue($this->B, 'hostname');
        [, $rb] = $this->fetch($this->TB);
        $this->assertSame([$jobB], array_column($rb['jobs'], 'job_id'));
        [, $ra] = $this->fetch($this->TA);
        $this->assertNotContains($jobB, array_column($ra['jobs'], 'job_id'));
    }

    public function testLifecycleAndIdempotentReports(): void
    {
        $jobA = $this->queue($this->A);
        $this->fetch($this->TA);
        $this->assertSame(200, $this->report($this->TA, ['job_id' => $jobA, 'attempt' => 1, 'state' => 'running', 'started_at' => $this->h::ts()]));
        $this->assertSame('running', $this->state($jobA));
        $this->assertSame(200, $this->report($this->TA, ['job_id' => $jobA, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => "hello\n", 'started_at' => $this->h::ts(-2), 'finished_at' => $this->h::ts()]));
        $this->assertSame(['succeeded', 0, "hello\n"], [$this->state($jobA), (int) $this->h->one("SELECT exit_code FROM endpoint_agent_jobs WHERE job_id='$jobA'"), $this->h->one("SELECT output FROM endpoint_agent_jobs WHERE job_id='$jobA'")]);
        $this->assertSame(200, $this->report($this->TA, ['job_id' => $jobA, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => "hello\n"]), 'the same final result again is idempotent');
        [$c, , $r] = $this->h->call('POST', 'agent_jobs', ['job_id' => $jobA, 'attempt' => 1, 'state' => 'failed', 'exit_code' => 1, 'output' => 'x'], $this->TA);
        $this->assertSame([409, 'conflict'], [$c, $r['code']]);
        $this->assertSame(409, $this->report($this->TA, ['job_id' => $jobA, 'attempt' => 1, 'state' => 'running']), 'a job cannot go back to running');
        $this->assertSame([], array_column($this->fetch($this->TA)[1]['jobs'], 'job_id'), 'a finished job is never offered again');
        $this->assertSame('SYSTEM', $this->h->one("SELECT run_as FROM endpoint_agent_jobs WHERE job_id='$jobA'"));
    }

    public function testReportValidationAndErrors(): void
    {
        $jobA = $this->queue($this->A);
        $jobB = $this->queue($this->B);
        $this->fetch($this->TA);
        foreach ([['job_id' => 'nope', 'attempt' => 1, 'state' => 'running'], ['job_id' => $this->h::uuid(), 'attempt' => 0, 'state' => 'running'], ['job_id' => $this->h::uuid(), 'attempt' => 1, 'state' => 'weird'],
            ['job_id' => $this->h::uuid(), 'attempt' => 1, 'state' => 'queued'], ['job_id' => $this->h::uuid(), 'attempt' => 1, 'state' => 'running', 'exit_code' => 'x'],
            ['job_id' => $this->h::uuid(), 'attempt' => 1, 'state' => 'running', 'output' => ['a']], ['job_id' => $this->h::uuid(), 'attempt' => 1, 'state' => 'running', 'started_at' => 'now'],
            ['job_id' => $this->h::uuid(), 'attempt' => 1, 'state' => 'running', 'finished_at' => '2001-01-01T00:00:00Z']] as $bad) {
            $this->assertSame(422, $this->report($this->TA, $bad), json_encode($bad));
        }
        $this->assertSame(404, $this->report($this->TA, ['job_id' => $this->h::uuid(), 'attempt' => 1, 'state' => 'running']));
        $this->assertSame(404, $this->report($this->TA, ['job_id' => $jobB, 'attempt' => 1, 'state' => 'running']), 'no oracle for another device\'s job id');
        $this->assertSame(409, $this->report($this->TA, ['job_id' => $jobA, 'attempt' => 5, 'state' => 'running']), 'an attempt that was never issued');
        [$c, , $r] = $this->h->call('POST', 'agent_jobs', str_repeat('x', 300000), $this->TA);
        $this->assertSame([413, 'too_large'], [$c, $r['code']]);
        [$c, $hd, $r] = $this->h->call('PUT', 'agent_jobs', null, $this->TA);
        $this->assertSame([405, 'GET, POST'], [$c, $hd['Allow']]);
        [$c] = $this->h->call('GET', 'agent_jobs');
        $this->assertSame(401, $c);
    }

    public function testOutputIsRedactedThenCappedAndStaysValidUtf8(): void
    {
        $id = $this->queue($this->A, 'noisy');
        $this->fetch($this->TA);
        $secrets = ['Bearer abcdefghijklmnop1234567890', 'password=Sup3rS3cret!', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U',
            str_repeat('ab', 32), "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBg\n-----END PRIVATE KEY-----", 'ConvertTo-SecureString "hunter2hunter2" -AsPlainText',
            'api_key: AKIAIOSFODNN7EXAMPLE', 'client_secret=zzzzzzzz', 'rvte1.' . str_repeat('a', 12) . '.' . str_repeat('b', 40)];
        $noisy = "start\n" . implode("\n", $secrets) . "\nend\n" . str_repeat("line of output\n", 12000);
        $this->report($this->TA, ['job_id' => $id, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => $noisy]);
        $stored = (string) $this->h->one("SELECT output FROM endpoint_agent_jobs WHERE job_id='$id'");
        foreach (['abcdefghijklmnop1234567890', 'Sup3rS3cret', 'dozjgNryP4J3jVmNHl0w5N', str_repeat('ab', 32), 'MIIEvQIBADANBg', 'hunter2hunter2', 'AKIAIOSFODNN7EXAMPLE', 'zzzzzzzz', str_repeat('b', 40)] as $needle) {
            $this->assertStringNotContainsString($needle, $stored);
        }
        $this->assertStringContainsString('[REDACTED]', $stored);
        $this->assertStringStartsWith('start', $stored);
        $this->assertLessThanOrEqual(65536, strlen($stored));
        $this->assertSame(1, (int) $this->h->one("SELECT output_truncated FROM endpoint_agent_jobs WHERE job_id='$id'"));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
        $this->assertSame('plain text with no secrets', Redactor::redact('plain text with no secrets'));
        [$s1] = JobService::sanitizeOutput("caf\xc3\xa9 \xff\xfe bad bytes", 1024);
        $this->assertTrue(mb_check_encoding($s1, 'UTF-8'));
        // a cut in the middle of a multi-byte character never leaves a broken one
        [$s2, $tr] = JobService::sanitizeOutput(str_repeat("\xe2\x82\xac", 2000), 1025);
        $this->assertTrue($tr && mb_check_encoding($s2, 'UTF-8'));
        // the per-job cap is the smaller of the job's and the instance's
        $id2 = $this->queue($this->A, 'again');
        $this->fetch($this->TA);
        $this->h->q("UPDATE endpoint_agent_jobs SET max_output_bytes=2048 WHERE job_id='$id2'");
        $this->report($this->TA, ['job_id' => $id2, 'attempt' => 1, 'state' => 'failed', 'output' => str_repeat('0123456789abcdef', 1000)]);
        $this->assertSame(2048, strlen((string) $this->h->one("SELECT output FROM endpoint_agent_jobs WHERE job_id='$id2'")));
    }

    /** Bug fix: the original ran a byte cut through iconv //IGNORE, which returns false for a cut inside a character and blanked the whole output. */
    public function testLongMultiByteOutputIsKeptNotBlanked(): void
    {
        $id = $this->queue($this->A);
        $this->fetch($this->TA);
        $this->h->q("UPDATE endpoint_agent_jobs SET max_output_bytes=1024 WHERE job_id='$id'");
        // 3-byte characters, longer than 4x the cap, and a cap-times-four byte cut that lands inside one (4096 is not a multiple of 3)
        $this->report($this->TA, ['job_id' => $id, 'attempt' => 1, 'state' => 'succeeded', 'output' => str_repeat("\xe2\x82\xac", 5000)]);
        $stored = (string) $this->h->one("SELECT output FROM endpoint_agent_jobs WHERE job_id='$id'");
        $this->assertGreaterThan(1000, strlen($stored));
        $this->assertLessThanOrEqual(1024, strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
        $this->assertSame(1, (int) $this->h->one("SELECT output_truncated FROM endpoint_agent_jobs WHERE job_id='$id'"));
        [$clean, $tr] = JobService::sanitizeOutput("ok \xff\xfe tail \xe2\x82", 1024);
        $this->assertSame(['ok  tail ', false], [$clean, $tr]);
    }

    public function testATimeoutReportedByTheAgentIsRecorded(): void
    {
        $r = $this->submit($this->A, 'powershell', 'sleep 999', [], 5);
        $jt = (string) $r['job_id'];
        $this->fetch($this->TA);
        $this->report($this->TA, ['job_id' => $jt, 'attempt' => 1, 'state' => 'running']);
        $this->report($this->TA, ['job_id' => $jt, 'attempt' => 1, 'state' => 'timed_out', 'exit_code' => null, 'output' => 'killed']);
        $this->assertSame('timed_out', $this->state($jt));
    }

    public function testHarmlessJobsAreReOfferedWithAHigherAttemptThenFailAsNeverStarted(): void
    {
        $jn = $this->queue($this->A, 'Get-Process');
        $this->assertSame(1, $this->fetch($this->TA)[1]['jobs'][0]['attempt']);
        $this->assertSame([], $this->fetch($this->TA)[1]['jobs'], 'not re-sent inside the acknowledgement window');
        $this->ago($jn, 'last_offered_at');
        $raw = (string) $this->h->call('GET', 'agent_jobs', null, $this->TA)[3]->body;
        $o = json_decode($raw, true)['jobs'];
        $this->assertSame([2, $jn], [$o[0]['attempt'], $o[0]['job_id']]);
        $obj = json_decode($raw, false)->jobs[0];
        $this->assertTrue(Signer::verify(Signer::jobMessage((array) $obj), $obj->signature, $this->pub), 'the re-offer carries its own valid signature');
        $this->ago($jn, 'last_offered_at');
        $this->assertSame(3, $this->fetch($this->TA)[1]['jobs'][0]['attempt']);
        $this->ago($jn, 'last_offered_at');
        $this->assertSame([], $this->fetch($this->TA)[1]['jobs']);
        $this->assertSame(['failed', 'never_started'], [$this->state($jn), $this->reason($jn)]);
        // a job that reported running is never re-offered; past its deadline it is timed_out
        $jr = $this->queue($this->A);
        $this->fetch($this->TA);
        $this->report($this->TA, ['job_id' => $jr, 'attempt' => 1, 'state' => 'running']);
        $this->ago($jr, 'last_offered_at');
        $this->assertNotContains($jr, array_column($this->fetch($this->TA)[1]['jobs'], 'job_id'));
        $this->ago($jr, 'started_at', 100000);
        $this->assertGreaterThanOrEqual(1, $this->h->module->jobs()->sweep());
        $this->assertSame(['timed_out', 'no_result_by_deadline'], [$this->state($jr), $this->reason($jr)]);
        // and the agent's late real result still wins
        $this->assertSame(200, $this->report($this->TA, ['job_id' => $jr, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => 'done']));
        $this->assertSame(['succeeded', 'late_result'], [$this->state($jr), $this->reason($jr)]);
    }

    public function testDestructiveJobsAreNeverRetried(): void
    {
        $this->assertSame('A reboot delay (params.delay_s) must be 5 to 3600 seconds.', $this->submit($this->A, 'reboot', null, ['delay_s' => 2])['error']);
        $this->assertFalse($this->submit($this->A, 'reboot', null, ['delay_s' => 3601])['ok']);
        $this->assertFalse($this->submit($this->A, 'reboot', null, ['delay_s' => '30'])['ok']);
        $jd = (string) $this->submit($this->A, 'reboot')['job_id'];
        $this->assertSame(1, (int) $this->h->one("SELECT destructive FROM endpoint_agent_jobs WHERE job_id='$jd'"), 'a reboot is always destructive');
        $this->assertSame('{"delay_s":30}', $this->h->one("SELECT params_json FROM endpoint_agent_jobs WHERE job_id='$jd'"), 'default delay 30');
        [, $d1] = $this->fetch($this->TA);
        $this->assertSame(['reboot', null, ['delay_s' => 30]], [$d1['jobs'][0]['type'], $d1['jobs'][0]['script'], $d1['jobs'][0]['params']]);
        $this->ago($jd, 'last_offered_at');
        $this->assertSame([], $this->fetch($this->TA)[1]['jobs']);
        $this->assertSame(['failed', 'result_lost'], [$this->state($jd), $this->reason($jd)]);
        $this->assertSame([], $this->fetch($this->TA)[1]['jobs']);
        $this->assertSame(200, $this->report($this->TA, ['job_id' => $jd, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => 'rebooted']));
        $this->assertSame(['succeeded', 'late_result'], [$this->state($jd), $this->reason($jd)], 'a late real result replaces result_lost');
        $jd2 = (string) $this->submit($this->A, 'powershell', 'Restart-Computer -Force', [], null, true)['job_id'];
        $this->fetch($this->TA);
        $this->report($this->TA, ['job_id' => $jd2, 'attempt' => 1, 'state' => 'running']);
        $this->ago($jd2, 'started_at', 100000);
        $this->h->module->jobs()->sweep();
        $this->assertSame(['failed', 'result_lost'], [$this->state($jd2), $this->reason($jd2)]);
        $this->assertSame(0, $this->fetch($this->TA)[0] - 200);
    }

    public function testExpiryAndCancel(): void
    {
        $je = $this->queue($this->A);
        $this->h->q("UPDATE endpoint_agent_jobs SET expires_at='" . gmdate('Y-m-d H:i:s', time() - 5) . "' WHERE job_id='$je'");
        $this->assertNotContains($je, array_column($this->fetch($this->TA)[1]['jobs'], 'job_id'));
        $this->assertSame('expired', $this->state($je));
        $this->assertSame(409, $this->report($this->TA, ['job_id' => $je, 'attempt' => 1, 'state' => 'running']));
        // a queued job found past its expiry by the report is expired AND the 409 keeps that state change
        $jf = $this->queue($this->A);
        $this->h->q("UPDATE endpoint_agent_jobs SET expires_at='" . gmdate('Y-m-d H:i:s', time() - 5) . "' WHERE job_id='$jf'");
        $this->assertSame(409, $this->report($this->TA, ['job_id' => $jf, 'attempt' => 1, 'state' => 'running']));
        $this->assertSame(['expired', 'expired_before_run'], [$this->state($jf), $this->reason($jf)]);
        $jc = $this->queue($this->A);
        $this->assertTrue($this->h->module->jobs()->cancel($jc, $this->A, 1));
        $this->assertSame('cancelled', $this->state($jc));
        $this->assertNotContains($jc, array_column($this->fetch($this->TA)[1]['jobs'], 'job_id'));
        $this->assertSame(409, $this->report($this->TA, ['job_id' => $jc, 'attempt' => 1, 'state' => 'running']));
        $this->assertFalse($this->h->module->jobs()->cancel($jc, $this->A, 1), 'only a queued job can be cancelled');
        $jx = $this->queue($this->B);
        $this->assertFalse($this->h->module->jobs()->cancel($jx, $this->A, 1), 'not through a different device');
        $this->assertSame('queued', $this->state($jx));
    }

    public function testCreationLimits(): void
    {
        $this->assertFalse($this->submit($this->A, 'powershell', '')['ok']);
        $this->assertFalse($this->submit($this->A, 'powershell', null)['ok']);
        $this->assertFalse($this->submit($this->A, 'powershell', str_repeat('a', 200000))['ok']);
        $this->assertFalse($this->submit($this->A, 'powershell', "a\0b")['ok']);
        $this->assertFalse($this->submit($this->A, 'powershell', "bad \xff utf8")['ok']);
        $this->assertSame('Timeout must be 1 to 3600 seconds.', $this->submit($this->A, 'powershell', 'x', [], 999999)['error']);
        $this->assertFalse($this->submit($this->A, 'powershell', 'x', [], 0)['ok']);
        $this->assertFalse($this->submit($this->A, 'powershell', 'x', ['bad name' => 1])['ok']);
        $this->assertFalse($this->submit($this->A, 'powershell', 'x', ['n' => 1.5])['ok']);
        $this->assertFalse($this->submit($this->A, 'powershell', 'x', ['n' => str_repeat('a', 1025)])['ok']);
        $this->assertFalse($this->submit($this->A, 'powershell', 'x', array_fill_keys(array_map(static fn (int $i): string => "p$i", range(1, 21)), 1))['ok']);
        $this->assertTrue($this->submit($this->A, 'powershell', 'x', ['s' => 'text', 'b' => true, 'n' => 5, 'z' => null])['ok']);
        $this->assertSame('Unknown job type.', $this->submit($this->A, 'formatc')['error']);
        // a script on a script-less type is dropped, not stored
        $jc = (string) $this->submit($this->A, 'collect', 'ignored')['job_id'];
        $this->assertNull($this->h->one("SELECT script FROM endpoint_agent_jobs WHERE job_id='$jc'"));
        // the default timeout comes from the settings
        $this->assertSame(300, (int) $this->h->one("SELECT timeout_s FROM endpoint_agent_jobs WHERE job_id='$jc'"));
    }

    public function testRevokingCancelsQueuedWorkAndBlocksNewJobs(): void
    {
        $jq = $this->queue($this->B, 'later');
        $this->assertTrue($this->h->module->deviceService()->revoke($this->B, 't', 1));
        $this->assertSame(['cancelled', 'device_revoked'], [$this->state($jq), $this->reason($jq)]);
        $r = $this->submit($this->B, 'powershell', 'x');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('revoked or retired', (string) $r['error']);
    }

    public function testTheLongPollWaitsInHalfSecondStepsUpToTheClampedWait(): void
    {
        [$c, $j] = $this->fetch($this->TA);
        $this->assertSame([200, [], []], [$c, $j['jobs'], $this->h->slept], 'no wait: one look, no sleeping');
        [$c, $j] = $this->fetch($this->TA, ['wait' => '1']);
        $this->assertSame([200, [], [500000, 500000]], [$c, $j['jobs'], $this->h->slept], 'a 1 s wait is two half-second rounds');
        $this->h->slept = [];
        $this->fetch($this->TA, ['wait' => '999']);
        $this->assertCount(10, $this->h->slept, 'the wait is clamped to 5 s');
        $this->h->slept = [];
        $this->fetch($this->TA, ['wait' => '-3']);
        $this->fetch($this->TA, ['wait' => 'abc']);
        $this->assertSame([], $this->h->slept);
        // an available job returns at once whatever wait says
        $this->queue($this->A);
        [, $j] = $this->fetch($this->TA, ['wait' => '5']);
        $this->assertCount(1, $j['jobs']);
        $this->assertSame([], $this->h->slept);
        // a job that shows up during the wait is returned by the round that sees it
        $id = $this->queue($this->B, 'late');
        $this->h->q("UPDATE endpoint_agent_jobs SET expires_at = '" . gmdate('Y-m-d H:i:s', time() + 3600) . "' WHERE job_id='$id'");
        $this->h->q("UPDATE endpoint_agent_jobs SET state='cancelled', finished_at=UTC_TIMESTAMP() WHERE job_id='$id'");
        $other = $this->h->module->deviceApi(fn (): bool => true, false, function (int $us) use ($id): void {
            $this->h->q("UPDATE endpoint_agent_jobs SET state='queued', finished_at=NULL WHERE job_id='$id'");
            $this->h->slept[] = $us;
            $this->h->pollClock += $us / 1e6;
        }, fn (): float => $this->h->pollClock);
        $r = $other->handle($this->h->request('GET', 'agent_jobs', null, $this->TB, ['wait' => '5']));
        $this->assertSame([$id], array_column(json_decode((string) $r->body, true)['jobs'], 'job_id'));
        $this->assertCount(1, $this->h->slept);
    }

    public function testTheJobTypeRegistryIsSeededAndExtensible(): void
    {
        $r = JobTypeRegistry::withDefaults();
        $this->assertSame(['powershell', 'reboot', 'collect'], $r->types());
        $this->assertTrue($r->get('reboot')?->destructive);
        $this->assertTrue($r->get('powershell')?->requiresScript);
        $this->assertSame('rmm.job.run_script', $r->get('powershell')?->ability);
        $this->assertSame('rmm.job.reboot', $r->get('reboot')?->ability);
        $this->assertSame('rmm.job.run_saved', $r->get('collect')?->ability);
        $this->assertFalse($r->has('shell'));
        $r->register(new JobType('inventory', 'rmm.job.run_saved', false, ['windows', 'linux'], false, null, 45));
        $svc = new JobService($this->h->module->sql(), $this->h->module->settings(), $r);
        $row = $this->h->module->devices()->find($this->A);
        $id = (string) $svc->create($row ?? [], 'inventory', null, [], null, false, 1)['job_id'];
        $this->assertSame(45, (int) $this->h->one("SELECT timeout_s FROM endpoint_agent_jobs WHERE job_id='$id'"), 'the registry default timeout is used');
        $this->expectException(\InvalidArgumentException::class);
        $r->register(new JobType('Bad Type!', 'x'));
    }

    public function testPendingCountReflectsOnlyWhatCouldBeOffered(): void
    {
        $this->queue($this->A);
        $d = (string) $this->submit($this->A, 'reboot')['job_id'];
        $svc = $this->h->module->jobs();
        $this->assertSame(2, $svc->pendingCount($this->A));
        $this->fetch($this->TA);
        $this->assertSame(0, $svc->pendingCount($this->A), 'offered and inside the ack window');
        $this->ago($d, 'last_offered_at');
        $this->assertSame(0, $svc->pendingCount($this->A), 'destructive: lost, not pending');
        $this->assertSame('failed', $this->state($d));
    }
}
