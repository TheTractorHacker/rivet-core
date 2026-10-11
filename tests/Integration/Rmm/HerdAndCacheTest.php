<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Capacity\DbTokenBucketStore;
use RivetCore\Rmm\Capacity\HerdControl;
use RivetCore\Rmm\Capacity\RedisTokenBucketStore;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Support\RmmCache;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Tests\Support\ArrayPsrCache;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\ScratchDb;
use RivetCore\Tests\Support\TestRedis;

/** Scale item S8: the stale-while-revalidate read cache and the herd control (warm start, token buckets). */
final class HerdAndCacheTest extends RmmTestCase
{
    private MutableClock $clock;

    protected function makeHarness(): RmmHarness
    {
        $this->clock = new MutableClock();

        return new RmmHarness($this->clock);
    }

    private function sql(): Sql
    {
        return new Sql($this->h->db, $this->clock);
    }

    /** @return \Closure():int a compute closure that counts its calls */
    private function counter(int &$calls, string|int $value = 'v'): \Closure
    {
        return static function () use (&$calls, $value) {
            ++$calls;

            return ['n' => $calls, 'v' => $value];
        };
    }

    // ------------------------------------------------------------------ the cache, both backends

    /** @return array<string,array{0:bool}> */
    public static function backends(): array
    {
        return ['table' => [false], 'shared PSR-16' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testAFreshValueIsServedWithoutComputing(bool $shared): void
    {
        $c = new RmmCache($this->sql(), $shared ? new ArrayPsrCache($this->clock) : null);
        $calls = 0;
        $this->assertSame(1, $c->remember('fleet', 'k', 15, 300, $this->counter($calls))['n']);
        $this->assertSame(1, $c->remember('fleet', 'k', 15, 300, $this->counter($calls))['n']);
        $this->assertSame(1, $calls);
        $this->assertSame(['hit' => 1, 'stale' => 0, 'miss' => 1, 'refresh' => 0, 'error' => 0], $c->stats());
        $this->assertSame($shared, $c->shared());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testAStaleValueIsServedWhileExactlyOneRequestRefreshesIt(bool $shared): void
    {
        $c = new RmmCache($this->sql(), $shared ? new ArrayPsrCache($this->clock) : null);
        $calls = 0;
        $c->remember('fleet', 'k', 15, 300, $this->counter($calls));
        $this->clock->advance(20);   // fresh window over, stale window open
        $r = $c->remember('fleet', 'k', 15, 300, $this->counter($calls));
        $this->assertSame(1, $r['n'], 'the caller still gets the OLD value, at once');
        $this->assertSame(2, $calls, 'and this one request refreshed it');
        $this->assertSame(2, $c->remember('fleet', 'k', 15, 300, $this->counter($calls))['n'], 'the next reader sees the refreshed value');
        $this->assertSame(2, $calls);
        // a second process holding the refresh lock: nobody else recomputes, everybody gets the stale value
        $this->clock->advance(20);
        $other = ScratchDb::connect();
        $this->assertNotNull($other);
        $name = 'rmm_cache_' . md5('fleet:k');
        $other->query("SELECT GET_LOCK('$name', 0)");
        $r = $c->remember('fleet', 'k', 15, 300, $this->counter($calls));
        $other->query("SELECT RELEASE_LOCK('$name')");
        $other->close();
        $this->assertSame([2, 2], [$r['n'], $calls], 'the refresh was left to the lock holder');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testAnExpiredValueIsComputedInlineAndAnInvalidationIsSoft(bool $shared): void
    {
        $c = new RmmCache($this->sql(), $shared ? new ArrayPsrCache($this->clock) : null);
        $calls = 0;
        $c->remember('fleet', 'k', 15, 300, $this->counter($calls));
        $this->clock->advance(400);
        $this->assertSame(2, $c->remember('fleet', 'k', 15, 300, $this->counter($calls))['n'], 'past the stale window it is a miss');
        $c->invalidate('fleet');
        $r = $c->remember('fleet', 'k', 15, 300, $this->counter($calls));
        $this->assertSame([2, 3], [$r['n'], $calls], 'a soft invalidation serves the old value once and recomputes behind it');
        $this->assertSame(3, $c->remember('fleet', 'k', 15, 300, $this->counter($calls))['n']);
        $c->invalidate('releases');
        $this->assertSame(3, $c->remember('fleet', 'k', 15, 300, $this->counter($calls))['n'], 'another group is untouched');
        $this->assertSame(3, $calls);
        $c->invalidate('fleet', true);
        $this->assertSame(4, $c->remember('fleet', 'k', 15, 300, $this->counter($calls))['n'], 'a hard invalidation drops the value: the next reader computes inline');
        $c->forget('fleet', 'k');
        $this->assertSame(5, $c->remember('fleet', 'k', 15, 300, $this->counter($calls))['n']);
    }

    public function testAZeroFreshTimeDisablesCachingAndALongKeyWorks(): void
    {
        $c = new RmmCache($this->sql());
        $calls = 0;
        $c->remember('fleet', 'k', 0, 300, $this->counter($calls));
        $c->remember('fleet', 'k', 0, 300, $this->counter($calls));
        $this->assertSame(2, $calls);
        $key = str_repeat('x', 300);
        $c->remember('fleet', $key, 15, 300, $this->counter($calls));
        $c->remember('fleet', $key, 15, 300, $this->counter($calls));
        $this->assertSame(3, $calls);
        $c->invalidate('fleet');
        $this->assertSame(3, $calls);
        $this->clock->advance(10000);
        $this->assertGreaterThan(0, $c->prune());
    }

    public function testACacheThatFailsNeverFailsTheCaller(): void
    {
        $psr = new ArrayPsrCache($this->clock);
        $psr->failing = true;
        $c = new RmmCache($this->sql(), $psr);
        $calls = 0;
        $this->assertSame(1, $c->remember('fleet', 'k', 15, 300, $this->counter($calls))['n']);
        $this->assertSame(2, $c->remember('fleet', 'k', 15, 300, $this->counter($calls))['n']);
        $c->invalidate('fleet');
        $c->forget('fleet', 'k');
        $this->assertGreaterThan(0, $c->stats()['error']);
        // the table backend with the table gone
        $this->h->q('DROP TABLE rmm_cache');
        try {
            $t = new RmmCache($this->sql());
            $this->assertSame(3, $t->remember('fleet', 'k', 15, 300, $this->counter($calls))['n']);
            $this->assertSame(0, $t->prune());
        } finally {
            $this->h->q(\RivetCore\Rmm\Migration\ScaleSchema::tables()['rmm_cache']);
        }
    }

    public function testTheFleetCountsAreCachedSharedAcrossScopesAndInvalidatedByAdminActions(): void
    {
        $tok = $this->h->token(null, 24, 100);
        $this->h->asset(['name' => 'PC1', 'serial' => 'FC-1', 'make' => 'Dell']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'FC-1']));
        $dev = (int) $j['device_id'];
        $rm = $this->h->module->readModel();
        $this->assertSame(1, $rm->fleetCounts()['total']);
        $before = $this->h->counting->statements;
        $this->assertSame(1, $rm->fleetCounts()['total']);
        $this->assertLessThanOrEqual(2, $this->h->counting->statements - $before, 'a cached read is one primary-key lookup (plus the settings row)');
        // enrolling invalidates (the new device shows at once) and so does revoking
        [, , $j2] = $this->h->enroll($tok, $this->h::device(['serial' => 'FC-2']));
        $this->assertSame(1, $rm->fleetCounts()['total'], 'an enrollment is a soft invalidation: the old figure is served once while one request refreshes it');
        $this->assertSame(2, $rm->fleetCounts()['total']);
        $this->assertTrue($this->h->module->deviceService()->revoke((int) $j2['device_id'], 'test', 1));
        $rm->fleetCounts();
        $this->assertSame(1, $rm->fleetCounts()['total']);
        $this->assertTrue($this->h->module->deviceService()->retire($dev, 1));
        $rm->fleetCounts();
        $this->assertSame(0, $rm->fleetCounts()['total']);
        // a different visibility scope is a different entry
        $this->assertSame(0, $rm->fleetCounts([$this->h->clientA])['total']);
        // caching can be switched off
        $this->h->module->settings()->update(['limits_json' => ['cache_fresh_s' => 0]]);
        $this->h->module->cache()->invalidate('fleet');
        $this->assertSame(0, $rm->fleetCounts()['total']);
    }

    public function testTheCapacityPanelReadsTheDeviceAggregateFromTheCache(): void
    {
        $tok = $this->h->token(null, 24, 100);
        $this->h->asset(['name' => 'PC1', 'serial' => 'CP-1', 'make' => 'Dell']);
        $this->h->enroll($tok, $this->h::device(['serial' => 'CP-1']));
        $cap = $this->h->module->capacity();
        $this->assertSame(1, $cap->build()['devices']['total']);
        $this->h->q("UPDATE endpoint_agent_devices SET retired_at = UTC_TIMESTAMP()");   // behind the cache's back
        $this->assertSame(1, $cap->build()['devices']['total'], 'the panel serves the cached aggregate');
        $this->h->module->cache()->invalidate('fleet');
        $cap->build();
        $this->assertSame(1, $cap->build()['devices']['total'] - 0 === 1 ? 1 : 0);
        $this->clock->advance(30);
        $cap->build();
        $this->assertSame(1, $cap->build()['devices']['retired']);
    }

    public function testActiveReleasesAreCachedOnASharedCacheOnly(): void
    {
        $psr = new ArrayPsrCache($this->clock);
        $h = new RmmHarness($this->clock, null, ['cache' => $psr]);
        $h->enable();
        $this->assertNull($h->module->updates()->addRelease('1.5.0', 'https://rmm.example.test/a.exe', str_repeat('a', 64), '0.0.0', 'stable', 100, '', 1));
        $dev = ['agent_version' => '1.0.0', 'ring' => 'stable', 'arch' => 'amd64', 'device_id' => 5, 'update_state_json' => null];
        $this->assertSame('1.5.0', $h->module->updates()->offeredRelease($dev)['version'] ?? null);
        $sets = $psr->sets;
        $before = $h->counting->statements;
        $h->module->updates()->offeredRelease($dev);
        $this->assertSame($sets, $psr->sets);
        $this->assertLessThanOrEqual($before + 1, $h->counting->statements, 'no release query on a cache hit');
        // a release published later is offered at once: the writer invalidated
        $this->assertNull($h->module->updates()->addRelease('1.6.0', 'https://rmm.example.test/b.exe', str_repeat('b', 64), '0.0.0', 'stable', 100, '', 1));
        $this->assertSame('1.6.0', $h->module->updates()->offeredRelease($dev)['version'] ?? null);
        RmmHarness::closeAll();
    }

    // ------------------------------------------------------------------ herd control

    public function testTheWarmStartRampIsLinearAndTheAdmissionIsUniformAndDeterministic(): void
    {
        $this->assertSame([10.0, 55.0, 100.0, 100.0], [HerdControl::admittedPercent(0, 100, 10), HerdControl::admittedPercent(50, 100, 10), HerdControl::admittedPercent(100, 100, 10), HerdControl::admittedPercent(500, 100, 10)]);
        $this->assertSame(100.0, HerdControl::admittedPercent(5, 0, 10));
        foreach ([10.0, 30.0, 75.0] as $pct) {
            $admitted = 0;
            for ($i = 0; $i < 20000; ++$i) {
                $admitted += HerdControl::admits('Bearer token-' . $i, 1_800_000_000, $pct) ? 1 : 0;
            }
            $this->assertEqualsWithDelta($pct, $admitted / 200, 1.5, "share at $pct %");
        }
        $this->assertSame(HerdControl::admits('Bearer abc', 1_800_000_003, 30.0), HerdControl::admits('Bearer abc', 1_800_000_007, 30.0), 'the same device in the same ten second slot always gets the same answer');
        $slots = 0;
        for ($s = 0; $s < 400; ++$s) {
            $slots += HerdControl::admits('Bearer abc', 1_800_000_000 + $s * 10, 30.0) ? 1 : 0;
        }
        $this->assertGreaterThan(60, $slots);
        $this->assertLessThan(180, $slots, 'a refused device is re-drawn every ten seconds and gets in within a few slots');
        $this->assertTrue(HerdControl::admits('x', 5, 100.0));
        [$lo, $hi] = HerdControl::retryWindow(600);
        $this->assertSame([10, 100], [$lo, $hi]);
        $this->assertSame([10, 20], HerdControl::retryWindow(3));
    }

    private function herd(?string $dir = null): HerdControl
    {
        $state = new \RivetCore\Rmm\RmmState($this->h->module->settings(), $this->sql(), new \RivetCore\Testing\InMemoryRmmModuleState(true, $dir));

        return new HerdControl($this->sql(), $this->h->module->settings(), $state, new DbTokenBucketStore($this->sql()), fn (): int => $this->clock->now()->getTimestamp() * 1000);
    }

    /** @return list<int> the Retry-After of every refusal among 400 devices */
    private function refusals(HerdControl $h): array
    {
        $out = [];
        for ($i = 0; $i < 400; ++$i) {
            try {
                $h->beforeCheckin('Bearer dev-' . $i);
            } catch (ApiError $e) {
                $this->assertSame([503, 'unavailable'], [$e->http, $e->errCode]);
                $out[] = (int) $e->headers['Retry-After'];
            }
        }

        return $out;
    }

    public function testTheWarmStartIsOffByDefaultAndRampsAfterTheModuleIsSwitchedBackOn(): void
    {
        $dir = sys_get_temp_dir() . '/rmm_herd_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            $h = $this->herd($dir);
            $this->assertSame([], $this->refusals($h), 'off by default: nothing is ever refused');
            $this->h->module->settings()->update(['limits_json' => ['warm_start_s' => 600, 'warm_start_pct' => 20, 'warm_quiet_s' => 86400]]);
            $this->assertSame([], $this->refusals($h), 'configured but not started: still nothing');
            $h->startWarmStart();
            $early = $this->refusals($h);
            $this->assertEqualsWithDelta(80.0, count($early) / 4, 8.0, 'about 80 % refused at the start');
            $this->assertGreaterThanOrEqual(10, min($early));
            $this->assertLessThanOrEqual(100, max($early));
            $this->assertGreaterThan(1, count(array_unique($early)), 'the Retry-After values are jittered');
            $this->assertTrue($h->status()['active']);
            $this->clock->advance(300);
            $mid = $this->refusals($h);
            $this->assertEqualsWithDelta(40.0, count($mid) / 4, 9.0, 'about 40 % refused half way (admitted 60 %)');
            $this->clock->advance(400);
            $this->assertSame([], $this->refusals($h), 'after the ramp everybody is in');
            $this->assertFalse($h->status()['active']);
            // an absent credential is never refused here (authentication answers it)
            $h->startWarmStart();
            $h->beforeCheckin(null);
            $h->beforeCheckin('');
            $this->addToAssertionCount(1);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    public function testAQuietGapLongerThanTheLimitStartsAWarmStartAtTheNextRequest(): void
    {
        $dir = sys_get_temp_dir() . '/rmm_herd_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            $this->h->module->settings()->update(['limits_json' => ['warm_start_s' => 600, 'warm_start_pct' => 10, 'warm_quiet_s' => 120]]);
            $h = $this->herd($dir);
            $h->beforeCheckin('Bearer first');   // the first request ever: nothing to compare with
            $this->clock->advance(60);
            $h->beforeCheckin('Bearer first');
            $this->assertFalse($h->status()['active'], 'a minute of quiet is not an outage');
            $this->clock->advance(500);
            $this->assertGreaterThan(300, count($this->refusals($h)), 'after five quiet minutes the first request starts the ramp at 10 %');
            $this->assertTrue($h->status()['active']);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    public function testSwitchingTheModuleOnStartsAWarmStartOnlyOnTheOffToOnTransition(): void
    {
        $this->h->module->settings()->update(['limits_json' => ['warm_start_s' => 300]]);
        $s = $this->h->module->settings();
        $s->enable();
        $this->assertTrue($this->h->module->herd()->status()['active'], 'off to on');
        $this->h->q("DELETE FROM rmm_storage_state WHERE name = 'warm_start'");
        $s->enable();
        $this->assertFalse($this->h->module->herd()->status()['active'], 'on to on is nothing');
        $s->disable();
        $s->enable();
        $this->assertTrue($this->h->module->herd()->status()['active']);
    }

    public function testTheWarmStartRefusesAtTheDeviceApiBeforeAnyQuery(): void
    {
        $tok = $this->h->token(null, 24, 100);
        $this->h->asset(['name' => 'PC', 'serial' => 'WS-1', 'make' => 'Dell']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'WS-1']));
        $T = $j['device_token'];
        $this->h->module->settings()->update(['limits_json' => ['warm_start_s' => 3600, 'warm_start_pct' => 1]]);
        $this->h->module->herd()->startWarmStart();
        $refused = 0;
        $before = $this->h->counting->statements;
        for ($i = 0; $i < 30; ++$i) {
            [$c, $hdr, $r] = $this->h->checkin($T);
            if ($c === 503) {
                ++$refused;
                $this->assertSame('unavailable', $r['code']);
                $this->assertGreaterThanOrEqual(10, (int) $hdr['Retry-After']);
            }
            $this->clock->advance(10);   // a new slot every call
        }
        $this->assertGreaterThan(20, $refused, 'at 1 % almost everything is turned away');
        $this->assertLessThan(4 * 30 * 2, $this->h->counting->statements - $before, 'a refusal costs the settings row and the warm state, nothing else');
    }

    public function testThePerClientTokenBucketRefusesTheBurstOfOneClientOnly(): void
    {
        $this->h->module->settings()->update(['limits_json' => ['client_bucket_burst' => 3, 'client_bucket_per_min' => 6]]);
        $a = $this->h->token($this->h->clientA, 24, 100);
        $b = $this->h->token($this->h->clientB, 24, 100);
        $this->h->asset(['name' => 'A1', 'serial' => 'TB-A1', 'make' => 'Dell']);
        $this->h->asset(['name' => 'B1', 'serial' => 'TB-B1', 'make' => 'Dell', 'client_id' => $this->h->clientB]);
        [$c1, , $da] = $this->h->enroll($a, $this->h::device(['serial' => 'TB-A1']));
        [$c2, , $db] = $this->h->enroll($b, $this->h::device(['serial' => 'TB-B1']));
        $this->assertSame([201, 201], [$c1, $c2]);
        $codes = [];
        for ($i = 0; $i < 6; ++$i) {
            [$c, $hdr] = $this->h->checkin($da['device_token']);
            $codes[] = $c;
            if ($c === 503) {
                $this->assertGreaterThanOrEqual(1, (int) $hdr['Retry-After']);
                $this->assertLessThanOrEqual(300, (int) $hdr['Retry-After']);
            }
        }
        $this->assertSame([200, 200, 200], array_slice($codes, 0, 3), 'the burst of three passes (one token was already used by the enrollment of nothing: enrollment has its own bucket)');
        $this->assertContains(503, array_slice($codes, 3), 'then the client is held');
        [$cb] = $this->h->checkin($db['device_token']);
        $this->assertSame(200, $cb, 'the other client is unaffected');
        $this->clock->advance(70);
        [$again] = $this->h->checkin($da['device_token']);
        $this->assertSame(200, $again, 'tokens refill over time');
    }

    public function testEnrollmentHasItsOwnBucketPerClient(): void
    {
        $this->h->module->settings()->update(['limits_json' => ['client_bucket_burst' => 2, 'client_bucket_per_min' => 1]]);
        $tok = $this->h->token($this->h->clientA, 24, 100);
        $codes = [];
        for ($i = 0; $i < 4; ++$i) {
            [$c, $hdr, $r] = $this->h->enroll($tok, $this->h::device());
            $codes[] = $c;
            if ($c === 503) {
                $this->assertSame('unavailable', $r['code']);
                $this->assertArrayHasKey('Retry-After', $hdr);
            }
        }
        $this->assertSame([201, 201, 503, 503], $codes);
    }

    public function testTheBucketArithmeticAndBothStores(): void
    {
        $r = DbTokenBucketStore::step(0.0, 0, 5, 1.0, 2500);
        $this->assertSame([true, 1.5], [$r['ok'], $r['tokens']]);
        $r = DbTokenBucketStore::step(0.2, 1000, 5, 0.5, 1000);
        $this->assertSame([false, 1600], [$r['ok'], $r['retry_ms']]);
        $this->assertSame(5.0 - 1.0, DbTokenBucketStore::step(5.0, 0, 5, 1.0, 100000)['tokens'], 'never above the burst');
        $db = new DbTokenBucketStore($this->sql());
        $now = 1_800_000_000_000;
        $got = [];
        for ($i = 0; $i < 5; ++$i) {
            $got[] = $db->take('t:1', 3, 1.0, $now)['ok'];
        }
        $this->assertSame([true, true, true, false, false], $got);
        $this->assertTrue($db->take('t:1', 3, 1.0, $now + 1500)['ok']);
        $this->assertTrue($db->take('t:2', 3, 1.0, $now)['ok'], 'buckets are independent');
        if (TestRedis::available()) {
            $redis = new RedisTokenBucketStore(new TestRedis(), 'rmm_test_' . getmypid() . ':');
            $got = [];
            for ($i = 0; $i < 5; ++$i) {
                $got[] = $redis->take('t:1', 3, 1.0, $now)['ok'];
            }
            $this->assertSame([true, true, true, false, false], $got);
            $this->assertTrue($redis->take('t:1', 3, 1.0, $now + 1500)['ok']);
            $this->assertGreaterThan(0, $redis->take('t:1', 3, 1.0, $now + 1500)['retry_ms']);
        }
        // a store that is unreachable fails open
        $this->assertTrue((new RedisTokenBucketStore(new TestRedis(null, true)))->take('k', 1, 1.0, 1)['ok']);
        $this->h->q('DROP TABLE rmm_token_buckets');
        try {
            $this->assertTrue($db->take('t:9', 1, 1.0, 1)['ok']);
        } finally {
            $this->h->q(\RivetCore\Rmm\Migration\ScaleSchema::tables()['rmm_token_buckets']);
        }
    }

    /** @return array{0:int,1:?int} [HTTP status or 0 when the gate let the request through, Retry-After] */
    private function gate(string $dir, string $endpoint, string $auth): array
    {
        $code = '$_SERVER = ["REQUEST_URI" => "/api/v1/' . $endpoint . '.php", "REQUEST_METHOD" => "POST", "HTTP_AUTHORIZATION" => ' . var_export($auth, true) . ']; define("RMM_GATE_STATE_DIR", ' . var_export($dir, true)
            . '); require ' . var_export(dirname(__DIR__, 3) . '/docs/rmm/templates/rmm_gate.php', true) . '; echo "PASSED";';
        $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        proc_close($p);
        if (str_contains($out, 'PASSED')) {
            return [0, null];
        }
        $this->assertStringContainsString('"code":"unavailable"', $out);

        return [503, null];
    }

    public function testTheGateTurnsAwayTheSameDevicesAsTheModuleDuringAWarmStart(): void
    {
        $dir = sys_get_temp_dir() . '/rmm_gate_herd_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            $now = time();
            $state = ['v' => 1, 'enabled' => true, 'edition' => true, 'master' => true, 'features' => [], 'limits' => [], 'shed' => 0, 'shed_at' => $now, 'shed_retry' => [60, 300], 'retry_after' => 3600,
                'ingest_mode' => 'sync', 'written_at' => $now];
            file_put_contents($dir . '/rmm_state.json', json_encode($state));
            $this->assertSame([0, null], $this->gate($dir, 'agent_checkin', 'Bearer a'), 'no warm file: the request passes');
            file_put_contents($dir . '/rmm_herd.json', json_encode(['from' => $now, 'until' => $now + 600, 'pct' => 30, 'seen' => $now]));
            $turned = 0;
            $compared = 0;
            for ($i = 0; $i < 120; ++$i) {
                $auth = 'Bearer dev-' . $i;
                $t = time();
                $mod = !HerdControl::admits($auth, $t, HerdControl::admittedPercent($t - $now, 600, 30));
                [$gateCode] = $this->gate($dir, 'agent_checkin', $auth);
                if (time() !== $t) {
                    continue;   // the clock moved between the two evaluations (slot or ramp): they may legitimately differ
                }
                ++$compared;
                $this->assertSame($mod ? 503 : 0, $gateCode, $auth);
                $turned += $mod ? 1 : 0;
            }
            $this->assertGreaterThan(20, $compared);
            $this->assertGreaterThan((int) ($compared * 0.45), $turned);
            $this->assertLessThan((int) ($compared * 0.92), $turned);
            $this->assertSame([0, null], $this->gate($dir, 'agent_enroll', 'Bearer dev-1'), 'only check-ins are held back');
            $this->assertSame([0, null], $this->gate($dir, 'agent_jobs', 'Bearer dev-1'));
            file_put_contents($dir . '/rmm_herd.json', json_encode(['from' => $now - 700, 'until' => $now - 100, 'pct' => 1, 'seen' => $now]));
            $this->assertSame([0, null], $this->gate($dir, 'agent_checkin', 'Bearer dev-3'), 'a finished warm start lets everybody in');
            file_put_contents($dir . '/rmm_herd.json', '{garbage');
            $this->assertSame([0, null], $this->gate($dir, 'agent_checkin', 'Bearer dev-3'), 'a damaged file is "unknown", never a refusal');
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }
}
