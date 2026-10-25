<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Verify\StoreVerifier;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Tests\Support\RmmTestCase;

/** The reconciliation of the control database with the hot store (#89 phase 3), on the MariaDB store and on PostgreSQL, with injected orphans, ledger drift and rollup / age problems. */
final class StoreVerifierTest extends RmmTestCase
{
    private ?PostgresConnectionInterface $pg = null;
    /** @var list<int> */
    private array $devs = [];
    private string $kind = 'mariadb';
    private string $newest = '';

    /** @return array<string,array{string}> */
    public static function kinds(): array
    {
        return ['mariadb' => ['mariadb'], 'postgres' => ['postgres']];
    }

    private function prepare(string $kind): void
    {
        $this->kind = $kind;
        $this->newest = gmdate('Y-m-d H:i:s', time() - 60);
        if ($kind === 'postgres') {
            if (($why = PostgresTestDatabase::unavailable()) !== null) {
                $this->markTestSkipped($why);
            }
            $this->pg = PostgresTestDatabase::connect(['breaker_open_s' => 0]);
            PostgresTestDatabase::prepare($this->pg);
            $this->h->q("UPDATE endpoint_agent_settings SET data_store = 'postgres' WHERE id = 1");
        }
        foreach ([1, 2, 3] as $i) {
            $this->h->q("INSERT INTO endpoint_agent_devices (install_id, hostname, client_id, last_checkin_at) VALUES ('" . sprintf('00000000-0000-4000-8000-%012d', $i) . "', 'H$i', 1, '{$this->newest}')");
        }
        $this->devs = array_map('intval', array_column($this->h->rows('SELECT device_id FROM endpoint_agent_devices ORDER BY device_id'), 'device_id'));
        foreach (['endpoint_agent_log_events', 'endpoint_agent_log_state', 'endpoint_agent_change_records', 'endpoint_agent_change_state', 'endpoint_agent_check_history', 'rmm_metric_hourly', 'rmm_metric_daily', 'rmm_metric_latest', 'rmm_metric_rollup_state'] as $t) {
            if ($kind === 'mariadb') {
                $this->h->q("DELETE FROM $t");
            }
        }
        // consistent baseline: each device has 2 log events with a matching ledger, one check history row, and fresh metric buckets
        foreach ($this->devs as $d) {
            $this->logEvents($d, 2);
            $this->ledger('endpoint_agent_log_state', $d, 2);
            $this->hot("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES ($d, 'cpu', 'ok', '', '{$this->newest}')");
        }
        $this->hot("INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value) VALUES (1, 'cpu', '', '" . gmdate('Y-m-d H:00:00', time() - 3600) . "', 1, 1, 1, 1)");
    }

    private function hot(string $sql): void
    {
        $this->kind === 'postgres' ? $this->pg->execute($sql) : $this->h->q($sql);
    }

