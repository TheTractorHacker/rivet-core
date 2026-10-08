<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\RmmModule;
use RivetCore\Rmm\RmmStateFile;
use RivetCore\Testing\InMemoryRmmModuleState;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\TempDir;

/**
 * The module switch (design 12): the state file and its fail-safe rules, the pre-bootstrap gate template served by a real `php -S`
 * with the database server's own counters as the proof of zero cost, the sub-switches, and disable/enable keeping every row.
 */
final class ModuleSwitchTest extends RmmTestCase
{
    private string $dir = '';
    /** @var list<resource> */
    private array $servers = [];

    protected function makeHarness(): RmmHarness
    {
        $this->dir = TempDir::make();

        return new RmmHarness(null, new InMemoryRmmModuleState(true, $this->dir));
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $p) {
            proc_terminate($p, 15);
            proc_close($p);
        }
        $this->servers = [];
        parent::tearDown();
        TempDir::remove($this->dir);
    }

    private function state(): array
    {
        $s = RmmStateFile::read($this->dir);
        $this->assertNotNull($s, 'a valid state file exists');

        return $s;
    }

    // ------------------------------------------------------------------ the file: writer

    public function testEverySettingsChangeRewritesTheStateFile(): void
    {
        $set = $this->h->module->settings();
        $this->assertFalse(is_file(RmmStateFile::path($this->dir)), 'nothing written before the first change');
        $set->enable();
        $s = $this->state();
        $this->assertSame([1, true, true, true, 0], [$s['v'], $s['enabled'], $s['edition'], $s['master'], $s['shed']]);
        $this->assertSame(['monitoring' => true, 'metrics' => true, 'jobs' => true, 'remote' => false, 'updates' => true], array_intersect_key($s['features'], array_flip(['monitoring', 'metrics', 'jobs', 'remote', 'updates'])), 'NULL features_json = the legacy defaults');
        $this->assertSame('sync', $s['ingest_mode']);
        $this->assertSame(3600, $s['retry_after']);
        $this->assertSame([60, 300], $s['shed_retry']);

        $set->disable();
        $this->assertFalse($this->state()['enabled']);
        $this->assertFalse($this->state()['master']);
        $set->enable();
        $this->assertSame([], $set->update(['features_json' => '{"monitoring":true,"jobs":false}', 'limits_json' => '{"max_checkins_per_min":99,"shed_retry_min_s":70}', 'ingest_mode' => 'queued']));
        $s = $this->state();
        $this->assertSame([true, false, false, 'queued', 99, [70, 300]], [$s['features']['monitoring'], $s['features']['jobs'], $s['features']['metrics'], $s['ingest_mode'], $s['limits']['max_checkins_per_min'], $s['shed_retry']]);
        $set->set(['mesh_enabled' => 1, 'features_json' => null]);
        $this->assertTrue($this->state()['features']['remote'], 'remote follows mesh_enabled under the legacy defaults');
        $set->set(['shed_level' => 2]);
        $this->assertSame(2, $this->state()['shed']);
        $this->assertGreaterThan(0, $this->state()['shed_at']);
        $set->set(['shed_level' => 0]);
        $this->assertSame([0, 0], [$this->state()['shed'], $this->state()['shed_at']]);
    }

    public function testTheFileIsWrittenAtomicallyWithMode0640AndNoTemporaryLeftovers(): void
    {
        $this->h->module->settings()->enable();
        $path = RmmStateFile::path($this->dir);
        $this->assertSame('0640', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame([basename($path)], array_map('basename', glob($this->dir . '/*') ?: []), 'only the state file, no .tmp');
        $raw = (string) file_get_contents($path);
        $this->assertIsArray(json_decode($raw, true));
        $this->assertStringStartsWith('{"v":1,', $raw);
        // an update replaces the inode (rename), it never edits in place: a reader holding the old one still sees a whole document
        $fh = fopen($path, 'rb');
        $inode = fstat($fh)['ino'];
        $this->h->module->settings()->disable();
        $this->assertNotSame($inode, fileinode($path));
        $this->assertTrue(json_decode((string) stream_get_contents($fh), true)['enabled'], 'the open handle still reads the complete old document');
        fclose($fh);
    }

    public function testAnUnchangedStateDoesNotTouchTheFile(): void
    {
        $this->h->module->settings()->enable();
        $path = RmmStateFile::path($this->dir);
        $inode = fileinode($path);
        $this->h->module->settings()->set(['retention_days' => 15]);   // not mirrored
        $this->h->module->settings()->set(['enabled' => 1]);          // mirrored column, same value
        $this->assertTrue($this->h->module->syncState());
        $this->assertSame($inode, fileinode($path));
    }

    public function testAnUnwritableDirectoryNeverBreaksTheSettingsWrite(): void
    {
        $h = new RmmHarness(null, new InMemoryRmmModuleState(true, '/proc/rmm-no-such-dir/state'));
        $h->module->settings()->enable();
        $this->assertTrue($h->module->enabled(), 'the module still works on the database alone');
        $this->assertFalse($h->module->syncState());
        $this->assertFalse(RmmStateFile::write(null, [], time()));
        $none = new RmmHarness(null, new InMemoryRmmModuleState(true, null));
        $none->module->settings()->enable();
        $this->assertFalse($none->module->syncState());
        $this->assertTrue($none->module->enabled());
    }

    // ------------------------------------------------------------------ the file: reader and the fail-safe rules

    /** @return array<string,array{0:string}> */
    public static function unknownFiles(): array
    {
        $ok = ['v' => 1, 'enabled' => false, 'edition' => true, 'master' => false, 'features' => ['monitoring' => true], 'shed' => 0, 'shed_at' => 0, 'shed_retry' => [60, 300],
            'retry_after' => 3600, 'ingest_mode' => 'sync', 'limits' => ['max_checkins_per_min' => 0], 'written_at' => 1];
        $with = static fn (array $over): string => (string) json_encode(array_merge($ok, $over));
        $without = static fn (string $k): string => (string) json_encode(array_diff_key($ok, [$k => 1]));

        return [
            'empty' => [''],
            'garbage' => ['not json at all <?php echo 1;'],
            'truncated' => [substr($with([]), 0, 40)],
            'a list' => ['[1,2,3]'],
            'a string' => ['"enabled"'],
            'null' => ['null'],
            'future version' => [$with(['v' => 2])],
            'old version' => [$with(['v' => 0])],
            'version as string' => [$with(['v' => '1'])],
            'no version' => [$without('v')],
            'enabled as string' => [$with(['enabled' => 'false'])],
            'enabled as int' => [$with(['enabled' => 0])],
            'no enabled' => [$without('enabled')],
            'no master' => [$without('master')],
            'features not an object of bools' => [$with(['features' => ['monitoring' => 1]])],
            'features a string' => [$with(['features' => 'all'])],
            'limits with a string value' => [$with(['limits' => ['max_checkins_per_min' => '5']])],
            'shed out of range' => [$with(['shed' => 9])],
            'shed as float' => [$with(['shed' => 1.5])],
            'bad ingest mode' => [$with(['ingest_mode' => 'turbo'])],
            'shed_retry wrong shape' => [$with(['shed_retry' => [60]])],
            'no written_at' => [$without('written_at')],
            'binary' => ["\x00\x01\x02\xff"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unknownFiles')]
    public function testAMissingOrDamagedOrStaleVersionFileIsUnknownNeverOffForAnEnabledInstall(string $content): void
    {
        $this->h->module->settings()->enable();
        $path = RmmStateFile::path($this->dir);
        file_put_contents($path, $content);
        $this->assertNull(RmmStateFile::read($this->dir));
        $this->assertTrue(RmmStateFile::enabled($this->dir), 'unknown means "go and look in the database"');
        // a fresh module (a new request) answers from the database and repairs the file on the spot
        $m = $this->freshModule();
        $this->assertTrue($m->enabled(), 'an enabled install stays enabled');
        $this->assertTrue($m->featureOn('metrics'));
        $repaired = RmmStateFile::read($this->dir);
        $this->assertNotNull($repaired, 'the first such request rewrote the file');
        $this->assertTrue($repaired['enabled']);
    }

    public function testAMissingFileIsUnknownToo(): void
    {
        $this->h->module->settings()->enable();
        unlink(RmmStateFile::path($this->dir));
        $this->assertNull(RmmStateFile::read($this->dir));
        $this->assertNull(RmmStateFile::read('/nonexistent/dir'));
        $this->assertNull(RmmStateFile::read(null));
        $this->assertTrue(RmmStateFile::enabled(null));
        $this->assertTrue($this->freshModule()->enabled());
        $this->assertFileExists(RmmStateFile::path($this->dir));
    }

    public function testAnOffInstallWithAnUnknownFileStaysOffAndTheFileSaysSo(): void
    {
        file_put_contents(RmmStateFile::path($this->dir), 'junk');
        $m = $this->freshModule();
        $this->assertFalse($m->enabled());
        $this->assertFalse($this->state()['enabled']);
        $this->assertFalse(RmmStateFile::enabled($this->dir));
    }

    private function freshModule(): RmmModule
    {
        $h = $this->h;

        return new RmmModule($h->db, $h->clock, $h->tenancy, $h->assets, $h->bridge, $h->box, $h->audit, $h->metrics, new InMemoryRmmModuleState(true, $this->dir), ['allow_insecure_http' => true]);
    }

    public function testAValidOffFileAnswersWithoutTheDatabaseAndAStaleOneOnlyDelaysUntilTheWriterRuns(): void
    {
        $this->h->module->settings()->enable();
        $this->assertTrue($this->state()['enabled']);
        $before = $this->h->counting->statements;
        $m = $this->freshModule();
        $this->assertTrue($m->enabled());
        $this->assertTrue($m->featureOn('jobs'));
        $this->assertFalse($m->featureOn('patching'));
        $this->assertSame($before, $this->h->counting->statements, 'a request that only needs "is the module on" runs no query');

        // an administrator disables it: the writer ran in the same request
        $this->h->module->settings()->disable();
        $before = $this->h->counting->statements;
        $m = $this->freshModule();
        $this->assertFalse($m->enabled());
        $this->assertFalse($m->featureOn('jobs'), 'nothing is on while the module is off');
        $this->assertSame($before, $this->h->counting->statements, 'and an off module costs zero queries');

        // somebody edits the database behind Core's back: the file is stale until the next write or sync, by design
        $this->h->q('UPDATE endpoint_agent_settings SET enabled = 1');
        $this->assertFalse($this->freshModule()->enabled());
        $this->assertTrue($this->freshModule()->syncState());
        $this->assertTrue($this->freshModule()->enabled());
    }

    public function testTheEditionKillSwitchWinsWithoutTheDatabase(): void
    {
        $this->h->module->settings()->enable();
        $kill = new class (true, $this->dir) extends InMemoryRmmModuleState {
            public function allow(bool $v): void
            {
                $this->allows = $v;
            }
        };
        $h = $this->h;
        $m = new RmmModule($h->db, $h->clock, $h->tenancy, $h->assets, $h->bridge, $h->box, $h->audit, $h->metrics, $kill, ['allow_insecure_http' => true]);
        $this->assertTrue($m->enabled());
        $kill->allow(false);
        $m2 = new RmmModule($h->db, $h->clock, $h->tenancy, $h->assets, $h->bridge, $h->box, $h->audit, $h->metrics, $kill, ['allow_insecure_http' => true]);
        $this->assertFalse($m2->enabled(), 'edition off, master on: off, even though the file still says on');
        $this->assertFalse($m2->featureOn('monitoring'));
        $this->assertTrue($m2->syncState());
        $s = $this->state();
        $this->assertSame([false, false, true], [$s['enabled'], $s['edition'], $s['master']], 'the edition calls syncState() after changing its own flag; the gate then refuses');
        $kill->allow(true);
        $m2->syncState();
        $this->assertTrue($this->state()['enabled']);
    }

    // ------------------------------------------------------------------ the gate, on a real server

    /** @return array{0:int,1:resource} port and process */
    private function startGate(?string $stateDir): array
    {
        for ($i = 0; $i < 50; ++$i) {
            $port = random_int(20000, 29000);
            $s = @stream_socket_server("tcp://127.0.0.1:$port");
            if ($s !== false) {
                fclose($s);
                break;
            }
        }
        $env = array_merge(getenv(), ['RMM_GATE_STATE_DIR' => $stateDir ?? '']);
        $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$port", __DIR__ . '/../../Fixtures/rmm_gate_router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, __DIR__, $env);
        $this->servers[] = $p;
        for ($i = 0; $i < 100; ++$i) {
            if (@fsockopen('127.0.0.1', $port)) {
                return [$port, $p];
            }
            usleep(50000);
        }
        $this->fail('the test server did not start');
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function http(int $port, string $method, string $path, ?string $body = null): array
    {
        $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10, 'content' => $body ?? '', 'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . str_repeat('a', 64)]]);
        $raw = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
        $headers = [];
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => (string) $raw];
    }

    /** @return array{connections:int,selects:int} the database server's own counters (the test uses a private scratch server, see below) */
    private function counters(): array
    {
        $out = [];
        foreach ($this->h->rows("SHOW GLOBAL STATUS WHERE Variable_name IN ('Connections', 'Com_select')") as $r) {
            $out[$r['Variable_name']] = (int) $r['Value'];
        }

        return ['connections' => $out['Connections'], 'selects' => $out['Com_select']];
    }

    private const DEVICE_ENDPOINTS = ['agent_enroll', 'agent_checkin', 'agent_jobs', 'agent_update', 'agent_installer'];

    public function testTheGateAnswersTheDisabledModuleWithTheExact503AndZeroDatabaseWork(): void
    {
        $this->h->module->settings()->enable();
        $this->h->module->settings()->disable();
        [$port] = $this->startGate($this->dir);
        $expected = '{"error":"The RMM service is disabled on this server.","code":"module_disabled"}';
        $this->assertSame($expected, (string) json_encode(['error' => 'The RMM service is disabled on this server.', 'code' => 'module_disabled'], \RivetCore\Rmm\Http\RmmResponse::JSON_FLAGS), 'the gate body is byte-identical to what DeviceApi builds');

        // is the server idle enough for its global counters to mean something? (a shared database server would not be)
        $a = $this->counters();
        usleep(300000);
        $idle = $a === $this->counters();
        $before = $this->counters();
        $n = 0;
        foreach (self::DEVICE_ENDPOINTS as $ep) {
            foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
                foreach (["/api/v1/$ep", "/api/v1/$ep.php", "/api/v1/$ep/", "/api/v1/$ep?wait=1&x=y"] as $path) {
                    $r = $this->http($port, $method, $path, $method === 'POST' || $method === 'PUT' ? '{"seq":1}' : null);
                    $this->assertSame(503, $r['status'], "$method $path");
                    $this->assertSame($expected, $r['body']);
                    $this->assertSame('3600', $r['headers']['retry-after']);
                    $this->assertSame('no-store', $r['headers']['cache-control']);
                    $this->assertSame('application/json', $r['headers']['content-type']);
                    ++$n;
                }
            }
        }
        $this->assertGreaterThanOrEqual(80, $n);
        $r = $this->http($port, 'HEAD', '/api/v1/agent_checkin');
        $this->assertSame([503, ''], [$r['status'], $r['body']]);

        // every one of them answered by the gate: no connection and no SELECT reached the database server
        $after = $this->counters();
        if ($idle) {
            $this->assertSame($before, $after, "$n gated requests: zero connections and zero SELECTs on the server's own counters");
        } else {
            $this->markTestIncomplete('the database server is shared and busy; the counter proof needs a private scratch server (see docs/rmm/CAPACITY.md)');
        }

        // the canary: with the module on the same server DOES connect and select, so the counters above can see a violation
        $this->h->module->settings()->enable();
        $c0 = $this->counters();
        $r = $this->http($port, 'GET', '/api/v1/agent_checkin');
        $this->assertSame(200, $r['status']);
        $this->assertSame(['passed' => true, 'db' => true], json_decode($r['body'], true));
        $c1 = $this->counters();
        $this->assertGreaterThan($c0['connections'], $c1['connections']);
        $this->assertGreaterThan($c0['selects'], $c1['selects']);
    }

    public function testTheGateIsSilentForOtherPathsAndWithoutAStateDirectory(): void
    {
        $this->h->module->settings()->enable();
        $this->h->module->settings()->disable();
        [$port] = $this->startGate($this->dir);
        foreach (['/api/v1/tickets', '/api/v1/agent_checkins_report', '/index.php', '/api/v1/x/agent_checkin_extra', '/api/v1/endpoint_devices', '/api/v1/endpoint_devices/12'] as $path) {
            $this->assertSame(200, $this->http($port, 'GET', $path)['status'], $path . ' is not gated (the technician endpoint authenticates first, see rmm_gate.php)');
        }
        [$port2] = $this->startGate(null);
        $this->assertSame(200, $this->http($port2, 'GET', '/api/v1/agent_checkin')['status'], 'no state directory configured: the gate does nothing');
    }

    public function testTheGateAgreesWithTheReaderOnEveryKindOfFile(): void
    {
        $this->h->module->settings()->enable();
        [$port] = $this->startGate($this->dir);
        $path = RmmStateFile::path($this->dir);
        $corpus = [];
        foreach (self::unknownFiles() as $name => [$content]) {
            $corpus[$name] = $content;
        }
        $good = ['v' => 1, 'enabled' => true, 'edition' => true, 'master' => true, 'features' => ['monitoring' => true], 'shed' => 0, 'shed_at' => 0, 'shed_retry' => [60, 300],
            'retry_after' => 3600, 'ingest_mode' => 'sync', 'limits' => ['max_checkins_per_min' => 0], 'written_at' => time()];
        $corpus['valid on'] = (string) json_encode($good);
        $corpus['valid off'] = (string) json_encode(['enabled' => false, 'master' => false] + $good);
        $corpus['valid edition off'] = (string) json_encode(['enabled' => false, 'edition' => false] + $good);
        foreach ($corpus as $name => $content) {
            file_put_contents($path, $content);
            $s = RmmStateFile::read($this->dir);
            $gateTurnsAway = $this->http($port, 'POST', '/api/v1/agent_checkin', '{}')['status'] === 503;
            $this->assertSame($s !== null && !$s['enabled'], $gateTurnsAway, "gate and reader agree on: $name");
            $this->assertSame($s !== null && !$s['enabled'], !RmmStateFile::enabled($this->dir), "enabled() agrees on: $name");
        }
    }

    public function testTheGateRefusesCheckinsAtShedLevelThreeWithAJitteredRetryAfterAndNothingElse(): void
    {
        $this->h->module->settings()->enable();
        $this->h->module->settings()->update(['limits_json' => '{"shed_retry_min_s":61,"shed_retry_max_s":65}']);
        $this->h->module->settings()->set(['shed_level' => 3]);
        [$port] = $this->startGate($this->dir);
        $seen = [];
        for ($i = 0; $i < 40; ++$i) {
            $r = $this->http($port, 'POST', '/api/v1/agent_checkin', '{}');
            $this->assertSame(503, $r['status']);
            $this->assertSame('{"error":"The service is busy. Try again later.","code":"unavailable"}', $r['body']);
            $ra = (int) $r['headers']['retry-after'];
            $this->assertGreaterThanOrEqual(61, $ra);
            $this->assertLessThanOrEqual(65, $ra);
            $seen[$ra] = true;
        }
        $this->assertGreaterThan(2, count($seen), 'the Retry-After is jittered');
        foreach (['agent_enroll', 'agent_jobs', 'agent_update', 'agent_installer', 'endpoint_devices'] as $ep) {
            $this->assertSame(200, $this->http($port, 'POST', "/api/v1/$ep", '{}')['status'], "$ep is never shed");
        }
        // a level older than its TTL is ignored: a stalled evaluator cannot wedge the fleet
        $path = RmmStateFile::path($this->dir);
        $d = json_decode((string) file_get_contents($path), true);
        $d['shed_at'] = time() - RmmStateFile::SHED_TTL_S - 5;
        file_put_contents($path, json_encode($d));
        $this->assertSame(200, $this->http($port, 'POST', '/api/v1/agent_checkin', '{}')['status']);
        $this->assertSame(0, RmmStateFile::shedLevel(RmmStateFile::read($this->dir), time()));
        $d['shed_at'] = time();
        file_put_contents($path, json_encode($d));
        $this->assertSame(503, $this->http($port, 'POST', '/api/v1/agent_checkin', '{}')['status']);
    }

    // ------------------------------------------------------------------ sub-switches and the device API

    public function testSubSwitchesAnswerFeatureDisabledAndOmitTheFeatureFromTheCheckin(): void
    {
        $tok = $this->h->token(null, 24, 10);
        $this->h->asset(['name' => 'A', 'serial' => 'SW-1', 'make' => 'Dell']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SW-1']));
        $T = $j['device_token'];
        $set = $this->h->module->settings();
        $this->h->q("INSERT INTO endpoint_agent_releases (version, url, sha256, min_version, ring, rollout_pct, arch) VALUES ('9.0.0', 'https://x/y', '" . str_repeat('a', 64) . "', '0.0.0', 'stable', 100, '')");

        // everything on (the legacy defaults)
        [$c, , $r] = $this->h->checkin($T);
        $this->assertSame(200, $c);
        $this->assertSame('9.0.0', $r['update']['version']);
        [$c] = $this->h->call('GET', 'agent_jobs', null, $T);
        $this->assertSame(200, $c);

        $this->assertSame([], $set->update(['features_json' => '{"monitoring":true,"metrics":true,"jobs":false,"updates":false}']));
        [$c, $h, $r] = $this->h->call('GET', 'agent_jobs', null, $T);
        $this->assertSame([503, 'feature_disabled', '3600'], [$c, $r['code'], $h['Retry-After']]);
        [$c, $h, $r] = $this->h->call('POST', 'agent_jobs', ['job_id' => 'x'], $T);
        $this->assertSame([503, 'feature_disabled'], [$c, $r['code']], 'job reports too: jobs are off');
        [$c, $h, $r] = $this->h->call('GET', 'agent_update', null, $T, ['arch' => 'amd64', 'version' => '1.0.0']);
        $this->assertSame([503, 'feature_disabled', '3600'], [$c, $r['code'], $h['Retry-After']]);
        [$c, , $r] = $this->h->checkin($T);
        $this->assertSame(200, $c, 'check-in itself keeps working so the device stays online');
        $this->assertArrayNotHasKey('update', $r, 'the response omits the feature');
        $this->assertSame(0, $r['jobs_pending']);
        $this->assertSame('3600', (string) $h['Retry-After']);

        // metrics off drops sample ingest, monitoring off skips the checks and the link health
        $samplesBefore = count($this->h->metrics->stored());
        $set->update(['features_json' => '{"monitoring":true,"metrics":false}']);
        $this->h->checkin($T, ['checks' => [['key' => 'm1', 'status' => 'ok']]]);
        $this->assertSame($samplesBefore, count($this->h->metrics->stored()));
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key='m1'"));
        $healthBefore = $this->h->bridge->health;
        $set->update(['features_json' => '{"monitoring":false,"metrics":true}']);
        $this->h->checkin($T, ['checks' => [['key' => 'm2', 'status' => 'ok']], 'metrics' => ['cpu_pct' => 77.5]]);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key='m2'"));
        $this->assertSame($healthBefore, $this->h->bridge->health, 'link health is monitoring data');
        $this->assertGreaterThan($samplesBefore, count($this->h->metrics->stored()));
        // the module itself off beats every sub-switch
        $set->update(['features_json' => null]);
        $this->assertTrue($this->h->module->featureOn('jobs'));
        $set->disable();
        $this->assertFalse($this->h->module->featureOn('jobs'));
    }

    public function testADisabledModuleAnswers503ModuleDisabledOnEveryDeviceEndpointInProcess(): void
    {
        $h = new RmmHarness(null, new InMemoryRmmModuleState(true, $this->dir), [], true);
        $tok = $h->token(null, 24, 10);
        [, , $j] = $h->enroll($tok, $h::device(['serial' => 'DM-1']));
        $T = $j['device_token'];
        $h->module->settings()->disable();
        foreach (['agent_enroll' => ['POST', ['enrollment_token' => $tok, 'device' => $h::device()]], 'agent_checkin' => ['POST', ['seq' => 1]], 'agent_jobs' => ['GET', null],
            'agent_update' => ['GET', null], 'agent_installer' => ['POST', ['token' => $tok, 'arch' => 'amd64']]] as $ep => [$method, $body]) {
            [$c, $hd, $r] = $h->call($method, $ep, $body, $ep === 'agent_enroll' || $ep === 'agent_installer' ? null : $T);
            $this->assertSame([503, 'module_disabled', '3600', 'no-store'], [$c, $r['code'], $hd['Retry-After'], $hd['Cache-Control']], $ep);
        }
    }

    // ------------------------------------------------------------------ disable and enable keep everything

    public function testDisableThenEnableKeepsEveryRowAndTheDeviceKeepsItsCredential(): void
    {
        $h = new RmmHarness(null, new InMemoryRmmModuleState(true, $this->dir), [], true);
        $tok = $h->token(null, 24, 10);
        $h->asset(['name' => 'A', 'serial' => 'KE-1', 'make' => 'Dell']);
        [, , $j] = $h->enroll($tok, $h::device(['serial' => 'KE-1']));
        $T = $j['device_token'];
        $h->checkin($T, ['checks' => [['key' => 'k', 'status' => 'fail', 'detail' => 'x']]]);
        $h->module->ingestQueue()->enqueue(['device_id' => (int) $j['device_id']]);
        $tables = array_merge(RmmHarness::TABLES, ['endpoint_agent_settings', 'integration_jobs']);
        $count = static function (RmmHarness $h) use ($tables): array {
            $o = [];
            foreach ($tables as $t) {
                $o[$t] = (int) $h->one("SELECT COUNT(*) FROM `$t`");
            }

            return $o;
        };
        $before = $count($h);
        $key = $h->rows('SELECT signing_public_key, signing_key_id FROM endpoint_agent_settings')[0];
        $h->module->settings()->disable();
        [$c, , $r] = $h->checkin($T);
        $this->assertSame([503, 'module_disabled'], [$c, $r['code']]);
        $this->assertSame($before, $count($h), 'disabling deleted nothing');
        $h->module->settings()->enable();
        [$c] = $h->checkin($T);
        $this->assertSame(200, $c, 'the same device token works again');
        $this->assertSame($key, $h->rows('SELECT signing_public_key, signing_key_id FROM endpoint_agent_settings')[0], 'the signing key is not re-minted');
        $after = $count($h);
        $this->assertSame($before['endpoint_agent_devices'], $after['endpoint_agent_devices']);
        $this->assertSame($before['endpoint_agent_checks'], $after['endpoint_agent_checks']);
        $this->assertSame($before['endpoint_agent_enrollment_tokens'], $after['endpoint_agent_enrollment_tokens']);
        $this->assertSame($before['endpoint_agent_checkins'] + 1, $after['endpoint_agent_checkins']);
    }
}
