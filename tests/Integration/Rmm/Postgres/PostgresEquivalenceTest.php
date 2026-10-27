<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\ChangeCriteria;
use RivetCore\Rmm\Store\LogCriteria;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\RmmDataStoreFactory;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Store\RmmDataStores;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Tests\Support\RmmHarness;

/**
 * THE equivalence test of phase 2 (#89): the same pseudo-random event streams are fed, in the same order, to the MariaDB stores and to the PostgreSQL stores, and EVERY read
 * the stores offer is compared (after normalising the number types of the two drivers). A difference is a bug in one of the two or a rule to write down in
 * docs/design/rmm-data-store.md ("Equivalence rules"); the few that are written down there are listed in the comments and the assertions below.
 *
 * Streams are seeded (the seed is in every failure message), so a failure is reproducible.
 */
final class PostgresEquivalenceTest extends TestCase
{
    private const T = 1_800_000_000;
    private const DAY = 86400;
    private const CAPS = ['rate_per_min' => 400, 'device_max_rows' => 300, 'fleet_max_rows' => 600];

    private RmmHarness $h;
    private PostgresConnectionInterface $pg;
    private RmmDataStores $maria;
    private RmmDataStores $postgres;
    private string $previousLog = '';
    private int $seed = 0;
    /** How many non-empty results were compared: a comparison of two empty answers proves nothing, so each test asserts a floor. */
    private int $compared = 0;

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        if (!getenv('RIVETCORE_TEST_DB_NAME') || !str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME (a scratch MariaDB) is required to compare the two stores.');
        }
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');
        $this->h = new RmmHarness();
        $this->h->enable();
        $this->pg = PostgresTestDatabase::connect();
        PostgresTestDatabase::prepare($this->pg);
        $sink = new DatabaseMetricSink($this->h->db, $this->h->clock);
        $this->maria = RmmDataStoreFactory::create(RmmDataStoreKind::MARIADB, $this->h->module->sql(), $sink);
        $this->postgres = RmmDataStoreFactory::create(RmmDataStoreKind::POSTGRES, $this->h->module->sql(), new DatabaseMetricSink($this->h->db, $this->h->clock), null, $this->pg);
    }

    protected function tearDown(): void
    {
        if (isset($this->h)) {
            RmmHarness::closeAll();
            ini_set('error_log', $this->previousLog);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function device(int $clientId, string $hostname): int
    {
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 50), $this->h::device(['hostname' => $hostname]));
        $id = (int) $j['device_id'];
        $this->h->q("UPDATE endpoint_agent_devices SET client_id = $clientId, hostname = '" . $this->h->mysqli->real_escape_string($hostname) . "' WHERE device_id = $id");

        return $id;
    }

    private function rnd(int $lo, int $hi): int
    {
        return mt_rand($lo, $hi);
    }

    /** @param list<mixed> $items */
    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /** Make the two drivers' answers comparable: numbers as numbers, booleans as ints, the rest as strings; floats to 9 significant digits. */
    private function norm(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map($this->norm(...), $v);
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->getTimestamp();
        }
        if (is_bool($v)) {
            return (int) $v;
        }
        if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $v) === 1)) {
            return rtrim(rtrim(sprintf('%.9g', (float) $v), '0'), '.') ?: '0';
        }

        return $v;
    }

    /**
     * Compare two read results. Rows are compared on the columns both stores return; the columns only MariaDB returns must be known ones.
     *
     * @param array<mixed> $a MariaDB
     * @param array<mixed> $b PostgreSQL
     * @param list<string> $mariaOnly columns allowed to exist on the MariaDB side only
     */
    private function same(string $what, array $a, array $b, array $mariaOnly = [], array $ignore = []): void
    {
        $strip = function (array $rows, bool $isMaria) use ($mariaOnly, $ignore): array {
            return array_map(function ($r) use ($mariaOnly, $ignore, $isMaria) {
                if (!is_array($r)) {
                    return $r;
                }
                foreach ($ignore as $k) {
                    unset($r[$k]);
                }
                if ($isMaria) {
                    foreach ($mariaOnly as $k) {
                        unset($r[$k]);
                    }
                }

                return $r;
            }, $rows);
        };
        $this->assertEquals($this->norm($strip($a, true)), $this->norm($strip($b, false)), "[seed {$this->seed}] $what differs between MariaDB and PostgreSQL");
        if ($a !== [] && $a !== [[]]) {
            ++$this->compared;
        }
    }

    // ------------------------------------------------------------------ metrics

    public function testMetricStreamsGiveTheSameLatestSeriesPeakAndDailyTier(): void
    {
        foreach ([11, 2027, 99] as $seed) {
            $this->setUp2($seed);
            $now = $this->h->clock->now()->getTimestamp();
            $assets = [];
            for ($i = 0; $i < 6; ++$i) {
                $assets[] = $this->rnd(1000, 1060);   // clustered ids, like a real fleet: the rollup walks the id range in slices of 500
            }
            $keys = ['cpu.utilization', 'memory.utilization', 'disk.utilization', 'disk.free_bytes', 'network.rx_bytes_per_s', 'system.uptime_seconds', 'bad key!'];
            $instances = [null, null, 'C:', 'D:', 'eth0'];
            for ($batch = 0; $batch < 40; ++$batch) {
                $samples = [];
                for ($n = $this->rnd(1, 60); $n > 0; --$n) {
                    $key = $this->pick($keys);
                    $value = $this->pick([$this->rnd(0, 100), $this->rnd(0, 100) + 0.37, $this->rnd(0, 5_000_000_000), -5, 101, NAN, 0, 100]);
                    $samples[] = ['asset_id' => $this->pick([...$assets, 0]), 'key' => $key, 'instance' => $this->pick($instances), 'value' => $value,
                        'at' => new \DateTimeImmutable('@' . ($now - $this->rnd(0, 40 * self::DAY))), 'label' => $this->pick([null, 'System', 'Data', str_repeat('x', 80)])];
                }
                $this->maria->metrics()?->ingest($samples, 1);
                $this->postgres->metrics()?->ingest($samples, 1);
            }
            $ra = $this->maria->metrics()->rollUp(30);
            $rb = $this->postgres->metrics()->rollUp(30);
            $this->assertSame($ra['days'], $rb['days'], "[seed $seed] days rolled up");
            $this->assertSame($ra['slices'], $rb['slices'], "[seed $seed] slices");
            $this->assertSame($ra['watermark'], $rb['watermark'], "[seed $seed] watermark");
            $this->assertSame($ra['behind_days'], $rb['behind_days']);
            $this->assertSame($this->maria->metrics()->rollupWatermark(), $this->postgres->metrics()->rollupWatermark());
            $this->assertSame(0, $this->maria->metrics()->rollUp(30)['days']);
            $this->assertSame(0, $this->postgres->metrics()->rollUp(30)['days']);
            foreach ($assets as $asset) {
                $this->same("latest($asset)", $this->latest($this->maria, $asset), $this->latest($this->postgres, $asset));
                $this->same("latest($asset, cpu)", $this->maria->metrics()->latest($asset, ['cpu.utilization', 'disk.utilization']), $this->postgres->metrics()->latest($asset, ['cpu.utilization', 'disk.utilization']));
                foreach (['cpu.utilization', 'memory.utilization', 'disk.utilization', 'disk.free_bytes', 'network.rx_bytes_per_s', 'system.uptime_seconds'] as $key) {
                    foreach ($instances as $inst) {
                        foreach ([3600 * 5, 3 * self::DAY, 7 * self::DAY + 3600, 20 * self::DAY, 39 * self::DAY] as $window) {
                            $since = new \DateTimeImmutable('@' . ($now - $window));
                            $until = new \DateTimeImmutable('@' . $now);
                            $this->same("peak($asset,$key," . ($inst ?? 'null') . ",$window)", [$this->maria->metrics()->peak($asset, $key, $inst, $since) ?? []], [$this->postgres->metrics()->peak($asset, $key, $inst, $since) ?? []]);
                            $this->same("series($asset,$key," . ($inst ?? 'null') . ",$window)", $this->maria->metrics()->series($asset, $key, $inst, $since, $until), $this->postgres->metrics()->series($asset, $key, $inst, $since, $until));
                        }
                    }
                }
            }
            // retention
            $pa = $this->maria->metrics()->prune(100000, null, 14, 30);
            $pb = $this->postgres->metrics()->prune(100000, null, 14, 30);
            $this->assertSame($pa, $pb, "[seed $seed] rows pruned");
            foreach ($assets as $asset) {
                $this->same("latest after prune($asset)", $this->latest($this->maria, $asset), $this->latest($this->postgres, $asset));
                $since = new \DateTimeImmutable('@' . ($now - 45 * self::DAY));
                $until = new \DateTimeImmutable('@' . $now);
                $this->same("series after prune($asset)", $this->maria->metrics()->series($asset, 'cpu.utilization', null, $since, $until), $this->postgres->metrics()->series($asset, 'cpu.utilization', null, $since, $until));
            }
        }
        $this->assertGreaterThan(300, $this->compared, 'the streams produced real data to compare');
    }

    /** @return list<array<string,mixed>> */
    private function latest(RmmDataStores $s, int $asset): array
    {
        return $s->metrics()->latest($asset);
    }

    /** A fresh pair of empty stores for the next seed. */
    private function setUp2(int $seed): void
    {
        $this->seed = $seed;
        mt_srand($seed);
        $this->h->wipe();
        PostgresTestDatabase::prepare($this->pg);
        $this->h->q('DELETE FROM rmm_metric_latest');
        $this->h->q('DELETE FROM rmm_metric_hourly');
        $this->h->q('DELETE FROM rmm_metric_daily');
        $this->h->q("DELETE FROM rmm_storage_state WHERE name = 'metric_daily_rollup'");
        $this->h->q("DELETE FROM rmm_metric_rollup_state WHERE name = 'metric_daily_rollup'");
    }

    // ------------------------------------------------------------------ check history, software, patch, deployment, hypervisor

    public function testHistoryStreamsGiveTheSameReadsAndRetention(): void
    {
        foreach ([5, 31337] as $seed) {
            $this->setUp2($seed);
            $devs = [$this->device(1, 'EQ-1'), $this->device(2, 'EQ-2'), $this->device(1, 'EQ-3')];
            $pkg = $this->h->mysqli->query("INSERT INTO rmm_packages (name, created_at, updated_at) VALUES ('Eq Pkg', NOW(), NOW())") ? (int) $this->h->mysqli->insert_id : 0;
            $vms = [];
            foreach ($devs as $d) {
                $this->h->q("INSERT INTO rmm_hypervisor_vms (device_id, vm_key, first_seen_at, last_seen_at) VALUES ($d, 'qemu/" . $this->rnd(1, 999999) . "', NOW(), NOW())");
                $vms[$d] = (int) $this->h->mysqli->insert_id;
            }
            for ($i = 0; $i < 60; ++$i) {
                $d = $this->pick($devs);
                $entries = [];
                for ($n = $this->rnd(0, 8); $n > 0; --$n) {
                    $entries[] = ['key' => $this->pick(['cpu', 'disk_c', 'svc']), 'status' => $this->pick(['ok', 'warn', 'fail']), 'detail' => 'd' . $this->rnd(0, 50), 'at' => self::utc(self::T + $this->rnd(0, 3 * self::DAY))];
                }
                foreach ([$this->maria, $this->postgres] as $s) {
                    $s->checkHistory()->append($d, $entries);
                }
                $changes = [];
                for ($n = $this->rnd(0, 5); $n > 0; --$n) {
                    $changes[] = ['key' => 'k' . $this->rnd(0, 40), 'name' => $this->pick(['App ', '100% pure_', 'Edge ']) . $this->rnd(0, 30), 'source' => $this->pick(['msi', 'store']), 'publisher' => 'P',
                        'type' => $this->pick(['installed', 'upgraded', 'removed']), 'old' => $this->pick([null, '1.0']), 'new' => $this->pick([null, '2.0'])];
                }
                $at = self::utc(self::T + $this->rnd(0, 3 * self::DAY));
                foreach ([$this->maria, $this->postgres] as $s) {
                    $s->softwareHistory()->append($d, $changes, $at);
                }
                $pEntry = ['device_id' => $d, 'client_id' => $d % 2, 'update_id' => 'KB' . $this->rnd(1, 20), 'title' => 'T' . $i, 'action' => 'install', 'result' => $this->pick(['ok', 'failed']), 'code' => '', 'detail' => 'x',
                    'at' => self::utc(self::T + $this->rnd(0, 3 * self::DAY)), 'job_id' => $this->pick([null, 'job-' . $i]), 'user_id' => $this->pick([null, 3])];
                $dEntry = ['device_id' => $d, 'client_id' => 1, 'package_id' => $this->pick([$pkg, $pkg + 77]), 'version_id' => $this->pick([null, 4]), 'action' => 'install', 'mode' => 'live', 'result' => 'installed', 'code' => '',
                    'detail' => 'd', 'version' => '1.' . $i, 'at' => $pEntry['at'], 'job_id' => $pEntry['job_id'] === null ? null : 'j' . $i, 'user_id' => $pEntry['user_id']];
                $from = $this->pick(['running', 'stopped', 'paused']);
                $to = $this->pick(['running', 'stopped', 'paused']);
                foreach ([$this->maria, $this->postgres] as $s) {
                    $s->patchHistory()->record($pEntry);
                    $s->deploymentHistory()->record($dEntry);
                    $s->hypervisorHistory()->recordTransition($vms[$d], $d, self::utc(self::T + $i), $from, $to, 4);
                }
            }
            $window = [self::utc(self::T - 10), self::utc(self::T + 4 * self::DAY)];
            foreach ($devs as $d) {
                foreach (['cpu', 'disk_c', 'svc', 'none'] as $key) {
                    $this->same("check since($d,$key)", $this->maria->checkHistory()->since($d, $key, $window[0], 500), $this->postgres->checkHistory()->since($d, $key, $window[0], 500));
                    $this->same("check lastBefore($d,$key)", [$this->maria->checkHistory()->lastBefore($d, $key, self::utc(self::T + self::DAY)) ?? []], [$this->postgres->checkHistory()->lastBefore($d, $key, self::utc(self::T + self::DAY)) ?? []]);
                }
                foreach ([null, 'app', '100%', 'pure_', '  edge  '] as $like) {
                    $a = $this->maria->softwareHistory()->forDevice($d, $like, 500, 0);
                    $b = $this->postgres->softwareHistory()->forDevice($d, $like, 500, 0);
                    $this->assertSame($a['total'], $b['total'], "[seed $seed] software total($d," . var_export($like, true) . ')');
                    $this->same("software forDevice($d)", $a['rows'], $b['rows'], [], ['history_id']);
                }
                $this->same("software page($d)", $this->maria->softwareHistory()->forDevice($d, null, 7, 5)['rows'], $this->postgres->softwareHistory()->forDevice($d, null, 7, 5)['rows'], [], ['history_id']);
                $this->same("software between($d)", $this->maria->softwareHistory()->between($d, $window[0], $window[1], 100), $this->postgres->softwareHistory()->between($d, $window[0], $window[1], 100), [], ['history_id']);
                $pa = $this->maria->patchHistory()->forDevice($d, 50, 3);
                $pb = $this->postgres->patchHistory()->forDevice($d, 50, 3);
                $this->assertSame($pa['total'], $pb['total']);
                $this->same("patch($d)", $pa['rows'], $pb['rows'], [], ['id']);
                $da = $this->maria->deploymentHistory()->forDevice($d, 50, 0);
                $db = $this->postgres->deploymentHistory()->forDevice($d, 50, 0);
                $this->assertSame($da['total'], $db['total']);
                $this->same("deployment($d)", $da['rows'], $db['rows'], [], ['id']);
                $this->same("hypervisor recent($d)", $this->maria->hypervisorHistory()->recent($vms[$d], 100), $this->postgres->hypervisorHistory()->recent($vms[$d], 100));
            }
            // retention
            $cutoff = self::utc(self::T + 1 * self::DAY);
            foreach ([['checkHistory', 5], ['softwareHistory', 7], ['patchHistory', 3], ['deploymentHistory', 100]] as [$store, $limit]) {
                $this->assertSame($this->maria->$store()->deleteOlderThan($cutoff, $limit), $this->postgres->$store()->deleteOlderThan($cutoff, $limit), "[seed $seed] $store prune with a limit");
                $this->assertSame($this->maria->$store()->deleteOlderThan($cutoff, 100000), $this->postgres->$store()->deleteOlderThan($cutoff, 100000), "[seed $seed] $store prune the rest");
            }
            foreach ($devs as $d) {
                $this->assertSame($this->maria->softwareHistory()->forDevice($d, null, 1, 0)['total'], $this->postgres->softwareHistory()->forDevice($d, null, 1, 0)['total']);
                $this->assertSame($this->maria->patchHistory()->forDevice($d, 1, 0)['total'], $this->postgres->patchHistory()->forDevice($d, 1, 0)['total']);
            }
            // orphans: forget one VM
            $this->h->q('DELETE FROM rmm_hypervisor_vms WHERE vm_id = ' . $vms[$devs[0]]);
            $exists = function (array $ids): array {
                return $ids === [] ? [] : array_map('intval', array_column($this->h->rows('SELECT vm_id FROM rmm_hypervisor_vms WHERE vm_id IN (' . implode(',', array_map('intval', $ids)) . ')'), 'vm_id'));
            };
            $this->assertSame($this->maria->hypervisorHistory()->deleteOrphans(), $this->postgres->hypervisorHistory()->deleteOrphans($exists), "[seed $seed] orphans");
        }
        $this->assertGreaterThan(60, $this->compared, 'the streams produced real data to compare');
    }

    // ------------------------------------------------------------------ logs and change records

    public function testLogStreamsGiveTheSameAdmissionLedgerSearchesAndRetention(): void
    {
        foreach ([7, 4242, 8] as $seed) {
            $this->setUp2($seed);
            $devs = [$this->device(1, 'LQ-1'), $this->device(2, 'LQ-2'), $this->device(3, 'LQ-3')];
            $clientOf = [$devs[0] => 1, $devs[1] => 2, $devs[2] => 3];
            $sources = ['Kernel', 'Service Control', 'Disk', 'Net_work', 'App%Core', "O'Brien"];
            $messages = ['disk failing', 'The 100% service entered_the running state', 'link up', 'link down', 'boom', 'Ünïcode msg', ''];
            for ($i = 0; $i < 70; ++$i) {
                $d = $this->pick($devs);
                $entries = [];
                for ($n = $this->rnd(0, 25); $n > 0; --$n) {
                    $ts = self::T + $this->rnd(0, 2 * self::DAY);
                    $lvl = $this->rnd(1, 6);
                    $src = $this->pick($sources);
                    $code = $this->pick([null, 100, 7036]);
                    $msg = $this->pick($messages);
                    $entries[] = ['ts' => $ts, 'level' => $lvl, 'source' => $src, 'code' => $code, 'message' => $msg, 'hash' => substr(hash('sha256', "$ts|$lvl|$src|$code|$msg"), 0, 32)];
                }
                if ($entries !== [] && $this->rnd(0, 4) === 0) {
                    $entries[] = $entries[0];   // a repeat in the same batch
                }
                $nowTs = self::T + $i * 17;
                $rejected = $this->rnd(0, 2);
                $agent = $this->rnd(0, 3);
                $a = $this->maria->logs()->admit($d, $entries, self::CAPS, $nowTs, $rejected, $agent, $clientOf[$d]);
                $b = $this->postgres->logs()->admit($d, $entries, self::CAPS, $nowTs, $rejected, $agent, $clientOf[$d]);
                $this->assertSame($a['inserted'], $b['inserted'], "[seed $seed] batch $i inserted");
                $this->assertSame($a['dropped'], $b['dropped'], "[seed $seed] batch $i dropped");
                $this->assertSame(count($a['kept']), count($b['kept']), "[seed $seed] batch $i kept");
            }
            foreach ($devs as $d) {
                $this->assertSame($this->maria->logs()->deviceLedger($d), $this->postgres->logs()->deviceLedger($d), "[seed $seed] ledger($d)");
            }
            $this->assertSame($this->maria->logs()->fleetRowCount(), $this->postgres->logs()->fleetRowCount());
            $this->assertSame($this->maria->logs()->oldestEventAt(), $this->postgres->logs()->oldestEventAt());
            foreach ($devs as $d) {
                $this->assertSame($this->maria->logs()->oldestEventAt($d), $this->postgres->logs()->oldestEventAt($d));
            }
            $win = [self::utc(self::T), self::utc(self::T + 2 * self::DAY + 100)];
            $criteria = [
                [], ['deviceId' => $devs[0]], ['clientId' => 2], ['visibleClientIds' => [1, 3]], ['visibleClientIds' => []], ['maxLevel' => 2], ['sourceContains' => 'kern'], ['sourceContains' => 'Net_work'],
                ['sourceContains' => 'App%'], ['sourceContains' => "o'b"], ['code' => 7036], ['messageContains' => '100%'], ['messageContains' => 'entered_the'], ['messageContains' => 'enteredXthe'],
                ['messageContains' => 'link'], ['maxLevel' => 3, 'sourceContains' => 'disk', 'messageContains' => 'FAIL'], ['deviceId' => $devs[1], 'clientId' => 1],
            ];
            foreach ($criteria as $c) {
                foreach ([[100, 0], [7, 3], [500, 0]] as [$limit, $offset]) {
                    $a = $this->maria->logs()->search(new LogCriteria($win[0], $win[1], ...$c), $limit, $offset);
                    $b = $this->postgres->logs()->search(new LogCriteria($win[0], $win[1], ...$c), $limit, $offset);
                    $this->assertSame($a['total'], $b['total'], "[seed $seed] log total " . json_encode($c));
                    $this->same('log search ' . json_encode($c), $a['rows'], $b['rows'], [], ['event_id']);
                }
            }
            foreach ($devs as $d) {
                foreach ([[5, '', null, ''], [2, 'kern', 100, 'disk'], [5, '', null, '100%'], [6, 'service', null, 'running']] as [$lvl, $src, $code, $msg]) {
                    $this->assertSame($this->maria->logs()->countMatching($d, self::utc(self::T), $lvl, $src, $code, $msg, 50), $this->postgres->logs()->countMatching($d, self::utc(self::T), $lvl, $src, $code, $msg, 50), "[seed $seed] countMatching($d)");
                }
            }
            // retention and caps
            $cut = self::utc(self::T + self::DAY);
            $pa = $this->maria->logs()->enforceLimits($cut, 40, 120, 500, 25);
            $pb = $this->postgres->logs()->enforceLimits($cut, 40, 120, 500, 25);
            $this->assertSame($pa, $pb, "[seed $seed] enforceLimits");
            foreach ($devs as $d) {
                $this->assertSame($this->maria->logs()->deviceLedger($d)['rows'], $this->postgres->logs()->deviceLedger($d)['rows']);
            }
            $a = $this->maria->logs()->search(new LogCriteria($win[0], $win[1]), 500, 0);
            $b = $this->postgres->logs()->search(new LogCriteria($win[0], $win[1]), 500, 0);
            $this->assertSame($a['total'], $b['total']);
            $this->same('log search after prune', $a['rows'], $b['rows'], [], ['event_id']);
        }
        $this->assertGreaterThan(40, $this->compared, 'the streams produced real data to compare');
    }

    public function testChangeStreamsGiveTheSameAdmissionLedgerSearchesTimelinesAndRetention(): void
    {
        foreach ([3, 1234] as $seed) {
            $this->setUp2($seed);
            $devs = [$this->device(1, 'CQ-1'), $this->device(2, 'CQ-2')];
            $clientOf = [$devs[0] => 1, $devs[1] => 2];
            $targets = ['HKLM\\Run', 'C:\\Tools\\100%_done.txt', 'lnx-group', 'HKLM\\Soft_ware'];
            $caps = ['rate_per_min' => 300, 'device_max_rows' => 200, 'fleet_max_rows' => 350];
            for ($i = 0; $i < 60; ++$i) {
                $d = $this->pick($devs);
                $records = [];
                for ($n = $this->rnd(0, 15); $n > 0; --$n) {
                    $ts = self::T + $this->rnd(0, 3 * self::DAY);
                    $kind = $this->pick(['registry', 'file', 'group']);
                    $watch = $this->pick(['win.run', 'win.tools', 'lnx.groups']);
                    $chg = $this->rnd(1, 3);
                    $target = $this->pick($targets);
                    $item = $this->pick(['Updater', 'file', 'wheel', 'Other']);
                    $new = $this->pick(['v2', 'v3', '', 'bob']);
                    $actor = $this->pick(['alice', 'bob', '', 'carol']);
                    $records[] = ['ts' => $ts, 'kind' => $kind, 'watch' => $watch, 'change' => $chg, 'target' => $target, 'item' => $item, 'old' => 'v1', 'new' => $new, 'summary' => $this->pick(['', 'changed the updater', 'sudo membership']),
                        'old_hash' => '', 'new_hash' => '', 'actor' => $actor, 'process' => 'p.exe', 'flags' => $this->rnd(0, 3), 'dedupe' => substr(hash('sha256', implode('|', [$ts, $kind, $watch, $chg, $target, $item, $new, $actor])), 0, 32)];
                }
                $nowTs = self::T + $i * 31;
                $cfg = $this->pick(['', 'aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb']);
                $mon = $this->pick([null, '{"ok":true}', '{"ok":false,"n":3}']);
                $a = $this->maria->changes()->admit($d, $records, $caps, $nowTs, 1, 2, $cfg, $mon, $clientOf[$d]);
                $b = $this->postgres->changes()->admit($d, $records, $caps, $nowTs, 1, 2, $cfg, $mon, $clientOf[$d]);
                $this->assertSame($a['inserted'], $b['inserted'], "[seed $seed] batch $i inserted");
                $this->assertSame($a['dropped'], $b['dropped'], "[seed $seed] batch $i dropped");
                $this->assertSame(count($a['kept']), count($b['kept']));
            }
            foreach ($devs as $d) {
                $this->assertSame($this->maria->changes()->deviceLedger($d), $this->postgres->changes()->deviceLedger($d), "[seed $seed] ledger($d)");
            }
            $this->assertSame($this->maria->changes()->ledgerTotals(), $this->postgres->changes()->ledgerTotals());
            $this->assertSame($this->maria->changes()->hasLedger(), $this->postgres->changes()->hasLedger());
            foreach ([null, [1], [2], [1, 2], []] as $vis) {
                $this->assertSame($this->maria->changes()->fleetRowCount($vis), $this->postgres->changes()->fleetRowCount($vis), "[seed $seed] fleetRowCount " . json_encode($vis));
                $this->same('countsByWatch ' . json_encode($vis), $this->maria->changes()->countsByWatch(self::utc(self::T), $vis), $this->postgres->changes()->countsByWatch(self::utc(self::T), $vis));
            }
            $win = [self::utc(self::T), self::utc(self::T + 3 * self::DAY + 100)];
            $criteria = [
                [], ['deviceId' => $devs[0]], ['clientId' => 2], ['visibleClientIds' => [2]], ['visibleClientIds' => []], ['kind' => 'file'], ['watch' => 'win.run'], ['changeType' => 3], ['targetContains' => '100%_done'],
                ['targetContains' => 'soft_'], ['itemContains' => 'updat'], ['actorContains' => 'ALICE'], ['anyText' => 'sudo'], ['anyText' => 'v3'], ['anyText' => "x' OR '1'='1"], ['deviceId' => $devs[1], 'kind' => 'registry', 'anyText' => 'changed'],
            ];
            foreach ($criteria as $c) {
                foreach ([[100, 0], [9, 4]] as [$limit, $offset]) {
                    $a = $this->maria->changes()->search(new ChangeCriteria($win[0], $win[1], ...$c), $limit, $offset);
                    $b = $this->postgres->changes()->search(new ChangeCriteria($win[0], $win[1], ...$c), $limit, $offset);
                    $this->assertSame($a['total'], $b['total'], "[seed $seed] change total " . json_encode($c));
                    $this->same('change search ' . json_encode($c), $a['rows'], $b['rows'], ['dedupe'], ['change_id']);
                }
            }
            foreach ($devs as $d) {
                $a = $this->maria->changes()->timeline($d, $win[0], $win[1], 300);
                $b = $this->postgres->changes()->timeline($d, $win[0], $win[1], 300);
                $this->same("timeline rows($d)", $a['rows'], $b['rows'], ['dedupe'], ['change_id']);
                $this->same("timeline actors($d)", $a['actors'], $b['actors']);
                $this->same("timeline days($d)", $a['days'], $b['days']);
                foreach ([['', '', 0, '', ''], ['win.run', '', 0, '', ''], ['', 'registry', 2, 'HKLM', 'upd'], ['', '', 0, '100%', '']] as [$w, $k, $ct, $t, $it]) {
                    $this->assertSame($this->maria->changes()->countMatching($d, self::utc(self::T), $w, $k, $ct, $t, $it, 80), $this->postgres->changes()->countMatching($d, self::utc(self::T), $w, $k, $ct, $t, $it, 80), "[seed $seed] countMatching($d)");
                }
                $recv = self::utc(self::T + 5 * 31);
                $ka = $this->maria->changes()->dedupeKeysReceivedAt($d, $recv);
                $kb = $this->postgres->changes()->dedupeKeysReceivedAt($d, $recv);
                sort($ka);
                sort($kb);
                $this->assertSame($ka, $kb, "[seed $seed] dedupe keys($d)");
            }
            $cut = self::utc(self::T + self::DAY);
            $this->assertSame($this->maria->changes()->enforceLimits($cut, 30, 70, 400, 13), $this->postgres->changes()->enforceLimits($cut, 30, 70, 400, 13), "[seed $seed] enforceLimits");
            $this->assertSame($this->maria->changes()->ledgerTotals(), $this->postgres->changes()->ledgerTotals());
            foreach ([null, [1], [2]] as $vis) {
                $this->assertSame($this->maria->changes()->fleetRowCount($vis), $this->postgres->changes()->fleetRowCount($vis));
            }
        }
        $this->assertGreaterThan(40, $this->compared, 'the streams produced real data to compare');
    }

    // ------------------------------------------------------------------ the rules that are written down

    public function testTheDocumentedDifferencesAreTheOnlyOnes(): void
    {
        $this->setUp2(1);
        $dev = $this->device(1, 'EQ-D');
        // 1. software history append is idempotent on PostgreSQL (the control database transaction may be retried), not on MariaDB (it shares that transaction)
        $change = [['key' => 'k', 'name' => 'App', 'source' => 'msi', 'publisher' => '', 'type' => 'installed', 'old' => null, 'new' => '1']];
        foreach ([$this->maria, $this->postgres] as $s) {
            $s->softwareHistory()->append($dev, $change, self::utc(self::T));
            $s->softwareHistory()->append($dev, $change, self::utc(self::T));
        }
        $this->assertSame([2, 1], [$this->maria->softwareHistory()->forDevice($dev, null, 10, 0)['total'], $this->postgres->softwareHistory()->forDevice($dev, null, 10, 0)['total']]);
        // 2. a hot store in another database cannot tell an orphan without the inventory: no closure, nothing deleted
        $this->assertSame(0, $this->postgres->hypervisorHistory()->deleteOrphans());
        // 3. MariaDB's collation folds accents in a LIKE, PostgreSQL's ILIKE folds case only
        foreach ([$this->maria, $this->postgres] as $s) {
            $s->softwareHistory()->append($dev, [['key' => 'e', 'name' => 'Édge Browser', 'source' => 'msi', 'publisher' => '', 'type' => 'installed', 'old' => null, 'new' => '9']], self::utc(self::T + 5));
        }
        $this->assertSame([1, 0], [$this->maria->softwareHistory()->forDevice($dev, 'edge', 10, 0)['total'], $this->postgres->softwareHistory()->forDevice($dev, 'edge', 10, 0)['total']]);
        $this->assertSame([1, 1], [$this->maria->softwareHistory()->forDevice($dev, 'édge', 10, 0)['total'], $this->postgres->softwareHistory()->forDevice($dev, 'ÉDGE', 10, 0)['total']], 'case is folded by both');
        // 4. case-sensitive key comparison and a case-separating actor grouping on PostgreSQL
        $rec = fn (string $actor, int $i): array => ['ts' => self::T + $i, 'kind' => 'registry', 'watch' => 'win.run', 'change' => 2, 'target' => "t$i", 'item' => 'i', 'old' => '', 'new' => 'n', 'summary' => '', 'old_hash' => '', 'new_hash' => '',
            'actor' => $actor, 'process' => '', 'flags' => 0, 'dedupe' => substr(hash('sha256', "$actor$i"), 0, 32)];
        foreach ([$this->maria, $this->postgres] as $s) {
            $s->changes()->admit($dev, [$rec('Alice', 1), $rec('alice', 2), $rec('bob', 3)], ['rate_per_min' => 100, 'device_max_rows' => 100, 'fleet_max_rows' => 100], self::T + 10, 0, 0, '', null, 1);
        }
        $this->assertSame(2, count($this->maria->changes()->timeline($dev, self::utc(self::T), self::utc(self::T + 100), 10)['actors']), 'MariaDB merges Alice and alice');
        $this->assertSame(3, count($this->postgres->changes()->timeline($dev, self::utc(self::T), self::utc(self::T + 100), 10)['actors']), 'PostgreSQL keeps them apart');
    }
}
