<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\AttestationStore;
use RivetCore\Compliance\Assessment;
use RivetCore\Compliance\SnapshotStore;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

final class ComplianceStoreTest extends TestCase
{
    private MysqliDatabase $db;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        (new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock()))->run();
        $this->db->execute('DELETE FROM compliance_attestations');
        $this->db->execute('DELETE FROM compliance_snapshots');
    }

    public function testAttestationsNewestWinsAndValidation(): void
    {
        $s = new AttestationStore($this->db);
        $today = new \DateTimeImmutable('2026-10-05');
        $s->record('policy_review', 1, 'Alex', '2026-01-01', null, 'first', $today);
        $s->record('policy_review', 1, 'Blake', '2026-09-30', '2027-09-30', "second\n", $today);
        $s->record('access_review', null, 'Sam', '2026-10-05', null, null, $today);
        $latest = $s->latestPerItem();
        self::assertSame('Blake', $latest['policy_review']['reviewer_name']);
        self::assertSame('2027-09-30', $latest['policy_review']['next_due_on']);
        self::assertNull($latest['access_review']['note']);
        self::assertCount(2, $s->history('policy_review'));

        foreach ([['Bad Item!', 'A', '2026-01-01', null], ['x', ' ', '2026-01-01', null], ['x', 'A', '2026-02-30', null], ['x', 'A', '2027-01-01', null], ['x', 'A', '2026-05-01', '2026-04-01']] as [$id, $who, $on, $next]) {
            try {
                $s->record($id, 1, $who, $on, $next, null, $today);
                self::fail("accepted $id/$who/$on/$next");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSnapshots(): void
    {
        $st = new SnapshotStore($this->db);
        self::assertNull($st->latestTakenAt());
        $a = new Assessment(new \DateTimeImmutable('2026-10-05 10:00:00'), [['id' => 'a']], [], ['all' => ['score' => 80.0]]);
        $id = $st->save($a, 7, 'bogus', '26.10.15');
        $list = $st->list();
        self::assertSame('manual', $list[0]['trigger_type']);
        self::assertEquals(80.0, $list[0]['summaries']['all']['score']);
        $got = $st->get($id);
        self::assertEquals($a->toArray(), $got['assessment']->toArray());
        self::assertNull($st->get(99999));
        self::assertNotNull($st->latestTakenAt());
    }
}
