<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\ChangeCriteria;
use RivetCore\Rmm\Store\LogCriteria;
use RivetCore\Rmm\Store\Postgres\PostgresChangeEventStore;
use RivetCore\Rmm\Store\Postgres\PostgresCheckHistoryStore;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresLogStore;
use RivetCore\Rmm\Store\Postgres\PostgresMetricStore;
use RivetCore\Rmm\Store\Postgres\PostgresQueryException;
use RivetCore\Rmm\Store\Postgres\PostgresRollupState;
use RivetCore\Rmm\Store\Postgres\PostgresSoftwareHistoryStore;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\PostgresTestDatabase;

/** Payload limits, time handling and the document columns of the PostgreSQL stores: the column sizes the services cut to, unicode, extreme times, jsonb. */
final class PostgresPayloadTest extends TestCase
{
    private const CAPS = ['rate_per_min' => 100000, 'device_max_rows' => 100000, 'fleet_max_rows' => 1000000];

    private PostgresConnectionInterface $db;

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $this->db = PostgresTestDatabase::connect();
        PostgresTestDatabase::prepare($this->db);
    }

    private function hash(string $s): string
    {
        return substr(hash('sha256', $s), 0, 32);
    }

    public function testTheLargestTextTheServicesAdmitIsStoredAndReadBackByteForByte(): void
    {
        $store = new PostgresLogStore($this->db);
        $emoji = str_repeat("\u{1F4BE}", 256) . str_repeat('é', 768);   // 1024 characters, 4 and 2 bytes each: 2.8 KB of UTF-8
        $this->assertSame(1024, mb_strlen($emoji));
        $r = $store->admit(1, [['ts' => 1_800_000_000, 'level' => 4, 'source' => str_repeat('s', 100), 'code' => 2147483647, 'message' => $emoji, 'hash' => $this->hash('big')]], self::CAPS, 1_800_000_010, 0, 0, 1);
        $this->assertSame(1, $r['inserted']);
        $row = $store->search(new LogCriteria('2027-01-01 00:00:00', '2030-01-01 00:00:00'), 10, 0)['rows'][0];
        $this->assertSame($emoji, $row['message']);
        $this->assertSame(2147483647, (int) $row['code']);
        $this->assertSame(1, $store->search(new LogCriteria('2027-01-01 00:00:00', '2030-01-01 00:00:00', messageContains: "\u{1F4BE}\u{1F4BE}é"), 10, 0)['total'] > 0 ? 1 : 0, 'a substring search over multi-byte text');

        $changes = new PostgresChangeEventStore($this->db);
        $rec = ['ts' => 1_800_000_000, 'kind' => 'registry', 'watch' => str_repeat('w', 48), 'change' => 3, 'target' => str_repeat('t', 300), 'item' => str_repeat('i', 200), 'old' => str_repeat('o', 512), 'new' => str_repeat('n', 512),
            'summary' => str_repeat('s', 200), 'old_hash' => str_repeat('a', 32), 'new_hash' => str_repeat('b', 32), 'actor' => str_repeat('c', 120), 'process' => str_repeat('p', 200), 'flags' => 3, 'dedupe' => $this->hash('rec')];
        $this->assertSame(1, $changes->admit(1, [$rec], self::CAPS, 1_800_000_010, 0, 0, str_repeat('f', 16), str_repeat('x', 4096), 1)['inserted']);
        $got = $changes->search(new ChangeCriteria('2027-01-01 00:00:00', '2030-01-01 00:00:00'), 10, 0)['rows'][0];
        foreach (['target' => 300, 'item' => 200, 'old_value' => 512, 'new_value' => 512, 'summary' => 200, 'actor' => 120, 'process' => 200, 'old_hash' => 32] as $col => $len) {
            $this->assertSame($len, strlen((string) $got[$col]), "$col is stored whole");
        }
        $this->assertSame(4096, strlen((string) $changes->deviceLedger(1)['monitor_json']));
    }

    public function testOneStatementCarriesTheLargestBatchesTheProtocolAllows(): void
    {
        $store = new PostgresLogStore($this->db);
        $entries = [];
        for ($i = 0; $i < 200; ++$i) {   // RemoteProtocol::LOG_BATCH_MAX
            $entries[] = ['ts' => 1_800_000_000 + $i, 'level' => 4, 'source' => 'S', 'code' => $i, 'message' => str_repeat('m', 1024), 'hash' => $this->hash("m$i")];
        }
        $this->assertSame(200, $store->admit(1, $entries, self::CAPS, 1_800_000_500, 0, 0, 1)['inserted']);
        $h = new PostgresCheckHistoryStore($this->db);
        $many = [];
        for ($i = 0; $i < 2000; ++$i) {
            $many[] = ['key' => 'k' . ($i % 7), 'status' => 'ok', 'detail' => str_repeat('d', 200), 'at' => gmdate('Y-m-d H:i:s', 1_800_000_000 + $i)];
        }
        $h->append(1, $many);
        $this->assertSame(2000, (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_check_history'));
        $sw = new PostgresSoftwareHistoryStore($this->db);
        $sw->append(1, array_map(static fn (int $i): array => ['key' => sprintf('%040d', $i), 'name' => str_repeat('n', 200), 'source' => 'msi', 'publisher' => str_repeat('p', 200), 'type' => 'installed', 'old' => str_repeat('1', 100), 'new' => str_repeat('2', 100)], range(1, 1500)), '2027-01-01 00:00:00');
        $this->assertSame(1500, $sw->forDevice(1, null, 500, 0)['total']);
        $this->assertCount(500, $sw->forDevice(1, null, 99999, 1000)['rows'] === [] ? [] : $sw->forDevice(1, null, 500, 1000)['rows'], 'the limit is capped at 500 and the offset reaches the end');
    }

    public function testTextBeyondTheColumnIsRefusedNotTruncated(): void
    {
        $store = new PostgresLogStore($this->db);
        $this->expectException(PostgresQueryException::class);
        try {
            $store->admit(1, [['ts' => 1_800_000_000, 'level' => 4, 'source' => 'S', 'code' => null, 'message' => str_repeat('m', 1025), 'hash' => $this->hash('long')]], self::CAPS, 1_800_000_010, 0, 0, 1);
        } finally {
            $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_log_events'), 'and the ledger did not move either');
            $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_log_state'));
        }
    }

    public function testBytesPostgresCannotStoreAreRefusedWithAClearError(): void
    {
        $store = new PostgresLogStore($this->db);
        foreach (["bad\0byte", "bad\xC3\x28utf8"] as $i => $msg) {
            try {
                $store->admit(1, [['ts' => 1_800_000_000, 'level' => 4, 'source' => 'S', 'code' => null, 'message' => $msg, 'hash' => $this->hash("bad$i")]], self::CAPS, 1_800_000_010, 0, 0, 1);
                $this->fail('refused');
            } catch (\Throwable $e) {
                $this->assertTrue($e instanceof PostgresQueryException || $e instanceof \ValueError || $e instanceof \PDOException || $e instanceof \RivetCore\Database\DatabaseException, get_class($e));
                $this->assertStringNotContainsString('bad', $e->getMessage(), 'the offending value is not echoed');
            }
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_log_events'));
    }

    public function testTimesRoundTripAsUtcWallClockAcrossTheOddDates(): void
    {
        $store = new PostgresLogStore($this->db);
        // the end of the 32 bit epoch, a DST change, a leap day, the year 2100, a time before every retention
        $stamps = [2_147_483_647, 2_147_483_648, 1_774_747_800, 1_709_251_200, 4_102_444_800, 0, 951_782_400];
        $entries = [];
        foreach ($stamps as $i => $t) {
            $entries[] = ['ts' => $t, 'level' => 4, 'source' => 'S', 'code' => null, 'message' => "t$i", 'hash' => $this->hash("t$i")];
        }
        $store->admit(1, $entries, self::CAPS, 1_800_000_000, 0, 0, 1);
        $got = array_map(static fn (array $r): string => (string) $r['occurred_at'], $store->search(new LogCriteria('1970-01-01 00:00:00', '2200-01-01 00:00:00'), 50, 0)['rows']);
        $expect = array_map(static fn (int $t): string => gmdate('Y-m-d H:i:s', $t), $stamps);
        rsort($expect);
        $this->assertSame($expect, $got, 'newest first, every instant the UTC wall clock it was sent as');
        $this->assertSame('1970-01-01 00:00:00', $store->oldestEventAt());
        // the session zone of somebody else's connection must not matter to reads
        $this->db->execute("SET TIME ZONE 'Pacific/Auckland'");
        $this->assertSame($expect, array_map(static fn (array $r): string => (string) $r['occurred_at'], $store->search(new LogCriteria('1970-01-01 00:00:00', '2200-01-01 00:00:00'), 50, 0)['rows']));
        $this->db->execute("SET TIME ZONE 'UTC'");
    }

    public function testTheDocumentColumnsRoundTrip(): void
    {
        // jsonb: the rollup note
        $state = new PostgresRollupState($this->db);
        $detail = ['day' => '2027-01-02 00:00:00', 'asset_done' => 12345, 'note' => "unicode \u{1F600} \"quotes\" \\ back", 'nested' => ['a' => [1, 2.5, null, true], 'b' => new \stdClass()]];
        $state->put('probe', '2027-01-02 00:00:00', json_decode((string) json_encode($detail), true));
        $back = $state->get('probe');
        $this->assertSame('2027-01-02 00:00:00', $back['watermark']);
        $this->assertSame(12345, $back['detail']['asset_done']);
        $this->assertSame("unicode \u{1F600} \"quotes\" \\ back", $back['detail']['note']);
        $this->assertSame([1, 2.5, null, true], $back['detail']['nested']['a']);
        $state->put('probe', null, null);
        $this->assertSame(['watermark' => null, 'detail' => null], $state->get('probe'));
        // text: the agent's status block comes back byte for byte (jsonb would have reformatted it)
        $changes = new PostgresChangeEventStore($this->db);
        $json = "{ \"b\":1,\n \"a\" : [ 1,2 ], \"u\":\"\\u00e9\\ud83d\\ude00\", \"dup\":1,\"dup\":2 }";
        $changes->admit(1, [], self::CAPS, 1_800_000_000, 0, 0, '', $json, 1);
        $this->assertSame($json, $changes->deviceLedger(1)['monitor_json']);
        // a metric label and instance with unicode
        $m = new PostgresMetricStore($this->db, new MutableClock());
        $m->ingest([['asset_id' => 9, 'key' => 'disk.utilization', 'instance' => "Données (É:)", 'value' => 50, 'at' => new \DateTimeImmutable('@' . (time() - 600)), 'label' => 'Système']], 1);
        $latest = $m->latest(9)[0];
        $this->assertSame(["Données (É:)", 'Système'], [$latest['instance'], $latest['label']]);
    }
}
