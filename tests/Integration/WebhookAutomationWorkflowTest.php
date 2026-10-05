<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Automation\AutomationRuleEvaluator;
use RivetCore\Automation\Migration\Migration0006AutomationRules;
use RivetCore\Database\DatabaseException;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;
use RivetCore\Webhooks\Migration\Migration0005WebhookDeliveries;
use RivetCore\Webhooks\WebhookDispatcher;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;
use RivetCore\Workflow\Migration\Migration0007WorkflowTables;
use RivetCore\Workflow\WorkflowService;

final class WebhookAutomationWorkflowTest extends TestCase
{
    private MysqliDatabase $db;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        foreach (['webhook_deliveries', 'automation_rules', 'workflow_run_tasks', 'workflow_runs', 'workflow_template_tasks', 'workflow_templates'] as $t) {
            $this->db->execute("DROP TABLE IF EXISTS $t");
        }
        (new Migration0005WebhookDeliveries())->up($this->db);
        (new Migration0006AutomationRules())->up($this->db);
        (new Migration0007WorkflowTables())->up($this->db);
    }

    private function subs(array $list): WebhookSubscriptionsInterface
    {
        return new class($list) implements WebhookSubscriptionsInterface {
            public function __construct(private array $list)
            {
            }

            public function forEvent(string $eventType): array
            {
                return $this->list;
            }
        };
    }

    // ---- webhooks
    public function testDeliverySignsExactBodyAndLogsEachAttempt(): void
    {
        $seen = [];
        $d = new WebhookDispatcher(
            $this->db,
            $this->subs([new WebhookSubscription(7, 'https://hooks.example/a', 's3cret'), new WebhookSubscription(8, 'https://hooks.example/b', 'other')]),
            new FixedClock(new \DateTimeImmutable('2026-03-04 05:06:07', new \DateTimeZone('UTC'))),
            ['X-ITFlow', 'X-RivetIT'],
            function (string $url, string $body, array $headers, int $timeout) use (&$seen): array {
                $seen[] = compact('url', 'body', 'headers', 'timeout');

                return $url === 'https://hooks.example/a' ? ['status' => 200, 'body' => 'ok', 'error' => null] : ['status' => null, 'body' => null, 'error' => 'Could not resolve host'];
            }
        );
        $results = $d->deliver('ticket.created', ['id' => 5, 'note' => 'é/ü']);

        $this->assertSame([7, 8], array_column($results, 'webhook_id'));
        $this->assertSame(200, $results[0]['http_status']);
        $this->assertNull($results[0]['error']);
        $this->assertNull($results[1]['http_status']);
        $this->assertSame('Could not resolve host', $results[1]['error']);

        $expected = '{"event":"ticket.created","timestamp":"2026-03-04T05:06:07Z","data":{"id":5,"note":"é/ü"}}';
        $this->assertSame($expected, $seen[0]['body']);
        $sig = 'sha256=' . hash_hmac('sha256', $expected, 's3cret');
        $this->assertContains('X-ITFlow-Signature: ' . $sig, $seen[0]['headers']);
        $this->assertContains('X-RivetIT-Signature: ' . $sig, $seen[0]['headers']);
        $this->assertContains('X-ITFlow-Event: ticket.created', $seen[0]['headers']);
        $this->assertContains('X-RivetIT-Event: ticket.created', $seen[0]['headers']);
        $this->assertContains('Content-Type: application/json', $seen[0]['headers']);
        $this->assertNotContains('X-ITFlow-Signature: ' . $sig, $seen[1]['headers'], 'each endpoint is signed with its own secret');

        $rows = $this->db->fetchAll('SELECT * FROM webhook_deliveries ORDER BY delivery_id');
        $this->assertCount(2, $rows);
        $this->assertEquals(200, $rows[0]['http_status']);
        $this->assertSame('ok', $rows[0]['response_body_snippet']);
        $this->assertSame($expected, $rows[0]['request_payload_json']);
        $this->assertSame('Could not resolve host', $rows[1]['response_body_snippet']);
        $this->assertEquals(1, $rows[0]['attempt_number']);
    }

    public function testNoSubscribersMeansNoRequestsAndNoRows(): void
    {
        $called = false;
        $d = new WebhookDispatcher($this->db, $this->subs([]), new FixedClock(), ['X-A'], function () use (&$called): array {
            $called = true;

            return ['status' => 200, 'body' => '', 'error' => null];
        });
        $this->assertSame([], $d->deliver('x', []));
        $this->assertFalse($called);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM webhook_deliveries')['c']);
    }

    public function testNeverThrows(): void
    {
        $boom = new class implements WebhookSubscriptionsInterface {
            public function forEvent(string $eventType): array
            {
                throw new \RuntimeException('subscription store down');
            }
        };
        $this->assertSame([], (new WebhookDispatcher($this->db, $boom, new FixedClock()))->deliver('x', []));

        $d = new WebhookDispatcher($this->db, $this->subs([new WebhookSubscription(1, 'https://h.example', 's')]), new FixedClock(), ['X-A'],
            fn () => throw new \RuntimeException('transport exploded'));
        $r = $d->deliver('x', []);
        $this->assertSame('transport failed', $r[0]['error']);

        $this->db->execute('DROP TABLE webhook_deliveries'); // logging failure must not surface either
        $ok = new WebhookDispatcher($this->db, $this->subs([new WebhookSubscription(1, 'https://h.example', 's')]), new FixedClock(), ['X-A'],
            fn () => ['status' => 204, 'body' => '', 'error' => null]);
        $this->assertSame(204, $ok->deliver('x', [])[0]['http_status']);
    }

    public function testRealCurlTransportPostsToALocalServer(): void
    {
        $dir = sys_get_temp_dir() . '/rc-hook-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/router.php', '<?php file_put_contents(__DIR__."/got.json", json_encode(["body"=>file_get_contents("php://input"),"sig"=>$_SERVER["HTTP_X_RIVETCORE_SIGNATURE"]??null,"method"=>$_SERVER["REQUEST_METHOD"]])); http_response_code(202); echo "accepted";');
        $port = random_int(20000, 40000);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $dir . '/router.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 50; $i++) {
                if (@fsockopen('127.0.0.1', $port)) {
                    break;
                }
                usleep(100000);
            }
            $d = new WebhookDispatcher($this->db, $this->subs([new WebhookSubscription(3, "http://127.0.0.1:$port/hook", 'k')]), new FixedClock());
            $r = $d->deliver('asset.updated', ['a' => 1]);
            $this->assertSame(202, $r[0]['http_status'], (string) $r[0]['error']);
            $got = json_decode((string) file_get_contents($dir . '/got.json'), true);
            $this->assertSame('POST', $got['method']);
            $this->assertSame('sha256=' . hash_hmac('sha256', $got['body'], 'k'), $got['sig']);
            $this->assertSame('accepted', $this->db->fetchOne('SELECT response_body_snippet s FROM webhook_deliveries')['s']);
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    // ---- automation
    public function testRuleMatching(): void
    {
        $ins = fn (string $name, string $event, ?string $cond, int $on = 1) => $this->db->execute(
            "INSERT INTO automation_rules (name, trigger_event, condition_json, action_type, is_enabled) VALUES (?, ?, ?, 'notify_user', ?)",
            [$name, $event, $cond, $on]
        );
        $ins('any', 'contact.started', null);
        $ins('empty-braces', 'contact.started', '{}');
        $ins('match', 'contact.started', '{"entity_type":"contact","action":"started"}');
        $ins('mismatch', 'contact.started', '{"action":"finished"}');
        $ins('missing-key', 'contact.started', '{"department":"HR"}');
        $ins('malformed', 'contact.started', '{not json');
        $ins('disabled', 'contact.started', null, 0);
        $ins('other-event', 'asset.created', null);
        $names = array_column((new AutomationRuleEvaluator($this->db))->findMatchingRules('contact.started', ['entity_type' => 'contact', 'action' => 'started']), 'name');
        sort($names);
        $this->assertSame(['any', 'empty-braces', 'match'], $names);
    }

    public function testConditionsCompareAsStringsAndMissingIsNotNull(): void
    {
        $e = new AutomationRuleEvaluator($this->db);
        $this->assertTrue($e->conditionsMatch('{"n":5}', ['n' => '5']));
        $this->assertFalse($e->conditionsMatch('{"n":null}', []));
        $this->assertFalse($e->conditionsMatch('[1,2]x', []));
    }

    // ---- workflow
    private function template(int $required = 2, int $optional = 1): int
    {
        $t = (int) $this->db->execute("INSERT INTO workflow_templates (name, type) VALUES ('Onboard', 'onboarding')")->insertId;
        $i = 0;
        for ($r = 0; $r < $required; $r++) {
            $this->db->execute("INSERT INTO workflow_template_tasks (workflow_template_id, title, required, sort_order) VALUES (?, ?, 1, ?)", [$t, "req$r", $i++]);
        }
        for ($o = 0; $o < $optional; $o++) {
            $this->db->execute("INSERT INTO workflow_template_tasks (workflow_template_id, title, required, sort_order) VALUES (?, ?, 0, ?)", [$t, "opt$o", $i++]);
        }

        return $t;
    }

    private function runStatus(int $run): string
    {
        return $this->db->fetchOne('SELECT status FROM workflow_runs WHERE run_id = ?', [$run])['status'];
    }

    private function taskIds(int $run): array
    {
        return array_map('intval', array_column($this->db->fetchAll('SELECT run_task_id FROM workflow_run_tasks WHERE run_id = ? ORDER BY sort_order', [$run]), 'run_task_id'));
    }

    public function testStartSnapshotsTemplateTasks(): void
    {
        $t = $this->template();
        $svc = new WorkflowService($this->db);
        $run = $svc->startRun($t, 42, 1);
        $this->assertSame(['req0', 'req1', 'opt0'], array_column($this->db->fetchAll('SELECT title FROM workflow_run_tasks WHERE run_id = ? ORDER BY sort_order', [$run]), 'title'));
        $this->db->execute("UPDATE workflow_template_tasks SET title = 'renamed' WHERE workflow_template_id = ?", [$t]);
        $this->assertSame('req0', $this->db->fetchOne('SELECT title FROM workflow_run_tasks WHERE run_id = ? ORDER BY sort_order LIMIT 1', [$run])['title'], 'editing the template does not rewrite a started run');
        $this->assertSame('onboarding', $this->db->fetchOne('SELECT type FROM workflow_runs WHERE run_id = ?', [$run])['type']);
        $this->assertEquals(42, $this->db->fetchOne('SELECT contact_id FROM workflow_runs WHERE run_id = ?', [$run])['contact_id']);
    }

    public function testRunCompletesWhenRequiredTasksAreDoneOptionalNeverGates(): void
    {
        $svc = new WorkflowService($this->db);
        $run = $svc->startRun($this->template(), 1, 1);
        [$a, $b, $opt] = $this->taskIds($run);
        $svc->completeTask($a, 1);
        $this->assertSame('in_progress', $this->runStatus($run));
        $svc->completeTask($b, 1);
        $this->assertSame('completed', $this->runStatus($run), 'optional task still pending does not block completion');
        $this->assertNotNull($this->db->fetchOne('SELECT completed_at FROM workflow_runs WHERE run_id = ?', [$run])['completed_at']);
    }

    public function testSkippingARequiredTaskCompletesWithExceptionsAndReopenReverts(): void
    {
        $svc = new WorkflowService($this->db);
        $run = $svc->startRun($this->template(), 1, 1);
        [$a, $b] = $this->taskIds($run);
        $svc->completeTask($a, 1);
        $svc->skipTask($b, 'not applicable', 1);
        $this->assertSame('completed_with_exceptions', $this->runStatus($run));
        $this->assertSame('not applicable', $this->db->fetchOne('SELECT skip_reason FROM workflow_run_tasks WHERE run_task_id = ?', [$b])['skip_reason']);
        $svc->reopenTask($b);
        $this->assertSame('in_progress', $this->runStatus($run));
        $this->assertNull($this->db->fetchOne('SELECT completed_at FROM workflow_runs WHERE run_id = ?', [$run])['completed_at']);
        $this->assertNull($this->db->fetchOne('SELECT skip_reason FROM workflow_run_tasks WHERE run_task_id = ?', [$b])['skip_reason']);
    }

    public function testCancelledRunStaysCancelled(): void
    {
        $svc = new WorkflowService($this->db);
        $run = $svc->startRun($this->template(), 1, 1);
        $svc->cancelRun($run);
        $svc->completeTask($this->taskIds($run)[0], 1);
        $svc->reopenTask($this->taskIds($run)[0]);
        $this->assertSame('cancelled', $this->runStatus($run));
    }

    public function testStartRunIsAtomicAndRejectsUnknownTemplate(): void
    {
        $svc = new WorkflowService($this->db);
        try {
            $svc->startRun(999, 1, 1);
            $this->fail('expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Workflow template 999 not found', $e->getMessage());
        }
        $t = $this->template();
        $this->db->execute('DROP TABLE workflow_run_tasks'); // make the task insert fail halfway through
        try {
            $svc->startRun($t, 1, 1);
            $this->fail('expected failure');
        } catch (DatabaseException) {
        }
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM workflow_runs')['c'], 'no half-created run is left behind');
    }
}
