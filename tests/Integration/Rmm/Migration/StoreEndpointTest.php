<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Migration;

use RivetCore\Rmm\Store\Migration\HotTable;
use RivetCore\Rmm\Store\Migration\HotTables;
use RivetCore\Rmm\Store\Migration\MariaDbEndpoint;
use RivetCore\Rmm\Store\Migration\PostgresEndpoint;
use RivetCore\Rmm\Store\Migration\StoreEndpoint;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Tests\Support\RmmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** The registry of hot tables against the real databases, and the endpoint operations the migration builds on, on MariaDB and on PostgreSQL. */
final class StoreEndpointTest extends RmmTestCase
{
    /** @var array<string,StoreEndpoint> */
    private array $ep = [];

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        parent::setUp();
        $pg = PostgresTestDatabase::connect();
        PostgresTestDatabase::prepare($pg);
        $this->ep = ['mariadb' => new MariaDbEndpoint(new Sql($this->h->db, $this->h->clock)), 'postgres' => new PostgresEndpoint($pg)];
    }

    public function testTheRegistryMatchesTheColumnsOfBothStores(): void
    {
        foreach ($this->ep as $kind => $ep) {
            foreach (HotTables::all() as $t) {
                $this->assertTrue($ep->tableExists($t), "$kind has {$t->name}");
                $have = $ep->columns($t);
                $want = $t->allColumns();
                if ($t->name === 'endpoint_agent_check_history' || $t->kind === HotTable::ID) {
                    $this->assertEqualsCanonicalizing($want, $have, "$kind {$t->name}");
                } else {
                    $this->assertEqualsCanonicalizing($want, $have, "$kind {$t->name}");
                }
            }
        }
    }

    /** @return array<string,array{0:string}> */
    public static function stores(): array
    {
        return ['mariadb' => ['mariadb'], 'postgres' => ['postgres']];
    }

    #[DataProvider("stores")]
    public function testIdTablesKeepGivenIdsPageByKeysAndAssignNewIdsAboveThem(string $kind): void
    {
        $ep = $this->ep[$kind];
        $t = HotTables::get('endpoint_agent_check_history');
        $ep->truncate($t);
        $rows = [];
        for ($i = 1; $i <= 25; ++$i) {
            $rows[] = ['hist_id' => $i * 10, 'device_id' => 7, 'check_key' => 'k', 'status' => 'ok', 'detail' => "d$i", 'reported_at' => '2026-01-01 00:00:' . sprintf('%02d', $i)];
        }
        $this->assertSame(25, $ep->insert($t, $rows, 'ignore', true));
        $this->assertSame(0, $ep->insert($t, $rows, 'ignore', true), 'a repeated insert writes nothing');
        $this->assertSame(250, $ep->maxId($t));
        $seen = [];
        $after = null;
        while (($page = $ep->readBatch($t, $after, 10)) !== []) {
            foreach ($page as $r) {
                $seen[] = $r['hist_id'];
            }
            $after = [end($page)['hist_id']];
        }
        $this->assertSame(range(10, 250, 10), $seen);
        $this->assertCount(5, $ep->readBatch($t, null, 100, 50), 'upToId');
        $this->assertSame(10, $ep->count($t, '2026-01-01 00:00:11'));
        $this->assertSame(5, $ep->count($t, null, '2026-01-01 00:00:21'));
        $ep->raiseNextId($t, 1000);
        $ep->insert($t, [['device_id' => 7, 'check_key' => 'k', 'status' => 'ok', 'detail' => 'new', 'reported_at' => '2026-02-01 00:00:00']], 'ignore', false);
        $this->assertSame(1000, $ep->maxId($t), 'the next id is the raised one');
        $ep->raiseNextId($t, 5);
        $ep->insert($t, [['device_id' => 7, 'check_key' => 'k', 'status' => 'ok', 'detail' => 'new2', 'reported_at' => '2026-02-01 00:00:01']], 'ignore', false);
        $this->assertSame(1001, $ep->maxId($t), 'raising never lowers it');
        $this->assertCount(27, $ep->readOwner($t, 7, null, 100));
        $this->assertSame([7 => 27], $ep->countsByDevice($t));
        $ep->truncate($t);
        $this->assertSame(0, $ep->count($t));
    }

    #[DataProvider("stores")]
    public function testTierRowsOverwriteAndTheLatestRuleKeepsTheNewestReading(string $kind): void
    {
        $ep = $this->ep[$kind];
        $h = HotTables::get('rmm_metric_hourly');
        $ep->truncate($h);
        $row = ['asset_id' => 1, 'metric_key' => 'cpu', 'instance' => '', 'hour_start' => '2026-01-01 10:00:00', 'samples' => 5, 'sum_value' => 12.5, 'min_value' => 1.25, 'max_value' => 9.0];
        $ep->insert($h, [$row], 'ignore', false);
        $ep->insert($h, [['samples' => 99] + $row], 'ignore', false);
        $this->assertSame(5, $ep->readBatch($h, null, 10)[0]['samples']);
        $ep->insert($h, [['samples' => 99, 'sum_value' => 0.1 + 0.2] + $row], 'overwrite', false);
        $r = $ep->readBatch($h, null, 10)[0];
        $this->assertSame([99, 0.1 + 0.2], [$r['samples'], $r['sum_value']], 'doubles survive the trip exactly');
        $this->assertSame([['asset_id' => 1, 'metric_key' => 'cpu', 'instance' => '', 'hour_start' => '2026-01-01 11:00:00']], array_map(static fn (array $x): array => array_intersect_key($x, array_flip(['asset_id', 'metric_key', 'instance', 'hour_start'])),
            $this->after($ep, $h, ['hour_start' => '2026-01-01 11:00:00'] + $row)));

        $l = HotTables::get('rmm_metric_latest');
        $ep->truncate($l);
        $new = ['asset_id' => 1, 'metric_key' => 'cpu', 'instance' => '', 'value' => 5.0, 'label' => 'x', 'sampled_at' => '2026-01-01 10:00:10'];
        $ep->insert($l, [$new], 'newer', false);
        $ep->insert($l, [['value' => 1.0, 'sampled_at' => '2026-01-01 10:00:05'] + $new], 'newer', false);
        $this->assertSame(5.0, $ep->readBatch($l, null, 5)[0]['value'], 'an older reading does not replace a newer one');
        $ep->insert($l, [['value' => 7.0, 'sampled_at' => '2026-01-01 10:00:20'] + $new], 'newer', false);
        $this->assertSame([7.0, '2026-01-01 10:00:20'], [$ep->readBatch($l, null, 5)[0]['value'], $ep->readBatch($l, null, 5)[0]['sampled_at']]);
    }

    #[DataProvider("stores")]
    public function testBinaryDigestsAndNullsRoundTripAndTheNaturalKeyStopsADuplicate(string $kind): void
    {
        $ep = $this->ep[$kind];
        $t = HotTables::get('endpoint_agent_log_events');
        $ep->truncate($t);
        $row = ['event_id' => 5, 'device_id' => 3, 'client_id' => null, 'occurred_at' => '2026-01-01 00:00:00', 'received_at' => '2026-01-01 00:00:01', 'level' => 2, 'source' => 'S', 'code' => null,
            'message' => "multi\nline ’ü", 'dedupe' => '00ff00ff00ff00ff00ff00ff00ff00ff'];
        $this->assertSame(1, $ep->insert($t, [$row], 'ignore', true));
        $this->assertSame(0, $ep->insert($t, [['event_id' => 6] + $row], 'ignore', true), 'the same dedupe digest of the same device is not stored twice');
        $this->assertSame([$row], $ep->readBatch($t, null, 5));
    }

    /** @return list<array<string,mixed>> */
    private function after(StoreEndpoint $ep, HotTable $t, array $key): array
    {
        $ep->insert($t, [$key], 'ignore', false);

        return $ep->readBatch($t, [1, 'cpu', '', '2026-01-01 10:00:00'], 10);
    }
}
