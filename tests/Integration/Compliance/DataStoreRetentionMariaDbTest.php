<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Compliance;

use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Tests\Support\DataStoreRetentionCases;

/** The retention check against the MariaDB RMM data store. */
final class DataStoreRetentionMariaDbTest extends DataStoreRetentionCases
{
    protected function activateStore(): void
    {
        self::assertSame(RmmDataStoreKind::MARIADB, $this->h->module->dataStores()->kind());
    }

    protected function expectedKind(): string
    {
        return RmmDataStoreKind::MARIADB;
    }

    public function testHousekeepingKeepsHourlyMetricsAtLeastAsLongAsTheMinimumEvenWhenTheStoreKeepsThemLess(): void
    {
        $asset = (int) $this->h->one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id = {$this->dev}");
        $twentyDaysAgo = gmdate('Y-m-d H:00:00', time() - 20 * 86400);
        $insert = "INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value) VALUES ($asset, 'cpu_pct', '', '$twentyDaysAgo', 3, 30, 5, 15)";
        $this->h->q('DELETE FROM rmm_metric_hourly');
        $this->h->q($insert);
        $this->h->module->housekeeping()->run();
        self::assertSame('0', (string) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly'), 'control: without a minimum the 14-day hourly tier is pruned');

        $this->h->q($insert);
        $this->check([self::minReq('metrics_hourly', 30)], true);   // wires the floors into the settings
        $this->h->module->housekeeping()->run();
        self::assertSame('1', (string) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly'), 'with a 30-day minimum the 20-day-old hourly row stays');
    }

    private static function minReq(string $class, int $days): \RivetCore\Compliance\Profile\Requirement
    {
        return new \RivetCore\Compliance\Profile\Requirement(\RivetCore\Compliance\Profile\RequirementType::RETENTION_MIN, $class, $days);
    }
}
