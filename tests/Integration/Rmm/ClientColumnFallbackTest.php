<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Migration\Migration0043RmmStoreDenormalisation;
use RivetCore\Rmm\Store\ChangeCriteria;
use RivetCore\Rmm\Store\ClientScopedChangeEventStore;
use RivetCore\Rmm\Store\ClientScopedLogStore;
use RivetCore\Rmm\Store\LogCriteria;
use RivetCore\Rmm\Store\MariaDb\ClientColumn;
use RivetCore\Tests\Support\RmmTestCase;

/** Code before migration (#89 phase 2): the MariaDB log and change stores keep working when `client_id` (migration 0043) does not exist yet, and again when it does. */
final class ClientColumnFallbackTest extends RmmTestCase
{
    private const T = 1_800_000_000;
    private const CAPS = ['rate_per_min' => 1000, 'device_max_rows' => 1000, 'fleet_max_rows' => 100000];

    private function device(int $client, string $name): int
    {
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 50), $this->h::device(['hostname' => $name]));
        $id = (int) $j['device_id'];
        $this->h->q("UPDATE endpoint_agent_devices SET client_id = $client WHERE device_id = $id");

        return $id;
    }

    private function dropColumns(): void
    {
        foreach (['endpoint_agent_log_events', 'endpoint_agent_change_records', 'endpoint_agent_change_state'] as $t) {
            $this->h->q("ALTER TABLE $t DROP COLUMN client_id");
        }
        ClientColumn::forget();
    }

    private function restore(): void
    {
        (new Migration0043RmmStoreDenormalisation())->up($this->h->db);
        ClientColumn::forget();
    }

    private function entry(int $i): array
    {
        return ['ts' => self::T + $i, 'level' => 4, 'source' => 'S', 'code' => null, 'message' => "m$i", 'hash' => substr(hash('sha256', "m$i"), 0, 32)];
    }

    private function record(int $i): array
    {
        return ['ts' => self::T + $i, 'kind' => 'registry', 'watch' => 'win.run', 'change' => 2, 'target' => "t$i", 'item' => 'i', 'old' => 'a', 'new' => 'b', 'summary' => '', 'old_hash' => '', 'new_hash' => '',
            'actor' => 'x', 'process' => 'p', 'flags' => 0, 'dedupe' => substr(hash('sha256', "r$i"), 0, 32)];
    }

    public function testLogsAndChangesWorkWithoutTheColumnAndKeepTheirShape(): void
    {
        $a = $this->device($this->h->clientA, 'FB-A');
        $b = $this->device($this->h->clientB, 'FB-B');
        $this->dropColumns();
        try {
            $logs = $this->h->module->dataStores()->logs();
            $changes = $this->h->module->dataStores()->changes();
            $this->assertInstanceOf(ClientScopedLogStore::class, $logs);
            $this->assertInstanceOf(ClientScopedChangeEventStore::class, $changes);
            // ingest with a client that cannot be stored: accepted, once, and the failed first attempt does not poison the transaction
            $this->assertSame(2, $logs->admit($a, [$this->entry(1), $this->entry(2)], self::CAPS, self::T + 10, 0, 0, $this->h->clientA)['inserted']);
            $this->assertSame(1, $logs->admit($b, [$this->entry(3)], self::CAPS, self::T + 10, 0, 0, $this->h->clientB)['inserted']);
            $this->assertSame(3, $logs->fleetRowCount());
            // searches scope by the device's client (the sub-select the old join did) and rows still carry client_id
            $q = fn (array $over = []): LogCriteria => new LogCriteria(gmdate('Y-m-d H:i:s', self::T), gmdate('Y-m-d H:i:s', self::T + 100), ...$over);
            $all = $logs->search($q(), 10, 0);
            $this->assertSame(3, $all['total']);
            $this->assertSame([$this->h->clientB, $this->h->clientA, $this->h->clientA], array_map(static fn (array $r): int => (int) $r['client_id'], $all['rows']));
            $this->assertSame(2, $logs->search($q(['visibleClientIds' => [$this->h->clientA]]), 10, 0)['total']);
            $this->assertSame(1, $logs->search($q(['clientId' => $this->h->clientB]), 10, 0)['total']);
            $this->assertSame(0, $logs->reassignClient($a, $this->h->clientB), 'nothing to move without the column');
            // change records
            $this->assertSame(2, $changes->admit($a, [$this->record(1), $this->record(2)], self::CAPS, self::T + 10, 0, 0, 'aaaaaaaaaaaaaaaa', '{"ok":1}', $this->h->clientA)['inserted']);
            $this->assertSame(1, $changes->admit($b, [$this->record(3)], self::CAPS, self::T + 10, 0, 0, '', null, $this->h->clientB)['inserted']);
            $this->assertSame('aaaaaaaaaaaaaaaa', $changes->deviceLedger($a)['config_version'], 'the ledger statement works without the column too');
            $this->assertSame([2, 1, 3], [$changes->fleetRowCount([$this->h->clientA]), $changes->fleetRowCount([$this->h->clientB]), $changes->fleetRowCount()]);
            $c = fn (array $over = []): ChangeCriteria => new ChangeCriteria(gmdate('Y-m-d H:i:s', self::T), gmdate('Y-m-d H:i:s', self::T + 100), ...$over);
            $rows = $changes->search($c(['visibleClientIds' => [$this->h->clientB]]), 10, 0);
            $this->assertSame(1, $rows['total']);
            $this->assertSame($this->h->clientB, (int) $rows['rows'][0]['client_id']);
            $this->assertSame(2, $changes->search($c(['clientId' => $this->h->clientA]), 10, 0)['total']);
            $this->assertSame($this->h->clientA, (int) $changes->timeline($a, gmdate('Y-m-d H:i:s', self::T), gmdate('Y-m-d H:i:s', self::T + 100), 10)['rows'][0]['client_id']);
            $this->assertSame(['win.run' => 2], array_column($changes->countsByWatch(gmdate('Y-m-d H:i:s', self::T), [$this->h->clientA]), 'c', 'watch'));
            $this->assertSame(0, $changes->reassignClient($a, $this->h->clientB));
        } finally {
            $this->restore();
        }
        // the migration ran: the column is back, backfilled from the devices (so the rows written meanwhile keep their visibility), and new rows carry the client
        $this->assertSame((string) $this->h->clientA, (string) $this->h->one("SELECT client_id FROM endpoint_agent_log_events WHERE device_id = $a LIMIT 1"));
        $logs = $this->h->module->dataStores()->logs();
        $this->assertSame(1, $logs->admit($a, [$this->entry(9)], self::CAPS, self::T + 20, 0, 0, $this->h->clientA)['inserted']);
        $this->assertSame((string) $this->h->clientA, (string) $this->h->one("SELECT client_id FROM endpoint_agent_log_events WHERE device_id = $a AND message = 'm9'"));
        $this->assertSame(1, $logs->reassignClient($a, $this->h->clientB) >= 1 ? 1 : 0);
    }

    public function testWithTheColumnTheClientIsStoredAndNothingFallsBack(): void
    {
        $a = $this->device($this->h->clientA, 'FB-C');
        $this->h->module->dataStores()->logs()->admit($a, [$this->entry(1)], self::CAPS, self::T + 10, 0, 0, $this->h->clientA);
        $this->assertTrue(ClientColumn::present());
        $this->assertSame((string) $this->h->clientA, (string) $this->h->one("SELECT client_id FROM endpoint_agent_log_events WHERE device_id = $a"));
    }
}
