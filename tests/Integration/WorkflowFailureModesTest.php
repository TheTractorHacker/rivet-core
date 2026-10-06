<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Support\SystemClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;
use RivetCore\Workflow\WorkflowService;

/**
 * Pins what docs/modules/workflow.md says about how the workflow engine fails: the task methods do not validate, and the run status
 * is derived. If a later release makes them stricter, update the page and this test together.
 */
final class WorkflowFailureModesTest extends TestCase
{
    private DatabaseInterface $db;
    private WorkflowService $wf;
    private int $template;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        (new MigrationRunner($this->db, CoreMigrations::all(), new SystemClock()))->run();
        foreach (['workflow_run_tasks', 'workflow_runs', 'workflow_template_tasks', 'workflow_templates'] as $t) {
            $this->db->execute("DELETE FROM `$t`");
        }
        $this->db->execute("INSERT INTO workflow_templates (name, type) VALUES ('T', 'onboarding')");
        $this->template = (int) $this->db->fetchOne('SELECT MAX(workflow_template_id) AS id FROM workflow_templates')['id'];
        $this->db->execute('INSERT INTO workflow_template_tasks (workflow_template_id, sort_order, title, required) VALUES (?, 1, ?, 1), (?, 2, ?, 0)', [$this->template, 'required', $this->template, 'optional']);
        $this->wf = new WorkflowService($this->db);
    }

    /** @return list<int> */
    private function taskIds(int $run): array
    {
        return array_map(static fn (array $r): int => (int) $r['run_task_id'], $this->db->fetchAll('SELECT run_task_id FROM workflow_run_tasks WHERE run_id = ? ORDER BY sort_order', [$run]));
    }

    private function runStatus(int $run): string
    {
        return (string) $this->db->fetchOne('SELECT status FROM workflow_runs WHERE run_id = ?', [$run])['status'];
    }

    public function testUnknownTemplateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wf->startRun(987654, 1, null);
    }

    public function testUnknownTaskIdsAreSilentNoOps(): void
    {
        $this->wf->completeTask(987654, 1);
        $this->wf->skipTask(987654, 'x', 1);
        $this->wf->reopenTask(987654);
        $this->addToAssertionCount(1);   // documented: no exception
    }

    public function testSkippingWithAnEmptyReasonIsAccepted(): void
    {
        $run = $this->wf->startRun($this->template, 5, 1);
        [$required] = $this->taskIds($run);
        $this->wf->skipTask($required, '', 1);
        $this->assertSame('skipped', $this->db->fetchOne('SELECT status FROM workflow_run_tasks WHERE run_task_id = ?', [$required])['status']);
    }

    public function testRunStatusIsDerivedFromRequiredTasksOnly(): void
    {
        $run = $this->wf->startRun($this->template, 5, 1);
        [$required, $optional] = $this->taskIds($run);
        $this->wf->completeTask($optional, 1);
        $this->assertSame('in_progress', $this->runStatus($run), 'an optional task never completes the run');
        $this->wf->completeTask($required, 1);
        $this->assertSame('completed', $this->runStatus($run));
        $this->wf->reopenTask($optional);
        $this->assertSame('in_progress', $this->runStatus($run), 'reopening un-completes a finished run');
    }

    public function testSkippedRequiredTaskEndsWithExceptions(): void
    {
        $run = $this->wf->startRun($this->template, 5, 1);
        [$required] = $this->taskIds($run);
        $this->wf->skipTask($required, 'not needed', 1);
        $this->assertSame('completed_with_exceptions', $this->runStatus($run));
    }

    public function testACancelledRunIsNeverRevived(): void
    {
        $run = $this->wf->startRun($this->template, 5, 1);
        [$required, $optional] = $this->taskIds($run);
        $this->wf->cancelRun($run);
        $this->wf->completeTask($required, 1);
        $this->wf->completeTask($optional, 1);
        $this->assertSame('cancelled', $this->runStatus($run));
        $this->wf->reopenTask($required);
        $this->assertSame('cancelled', $this->runStatus($run));
    }
}
