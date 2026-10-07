<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Capacity\CapacityReport as C;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Tests\Support\RmmTestCase;

/** The capacity panel's data: the pure estimate model against the table of design 13.2, warnings, presets, profiles and the live report. */
final class CapacityTest extends RmmTestCase
{
    /** @return array{devices:int,check_in_interval_s:int,collect_interval_s:int,metrics:bool,retention_days:int} */
    private static function in(int $devices, int $checkin = 300, int $collect = 60, bool $metrics = true, int $retention = 30): array
    {
        return ['devices' => $devices, 'check_in_interval_s' => $checkin, 'collect_interval_s' => $collect, 'metrics' => $metrics, 'retention_days' => $retention];
    }

    public function testTheModelReproducesTheTableOfDesignSection13Dot2(): void
    {
        // [devices, check-in, collect, metrics] => check-ins/s, rows/s, statements/s, workers avg, workers peak, GB per month (the design's table, rounded)
        $cases = [
            'defaults 500' => [self::in(500), 1.7, 70, 40, 0.25, 0.8, 18.0],
            'defaults 5000' => [self::in(5000), 16.7, 700, 400, 2.5, 7.5, 180.0],
            'recommended 500' => [self::in(500, 300, 300), 1.7, 23, 30, 0.25, 0.8, 3.6],
            'recommended 5000' => [self::in(5000, 300, 300), 16.7, 233, 300, 2.5, 7.5, 36.0],
            'light 5000' => [self::in(5000, 600, 600, false), 8.3, 67, 100, 1.2, 3.7, 0.0],
        ];
        foreach ($cases as $name => [$in, $cps, $rows, $stm, $w, $wp, $gb]) {
            $p = C::project($in);
            $this->assertEqualsWithDelta($cps, $p['checkins_per_s'], 0.06, "$name check-ins/s");
            $this->assertEqualsWithDelta($rows, $p['rows_per_s'], $rows * 0.15, "$name rows/s");
            $this->assertEqualsWithDelta($stm, $p['statements_per_s'], $stm * 0.25, "$name statements/s");
            $this->assertEqualsWithDelta($w, $p['workers_avg'], 0.1, "$name workers");
            $this->assertEqualsWithDelta($wp, $p['workers_peak'], 0.3, "$name peak workers");
            $this->assertEqualsWithDelta($gb, $p['storage_per_month_gb'], max(0.2, $gb * 0.03), "$name GB per month");
        }
        $p = C::project(self::in(10000, 300, 60));
        $this->assertEqualsWithDelta(33.3, $p['checkins_per_s'], 0.05);
        $this->assertEqualsWithDelta(363.0, $p['storage_per_month_gb'], 5.0, 'the 10,000 row: about 360 GB per month of raw samples at the defaults');
        $this->assertSame(5, $p['batches']);
        $this->assertSame(35, $p['sample_rows_per_checkin']);
        // retention drives the steady state: seven days of the recommended profile
        $this->assertEqualsWithDelta(8.5, C::project(self::in(5000, 300, 300, true, 7))['storage_steady_gb'], 0.2);
    }

    public function testTheModelIsPureAndHandlesDegenerateInput(): void
    {
        $a = C::project(self::in(1234, 417, 90));
        $this->assertSame($a, C::project(self::in(1234, 417, 90)));
        $z = C::project(self::in(0));
        $this->assertSame([0.0, 0.0, 0.0], [$z['checkins_per_s'], $z['rows_per_s'], $z['storage_per_month_gb']]);
        $odd = C::project(self::in(10, 0, 0, true, 0));   // intervals and retention are clamped to 1, never a division by zero
        $this->assertSame(1, $odd['batches']);
    }

    public function testWarnings(): void
    {
        $base = ['devices' => 100, 'max_devices' => 0, 'collect_interval_s' => 300, 'retention_days' => 7, 'queue_age_s' => 0, 'shed_level' => 0, 'redis_available' => true,
            'projected_storage_gb' => 1.0, 'storage_budget_gb' => null, 'ingest_mode' => 'sync', 'dead_letter' => 0];
        $codes = static fn (array $over): array => array_column(C::warnings($over + $base), 'code');
        $this->assertSame([], $codes([]));
        $this->assertSame(['device_limit'], $codes(['max_devices' => 120]), '80% of the limit');
        $this->assertSame([], $codes(['max_devices' => 126]));
        $this->assertSame(['collect_interval'], $codes(['collect_interval_s' => 30, 'devices' => 201]));
        $this->assertSame([], $codes(['collect_interval_s' => 30, 'devices' => 200]));
        $this->assertSame(['queue_age'], $codes(['queue_age_s' => 301]));
        $this->assertSame(['shedding'], $codes(['shed_level' => 2]));
        $this->assertSame(['dead_letter'], $codes(['dead_letter' => 3]));
        $this->assertSame(['redis', 'queued_ingest'], $codes(['devices' => 1001, 'redis_available' => false]));
        $this->assertSame(['queued_ingest'], $codes(['devices' => 1001, 'redis_available' => null]), 'an unknown Redis state is not a warning');
        $this->assertSame(['retention'], $codes(['retention_days' => 15, 'devices' => 501]));
        $this->assertSame(['storage'], $codes(['storage_budget_gb' => 0.5]));
        $this->assertSame([], $codes(['storage_budget_gb' => 5.0]));
    }

