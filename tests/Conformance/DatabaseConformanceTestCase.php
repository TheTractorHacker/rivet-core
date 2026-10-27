<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Audit\AuditReader;
use RivetCore\Audit\AuditService;
use RivetCore\Jobs\JobQueue;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Support\NullRequestContext;
use RivetCore\Support\SystemClock;
use RivetCore\Testing\DatabaseContractTestCase;

/**
 * DatabaseContractTestCase (insert ids, parameter types, no interpolation, transactions, error mapping) plus what Core
 * itself needs from the adapter in practice: it must be able to run every Core migration (twice), write and read audit
 * rows with 4-byte characters, and run the job queue's conditional UPDATE claim. A scratch database only: the Core tables
 * are created in it and dropped again.
 */
abstract class DatabaseConformanceTestCase extends DatabaseContractTestCase
{
    /** Every table Core creates; dropped after each test so the scratch database is left as it was. */
    private const CORE_TABLES = [
        'audit_events', 'integration_jobs', 'mcp_unlinked_identities', 'problems', 'changes', 'webhook_deliveries', 'automation_rules',
        'workflow_templates', 'workflow_template_tasks', 'workflow_runs', 'workflow_run_tasks',
        'compliance_attestations', 'compliance_snapshots', 'compliance_subjects', 'compliance_responsibilities', 'compliance_shared_report',
        'rivet_core_migrations',
    ];

    protected function tearDown(): void
    {
        $db = $this->database();
        foreach (self::CORE_TABLES as $t) {
            $db->execute("DROP TABLE IF EXISTS `$t`");
        }
        parent::tearDown();
    }

    private function migrate(): MigrationRunner
    {
        return new MigrationRunner($this->database(), CoreMigrations::all(), new SystemClock());
    }

    public function testEveryCoreMigrationAppliesAndASecondRunDoesNothing(): void
    {
        $first = $this->migrate()->run();
        $this->assertCount(count(CoreMigrations::all()), $first);
        $this->assertSame([], $this->migrate()->run());
        $this->assertSame([], $this->migrate()->pending());
    }

    public function testAuditRoundTripWithFourByteCharactersAndNullParameters(): void
    {
        $this->migrate()->run();
        $db = $this->database();
        (new AuditService($db, new NullRequestContext()))->log('settings.edit', null, null, null, 'edit', "Emoji \u{1F600} and ü", ['k' => "\u{1F600}"]);
        $page = (new AuditReader($db))->page([], 1, 10);
        $this->assertSame(1, $page->total);
        $this->assertSame("Emoji \u{1F600} and ü", $page->rows[0]['summary']);
        $this->assertNull($page->rows[0]['actor_user_id']);
        $this->assertSame(['k' => "\u{1F600}"], $page->rows[0]['metadata']);
    }

    public function testJobQueueClaimIsExclusiveThroughTheAdapter(): void
    {
        $this->migrate()->run();
        $q = new JobQueue($this->database());
        $q->enqueue('conformance.job', ['a' => 1]);
        $q->enqueue('conformance.job', ['a' => 2]);
        $first = $q->claim(1);
        $second = $q->claim(5);
        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertNotSame($first[0]['job_id'], $second[0]['job_id'], 'the same job was claimed twice');
        $this->assertSame([], $q->claim(5));
    }

    public function testParameterisedLikeAndInListQueriesWork(): void
    {
        $db = $this->database();
        $db->execute('INSERT INTO rc_contract (name, n) VALUES (?, ?), (?, ?), (?, ?)', ['100%', 1, 'a_b', 2, 'other', 3]);
        $this->assertCount(1, $db->fetchAll('SELECT * FROM rc_contract WHERE name LIKE ?', ['100\\%']));
        $this->assertCount(2, $db->fetchAll('SELECT * FROM rc_contract WHERE n IN (?, ?)', [1, 3]));
    }

    public function testMoreThanOneThousandParametersInOneStatement(): void
    {
        $db = $this->database();
        $rows = 600;
        $db->execute('INSERT INTO rc_contract (name, n) VALUES ' . implode(',', array_fill(0, $rows, '(?, ?)')), array_merge(...array_map(static fn (int $i): array => ['r' . $i, $i], range(1, $rows))));
        $this->assertSame($rows, (int) $db->fetchOne('SELECT COUNT(*) AS c FROM rc_contract')['c']);
    }
}
