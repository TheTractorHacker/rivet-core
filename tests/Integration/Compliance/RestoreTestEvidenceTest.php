<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Compliance;

use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupStore;
use RivetCore\Compliance\Backup\RestoreTestFact;
use RivetCore\Compliance\Backup\RestoreTestStore;
use RivetCore\Compliance\Backup\RowCounts;
use RivetCore\Compliance\Check\RestoreTestRecencyCheck;
use RivetCore\Compliance\Profile\FixedRequirementsProvider;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Compliance\Status;
use RivetCore\Tests\Support\FakeBackupFacts;
use RivetCore\Tests\Support\ProfileTestCase;

final class RestoreTestEvidenceTest extends ProfileTestCase
{
    private RestoreTestStore $tests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tests = new RestoreTestStore($this->db, $this->clock);
    }

    private function at(string $spec): \DateTimeImmutable
    {
        return $this->clock->now()->modify($spec);
    }

    /** @param list<string> $stores */
    private function record(string $when = '-2 days', bool $passed = true, array $stores = [BackupStore::CONTROL_DB], ?array $counts = null, string $dataStore = 'mariadb'): int
    {
        $counts ??= [['store' => BackupStore::CONTROL_DB, 'table' => 'audit_events', 'expected' => 120, 'restored' => 120]];

        return $this->tests->record($this->at($when), $passed, $stores, $dataStore, $this->at($when)->modify('-1 day'), $counts, null, 420, 'Restored into a scratch VM', 5, 'Sam Operator');
    }

    private function check(int $days = 92, string $kind = 'mariadb', ?FakeBackupFacts $facts = null): RestoreTestRecencyCheck
    {
        $p = new FixedRequirementsProvider(new FrameworkProfile('contract_x', 'Contract X', '', 1, false, [new Requirement(RequirementType::RESTORE_TEST_MAX_AGE, null, $days)]));

        return new RestoreTestRecencyCheck($p, $this->tests, $facts, $this->clock, fn () => $kind, '/admin/compliance');
    }

    public function testRecordAndReadBack(): void
    {
        self::assertNull($this->tests->latest());
        $id = $this->record();
        $r = $this->tests->latest();
        self::assertSame($id, $r?->id);
        self::assertTrue($r->passed);
        self::assertSame('Sam Operator', $r->recordedByName);
        self::assertSame([BackupStore::CONTROL_DB], $r->stores);
        self::assertTrue($r->countsCompared());
        self::assertTrue($r->countsMatch());
        self::assertSame(420, $r->durationS);
        self::assertSame('Restored into a scratch VM', $r->note);
        self::assertEqualsWithDelta($this->at('-2 days')->getTimestamp(), $r->testedAt->getTimestamp(), 2);
        $this->record('-10 days');
        self::assertSame([$id], [$this->tests->list()[0]->id], 'newest TEST date first, not newest record');
        self::assertCount(2, $this->tests->list());
    }

    public function testInvalidRecordsAreRefusedAndStoreNothing(): void
    {
        $ok = [['store' => 'control_db', 'table' => 'audit_events', 'expected' => 5, 'restored' => 5]];
        $cases = [
            'future' => fn () => $this->tests->record($this->at('+1 day'), true, ['control_db'], 'mariadb', null, $ok, null, null, null, 1, 'A'),
            'ancient' => fn () => $this->tests->record($this->at('-30 years'), true, ['control_db'], 'mariadb', null, $ok, null, null, null, 1, 'A'),
            'backup newer than test' => fn () => $this->tests->record($this->at('-2 days'), true, ['control_db'], 'mariadb', $this->at('-1 day'), $ok, null, null, null, 1, 'A'),
            'no stores' => fn () => $this->tests->record($this->at('-1 day'), true, [], 'mariadb', null, [], null, null, null, 1, 'A'),
            'unknown store' => fn () => $this->tests->record($this->at('-1 day'), true, ['mongo'], 'mariadb', null, [], null, null, null, 1, 'A'),
            'unknown data store' => fn () => $this->tests->record($this->at('-1 day'), true, ['control_db'], 'oracle', null, [], null, null, null, 1, 'A'),
            'passed with different counts' => fn () => $this->tests->record($this->at('-1 day'), true, ['control_db'], 'mariadb', null, [['store' => 'control_db', 'table' => 'audit_events', 'expected' => 5, 'restored' => 4]], null, null, null, 1, 'A'),
            'count of a store not tested' => fn () => $this->tests->record($this->at('-1 day'), true, ['control_db'], 'postgres', null, [['store' => 'rmm_postgres', 'table' => 'x', 'expected' => 1, 'restored' => 1]], null, null, null, 1, 'A'),
            'bad table' => fn () => $this->tests->record($this->at('-1 day'), true, ['control_db'], 'mariadb', null, [['store' => 'control_db', 'table' => 'a; DROP TABLE x', 'expected' => 1, 'restored' => 1]], null, null, null, 1, 'A'),
            'negative count' => fn () => $this->tests->record($this->at('-1 day'), false, ['control_db'], 'mariadb', null, [['store' => 'control_db', 'table' => 'a', 'expected' => -1, 'restored' => 1]], null, null, null, 1, 'A'),
            'string count' => fn () => $this->tests->record($this->at('-1 day'), false, ['control_db'], 'mariadb', null, [['store' => 'control_db', 'table' => 'a', 'expected' => '1', 'restored' => 1]], null, null, null, 1, 'A'),
            'extra key' => fn () => $this->tests->record($this->at('-1 day'), false, ['control_db'], 'mariadb', null, [['store' => 'control_db', 'table' => 'a', 'expected' => 1, 'restored' => 1, 'sql' => 'x']], null, null, null, 1, 'A'),
            'duplicate table' => fn () => $this->tests->record($this->at('-1 day'), true, ['control_db'], 'mariadb', null, [$ok[0], $ok[0]], null, null, null, 1, 'A'),
            'too many tables' => fn () => $this->tests->record($this->at('-1 day'), true, ['control_db'], 'mariadb', null, array_map(fn (int $i) => ['store' => 'control_db', 'table' => "t$i", 'expected' => 1, 'restored' => 1], range(1, 201)), null, null, null, 1, 'A'),
            'no name' => fn () => $this->tests->record($this->at('-1 day'), true, ['control_db'], 'mariadb', null, $ok, null, null, null, 1, '  '),
            'secret in note' => fn () => $this->tests->record($this->at('-1 day'), true, ['control_db'], 'mariadb', null, $ok, null, null, 'restored with password=hunter2', 1, 'A'),
            'duration' => fn () => $this->tests->record($this->at('-1 day'), true, ['control_db'], 'mariadb', null, $ok, null, -5, null, 1, 'A'),
        ];
        foreach ($cases as $name => $op) {
            try {
                $op();
                self::fail("accepted: $name");
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage(), $name);
            }
        }
        self::assertNull($this->tests->latest(), 'nothing was stored');
    }

    public function testAFailedTestWithDifferingCountsIsRecordable(): void
    {
        $id = $this->tests->record($this->at('-1 day'), false, ['control_db'], 'mariadb', null, [['store' => 'control_db', 'table' => 'audit_events', 'expected' => 5, 'restored' => 4]], null, null, null, 1, 'A');
        $r = $this->tests->latest();
        self::assertSame($id, $r?->id);
        self::assertFalse($r->passed);
        self::assertFalse($r->countsMatch());
        self::assertSame([['store' => 'control_db', 'table' => 'audit_events', 'expected' => 5, 'restored' => 4]], $r->mismatches());
    }

    public function testPairingCountsTreatsAMissingRestoredTableAsZero(): void
    {
        $pairs = RestoreTestStore::pairCounts('control_db', ['audit_events' => 10, 'compliance_snapshots' => 3], ['audit_events' => 10]);
        self::assertSame([['store' => 'control_db', 'table' => 'audit_events', 'expected' => 10, 'restored' => 10], ['store' => 'control_db', 'table' => 'compliance_snapshots', 'expected' => 3, 'restored' => 0]], $pairs);
        $this->expectException(\InvalidArgumentException::class);
        $this->tests->record($this->at('-1 day'), true, ['control_db'], 'mariadb', null, $pairs, null, null, null, 1, 'A');
    }

    public function testRowCountsCountOnlyKnownTables(): void
    {
        $this->db->execute('DELETE FROM compliance_restore_tests');
        $this->record();
        self::assertSame(['compliance_restore_tests' => 1], RowCounts::control($this->db, ['compliance_restore_tests']));
        foreach (['users', 'audit_events; DROP TABLE x', '`compliance_restore_tests`', ''] as $bad) {
            try {
                RowCounts::control($this->db, [$bad]);
                self::fail('counted ' . $bad);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheOutputOfRmmStoreVerifyIsReducedToEvidence(): void
    {
        $out = json_encode(['kind' => 'postgres', 'checked_at' => '2026-10-26 10:00:00', 'clean' => false, 'counts' => ['control_devices' => 7, 'endpoint_agent_log_events' => 120, 'rmm_patch_history' => 0],
            'findings' => [['code' => 'orphan_rows', 'severity' => 'warning', 'table' => 'endpoint_agent_log_events', 'count' => 2, 'devices' => [41, 42], 'detail' => 'two ids']]]);
        $s = RestoreTestStore::verifySummary((string) $out);
        self::assertSame(['kind' => 'postgres', 'checked_at' => '2026-10-26 10:00:00', 'clean' => false, 'finding_codes' => ['orphan_rows' => 1], 'table_counts' => ['endpoint_agent_log_events' => 120, 'rmm_patch_history' => 0]], $s);
        self::assertStringNotContainsString('two ids', (string) json_encode($s));
        foreach (['', 'x', '[]', '{"kind":"postgres"}', '{"kind":"nope","checked_at":"2026-10-26 10:00:00","clean":true,"counts":{},"findings":[]}',
            '{"kind":"postgres","checked_at":"yesterday","clean":true,"counts":{},"findings":[]}',
            '{"kind":"postgres","checked_at":"2026-10-26 10:00:00","clean":true,"counts":{},"findings":[{"code":"orphan_rows"}]}',
            '{"kind":"postgres","checked_at":"2026-10-26 10:00:00","clean":true,"counts":{},"findings":["x"]}'] as $bad) {
            try {
                RestoreTestStore::verifySummary($bad);
                self::fail('accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        // and it can be attached to a record, then shows up as evidence in the check
        $this->tests->record($this->at('-1 day'), true, ['control_db', 'rmm_postgres'], 'postgres', null, [], $s, null, null, 1, 'Sam');
        $r = $this->check(92, 'postgres')->run();
        self::assertStringContainsString('reconciliation (rmm-store-verify) reported 1 finding(s): orphan_rows', (string) $r->detail);
    }

    public function testTheCheckAcrossEveryEvidenceSituation(): void
    {
        $c = $this->check();
        $r = $c->run();
        self::assertSame(Status::Fail, $r->status);
        self::assertStringContainsString('none is on record', (string) $r->detail);

        $this->record('-2 days');
        $r = $c->run();
        self::assertSame(Status::Pass, $r->status, (string) $r->detail);
        self::assertStringContainsString('Met: the row counts of the restored data match the source.', (string) $r->detail);
        self::assertStringContainsString('Sam Operator', (string) $r->detail);
        self::assertEqualsWithDelta(2.0, $r->metrics['age_days'], 0.1);

        // too old
        self::assertSame(Status::Fail, $this->check(1)->run()->status);

        // a newer failed test beats an older pass
        $this->record('-1 day', false, [BackupStore::CONTROL_DB], [['store' => 'control_db', 'table' => 'audit_events', 'expected' => 5, 'restored' => 4]]);
        $r = $c->run();
        self::assertSame(Status::Fail, $r->status);
        self::assertSame('The last restore test failed.', $r->summary);
    }

    public function testEvidenceWithoutCountsOrWithoutThePostgresStoreIsAWarning(): void
    {
        $this->record('-1 day', true, [BackupStore::CONTROL_DB], []);
        $r = $this->check()->run();
        self::assertSame(Status::Warn, $r->status);
        self::assertStringContainsString('no row counts were compared', $r->summary);

        $this->db->execute('DELETE FROM compliance_restore_tests');
        $this->record('-1 day', true, [BackupStore::CONTROL_DB]);
        self::assertSame(Status::Pass, $this->check(92, 'mariadb')->run()->status, 'control database only is complete on MariaDB');
        $r = $this->check(92, 'postgres')->run();
        self::assertSame(Status::Warn, $r->status, 'the PostgreSQL store was not restored');
        self::assertStringContainsString('PostgreSQL RMM store', $r->summary);

        $this->db->execute('DELETE FROM compliance_restore_tests');
        $this->record('-1 day', true, [BackupStore::CONTROL_DB, BackupStore::RMM_POSTGRES], [
            ['store' => 'control_db', 'table' => 'audit_events', 'expected' => 9, 'restored' => 9], ['store' => 'rmm_postgres', 'table' => 'endpoint_agent_log_events', 'expected' => 500, 'restored' => 500],
        ], 'postgres');
        self::assertSame(Status::Pass, $this->check(92, 'postgres')->run()->status);
    }

    public function testTheEditionsOwnScheduledTestCountsWhenItIsNewer(): void
    {
        $facts = new FakeBackupFacts(new BackupFacts(true, $this->at('-1 hour'), [BackupStore::CONTROL_DB], ['local'], null, null, null, null, new RestoreTestFact($this->at('-3 days'), true, [BackupStore::CONTROL_DB], true)));
        $c = $this->check(92, 'mariadb', $facts);
        self::assertSame(Status::Pass, $c->run()->status, 'no recorded test, but the edition ran one');
        $this->record('-10 days', false, [BackupStore::CONTROL_DB], [['store' => 'control_db', 'table' => 't', 'expected' => 1, 'restored' => 0]]);
        self::assertSame(Status::Pass, $c->run()->status, 'the edition test (3 days) is newer than the recorded failed one (10 days)');
        $this->record('-1 day', false, [BackupStore::CONTROL_DB], [['store' => 'control_db', 'table' => 't', 'expected' => 1, 'restored' => 0]]);
        self::assertSame(Status::Fail, $c->run()->status, 'a newer recorded failure wins');
        $facts->facts = BackupFacts::unavailable();
        self::assertSame(Status::Fail, $c->run()->status);
    }
}