    public function testPresetsArePatchesThatNeverApplyThemselvesAndChangeTheProjection(): void
    {
        $cfg = ['check_in_interval_s' => 300, 'collect_interval_s' => 60, 'retention_days' => 30, 'ingest_mode' => 'sync', 'limits_json' => '{"retry_after_min_s":40}'];
        $features = ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'remote' => false];
        $features += array_fill_keys(array_diff(RmmSettings::FEATURES, array_keys($features)), false);
        $limits = ['max_checkins_per_min' => 0] + RmmSettings::LIMIT_DEFAULTS;
        $presets = C::presets($cfg, $features, $limits, 5000);
        $byId = array_column($presets, null, 'id');
        $this->assertSame(['lengthen_check_in', 'collect_300', 'metrics_off', 'retention_7', 'queued_ingest', 'cap_checkins'], array_keys($byId));
        $this->assertSame(['check_in_interval_s' => 600], $byId['lengthen_check_in']['patch']);
        $this->assertSame(['collect_interval_s' => 300], $byId['collect_300']['patch']);
        $this->assertSame(['retention_days' => 7], $byId['retention_7']['patch']);
        $this->assertSame(['ingest_mode' => 'queued'], $byId['queued_ingest']['patch']);
        $this->assertFalse(json_decode($byId['metrics_off']['patch']['features_json'], true)['metrics']);
        $this->assertTrue(json_decode($byId['metrics_off']['patch']['features_json'], true)['monitoring'], 'only metrics changes');
        foreach ($presets as $p) {
            $this->assertTrue($p['applicable'], $p['id']);
            $this->assertLessThanOrEqual($p['before']['rows_per_s'], $p['after']['rows_per_s'], $p['id'] . ' never increases the load');
        }
        $this->assertEqualsWithDelta(8.33, $byId['lengthen_check_in']['after']['checkins_per_s'], 0.01, 'half the requests');
        $this->assertLessThan($byId['lengthen_check_in']['before']['statements_per_s'] * 0.65, $byId['lengthen_check_in']['after']['statements_per_s'], 'far fewer statements');
        $this->assertEqualsWithDelta(641.7, $byId['lengthen_check_in']['after']['rows_per_s'], 1.0, 'the model is honest: samples are still collected every 60 s, so each check-in carries twice the batches and the rows barely drop');
        $this->assertLessThan($byId['collect_300']['before']['storage_per_month_gb'] / 4, $byId['collect_300']['after']['storage_per_month_gb']);
        $this->assertSame(0, $byId['metrics_off']['after']['sample_rows_per_checkin']);
        $this->assertSame($byId['retention_7']['before']['storage_per_month_gb'], $byId['retention_7']['after']['storage_per_month_gb'], 'retention changes the steady state, not the monthly inflow');
        $this->assertLessThan($byId['retention_7']['before']['storage_steady_gb'], $byId['retention_7']['after']['storage_steady_gb']);
        // the cap keeps the stored limits and adds 4x the steady rate per minute: 4 x 5000 / 300 x 60
        $cap = json_decode($byId['cap_checkins']['patch']['limits_json'], true);
        $this->assertSame(['max_checkins_per_min' => 4000, 'retry_after_min_s' => 40], $cap);
        // a preset that would change nothing is flagged
        $light = C::presets(['check_in_interval_s' => 900, 'collect_interval_s' => 300, 'retention_days' => 7, 'ingest_mode' => 'queued', 'limits_json' => null], ['metrics' => false] + $features, ['max_checkins_per_min' => 50] + $limits, 100);
        $this->assertSame([false], array_unique(array_column($light, 'applicable')));
    }

    public function testEveryPresetAndProfilePatchIsAcceptedByTheSettingsValidator(): void
    {
        $cfg = $this->h->module->settings()->get(true);
        $all = C::presets($cfg, $this->h->module->settings()->features(), $this->h->module->settings()->limits(), 800);
        foreach ($all as $p) {
            $this->assertSame([], $this->h->module->settings()->update($p['patch']), $p['id']);
        }
        $row = $this->h->module->settings()->get(true);
        $this->assertSame([600, 300, 7, 'queued'], [(int) $row['check_in_interval_s'], (int) $row['collect_interval_s'], (int) $row['retention_days'], $row['ingest_mode']]);
        $this->assertFalse($this->h->module->settings()->features()['metrics']);
        $this->assertSame(640, $this->h->module->settings()->limits()['max_checkins_per_min'], '4 x 800 devices / 300 s x 60: computed from the interval the report was built with');
        foreach (C::profiles() as $name => $prof) {
            $this->assertSame([], $this->h->module->settings()->update($prof['patch']), $name);
        }
        $light = $this->h->module->settings()->features();
        $this->assertSame([true, false, false, true], [$light['monitoring'], $light['metrics'], $light['jobs'], $light['updates']], 'the last profile applied is Light: monitoring and updates only');
        $this->h->module->settings()->update(C::profiles()['defaults']['patch']);
        $this->assertNull($this->h->module->settings()->get(true)['features_json'], 'Defaults is the legacy NULL');
        $this->assertSame([300, 60, 30], [(int) $this->h->module->settings()->get()['check_in_interval_s'], (int) $this->h->module->settings()->get()['collect_interval_s'], (int) $this->h->module->settings()->get()['retention_days']]);
    }

    public function testTheLiveReportAgainstFixtureRows(): void
    {
        $this->h->enable();
        $tok = $this->h->token(null, 24, 100);
        $ids = [];
        foreach (range(1, 7) as $i) {
            [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => "CAP-$i"]));
            $ids[$i] = (int) $j['device_id'];
        }
        $ago = static fn (int $s): string => gmdate('Y-m-d H:i:s', time() - $s);
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = '" . $ago(30) . "' WHERE device_id IN ({$ids[1]},{$ids[2]})");        // online
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = '" . $ago(3600) . "' WHERE device_id = {$ids[3]}");                    // offline (default offline_after_s is below an hour)
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = '" . $ago(86400 * 400) . "' WHERE device_id = {$ids[4]}");           // stale
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = NULL WHERE device_id = {$ids[5]}");                                   // never
        $this->h->q("UPDATE endpoint_agent_devices SET revoked_at = UTC_TIMESTAMP() WHERE device_id = {$ids[6]}");
        $this->h->q("UPDATE endpoint_agent_devices SET retired_at = UTC_TIMESTAMP() WHERE device_id = {$ids[7]}");
        $this->h->q('DELETE FROM endpoint_agent_checkins');
        $this->h->q("INSERT INTO endpoint_agent_checkins (device_id, seq, received_at, collected_at) VALUES ({$ids[1]}, 1, '" . $ago(10) . "', '" . $ago(10) . "'), ({$ids[1]}, 2, '" . $ago(20) . "', '" . $ago(20) . "'),
            ({$ids[2]}, 1, '" . $ago(1800) . "', '" . $ago(1800) . "'), ({$ids[2]}, 2, '" . $ago(7200) . "', '" . $ago(7200) . "')");
        $this->h->module->settings()->update(['max_devices' => 5]);
        $r = $this->h->module->capacity()->build(['redis_available' => false, 'storage_budget_gb' => null]);

        $this->assertSame(['total' => 7, 'active' => 5, 'online' => 2, 'offline' => 1, 'stale' => 2, 'never' => 1, 'revoked' => 1, 'retired' => 1], array_diff_key($r['devices'], ['pending_approval' => 0]));
        $this->assertSame([2, 3], [$r['checkins']['last_minute'], $r['checkins']['last_hour']]);
        $this->assertSame(0, $r['queue']['pending']);
        $this->assertSame('sync', $r['ingest_mode']);
        $this->assertSame(0, $r['shed']['level']);
        $names = array_column($r['tables'], 'table');
        $this->assertContains('endpoint_agent_devices', $names);
        $this->assertContains('integration_jobs', $names);
        $this->assertContains('endpoint_agent_checkins', $names);
        $this->assertSame(5, $r['settings']['devices']);
        $this->assertSame(300, $r['settings']['check_in_interval_s']);
        $this->assertEqualsWithDelta(5 / 300, $r['projection']['checkins_per_s'], 0.01);
        $this->assertContains('device_limit', array_column($r['warnings'], 'code'), '5 of 5 devices');
        $this->assertCount(6, $r['presets']);
        $this->assertTrue($r['enabled']);
        // build() is read-only: it changed no setting
        $this->assertSame(0, (int) $this->h->one('SELECT shed_level FROM endpoint_agent_settings'));
        $this->assertSame('sync', $this->h->one('SELECT ingest_mode FROM endpoint_agent_settings'));
    }
}
