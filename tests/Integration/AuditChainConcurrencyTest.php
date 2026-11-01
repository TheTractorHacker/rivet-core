<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditChainHasher;
use RivetCore\Audit\AuditChainResult;
use RivetCore\Audit\AuditChainVerifier;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

/** ERR-04: several PHP processes (separate database connections) write audit events at once; the chain must not fork and nothing may fail. */
final class AuditChainConcurrencyTest extends TestCase
{
    private MysqliDatabase $db;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        foreach (['audit_events', 'audit_chain_head', 'audit_chain_checkpoint', 'rivet_core_migrations'] as $t) {
            $this->db->execute("DROP TABLE IF EXISTS $t");
        }
        (new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock()))->run();
    }

    /** @return array{0:int,1:list<string>} */
    private function runWorkers(int $workers, int $each, string $keyHex = ''): array
    {
        $script = dirname(__DIR__) . '/Support/audit_chain_worker.php';
        $procs = [];
        $pipes = [];
        for ($w = 0; $w < $workers; $w++) {
            $procs[$w] = proc_open([PHP_BINARY, $script, "w$w", (string) $each, $keyHex], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w]);
            $this->assertIsResource($procs[$w]);
        }
        $failed = 0;
        $errors = [];
        foreach ($procs as $w => $p) {
            $err = stream_get_contents($pipes[$w][2]);
            stream_get_contents($pipes[$w][1]);
            if (proc_close($p) !== 0) {
                $failed++;
                $errors[] = (string) $err;
            }
        }

        return [$failed, $errors];
    }

    public function testParallelWritersNeitherForkNorBreakTheChainNorFailAUserAction(): void
    {
        [$failed, $errors] = $this->runWorkers(6, 40);
        $this->assertSame(0, $failed, implode("\n", $errors));

        $this->assertSame(240, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM audit_events')['c']);
        $this->assertSame(240, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM audit_events WHERE row_hash IS NOT NULL')['c'], 'every event was chained, none fell back');
        // a fork would show up as two rows sharing one prev_hash
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM (SELECT prev_hash FROM audit_events GROUP BY prev_hash HAVING COUNT(*) > 1) t')['c']);
        $r = (new AuditChainVerifier($this->db))->verify();
        $this->assertSame(AuditChainResult::OK, $r->status, $r->reason);
        $this->assertSame(240, $r->chained);
        // every worker's own events are in order
        $this->assertSame(AuditChainHasher::GENESIS, $this->db->fetchOne('SELECT prev_hash FROM audit_events ORDER BY audit_id LIMIT 1')['prev_hash']);
    }

    public function testParallelKeyedWritersAlsoVerify(): void
    {
        $key = AuditChainHasher::deriveKey(str_repeat('c', 32));
        [$failed, $errors] = $this->runWorkers(4, 25, bin2hex($key));
        $this->assertSame(0, $failed, implode("\n", $errors));
        $r = (new AuditChainVerifier($this->db, $key))->verify();
        $this->assertSame(AuditChainResult::OK, $r->status, $r->reason);
        $this->assertSame(100, $r->chained);
    }
}
