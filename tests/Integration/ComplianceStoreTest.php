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

    public function testSharedReportExposesOnlyTheReducedView(): void
    {
        $this->db->execute('DELETE FROM compliance_shared_report');
        $sr = new \RivetCore\Compliance\SharedReport($this->db);
        self::assertFalse($sr->isPublished());
        self::assertNull($sr->current());
        $a = new Assessment(
            new \DateTimeImmutable('2026-10-05 10:00:00'),
            [['id' => 'mfa', 'title' => 'MFA', 'category' => 'Access', 'why' => 'w', 'controls' => ['soc2' => ['CC6.1']], 'status' => 'fail', 'status_label' => 'Fail', 'summary' => '3 of 9 agents have no MFA', 'detail' => 'alice@example.com', 'fix_path' => 'users.php', 'metrics' => ['agents' => 9]]],
            [['id' => 'x', 'title' => 'Access review', 'category' => 'Access', 'why' => 'w', 'controls' => ['hipaa' => ['164']], 'interval_days' => 90, 'state' => 'current', 'reviewed_on' => '2026-09-01', 'next_due_on' => '2026-12-01', 'reviewer_name' => 'Secret Person', 'note' => 'private evidence']],
            ['all' => ['score' => 50.0], 'soc2' => ['score' => 0.0]]
        );
        $id = (new SnapshotStore($this->db))->save($a, 1);
        try {
            $sr->publish(99999, null, 1);
            self::fail('published a missing snapshot');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $sr->publish($id, '  Hello  ', 1);
        $sr->publish($id, str_repeat('x', 5000), 2);
        self::assertTrue($sr->isPublished());
        $cur = $sr->current();
        self::assertSame(\RivetCore\Compliance\SharedReport::NOTE_MAX, mb_strlen($cur['note']));
        $json = json_encode($cur);
        foreach (['alice@example.com', 'users.php', 'Secret Person', 'private evidence', '3 of 9', 'CC6.1', 'metrics'] as $leak) {
            self::assertStringNotContainsString($leak, $json, "leaked: $leak");
        }
        self::assertSame('Fail', $cur['view']['automatic'][0]['status_label']);
        self::assertSame('current', $cur['view']['manual'][0]['state']);
        self::assertSame(50.0, $cur['view']['scores'][0]['score']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM compliance_shared_report')['c'], 'only one row ever');
        $sr->unpublish();
        self::assertFalse($sr->isPublished());
    }
}