    private function logEvents(int $device, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $hex = md5("$device-$i-" . mt_rand());
            $dedupe = $this->kind === 'postgres' ? "decode('$hex', 'hex')" : "UNHEX('$hex')";
            $this->hot("INSERT INTO endpoint_agent_log_events (device_id, client_id, occurred_at, received_at, level, source, code, message, dedupe) VALUES ($device, 1, '{$this->newest}', '{$this->newest}', 3, 'x', 1, 'm', $dedupe)");
        }
    }

    private function ledger(string $table, int $device, int $rows): void
    {
        $this->hot("INSERT INTO $table (device_id, row_count) VALUES ($device, $rows)");
    }

    /** @return array<string,mixed> */
    private function verify(int $gap = 3600): array
    {
        return (new StoreVerifier($this->h->db, new MutableClock(), $this->pg))->run($gap);
    }

    /** @param array<string,mixed> $report @return list<string> */
    private static function codes(array $report): array
    {
        $c = array_map(static fn (array $f): string => $f['code'] . ':' . $f['table'], $report['findings']);
        sort($c);

        return $c;
    }


    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function testAConsistentStoreIsClean(string $kind): void
    {
        $this->prepare($kind);
        $r = $this->verify();
        $this->assertSame([], $r['findings'], json_encode($r['findings']));
        $this->assertTrue($r['clean']);
        $this->assertSame($kind, $r['kind']);
        $this->assertSame(3, $r['counts']['control_devices']);
        $this->assertSame(6, $r['counts']['endpoint_agent_log_events']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function testOrphansLedgerDriftAndMissingLedgerAreFound(string $kind): void
    {
        $this->prepare($kind);
        $this->logEvents(9999, 3);                              // a device the control database does not know
        $this->ledger('endpoint_agent_log_state', 9999, 3);
        $this->hot('UPDATE endpoint_agent_log_state SET row_count = 7 WHERE device_id = ' . $this->devs[0]);   // drift: ledger says 7, rows are 2
        $this->hot('DELETE FROM endpoint_agent_log_state WHERE device_id = ' . $this->devs[2]);                // events without a ledger row
        $r = $this->verify();
        $this->assertSame(['events_without_ledger:endpoint_agent_log_events', 'ledger_drift:endpoint_agent_log_state', 'orphan_rows:endpoint_agent_log_events', 'orphan_rows:endpoint_agent_log_state'], self::codes($r));
        $by = [];
        foreach ($r['findings'] as $f) {
            $by[$f['code'] . ':' . $f['table']] = $f;
        }
        $this->assertSame([9999], $by['orphan_rows:endpoint_agent_log_events']['devices']);
        $this->assertSame(3, $by['orphan_rows:endpoint_agent_log_events']['rows']);
        $this->assertSame([['device_id' => $this->devs[0], 'ledger' => 7, 'rows' => 2]], $by['ledger_drift:endpoint_agent_log_state']['examples']);
        $this->assertSame([$this->devs[2]], $by['events_without_ledger:endpoint_agent_log_events']['devices']);
        $this->assertSame($r, $this->verify(), 'reading twice answers the same: the verifier writes nothing');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function testRollupWatermarkContradictionsAreFound(string $kind): void
    {
        $this->prepare($kind);
        $this->hot("INSERT INTO rmm_metric_daily (asset_id, metric_key, instance, day_start, samples, sum_value, min_value, max_value) VALUES (1, 'cpu', '', '" . gmdate('Y-m-d', time() - 86400 * 2) . "', 1, 1, 1, 1)");
        $r = $this->verify();
        $this->assertContains('rollup_no_watermark:rmm_metric_rollup_state', self::codes($r), 'daily rows without a watermark');

        $wm = gmdate('Y-m-d 00:00:00', time() - 86400 * 5);   // watermark older than the daily row
        $this->hot("INSERT INTO rmm_metric_rollup_state (name, watermark) VALUES ('metric_daily_rollup', '$wm')");
        $codes = self::codes($this->verify());
        $this->assertContains('rollup_daily_beyond_watermark:rmm_metric_daily', $codes);
        $this->assertNotContains('rollup_no_watermark:rmm_metric_rollup_state', $codes);

        // hourly buckets before the watermark on a day with no daily row
        $this->hot('UPDATE rmm_metric_rollup_state SET watermark = \'' . gmdate('Y-m-d 00:00:00', time() - 86400) . '\'');
        $this->hot("INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value) VALUES (1, 'cpu', '', '" . gmdate('Y-m-d 05:00:00', time() - 86400 * 4) . "', 1, 1, 1, 1)");
        $r = $this->verify();
        $missing = array_values(array_filter($r['findings'], static fn (array $f): bool => $f['code'] === 'rollup_missing_days'));
        $this->assertCount(1, $missing);
        $this->assertSame([gmdate('Y-m-d', time() - 86400 * 4)], $missing[0]['days']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function testAHotStoreOlderThanTheControlDatabaseIsAGap(string $kind): void
    {
        $this->prepare($kind);
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = '" . gmdate('Y-m-d H:i:s', time() + 7200) . "' WHERE device_id = " . $this->devs[0]);
        $r = $this->verify(3600);
        $gap = array_values(array_filter($r['findings'], static fn (array $f): bool => $f['code'] === 'hot_older_than_control'));
        $this->assertNotEmpty($gap);
        $tables = array_column($gap, 'table');
        $this->assertContains('endpoint_agent_check_history', $tables);
        $this->assertGreaterThan(7000, $gap[0]['gap_seconds']);
        $this->assertSame([], array_filter($this->verify(86400)['findings'], static fn (array $f): bool => $f['code'] === 'hot_older_than_control'), 'a larger tolerance accepts it');
    }

    public function testAnUnreachablePostgresIsReportedNotThrown(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $this->h->q("UPDATE endpoint_agent_settings SET data_store = 'postgres' WHERE id = 1");
        $bad = PdoPostgresConnection::fromConfig(PostgresTestDatabase::config(['port' => 1, 'breaker_open_s' => 0, 'connect_timeout' => 1]));
        $r = (new StoreVerifier($this->h->db, new MutableClock(), $bad))->run();
        $this->assertSame(['hot_store_unreachable:'], self::codes($r));
        $this->assertFalse($r['clean']);
    }

    public function testThePostgresKindWithoutAConnectionIsAUsageError(): void
    {
        $this->h->q("UPDATE endpoint_agent_settings SET data_store = 'postgres' WHERE id = 1");
        $this->expectException(\InvalidArgumentException::class);
        (new StoreVerifier($this->h->db, new MutableClock(), null))->run();
    }

    /** The command line tool: exit codes and JSON, on the MariaDB store. */
    public function testTheScriptExitCodes(): void
    {
        $this->prepare('mariadb');
        $run = function (array $args): array {
            $cmd = array_merge([PHP_BINARY, __DIR__ . '/../../../../scripts/rmm-store-verify.php'], $args);
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);

            return [proc_close($p), (string) $out, (string) $err];
        };
        $boot = '--bootstrap=' . __DIR__ . '/../../../Fixtures/rmm/cli-bootstrap.php';
        [$code, $out] = $run([$boot, '--json']);
        $this->assertSame(0, $code, $out);
        $this->assertTrue(json_decode($out, true)['clean']);
        $this->logEvents(9999, 1);
        [$code, $out] = $run([$boot]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('orphan_rows endpoint_agent_log_events', $out);
        [$code] = $run([]);
        $this->assertSame(2, $code, 'no bootstrap is a usage error');
        [$code] = $run([$boot, '--nope']);
        $this->assertSame(2, $code);
    }

    /** The script on the PostgreSQL store, using the connection STORED by the setting (password sealed with the bootstrap's SecretBox). */
    public function testTheScriptUsesTheStoredSealedConnection(): void
    {
        $this->prepare('postgres');
        $box = new \RivetCore\Testing\InMemorySecretBox(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $c = PostgresTestDatabase::config();
        $sealed = $box->encrypt((string) getenv('RIVETCORE_TEST_PG_PASS'));
        $this->h->q('DELETE FROM rmm_data_store_connection');
        $this->h->db->execute("INSERT INTO rmm_data_store_connection (id, host, port, db_name, db_user, password_sealed, sslmode, schema_name, updated_at) VALUES (1, ?, ?, ?, ?, ?, 'disable', 'public', NOW())",
            [$c->host, $c->port, $c->database, $c->user, $sealed]);
        $cmd = [PHP_BINARY, __DIR__ . '/../../../../scripts/rmm-store-verify.php', '--json', '--bootstrap=' . __DIR__ . '/../../../Fixtures/rmm/cli-bootstrap.php'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($p), $out . $err);
        $j = json_decode($out, true);
        $this->assertSame('postgres', $j['kind']);
        $this->assertSame(6, $j['counts']['endpoint_agent_log_events']);
        $this->assertStringNotContainsString((string) getenv('RIVETCORE_TEST_PG_PASS'), $out . $err);
        $this->h->q('DELETE FROM rmm_data_store_connection');
    }
}
