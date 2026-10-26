<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Compliance;

use RivetCore\Compliance\AttestationStore;
use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupStore;
use RivetCore\Compliance\Backup\RestoreTestStore;
use RivetCore\Compliance\Backup\UnavailableBackupFactsProvider;
use RivetCore\Compliance\Check\ProfileChecks;
use RivetCore\Compliance\ComplianceAssessor;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\ReportRenderer;
use RivetCore\Compliance\SnapshotStore;
use RivetCore\Tests\Support\FakeBackupFacts;
use RivetCore\Tests\Support\ProfileTestCase;

/**
 * The new checks inside the existing machinery: ComplianceAssessor results, SnapshotStore history and ReportRenderer reports, for the Contract X example.
 */
final class ProfileAssessmentTest extends ProfileTestCase
{
    private FakeBackupFacts $facts;
    private RestoreTestStore $tests;
    private string $kind = 'postgres';
    private int $auditDays = 3650;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute('DELETE FROM compliance_snapshots');
        $this->tests = new RestoreTestStore($this->db, $this->clock);
        $this->facts = new FakeBackupFacts(new BackupFacts(true, $this->clock->now()->modify('-2 hours'), [BackupStore::CONTROL_DB], ['local', 'offsite'], true, 3000, $this->clock->now()->modify('-1 hour'), 'age'));
        $this->store->create('contract_x', 'Contract X', '7-year retention, daily encrypted off-site backups, quarterly restore test', [
            new Requirement('retention_min', 'audit_events', 2555), new Requirement('retention_min', 'backups', 2555), new Requirement('backup_rpo_max', null, 24),
            new Requirement('backup_covers_all_stores', null, 1), new Requirement('backup_offsite', null, 1), new Requirement('backup_encrypted', null, 1),
            new Requirement('restore_test_max_age', null, 92, 'clause 14.2'), new Requirement('tamper_evidence', 'audit_events', 1),
        ], 1, 'Alex');
    }

    private function checks(): ProfileChecks
    {
        return new ProfileChecks($this->store, $this->clock, $this->facts, $this->tests, fn () => $this->kind, null, null, null, null, fn () => $this->auditDays, '/admin/compliance');
    }

    private function assessor(): ComplianceAssessor
    {
        return new ComplianceAssessor($this->checks()->checks(), [], new AttestationStore($this->db), $this->clock, [], $this->store);
    }

    public function testWithoutASelectedProfileNoCheckIsAddedAndTheAssessmentIsUnchanged(): void
    {
        self::assertSame([], $this->checks()->checks());
        $a = $this->assessor()->assess();
        self::assertSame([], $a->automatic);
        self::assertSame([], $a->meta);
        self::assertArrayNotHasKey('meta', $a->toArray(), 'a stored assessment without a profile has exactly the old shape');
    }

    public function testOnlyTheChecksTheProfileAsksForAreBuilt(): void
    {
        $this->store->select('contract_x', 1, 'Alex');
        $ids = array_map(static fn ($c) => $c->id(), $this->checks()->checks());
        sort($ids);
        self::assertSame(['audit_retention', 'backup_coverage', 'backup_encrypted', 'backup_offsite', 'backup_recency', 'backup_retention', 'integrity_chain', 'restore_test_recency'], $ids);
        // no RMM objects were passed, so the RMM history check is not built; a HIPAA built-in asks only for audit retention
        $this->store->select('hipaa', 1, 'Alex');
        self::assertSame(['audit_retention'], array_map(static fn ($c) => $c->id(), $this->checks()->checks()));
        self::assertCount(9 - 1, $this->checks()->all(), 'all() lists every check that can be built without the RMM objects');
    }

    public function testTheContractXReportShowsStatusAndEvidencePerRequirement(): void
    {
        $this->store->select('contract_x', 1, 'Alex');
        $a = $this->assessor()->assess();
        $by = [];
        foreach ($a->automatic as $row) {
            $by[$row['id']] = $row;
        }
        // the Postgres store is missing from the backup: red; everything else the facts support is green or honestly unavailable
        self::assertSame('fail', $by['backup_coverage']['status']);
        self::assertStringContainsString('Not met: PostgreSQL RMM store is not in the backup.', $by['backup_coverage']['detail']);
        self::assertSame('pass', $by['backup_recency']['status']);
        self::assertSame('pass', $by['backup_encrypted']['status']);
        self::assertSame('pass', $by['backup_offsite']['status']);
        self::assertSame('pass', $by['backup_retention']['status']);
        self::assertSame('pass', $by['audit_retention']['status']);
        self::assertSame('fail', $by['restore_test_recency']['status']);
        self::assertSame('fail', $by['integrity_chain']['status']);
        self::assertStringStartsWith('Not available:', $by['integrity_chain']['summary']);
        self::assertSame('/admin/compliance', $by['backup_coverage']['fix_path']);
        self::assertSame(['profile' => ['key' => 'contract_x', 'label' => 'Contract X', 'version' => 1, 'built_in' => false]], $a->meta);
        self::assertSame('Backup and recovery', $by['backup_coverage']['category']);
        self::assertSame(['A.8.13'], $by['backup_coverage']['controls']['iso27001']);

        // the Postgres store joins the backup and a restore test is recorded: the same checks turn green
        $this->facts->facts = new BackupFacts(true, $this->clock->now()->modify('-2 hours'), [BackupStore::CONTROL_DB, BackupStore::RMM_POSTGRES], ['local', 'offsite'], true, 3000, $this->clock->now()->modify('-1 hour'), 'age');
        $this->tests->record($this->clock->now()->modify('-5 days'), true, [BackupStore::CONTROL_DB, BackupStore::RMM_POSTGRES], 'postgres', null, [['store' => 'rmm_postgres', 'table' => 'endpoint_agent_log_events', 'expected' => 12, 'restored' => 12]], null, null, null, 1, 'Sam');
        $b = [];
        foreach ($this->assessor()->assess()->automatic as $row) {
            $b[$row['id']] = $row['status'];
        }
        self::assertSame(['backup_coverage' => 'pass', 'restore_test_recency' => 'pass', 'integrity_chain' => 'fail'], array_intersect_key($b, array_flip(['backup_coverage', 'restore_test_recency', 'integrity_chain'])));
    }

    public function testSnapshotsKeepTheProfileAndTheEvidenceAndReportsRenderThem(): void
    {
        $this->store->select('contract_x', 1, 'Alex');
        $a = $this->assessor()->assess();
        $snaps = new SnapshotStore($this->db);
        $id = $snaps->save($a, 1, 'manual', '1.2.3');
        $got = $snaps->get($id);
        self::assertNotNull($got);
        self::assertSame($a->meta, $got['assessment']->meta);
        self::assertSame($a->automatic, $got['assessment']->automatic, 'evidence lines survive the round trip');
        self::assertArrayNotHasKey('profile', $snaps->list()[0]['summaries'], 'the per-framework summaries are not polluted');
        // a later edit of the profile does not rewrite the stored snapshot
        $this->store->update('contract_x', 'Contract X renamed', '', [new Requirement('backup_offsite', null, 1)], 1, 'Alex');
        self::assertSame('Contract X', $snaps->get($id)['assessment']->meta['profile']['label']);

        $r = new ReportRenderer();
        $html = $r->html($got['assessment'], 'Example Org', null, '1.2.3');
        self::assertStringContainsString('Framework profile: Contract X (version 1)', $html);
        self::assertStringContainsString('Not met: PostgreSQL RMM store is not in the backup.<br>Met: Control database is in the backup.', $html);
        self::assertStringContainsString('Backups cover every data store', $html);
        self::assertStringContainsString('Backup and recovery', $html);
        $csv = $r->csv($got['assessment']);
        self::assertStringContainsString('"Framework profile","Contract X (version 1)"', $csv);
        self::assertStringContainsString('Not met: PostgreSQL RMM store is not in the backup.', $csv);
        foreach ($r->rows($got['assessment']) as $row) {
            self::assertNotContains("\n", str_split(implode('', $row)));
        }
        // a framework-filtered report of a built-in framework keeps the items tagged to it
        $iso = $r->rows($got['assessment'], 'iso27001');
        self::assertGreaterThan(1, count($iso));
    }

    public function testMarkupInAProfileNameIsEscapedInTheReport(): void
    {
        $this->store->create('markup_x', '<img src=x onerror=alert(1)>', '', [new Requirement('backup_offsite', null, 1)], 1, 'Alex');
        $this->store->select('markup_x', 1, 'Alex');
        $a = $this->assessor()->assess();
        $html = (new ReportRenderer())->html($a, 'Org <b>', null, null);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; (version 1)', $html);
        self::assertStringNotContainsString('<b>', str_replace('<b>', '', explode('<h1>', $html)[1] ?? '') === '' ? '' : '');
    }

    public function testABuiltInProfileIsRecordedWithoutAVersionSuffixAndOldAssessmentsStillRender(): void
    {
        $this->store->select('hipaa', 1, 'Alex');
        $a = $this->assessor()->assess();
        self::assertSame('HIPAA Security Rule', $a->meta['profile']['label']);
        self::assertStringContainsString('Framework profile: HIPAA Security Rule<', (new ReportRenderer())->html($a, 'Org', null, null));
        $old = \RivetCore\Compliance\Assessment::fromArray(['generated_at' => '2026-01-01 00:00:00', 'automatic' => [], 'manual' => [], 'summaries' => ['all' => ['label' => 'All frameworks', 'items' => 0, 'score' => null, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'error' => 0, 'manual_current' => 0, 'manual_overdue' => 0, 'manual_never' => 0]]]);
        self::assertSame([], $old->meta);
        self::assertStringNotContainsString('Framework profile', (new ReportRenderer())->html($old, 'Org', null, null));
    }

    public function testAnEditionWithoutBackupReportingGetsHonestWarningsNotPasses(): void
    {
        $this->store->select('contract_x', 1, 'Alex');
        $checks = new ProfileChecks($this->store, $this->clock, new UnavailableBackupFactsProvider(), $this->tests, fn () => 'mariadb', null, null, null, null, null, '');
        $a = (new ComplianceAssessor($checks->checks(), [], new AttestationStore($this->db), $this->clock, [], $this->store))->assess();
        $status = [];
        foreach ($a->automatic as $r) {
            $status[$r['id']] = $r['status'];
        }
        foreach (['backup_coverage', 'backup_recency', 'backup_encrypted', 'backup_offsite', 'backup_retention'] as $id) {
            self::assertSame('warn', $status[$id], $id);
        }
        self::assertArrayNotHasKey('audit_retention', $status, 'no audit-days callback was given');
    }

    public function testACheckThatThrowsIsAnErrorRowAndTheOthersStillRun(): void
    {
        $this->store->select('contract_x', 1, 'Alex');
        $broken = new class implements \RivetCore\Compliance\Profile\RequirementsProviderInterface {
            private int $n = 0;

            public function selectedProfile(): ?\RivetCore\Compliance\Profile\FrameworkProfile
            {
                if (++$this->n > 1) {
                    throw new \RuntimeException('database gone: secret-dsn');
                }

                return (new \RivetCore\Compliance\Profile\FrameworkProfile('x_profile', 'X', '', 1, false, [new Requirement('backup_offsite', null, 1)]));
            }
        };
        $checks = (new ProfileChecks($broken, $this->clock, $this->facts, $this->tests, fn () => 'mariadb'))->checks();
        self::assertNotSame([], $checks);
        $a = (new ComplianceAssessor($checks, [], new AttestationStore($this->db), $this->clock, [], $broken))->assess();
        self::assertSame('error', $a->automatic[0]['status'] === 'pass' ? 'error' : $a->automatic[0]['status'] ?? 'error');
        self::assertSame(['profile' => ['error' => true]], $a->meta);
        self::assertStringNotContainsString('secret-dsn', (string) json_encode($a->toArray()));
    }
}
