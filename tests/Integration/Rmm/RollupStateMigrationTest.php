<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Migration\Migration0043RmmStoreDenormalisation;
use RivetCore\Rmm\Storage\StorageState;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Tests\Support\RmmTestCase;

/** Decision 3 of #89 phase 1: the rollup watermark lives next to the metric tiers (migration 0043), the old row keeps being written, and the old release's row still answers. */
final class RollupStateMigrationTest extends RmmTestCase
{
    private function sink(): DatabaseMetricSink
    {
        return new DatabaseMetricSink($this->h->db, $this->h->clock);
    }

    private function rolledUp(): string
    {
        $sink = $this->sink();
        $asset = random_int(1_000_000, 2_000_000_000);
        $day = intdiv(time(), 86400) * 86400 - 3 * 86400;
        $sink->ingest([['asset_id' => $asset, 'key' => 'cpu.utilization', 'instance' => null, 'value' => 5, 'at' => new \DateTimeImmutable('@' . ($day + 3700)), 'label' => null]], 1);
        $sink->rollUp(10);

        return (string) $sink->rollupWatermark();
    }

    public function testTheWatermarkIsWrittenToTheNewTableAndKeptInTheOldRowToo(): void
    {
        $wm = $this->rolledUp();
        $this->assertNotSame('', $wm);
        $this->assertSame($wm, (string) $this->h->one("SELECT watermark FROM rmm_metric_rollup_state WHERE name = 'metric_daily_rollup'"), 'next to the tiers');
        $this->assertSame($wm, (string) $this->h->one("SELECT watermark FROM rmm_storage_state WHERE name = 'metric_daily_rollup'"), 'and where the previous release reads it');
    }

    public function testTheNewTableIsReadFirst(): void
    {
        $this->rolledUp();
        $this->h->q("UPDATE rmm_metric_rollup_state SET watermark = '2000-01-02 00:00:00' WHERE name = 'metric_daily_rollup'");
        $this->assertSame('2000-01-02 00:00:00', $this->sink()->rollupWatermark());
    }

    public function testBeforeTheMigrationTheOldRowAnswersAndTheRollupStillWorks(): void
    {
        $this->h->q('DROP TABLE rmm_metric_rollup_state');
        try {
            $this->assertNull($this->sink()->rollupWatermark());
            $wm = $this->rolledUp();
            $this->assertSame($wm, (string) $this->h->one("SELECT watermark FROM rmm_storage_state WHERE name = 'metric_daily_rollup'"));
            $this->assertSame($wm, $this->sink()->rollupWatermark());
        } finally {
            (new Migration0043RmmStoreDenormalisation())->up($this->h->db);
        }
    }

    public function testTheMigrationCopiesAnExistingWatermarkAndIsIdempotent(): void
    {
        $this->h->q('DROP TABLE rmm_metric_rollup_state');
        $this->h->q("INSERT INTO rmm_storage_state (name, watermark, detail, updated_at) VALUES ('metric_daily_rollup', '2026-01-05 00:00:00', '{\"day\":\"2026-01-05 00:00:00\",\"asset_done\":9}', NOW())
            ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), detail = VALUES(detail)");
        $m = new Migration0043RmmStoreDenormalisation();
        $m->up($this->h->db);
        $m->up($this->h->db);
        $this->assertSame('2026-01-05 00:00:00', (string) $this->h->one("SELECT watermark FROM rmm_metric_rollup_state WHERE name = 'metric_daily_rollup'"));
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_rollup_state'));
        $this->assertSame('2026-01-05 00:00:00', (string) $this->sink()->rollupWatermark());
        $this->assertSame(['day' => '2026-01-05 00:00:00', 'asset_done' => 9], (new StorageState($this->h->module->sql()))->get(StorageState::ROLLUP)['detail']);
    }

    public function testTheMigrationGivesExistingEventRowsTheirDevicesClient(): void
    {
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 5), $this->h::device(['hostname' => 'MIG-1']));
        $dev = (int) $j['device_id'];
        $this->h->q("UPDATE endpoint_agent_devices SET client_id = {$this->h->clientB} WHERE device_id = $dev");
        $this->h->q("INSERT INTO endpoint_agent_log_events (device_id, occurred_at, received_at, level, source, code, message, dedupe, client_id) VALUES ($dev, NOW(), NOW(), 4, 'S', NULL, 'm', UNHEX('00000000000000000000000000000001'), NULL)");
        $this->h->q("INSERT INTO endpoint_agent_change_records (device_id, occurred_at, received_at, kind, watch, change_type, dedupe, client_id) VALUES ($dev, NOW(), NOW(), 'registry', 'w', 1, UNHEX('00000000000000000000000000000002'), NULL)");
        $this->h->q("INSERT INTO endpoint_agent_change_state (device_id, row_count, client_id) VALUES ($dev, 1, NULL) ON DUPLICATE KEY UPDATE client_id = NULL");
        (new Migration0043RmmStoreDenormalisation())->up($this->h->db);
        foreach (['endpoint_agent_log_events', 'endpoint_agent_change_records', 'endpoint_agent_change_state'] as $t) {
            $this->assertSame((string) $this->h->clientB, (string) $this->h->one("SELECT client_id FROM $t WHERE device_id = $dev"), "$t is backfilled");
        }
    }
}
