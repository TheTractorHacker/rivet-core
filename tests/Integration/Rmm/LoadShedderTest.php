<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Capacity\LoadShedder;
use RivetCore\Rmm\RmmStateFile;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Testing\InMemoryRmmModuleState;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\TempDir;

/** Staged load shedding: levels from the signals, hysteresis, persistence, the request-path tick, and the effect on check-ins. */
final class LoadShedderTest extends RmmTestCase
{
    private string $dir = '';
    private MutableClock $clock;
    private float $probeMs = 1.0;

    protected function makeHarness(): RmmHarness
    {
        $this->dir = TempDir::make();
        $this->clock = new MutableClock();

        return new RmmHarness($this->clock, new InMemoryRmmModuleState(true, $this->dir));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TempDir::remove($this->dir);
    }

    private function shedder(): LoadShedder
    {
        $m = $this->h->module;

        return new LoadShedder($m->sql(), $m->settings(), $m->ingestQueue(), $m->state(), $this->h->audit, fn (): float => $this->probeMs);
    }

    /** @return array{backlog:int,db_ms:float,rate_per_min:int} */
    private static function sig(int $backlog = 0, float $ms = 1.0, int $rate = 0): array
    {
        return ['backlog' => $backlog, 'db_ms' => $ms, 'rate_per_min' => $rate];
    }

    public function testRawLevelFollowsTheThresholdsOfEachSignal(): void
    {
        $l = RmmSettings::LIMIT_DEFAULTS;   // backlog 500/2000/8000, db 100/300/1000 ms, rate off
        $this->assertSame(0, LoadShedder::rawLevel($l, self::sig(499, 99.9)));
        $this->assertSame(1, LoadShedder::rawLevel($l, self::sig(500)));
        $this->assertSame(2, LoadShedder::rawLevel($l, self::sig(2000)));
        $this->assertSame(3, LoadShedder::rawLevel($l, self::sig(8000)));
        $this->assertSame(1, LoadShedder::rawLevel($l, self::sig(0, 100.0)));
        $this->assertSame(3, LoadShedder::rawLevel($l, self::sig(0, 5000.0)));
        $this->assertSame(2, LoadShedder::rawLevel($l, self::sig(600, 350.0)), 'the highest signal wins');
        $this->assertSame(0, LoadShedder::rawLevel($l, self::sig(0, 1.0, 1000000)), 'the rate signal is off by default');
        $l['shed_rate_per_min'] = 100;
        $this->assertSame([0, 1, 2, 3], [LoadShedder::rawLevel($l, self::sig(0, 1.0, 99)), LoadShedder::rawLevel($l, self::sig(0, 1.0, 100)),
            LoadShedder::rawLevel($l, self::sig(0, 1.0, 200)), LoadShedder::rawLevel($l, self::sig(0, 1.0, 400))], 'rate: 1x, 2x, 4x the base');
        $l['shed_backlog_l2'] = 0;
        $this->assertSame(1, LoadShedder::rawLevel($l, self::sig(5000)), 'a zero threshold means that level never triggers on that signal');
    }

    public function testHysteresisEscalatesAtOnceAndRecoversOneLevelPerTwoHealthyEvaluations(): void
    {
        $this->assertSame([3, 0], LoadShedder::step(0, 3, 0), 'escalation is immediate and may skip levels');
        $this->assertSame([2, 0], LoadShedder::step(2, 2, 1), 'still at the level: the streak resets');
        $this->assertSame([3, 1], LoadShedder::step(3, 0, 0), 'one healthy evaluation changes nothing');
        $this->assertSame([2, 0], LoadShedder::step(3, 0, 1), 'two consecutive healthy evaluations step down ONE level');
        $this->assertSame([2, 1], LoadShedder::step(2, 1, 0), 'a raw level below the current counts as healthy');
        // a flapping signal never recovers
        $level = 3;
        $streak = 0;
        foreach ([0, 3, 0, 3, 0, 3] as $raw) {
            [$level, $streak] = LoadShedder::step($level, $raw, $streak);
        }
        $this->assertSame(3, $level);
        // and the full walk down from L3 takes six healthy evaluations
        $level = 3;
        $streak = 0;
        $n = 0;
        while ($level > 0) {
            [$level, $streak] = LoadShedder::step($level, 0, $streak);
            ++$n;
        }
        $this->assertSame(6, $n);
    }

