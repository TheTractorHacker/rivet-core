<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Red team 2026-11-21 CORE-API-2: device-reported numbers outside their plausible range are rejected (treated as missing), never clamped and never cast into
 * garbage integers: the edition's health columns, the metric samples and the stored last metrics all see "unknown". In-range values, boundaries included, pass.
 * A PHP warning ("not representable as an int") fails these tests on its own (failOnWarning).
 */
final class MetricRangeTest extends RmmTestCase
{
    private int $dev = 0;
    private string $token = '';
    private int $assetId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->enable();
        $this->h->module->settings()->update(['features_json' => array_fill_keys(RmmSettings::FEATURES, true)]);
        $tok = $this->h->token(null, 24, 10);
        $this->assetId = $this->h->asset(['name' => 'Range PC', 'serial' => 'SN-RANGE-1']);
        [$c, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SN-RANGE-1']));
        $this->assertSame(201, $c);
        $this->assertSame('linked', $j['status']);
        $this->dev = (int) $j['device_id'];
        $this->token = $j['device_token'];
    }

    /** @return array<string,mixed> the health the edition's link received */
    private function health(): array
    {
        return $this->h->bridge->health[$this->h->integrationId() . ':' . $this->assetId] ?? [];
    }

    /** @return array<string,int|float> key[instance] => value of the samples ingested so far */
    private function samples(): array
    {
        $out = [];
        foreach ($this->h->metrics->stored() as $s) {
            $out[$s['key'] . ($s['instance'] !== null ? '[' . $s['instance'] . ']' : '')] = $s['value'];
        }

        return $out;
    }

    public function testOutOfRangeReadingsAreRejectedEverywhere(): void
    {
        [$c] = $this->h->checkin($this->token, ['metrics' => ['cpu_pct' => 1.5e300, 'mem_pct' => -5e20, 'disk' => [['mount' => 'C:', 'used_pct' => 1e30]],
            'net_rx_bps' => 1e300, 'net_tx_bps' => -1, 'cpu_core_pct' => [1e300, -0.5, 101.0], 'disk_read_iops' => 1e300, 'disk_write_iops' => 2e9,
            'disk_queue' => 1e300, 'battery_pct' => -3.0]]);
        $this->assertSame(200, $c);
        $h = $this->health();
        $this->assertNotSame([], $h, 'the link was updated');
        $this->assertSame([null, null, null], [$h['cpu_pct'], $h['ram_pct'], $h['disk_pct']], 'unknown, never a cast of garbage');
        $this->assertSame([], $this->samples(), 'no sample for any out-of-range reading');
        $last = json_decode((string) $this->h->one("SELECT last_metrics_json FROM endpoint_agent_devices WHERE device_id = {$this->dev}"), true);
        $this->assertSame(['cpu_pct' => null, 'mem_pct' => null, 'disk' => [['mount' => 'C:', 'used_pct' => null]], 'net_rx_bps' => null, 'net_tx_bps' => null], $last);
    }

    public function testInRangeReadingsAndTheBoundariesStillPass(): void
    {
        [$c] = $this->h->checkin($this->token, ['metrics' => ['cpu_pct' => 100, 'mem_pct' => 0.0, 'disk' => [['mount' => 'C:', 'used_pct' => 55.6]],
            'net_rx_bps' => 8.0e9, 'net_tx_bps' => 0, 'cpu_core_pct' => [12.5, 100.0], 'disk_read_iops' => 1.0e9, 'disk_write_iops' => 3, 'disk_queue' => 0.5,
            'battery_pct' => 100.0]]);
        $this->assertSame(200, $c);
        $h = $this->health();
        $this->assertSame([100, 0, 56], [$h['cpu_pct'], $h['ram_pct'], $h['disk_pct']]);
        $s = $this->samples();
        $this->assertEquals(100.0, $s['cpu.utilization']);
        $this->assertEquals(0.0, $s['memory.utilization']);
        $this->assertEquals(55.6, $s['disk.utilization[C:]']);
        $this->assertEquals(1.0e9, $s['network.rx_bytes_per_s[total]'], '8 Gbit/s is 1e9 bytes/s');
        $this->assertEquals(100.0, $s['cpu.core.utilization[1]']);
        $this->assertEquals(1.0e9, $s['disk.read_iops[total]']);
        $this->assertEquals(100.0, $s['battery.charge_percent']);
        // just past a bound is out
        $this->h->checkin($this->token, ['metrics' => ['cpu_pct' => 100.01, 'mem_pct' => 40, 'disk' => [], 'net_rx_bps' => 1.0e13 + 1e6, 'net_tx_bps' => 1]]);
        $h = $this->health();
        $this->assertSame([null, 40], [$h['cpu_pct'], $h['ram_pct']]);
    }

    public function testTheLinkerRejectsAnOutOfRangePercentageOnItsOwn(): void
    {
        $dev = $this->h->rows("SELECT * FROM endpoint_agent_devices WHERE device_id = {$this->dev}")[0];
        $this->h->module->linker()->applyCheckin($dev, ['cpu' => 1.5e300, 'mem' => -5e20, 'disk' => INF]);
        $h = $this->health();
        $this->assertSame([null, null, null], [$h['cpu_pct'], $h['ram_pct'], $h['disk_pct']]);
        $this->h->module->linker()->applyCheckin($dev, ['cpu' => 99.5, 'mem' => 0.4, 'disk' => null]);
        $h = $this->health();
        $this->assertSame([100, 0, null], [$h['cpu_pct'], $h['ram_pct'], $h['disk_pct']]);
    }
}
