<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Automation\AutomationExecutor;
use RivetCore\Automation\AutomationRuleEvaluator;
use RivetCore\Automation\AutomationRuleStore;
use RivetCore\Automation\EventContext;
use RivetCore\Jobs\JobQueue;
use RivetCore\Jobs\JobWorker;
use RivetCore\Jobs\PermanentJobFailure;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;
use RivetCore\Webhooks\WebhookDispatcher;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

final class JobsAutomationWebhookTest extends TestCase
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
        foreach (['integration_jobs', 'automation_rules', 'webhook_deliveries'] as $t) {
            $this->db->execute("DELETE FROM $t");
        }
    }

    // ---- JobWorker
    public function testWorkerRunsRegisteredHandlersRetriesAndDeadLetters(): void
    {
        $q = new JobQueue($this->db);
        $ran = [];
        $w = (new JobWorker($q))
            ->register('ok.job', function (array $p) use (&$ran) { $ran[] = $p; return ['done' => true]; })
            ->register('flaky.job', fn () => throw new \RuntimeException('boom'))
            ->register('permanent.job', fn () => throw new PermanentJobFailure('gone'));
        $q->enqueue('ok.job', ['n' => 1]);
        $q->enqueue('flaky.job', [], null, null, 0, 3);
        $q->enqueue('permanent.job');
        $q->enqueue('mystery.job');
        $r = $w->run();
        self::assertSame(4, $r['claimed']);
        self::assertSame(1, $r['completed']);
        self::assertSame(1, $r['retrying'], 'a failing job with attempts left is retried later');
        self::assertSame(2, $r['dead'], 'a permanent failure and an unknown job type are dead-lettered at once');
        self::assertSame([['n' => 1]], $ran);
        $stats = $q->stats();
        self::assertSame(1, $stats['completed']);
        self::assertSame(2, $stats['dead_letter']);
        self::assertSame(1, $stats['pending']);
        self::assertSame(0, $w->run()['claimed'], 'the retry is not due yet (backoff)');
        $dead = $q->recent(10, 'dead_letter');
        self::assertCount(2, $dead);
        self::assertTrue($q->retry((int) $dead[0]['job_id']));
        self::assertSame(2, $q->stats()['pending'], 'the retried dead letter is pending again (plus the backing-off job)');
        self::assertStringContainsString("No handler registered", implode(' ', array_column($dead, 'error')));
    }

    public function testStaleRunningJobsAreReleased(): void
    {
        $q = new JobQueue($this->db);
        $id = $q->enqueue('x');
        $q->claim(1);
        self::assertSame(0, $q->requeueStale(15), 'a job that just started is not stale');
        $this->db->execute("UPDATE integration_jobs SET started_at = NOW() - INTERVAL 30 MINUTE, heartbeat_at = NOW() - INTERVAL 30 MINUTE WHERE job_id = ?", [$id]);
        self::assertSame(1, $q->requeueStale(15));
        self::assertSame('pending', $this->db->fetchOne('SELECT status FROM integration_jobs WHERE job_id = ?', [$id])['status']);
    }

    public function testPurgeKeepsDeadLetters(): void
    {
        $q = new JobQueue($this->db);
        $a = $q->enqueue('a'); $b = $q->enqueue('b');
        $this->db->execute("UPDATE integration_jobs SET status='completed', completed_at = NOW() - INTERVAL 40 DAY WHERE job_id = ?", [$a]);
        $this->db->execute("UPDATE integration_jobs SET status='dead_letter', completed_at = NOW() - INTERVAL 40 DAY WHERE job_id = ?", [$b]);
        self::assertSame(1, $q->purgeCompleted(30));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM integration_jobs')['c']);
    }

    // ---- automation
    public function testFlattenAndInterpolate(): void
    {
        $flat = EventContext::flatten(['event' => 'ticket.created', 'ticket' => ['priority' => 'High', 'id' => 7, 'vip' => true], 'tags' => ['a', 'b'], 'none' => null]);
        self::assertSame('High', $flat['ticket.priority']);
        self::assertSame('High', $flat['priority'], 'the bare leaf name works when unambiguous');
        self::assertSame('1', $flat['ticket.vip']);
        self::assertArrayNotHasKey('tags', $flat);
        self::assertArrayNotHasKey('none', $flat);
        $out = AutomationExecutor::interpolate(['subject' => 'P{ticket.id} is {priority}', 'url' => 'https://{ticket.id}.evil.example/', 'user_id' => '{ticket.id}'], $flat);
        self::assertSame('P7 is High', $out['subject']);
        self::assertSame('https://{ticket.id}.evil.example/', $out['url'], 'event data never steers a URL');
        self::assertSame('{ticket.id}', $out['user_id']);
    }

    public function testStoreValidatesAndEvaluatorMatches(): void
    {
        $s = new AutomationRuleStore($this->db);
        $id = $s->save(null, 'High tickets', 'ticket.created', ['ticket.priority' => 'High'], 'notify_user', ['message' => 'High: {ticket.id}', 'user_id' => 2], true);
        $s->save(null, 'Other', 'ticket.closed', [], 'send_webhook', ['url' => 'https://hooks.example/x', 'secret' => 's'], true);
        $disabled = $s->save(null, 'Off', 'ticket.created', [], 'notify_user', ['message' => 'x'], false);
        foreach ([
            [null, '', 'ticket.created', [], 'notify_user', ['message' => 'x']],
            [null, 'n', 'Bad Event!', [], 'notify_user', ['message' => 'x']],
            [null, 'n', 'ticket.created', [], 'launch_missiles', []],
            [null, 'n', 'ticket.created', ['bad field' => 'x'], 'notify_user', ['message' => 'x']],
            [null, 'n', 'ticket.created', [], 'send_webhook', ['url' => 'javascript:alert(1)']],
            [null, 'n', 'ticket.created', [], 'create_ticket', ['subject' => '']],
            [999999, 'n', 'ticket.created', [], 'notify_user', ['message' => 'x']],
        ] as [$i, $n, $e, $c, $a, $cfg]) {
            try {
                $s->save($i, $n, $e, $c, $a, $cfg, true);
                self::fail("accepted $n/$e/$a");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $ev = new AutomationRuleEvaluator($this->db);
        $match = $ev->findMatchingRules('ticket.created', EventContext::flatten(['ticket' => ['priority' => 'High', 'id' => 5]]));
        self::assertSame([$id], array_map(fn ($r) => (int) $r['rule_id'], $match), 'only the enabled rule whose conditions hold fires');
        self::assertSame([], $ev->findMatchingRules('ticket.created', EventContext::flatten(['ticket' => ['priority' => 'Low']])));
        $s->setEnabled($disabled, true);
        self::assertCount(2, $ev->findMatchingRules('ticket.created', EventContext::flatten(['ticket' => ['priority' => 'High']])));

        $res = (new AutomationExecutor())->execute($s->find($id), EventContext::flatten(['ticket' => ['id' => 5]]), [
            'notify_user' => function (array $cfg) { return "notified {$cfg['user_id']}: {$cfg['message']}"; },
        ]);
        self::assertTrue($res['ok']);
        self::assertSame('notified 2: High: 5', $res['message']);
        $res = (new AutomationExecutor())->execute($s->find($id), [], ['notify_user' => fn () => throw new \RuntimeException('smtp down')]);
        self::assertFalse($res['ok']);
        self::assertSame('smtp down', $res['message']);
        self::assertFalse((new AutomationExecutor())->execute($s->find($id), [], [])['ok'], 'no handler is reported, not fatal');
        $s->delete($id);
        self::assertNull($s->find($id));
    }

    // ---- webhook single delivery
    public function testDeliverToLogsAttemptNumberKeepsBodyAndReportsGone(): void
    {
        $seen = [];
        $subs = new class() implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface {
            public function forEvent(string $eventType): array { return []; }
            public function find(int $webhookId): ?WebhookSubscription { return $webhookId === 7 ? new WebhookSubscription(7, 'https://h.example/a', 'k') : null; }
        };
        $status = 500;
        $d = new WebhookDispatcher($this->db, $subs, new FixedClock(), ['X-Test'], function ($url, $body, $headers) use (&$seen, &$status) {
            $seen[] = [$body, $headers];
            return ['status' => $status, 'body' => 'x', 'error' => null];
        });
        $r1 = $d->deliverTo(7, 'ticket.created', ['id' => 1], 1, '2026-01-02T03:04:05Z');
        self::assertFalse($r1['ok']);
        self::assertSame('HTTP 500', $r1['error']);
        $status = 204;
        $r2 = $d->deliverTo(7, 'ticket.created', ['id' => 1], 2, '2026-01-02T03:04:05Z');
        self::assertTrue($r2['ok']);
        self::assertSame($seen[0][0], $seen[1][0], 'a retry sends byte-identical content');
        self::assertSame(hash_hmac('sha256', $seen[0][0], 'k'), substr(explode('sha256=', implode("\n", $seen[0][1]))[1], 0, 64));
        $rows = $this->db->fetchAll('SELECT attempt_number, http_status FROM webhook_deliveries ORDER BY delivery_id');
        self::assertSame([[1, 500], [2, 204]], array_map(fn ($r) => [(int) $r['attempt_number'], (int) $r['http_status']], $rows));
        $gone = $d->deliverTo(99, 'ticket.created', []);
        self::assertTrue($gone['gone'] ?? false);
        self::assertFalse($gone['ok']);
    }
}