    public function testEvaluateWritesTheLevelAuditsAndMirrorsItIntoTheStateFile(): void
    {
        $this->h->enable();
        $s = $this->shedder();
        $r = $s->evaluate(self::sig(600));
        $this->assertSame([1, 0, true], [$r['level'], $r['previous'], $r['changed']]);
        $this->assertSame(1, (int) $this->h->one('SELECT shed_level FROM endpoint_agent_settings'));
        $this->assertSame(1, RmmStateFile::read($this->dir)['shed']);
        $this->assertGreaterThan(0, RmmStateFile::read($this->dir)['shed_at']);
        $this->assertSame('RMM Load Shed Level Changed', $this->h->audit->records()[array_key_last($this->h->audit->records())]['action']);

        $r = $s->evaluate(self::sig(9000));
        $this->assertSame([3, 1], [$r['level'], $r['previous']]);
        // recovery needs two healthy evaluations, and the streak survives between evaluations (the cron is a new process each minute)
        $this->assertSame(3, $this->shedder()->evaluate(self::sig())['level']);
        $this->assertSame(1, $this->shedder()->readMemory($this->dir)['streak']);
        $this->assertSame(2, $this->shedder()->evaluate(self::sig())['level']);
        $this->assertSame(2, (int) $this->h->one('SELECT shed_level FROM endpoint_agent_settings'));
        $this->assertSame(2, RmmStateFile::read($this->dir)['shed']);
        $transitions = array_filter($this->h->audit->records(), static fn (array $r): bool => $r['action'] === 'RMM Load Shed Level Changed');
        $this->assertCount(3, $transitions);
    }

    public function testWithoutAStateDirectoryEachHealthyEvaluationStepsDown(): void
    {
        $h = new RmmHarness(null, new InMemoryRmmModuleState(true, null));
        $h->enable();
        $m = $h->module;
        $s = new LoadShedder($m->sql(), $m->settings(), $m->ingestQueue(), $m->state(), $h->audit, static fn (): float => 1.0);
        $this->assertSame(3, $s->evaluate(self::sig(9000))['level']);
        $this->assertSame(2, $s->evaluate(self::sig())['level']);
        $this->assertNull($s->tick(), 'the request-path tick needs a state directory');
    }

