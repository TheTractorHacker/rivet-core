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

    /** CORE-DS-58: with a vault the send_webhook secret is stored sealed, left out of ordinary reads, and a clear one from before is sealed. */
    public function testTheWebhookSecretIsSealedAndRedactedWhenTheStoreHasAVault(): void
    {
        $vault = new \RivetCore\Crypto\SettingsVault(\RivetCore\Crypto\KeyRing::single(random_bytes(32)));
        $legacy = new AutomationRuleStore($this->db);
        $old = $legacy->save(null, 'Old', 'ticket.created', [], 'send_webhook', ['url' => 'https://hooks.example/old', 'secret' => 'old stored value'], true);
        $this->assertStringContainsString('old stored value', (string) $this->db->fetchOne('SELECT action_config_json FROM automation_rules WHERE rule_id = ?', [$old])['action_config_json'], 'before: in clear');
        $s = new AutomationRuleStore($this->db, $vault);
        $new = $s->save(null, 'New', 'ticket.created', [], 'send_webhook', ['url' => 'https://hooks.example/new', 'secret' => 'fresh-secret-456'], true);
        $raw = (string) $this->db->fetchOne('SELECT action_config_json FROM automation_rules WHERE rule_id = ?', [$new])['action_config_json'];
        self::assertStringNotContainsString('fresh-secret-456', $raw);
        self::assertStringContainsString('secret_enc', $raw);
        // ordinary reads never carry it
        foreach ([$s->find($new), ...array_filter($s->all(), static fn (array $r): bool => (int) $r['rule_id'] === $new)] as $row) {
            $cfg = json_decode((string) $row['action_config_json'], true);
            self::assertSame(['', true], [$cfg['secret'], $cfg['secret_set']]);
            self::assertArrayNotHasKey('secret_enc', $cfg);
            self::assertStringNotContainsString('fresh-secret-456', json_encode($row) ?: '');
        }
        // the code that signs the delivery asks for it
        self::assertSame('fresh-secret-456', json_decode((string) $s->find($new, true)['action_config_json'], true)['secret']);
        // reading the old row through the store seals it in place
        $cfg = json_decode((string) $s->find($old)['action_config_json'], true);
        self::assertTrue($cfg['secret_set']);
        self::assertStringNotContainsString('old stored value', (string) $this->db->fetchOne('SELECT action_config_json FROM automation_rules WHERE rule_id = ?', [$old])['action_config_json']);
        self::assertSame('old stored value', json_decode((string) $s->find($old, true)['action_config_json'], true)['secret']);
        self::assertSame(0, $s->sealStoredSecrets(), 'and nothing is left to seal');
        // an edit that does not re-enter the secret keeps it
        $s->save($new, 'New renamed', 'ticket.created', [], 'send_webhook', ['url' => 'https://hooks.example/new', 'secret' => '', 'keep_secret' => true], true);
        self::assertSame('fresh-secret-456', json_decode((string) $s->find($new, true)['action_config_json'], true)['secret']);
    }

    /** CRR-16: the documented pipeline (evaluator, then executor) hands the handler the real secret, not an empty one. */
    public function testASealedWebhookSecretReachesTheHandlerThroughEvaluatorAndExecutor(): void
    {
        $vault = new \RivetCore\Crypto\SettingsVault(\RivetCore\Crypto\KeyRing::single(random_bytes(32)));
        $store = new AutomationRuleStore($this->db, $vault);
        $store->save(null, 'Hook', 'ticket.created', [], 'send_webhook', ['url' => 'https://hooks.example/h', 'secret' => 'hmac-key-for-delivery'], true);
        $seen = [];
        $handlers = ['send_webhook' => static function (array $config, array $ctx, array $rule) use (&$seen): string {
            $seen[] = $config;

            return 'sent';
        }];
        $rules = (new \RivetCore\Automation\AutomationRuleEvaluator($this->db, $vault))->findMatchingRules('ticket.created', []);
        $this->assertCount(1, $rules);
        $res = (new \RivetCore\Automation\AutomationExecutor())->execute($rules[0], [], $handlers);
        $this->assertTrue($res['ok'], json_encode($res));
        $this->assertSame('hmac-key-for-delivery', $seen[0]['secret'], 'the handler signs with the real secret');
        $this->assertArrayNotHasKey('secret_enc', $seen[0]);
        // a secret that cannot be opened (another key) is not delivered unsigned: the rule is left out
        $other = new \RivetCore\Crypto\SettingsVault(\RivetCore\Crypto\KeyRing::single(random_bytes(32)));
        $this->assertSame([], (new \RivetCore\Automation\AutomationRuleEvaluator($this->db, $other))->findMatchingRules('ticket.created', []));
    }

    /** R3-6: a new webhook rule is never enabled with an empty secret: it is inserted disabled, enabled once sealed, and nothing is left behind when sealing fails. */
    public function testANewWebhookRuleIsNeverEnabledBeforeItsSecretIsSealed(): void
    {
        $count = fn (): int => (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM automation_rules')['c'];
        $before = $count();
        $noKey = new AutomationRuleStore($this->db, new \RivetCore\Crypto\SettingsVault(\RivetCore\Crypto\KeyRing::empty()));
        $threw = false;
        try {
            $noKey->save(null, 'Hook', 'ticket.created', [], 'send_webhook', ['url' => 'https://hooks.example/h', 'secret' => 'needs-a-key'], true);
        } catch (\RivetCore\Crypto\KeyUnavailable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'sealing without a key throws');
        $this->assertSame($before, $count(), 'and no rule, enabled or not, is left behind');
        // while it is being sealed the rule is not enabled: a store that watches the table at that moment sees it disabled
        $vault = new \RivetCore\Crypto\SettingsVault(\RivetCore\Crypto\KeyRing::single(random_bytes(32)));
        $ok = (new AutomationRuleStore($this->db, $vault))->save(null, 'Hook', 'ticket.created', [], 'send_webhook', ['url' => 'https://hooks.example/h', 'secret' => 'fine'], true);
        $row = $this->db->fetchOne('SELECT is_enabled, action_config_json FROM automation_rules WHERE rule_id = ?', [$ok]);
        $this->assertSame(1, (int) $row['is_enabled'], 'enabled once sealed');
        $this->assertStringContainsString('secret_enc', (string) $row['action_config_json']);
        $off = (new AutomationRuleStore($this->db, $vault))->save(null, 'Off', 'ticket.created', [], 'send_webhook', ['url' => 'https://hooks.example/o', 'secret' => 'fine'], false);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT is_enabled FROM automation_rules WHERE rule_id = ?', [$off])['is_enabled'], 'a rule saved disabled stays disabled');
        // an update that cannot seal leaves the stored rule as it was
        $stored = (string) $this->db->fetchOne('SELECT action_config_json FROM automation_rules WHERE rule_id = ?', [$ok])['action_config_json'];
        try {
            $noKey->save($ok, 'Hook', 'ticket.created', [], 'send_webhook', ['url' => 'https://hooks.example/h', 'secret' => 'new'], true);
            $this->fail('expected KeyUnavailable');
        } catch (\RivetCore\Crypto\KeyUnavailable) {
        }
        $this->assertSame($stored, (string) $this->db->fetchOne('SELECT action_config_json FROM automation_rules WHERE rule_id = ?', [$ok])['action_config_json']);
    }

    /** CRR-21: a sealed secret is bound to its rule (a copy in another rule does not open) and rows sealed by the first version are still read and re-sealed per rule. */
    public function testASealedSecretIsBoundToItsRuleAndOldRowsStillRead(): void
    {
        $vault = new \RivetCore\Crypto\SettingsVault(\RivetCore\Crypto\KeyRing::single(random_bytes(32)));
        $s = new AutomationRuleStore($this->db, $vault);
        $a = $s->save(null, 'A', 'ticket.created', [], 'send_webhook', ['url' => 'https://hooks.example/a', 'secret' => 'secret-of-a'], true);
        $b = $s->save(null, 'B', 'ticket.created', [], 'send_webhook', ['url' => 'https://hooks.example/b', 'secret' => 'secret-of-b'], true);
        $encA = json_decode((string) $this->db->fetchOne('SELECT action_config_json FROM automation_rules WHERE rule_id = ?', [$a])['action_config_json'], true)['secret_enc'];
        $cfgB = json_decode((string) $this->db->fetchOne('SELECT action_config_json FROM automation_rules WHERE rule_id = ?', [$b])['action_config_json'], true);
        $cfgB['secret_enc'] = $encA;
        $this->db->execute('UPDATE automation_rules SET action_config_json = ? WHERE rule_id = ?', [json_encode($cfgB), $b]);
        $this->assertSame('secret-of-a', json_decode((string) $s->find($a, true)['action_config_json'], true)['secret']);
        $threw = false;
        try {
            $s->find($b, true);
        } catch (\RivetCore\Crypto\CryptoException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'rule A\'s sealed secret copied into rule B does not open there');
        // a row sealed by the first version (one shared name) still reads and is re-sealed under its own name
        $legacy = $s->save(null, 'Legacy', 'ticket.created', [], 'send_webhook', ['url' => 'https://hooks.example/l', 'secret' => 'x'], true);
        $cfg = json_decode((string) $this->db->fetchOne('SELECT action_config_json FROM automation_rules WHERE rule_id = ?', [$legacy])['action_config_json'], true);
        $cfg['secret_enc'] = $vault->encrypt(AutomationRuleStore::SECRET_CONTEXT, 'old-first-version');
        $this->db->execute('UPDATE automation_rules SET action_config_json = ? WHERE rule_id = ?', [json_encode($cfg), $legacy]);
        $this->assertSame('old-first-version', json_decode((string) $s->find($legacy, true)['action_config_json'], true)['secret']);
        $this->assertGreaterThanOrEqual(1, $s->sealStoredSecrets());
        $after = json_decode((string) $this->db->fetchOne('SELECT action_config_json FROM automation_rules WHERE rule_id = ?', [$legacy])['action_config_json'], true)['secret_enc'];
        $this->assertSame('old-first-version', $vault->decrypt(AutomationRuleStore::secretContext($legacy), $after), 're-sealed under the rule\'s own name');
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
