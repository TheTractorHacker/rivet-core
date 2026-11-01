<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditChainHasher;
use RivetCore\Audit\AuditChainResult;
use RivetCore\Audit\AuditChainVerifier;
use RivetCore\Audit\AuditChainWriter;
use RivetCore\Audit\AuditService;
use RivetCore\Compliance\Check\AuditChainIntactCheck;
use RivetCore\Compliance\Status;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Retention\RetentionService;
use RivetCore\Support\NullRequestContext;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

/** ERR-04: the audit hash chain against a real MariaDB/MySQL. */
final class AuditChainTest extends TestCase
{
    private MysqliDatabase $db;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        foreach (['audit_events', 'audit_chain_head', 'audit_chain_checkpoint', 'webhook_deliveries', 'integration_jobs', 'rivet_core_migrations'] as $t) {
            $this->db->execute("DROP TABLE IF EXISTS $t");
        }
        (new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock()))->run();
    }

    private function audit(?string $key = null): AuditService
    {
        return new AuditService($this->db, new NullRequestContext(), null, new AuditChainWriter($this->db, $key));
    }

    private function log(AuditService $a, int $n, string $prefix = 'e'): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $a->log('test.event', 7, 'thing', $i, 'create', "$prefix $i", ['n' => $i, 'ünï' => 'cødé / "q"']);
        }
    }

    private function verify(?string $key = null, ?array $anchor = null): AuditChainResult
    {
        return (new AuditChainVerifier($this->db, $key, 2))->verify($anchor);
    }

    /** @return list<int> */
    private function ids(): array
    {
        return array_map('intval', array_column($this->db->fetchAll('SELECT audit_id FROM audit_events ORDER BY audit_id'), 'audit_id'));
    }

    public function testChainedRowsVerifyAndEachLinksToThePreviousOne(): void
    {
        $this->log($this->audit(), 5);
        $r = $this->verify();
        $this->assertSame(AuditChainResult::OK, $r->status, $r->reason);
        $this->assertTrue($r->ok);
        $this->assertSame(5, $r->chained);
        $this->assertSame(0, $r->legacyUnchained);
        $rows = $this->db->fetchAll('SELECT prev_hash, row_hash FROM audit_events ORDER BY audit_id');
        $this->assertSame(AuditChainHasher::GENESIS, $rows[0]['prev_hash']);
        for ($i = 1; $i < 5; $i++) {
            $this->assertSame($rows[$i - 1]['row_hash'], $rows[$i]['prev_hash']);
        }
        $head = $this->db->fetchOne('SELECT * FROM audit_chain_head');
        $this->assertSame($rows[4]['row_hash'], $head['head_hash']);
    }

    public function testNothingChainedYetIsReportedAsEmptyNotAsOk(): void
    {
        $r = $this->verify();
        $this->assertSame(AuditChainResult::EMPTY, $r->status);
        $this->assertTrue($r->ok);
        $this->assertSame(0, $r->chained);
    }

    public function testLegacyRowsAreCountedAndHistoryIsNeverRewritten(): void
    {
        $this->db->execute("INSERT INTO audit_events (event_type, action) VALUES ('old.a', 'x'), ('old.b', 'x'), ('old.c', 'x')");
        $this->log($this->audit(), 3);
        $r = $this->verify();
        $this->assertSame(AuditChainResult::OK, $r->status, $r->reason);
        $this->assertSame(3, $r->chained);
        $this->assertSame(3, $r->legacyUnchained);
        $this->assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM audit_events WHERE row_hash IS NULL')['c'], 'legacy rows keep NULL hashes');
    }

    public function testAnEditedRowIsFoundAtItsId(): void
    {
        $this->log($this->audit(), 6);
        $ids = $this->ids();
        $this->db->execute("UPDATE audit_events SET summary = 'tampered' WHERE audit_id = ?", [$ids[3]]);
        $r = $this->verify();
        $this->assertSame(AuditChainResult::BROKEN, $r->status);
        $this->assertFalse($r->ok);
        $this->assertSame($ids[3], $r->firstBrokenId);
        $this->assertSame(3, $r->chained, 'rows before the break were verified');
    }

    public function testADeletedMiddleRowIsFound(): void
    {
        $this->log($this->audit(), 6);
        $ids = $this->ids();
        $this->db->execute('DELETE FROM audit_events WHERE audit_id = ?', [$ids[2]]);
        $r = $this->verify();
        $this->assertSame(AuditChainResult::BROKEN, $r->status);
        $this->assertSame($ids[3], $r->firstBrokenId);
    }

    public function testADeletedTailIsFoundThroughTheHeadRecord(): void
    {
        $this->log($this->audit(), 4);
        $ids = $this->ids();
        $this->db->execute('DELETE FROM audit_events WHERE audit_id = ?', [$ids[3]]);
        $r = $this->verify();
        $this->assertSame(AuditChainResult::BROKEN, $r->status);
        $this->assertStringContainsString('end of the chain', $r->reason);
    }

    public function testOldRowsDeletedByHandAreFoundButRetentionPruningIsNot(): void
    {
        $this->log($this->audit(), 5);
        $ids = $this->ids();
        $this->db->execute('DELETE FROM audit_events WHERE audit_id IN (?, ?)', [$ids[0], $ids[1]]);
        $r = $this->verify();
        $this->assertSame(AuditChainResult::BROKEN, $r->status, 'no checkpoint accounts for the missing head of the chain');
        $this->assertSame($ids[2], $r->firstBrokenId);
    }

    public function testTheChainSurvivesRetentionPruning(): void
    {
        $a = $this->audit();
        $w = new AuditChainWriter($this->db);
        $insert = 'INSERT INTO audit_events (event_type, action, created_at) VALUES (?, ?, ?)';
        foreach ([200, 150, 120] as $days) {
            $w->append($insert, ['old', 'a', date('Y-m-d H:i:s', time() - $days * 86400)]);
        }
        $this->log($a, 3);
        $this->assertSame(AuditChainResult::OK, $this->verify()->status);

        $deleted = (new RetentionService($this->db))->prune(90);
        $this->assertSame(3, $deleted['audit_events']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM audit_chain_checkpoint')['c']);
        $r = $this->verify();
        $this->assertSame(AuditChainResult::OK, $r->status, $r->reason);
        $this->assertSame(3, $r->chained);

        // new rows keep chaining after the prune, and a second prune with nothing old records nothing more
        $this->log($a, 2, 'later');
        $this->assertSame(AuditChainResult::OK, $this->verify()->status);
        (new RetentionService($this->db))->prune(90);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM audit_chain_checkpoint')['c']);

        // ... but deleting one more old row by hand is still caught
        $first = $this->ids()[0];
        $this->db->execute('DELETE FROM audit_events WHERE audit_id = ?', [$first]);
        $this->assertSame(AuditChainResult::BROKEN, $this->verify()->status);
    }

    public function testPruningEveryRowLeavesAnEmptyButAccountedForChainAndItContinues(): void
    {
        $w = new AuditChainWriter($this->db);
        $insert = 'INSERT INTO audit_events (event_type, action, created_at) VALUES (?, ?, ?)';
        $w->append($insert, ['old', 'a', date('Y-m-d H:i:s', time() - 300 * 86400)]);
        $w->append($insert, ['old', 'a', date('Y-m-d H:i:s', time() - 200 * 86400)]);
        (new RetentionService($this->db))->prune(90);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM audit_events')['c']);
        $r = $this->verify();
        $this->assertSame(AuditChainResult::EMPTY, $r->status, $r->reason);
        $this->log($this->audit(), 2);
        $r = $this->verify();
        $this->assertSame(AuditChainResult::OK, $r->status, $r->reason);
        $this->assertSame(2, $r->chained);
    }

    public function testAllRowsDeletedByHandIsNotMistakenForAFreshChain(): void
    {
        $this->log($this->audit(), 3);
        $this->db->execute('DELETE FROM audit_events');
        $this->assertSame(AuditChainResult::BROKEN, $this->verify()->status);
    }

    public function testUnchainedRowsAfterTheStartAreReported(): void
    {
        $this->log($this->audit(), 2);
        $this->db->execute("INSERT INTO audit_events (event_type, action) VALUES ('sneaky', 'x')");
        $this->log($this->audit(), 1);
        $r = $this->verify();
        $this->assertSame(AuditChainResult::UNCHAINED_ROWS, $r->status);
        $this->assertFalse($r->ok);
        $this->assertSame(1, $r->unchainedAfterStart);
        $this->assertSame(3, $r->chained);
        $this->assertNotNull($r->firstBrokenId);
    }

    public function testAnEventIsNeverLostWhenTheChainTablesAreMissing(): void
    {
        $this->db->execute('DROP TABLE audit_chain_head');
        $a = $this->audit();
        $a->log('x.y', 1, null, null, 'act', 'still stored');
        $a->log('x.y', 1, null, null, 'act', 'and again');
        $this->assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) c FROM audit_events WHERE event_type = 'x.y'")['c']);
        $this->assertSame(AuditChainResult::NOT_INSTALLED, $this->verify()->status);
    }

    public function testKeyedChainNeedsTheSameKey(): void
    {
        $key = AuditChainHasher::deriveKey(str_repeat('k', 32));
        $this->log($this->audit($key), 3);
        $this->assertSame(AuditChainResult::OK, $this->verify($key)->status);
        $this->assertSame(AuditChainResult::KEY_UNAVAILABLE, $this->verify(null)->status);
        $this->assertSame(AuditChainResult::KEY_MISMATCH, $this->verify(AuditChainHasher::deriveKey(str_repeat('z', 32)))->status);
        // without the key an attacker cannot recompute a valid hash for an edited row
        $id = $this->ids()[1];
        $this->db->execute("UPDATE audit_events SET summary = 'x' WHERE audit_id = ?", [$id]);
        $plain = AuditChainHasher::rowHash($this->db->fetchOne('SELECT * FROM audit_events WHERE audit_id = ?', [$id]), (string) $this->db->fetchOne('SELECT prev_hash FROM audit_events WHERE audit_id = ?', [$id])['prev_hash'], null);
        $this->db->execute('UPDATE audit_events SET row_hash = ? WHERE audit_id = ?', [$plain, $id]);
        $this->assertSame(AuditChainResult::BROKEN, $this->verify($key)->status);
    }

    public function testWritingWithADifferentKeyNeverLosesTheEventButLeavesItUnchained(): void
    {
        $this->log($this->audit(AuditChainHasher::deriveKey(str_repeat('a', 32))), 2);
        $this->log($this->audit(null), 1);
        $this->assertSame(3, count($this->ids()));
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM audit_events WHERE row_hash IS NOT NULL')['c']);
    }

    public function testExternalAnchorCatchesAFullyRecomputedChainAndTailRemoval(): void
    {
        $this->log($this->audit(), 4);
        $anchor = (new AuditChainVerifier($this->db))->exportHead();
        $this->assertNotNull($anchor);
        $this->assertSame('plain', $anchor['mode']);
        $this->assertSame(4, $this->verify(null, $anchor)->chained);
        $this->assertSame('verified', $this->verify(null, $anchor)->anchor);

        // a database administrator rewrites the last row AND recomputes its hash and the head: only the off-database anchor notices
        $id = $anchor['id'];
        $this->db->execute("UPDATE audit_events SET summary = 'rewritten' WHERE audit_id = ?", [$id]);
        $row = $this->db->fetchOne('SELECT * FROM audit_events WHERE audit_id = ?', [$id]);
        $h = AuditChainHasher::rowHash($row, (string) $row['prev_hash'], null);
        $this->db->execute('UPDATE audit_events SET row_hash = ? WHERE audit_id = ?', [$h, $id]);
        $this->db->execute('UPDATE audit_chain_head SET head_hash = ?', [$h]);
        $this->assertSame(AuditChainResult::OK, $this->verify()->status, 'consistent rewrite: not detectable inside the database');
        $this->assertSame(AuditChainResult::BROKEN, $this->verify(null, $anchor)->status);

        // an anchor older than the oldest kept row cannot be checked, which is reported, not failed
        $this->db->execute('DELETE FROM audit_events WHERE audit_id < ?', [$this->ids()[2]]);
        $this->db->execute('INSERT INTO audit_chain_checkpoint (anchor_hash, rows_pruned) SELECT prev_hash, 2 FROM audit_events ORDER BY audit_id LIMIT 1');
        $old = ['id' => 1, 'hash' => str_repeat('a', 64)];
        $this->assertSame('predates_kept_rows', $this->verify(null, $old)->anchor);
    }

    public function testExportHeadIsNullBeforeAnythingIsChained(): void
    {
        $this->assertNull((new AuditChainVerifier($this->db))->exportHead());
    }

    public function testTheComplianceCheckPassesWarnsAndFails(): void
    {
        $check = new AuditChainIntactCheck(new AuditChainVerifier($this->db));
        $this->assertSame(Status::Warn, $check->run()->status);
        $this->log($this->audit(), 3);
        $this->assertSame(Status::Pass, $check->run()->status);
        $this->db->execute("UPDATE audit_events SET action = 'zz' WHERE audit_id = ?", [$this->ids()[1]]);
        $this->assertSame(Status::Fail, $check->run()->status);
    }

    public function testMigrationLeavesExistingRowsAloneAndIsIdempotent(): void
    {
        $this->db->execute("INSERT INTO audit_events (event_type, action) VALUES ('keep', 'me')");
        $this->db->execute('DROP TABLE rivet_core_migrations');
        (new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock()))->run();
        $row = $this->db->fetchOne("SELECT prev_hash, row_hash FROM audit_events WHERE event_type = 'keep'");
        $this->assertNull($row['prev_hash']);
        $this->assertNull($row['row_hash']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM audit_chain_head')['c']);
    }

    public function testUnicodeAndNullFieldsHashStably(): void
    {
        $a = $this->audit();
        $a->log('u.n', null, null, null, 'act', null, []);
        $a->log('u.n', 2, 'thing', 'üñí/😀', 'act', "multi\nline \"q\" 😀", ['k' => ["a" => "ü"]]);
        $this->assertSame(AuditChainResult::OK, $this->verify()->status);
    }
}