    public function testRealSignalsBacklogDatabaseProbeAndCheckinRate(): void
    {
        $this->h->enable();
        $s = $this->shedder();
        $this->assertSame(self::sig(0, 1.0, 0), $s->signals());
        $this->h->module->settings()->update(['ingest_mode' => 'queued']);
        $q = $this->h->module->ingestQueue();
        for ($i = 0; $i < 3; ++$i) {
            $q->enqueue(['device_id' => 1]);
        }
        $this->probeMs = 12.5;
        $tok = $this->h->token(null, 24, 10);
        $this->h->asset(['name' => 'A', 'serial' => 'SS-1', 'make' => 'Dell']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SS-1']));
        $this->h->checkin($j['device_token']);
        $this->h->checkin($j['device_token']);
        $sig = $s->signals();
        $this->assertSame([5, 12.5, 2], [$sig['backlog'], $sig['db_ms'], $sig['rate_per_min']], 'three queued jobs plus the two check-ins just made');
        $this->assertSame([], $this->h->module->settings()->update(['limits_json' => '{"shed_backlog_l1":4,"shed_backlog_l2":10,"shed_backlog_l3":20}']));
        $this->assertSame(1, LoadShedder::rawLevel($this->h->module->settings()->limits(), $sig));
    }

    public function testTheRequestPathTickEvaluatesAtMostOncePerTenSeconds(): void
    {
        $this->h->enable();
        $s = $this->shedder();
        $first = $s->tick();
        $this->assertNotNull($first, 'the first tick evaluates');
        $this->assertNull($s->tick(), 'a second tick inside 10 s does nothing');
        $this->clock->advance(9);
        $this->assertNull($s->tick());
        $this->clock->advance(2);
        $this->assertNotNull($s->tick());
        // a request that cannot take the lock does not evaluate (another request is already doing it)
        $this->clock->advance(11);
        $lock = fopen($this->dir . '/' . LoadShedder::LOCK_FILE, 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $this->assertNull($s->tick());
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertNotNull($s->tick());
    }

    public function testEndToEndLoweredLimitsMakeCheckinsStretchThenGetRefusedAndRecover(): void
    {
        $tok = $this->h->token(null, 24, 10);
        $this->h->asset(['name' => 'A', 'serial' => 'EE-1', 'make' => 'Dell']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'EE-1']));
        $T = $j['device_token'];
        $set = $this->h->module->settings();
        // thresholds on the check-in rate: 3 / minute is level 1, 6 level 2, 12 level 3
        $this->assertSame([], $set->update(['limits_json' => '{"shed_rate_per_min":3,"shed_retry_min_s":61,"shed_retry_max_s":63}']));
        $this->h->checkin($T);
        $this->h->checkin($T);
        $this->h->checkin($T);
        $this->clock->advance(11);
        [$c, , $r] = $this->h->checkin($T);
        $this->assertSame([200, 300], [$c, $r['next_check_in_s']], 'the first evaluation (4 check-ins/min) raised level 1: samples are dropped, intervals not touched');
        $this->assertSame(1, (int) $this->h->one('SELECT shed_level FROM endpoint_agent_settings'));
        for ($i = 0; $i < 3; ++$i) {
            $this->h->checkin($T);
        }
        $this->clock->advance(11);
        [$c, , $r] = $this->h->checkin($T);
        $this->assertSame([200, 600], [$c, $r['next_check_in_s']], 'level 2 lengthens the interval');
        for ($i = 0; $i < 6; ++$i) {
            $this->h->checkin($T);
        }
        $this->clock->advance(11);
        [$c, $hd, $r] = $this->h->checkin($T);   // this request's tick lifts the level to 3 and the same request is refused
        $this->assertSame([503, 'unavailable'], [$c, $r['code']]);
        $this->assertGreaterThanOrEqual(61, (int) $hd['Retry-After']);
        $this->assertLessThanOrEqual(63, (int) $hd['Retry-After']);
        $this->assertSame(3, (int) $this->h->one('SELECT shed_level FROM endpoint_agent_settings'));
        $this->assertSame(3, RmmStateFile::read($this->dir)['shed'], 'the gate sees the same level without the database');
        // load drops (the rate window empties): the level walks down one step per two healthy ticks while refused requests keep ticking
        $this->h->q('DELETE FROM endpoint_agent_checkins');
        $this->assertSame([], $set->update(['limits_json' => '{"shed_rate_per_min":1000,"shed_retry_min_s":61,"shed_retry_max_s":63}']), 'the administrator raises the threshold');
        $levels = [];
        for ($i = 0; $i < 8; ++$i) {
            $this->clock->advance(11);
            $this->h->checkin($T);
            $levels[] = (int) $this->h->one('SELECT shed_level FROM endpoint_agent_settings');
        }
        $this->assertSame(0, end($levels), 'recovered');
        $sorted = $levels;
        rsort($sorted);
        $this->assertSame($sorted, $levels, 'monotone recovery, no flapping');
        [$c] = $this->h->checkin($T);
        $this->assertSame(200, $c);
    }

    public function testHousekeepingRunsTheEvaluationAndReportsTheLevel(): void
    {
        $this->h->enable();
        $this->h->module->settings()->update(['limits_json' => '{"shed_backlog_l1":1,"shed_backlog_l2":100,"shed_backlog_l3":200}']);
        $this->h->module->ingestQueue()->enqueue(['x' => 1]);
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['shed_level']);
        $this->h->q("UPDATE integration_jobs SET status='completed'");
        $this->h->module->housekeeping()->run();
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(0, $out['shed_level'], 'two healthy runs later');
    }

    public function testThresholdValidationRefusesDecreasingLevels(): void
    {
        foreach (['{"shed_backlog_l1":500,"shed_backlog_l2":100}', '{"shed_db_ms_l2":2000}', '{"shed_backlog_l3":10}'] as $bad) {
            $this->assertNotNull(RmmSettings::validateLimits($bad)[1], $bad);
        }
        $this->assertNull(RmmSettings::validateLimits('{"shed_backlog_l1":0,"shed_backlog_l2":50,"shed_backlog_l3":60}')[1], 'zero means off for that level and is not ordered');
        $this->assertNull(RmmSettings::validateLimits('{"shed_rate_per_min":500}')[1]);
    }
}
