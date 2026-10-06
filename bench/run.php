<?php

declare(strict_types=1);

/*
 * RivetCore benchmark harness (issue #42). Plain PHP CLI, no extra dependencies.
 *
 *   php bench/run.php [--runs=3] [--scale=1] [--only=audit,jobs] [--json=out.json] [--check=bench/thresholds.json]
 *
 * Needs a SCRATCH MariaDB/MySQL database (the name must contain "scratch", "bench" or "test": the harness TRUNCATEs the
 * Core tables in it): RIVETCORE_BENCH_DB_{HOST,NAME,USER,PASS}, falling back to RIVETCORE_TEST_DB_*. Redis cases run only
 * when RIVETCORE_BENCH_REDIS_PORT (or RIVETCORE_TEST_REDIS_PORT) names a THROWAWAY Redis on 127.0.0.1; never point it at
 * a real one (keys under "bench:" are written and deleted). Without Redis those cases are skipped and say so.
 *
 * Each case is repeated --runs times (default 3) and the median run by throughput is reported. --scale multiplies the
 * data-set sizes. --check exits 1 when a case falls below its minimum ops/s in the thresholds file.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Harness.php';

use RivetCore\Audit\AuditReader;
use RivetCore\Audit\AuditService;
use RivetCore\Bench\Ctx;
use RivetCore\Bench\Harness;
use RivetCore\Compliance\CheckInterface;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\ComplianceAssessor;
use RivetCore\Compliance\AttestationProviderInterface;
use RivetCore\Compliance\Framework;
use RivetCore\Compliance\ManualItem;
use RivetCore\Compliance\ReportRenderer;
use RivetCore\Contracts\ClockInterface;
use RivetCore\Jobs\JobQueue;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Redis\CronGuard;
use RivetCore\Redis\LockManager;
use RivetCore\Redis\RateLimiter;
use RivetCore\Redis\RedisClientProviderInterface;
use RivetCore\Retention\RetentionService;
use RivetCore\Support\NullRequestContext;
use RivetCore\Support\SystemClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Ui\DateRange;
use RivetCore\Webhooks\UrlPolicy;
use RivetCore\Webhooks\WebhookDispatcher;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

$opt = getopt('', ['runs::', 'scale::', 'only::', 'json::', 'check::', 'help']);
if (isset($opt['help'])) {
    echo "php bench/run.php [--runs=3] [--scale=1] [--only=audit,jobs] [--json=out.json] [--check=bench/thresholds.json]\n";
    exit(0);
}
$runs = max(1, (int) ($opt['runs'] ?? 3));
$scale = max(0.01, (float) ($opt['scale'] ?? 1));
$only = isset($opt['only']) ? array_values(array_filter(explode(',', (string) $opt['only']))) : [];
$n = static fn (int $base): int => max(10, (int) round($base * $scale));

$env = static fn (string $k): string => (string) (getenv('RIVETCORE_BENCH_' . $k) ?: getenv('RIVETCORE_TEST_' . $k) ?: '');
$dbName = $env('DB_NAME');
if ($dbName === '' || !preg_match('/scratch|bench|test/i', $dbName)) {
    fwrite(STDERR, "Set RIVETCORE_BENCH_DB_NAME (or RIVETCORE_TEST_DB_NAME) to a throwaway database whose name contains scratch, bench or test.\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($env('DB_HOST') ?: '127.0.0.1', $env('DB_USER') ?: 'root', $env('DB_PASS'), $dbName);
$mysqli->set_charset('utf8mb4');
$db = new MysqliDatabase($mysqli);
$clock = new SystemClock();
(new MigrationRunner($db, CoreMigrations::all(), $clock))->run();

$redisPort = (int) $env('REDIS_PORT');
$redisProvider = new class($redisPort) implements RedisClientProviderInterface {
    public function __construct(private int $port)
    {
    }

    public function client(): ?\Predis\Client
    {
        return $this->port > 0 ? new \Predis\Client(['host' => '127.0.0.1', 'port' => $this->port, 'timeout' => 1.0]) : null;
    }
};
$redisDown = new class implements RedisClientProviderInterface {
    public function client(): ?\Predis\Client
    {
        return null;
    }
};

$truncate = static function (string ...$tables) use ($db): void {
    foreach ($tables as $t) {
        $db->execute("TRUNCATE TABLE `$t`");
    }
};

/** Multi-row INSERT of audit rows with a spread of types, actors and dates; $oldDays > 0 backdates them. */
$seedAudit = static function (int $rows, int $backdateDays) use ($db): void {
    $types = ['user.login', 'user.logout', 'ticket.create', 'ticket.update', 'client.edit', 'settings.edit', 'asset.create', 'invoice.send'];
    $chunk = 1000;
    for ($done = 0; $done < $rows; $done += $chunk) {
        $take = min($chunk, $rows - $done);
        $vals = [];
        $params = [];
        for ($i = 0; $i < $take; $i++) {
            $k = $done + $i;
            $vals[] = '(?, ?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? SECOND))';
            array_push($params, $types[$k % 8], 1 + ($k % 40), 'entity', (string) ($k % 5000), 'act', 'Seeded row ' . $k, '{"k":' . $k . '}', '10.0.0.' . ($k % 250), $backdateDays * 86400 + ($rows - $k));
        }
        $db->execute('INSERT INTO audit_events (event_type, actor_user_id, entity_type, entity_id, action, summary, metadata_json, ip_address, created_at) VALUES ' . implode(',', $vals), $params);
    }
};

$h = new Harness();

// ---- audit ----------------------------------------------------------------------------------------------------------
$h->case('audit.write', 'events/s (1 INSERT each, autocommit)', function (Ctx $c) use ($db, $truncate, $n): void {
    $truncate('audit_events');
    $audit = new AuditService($db, new NullRequestContext());
    $c->loop($n(1000), static fn (int $i) => $audit->log('ticket.update', 7, 'ticket', $i, 'edit', 'Changed priority', ['from' => 'Low', 'to' => 'High', 'password' => 'x']));
});

$auditRows = $n(100000);
$readerReady = false;
$prepReader = static function () use ($truncate, $seedAudit, $auditRows, &$readerReady): void {
    if (!$readerReady) {
        $truncate('audit_events');
        $seedAudit($auditRows, 0);
        $readerReady = true;
    }
};
$h->case('audit.reader.page1', "pages/s (50 rows, $auditRows-row table, COUNT + page)", function (Ctx $c) use ($db, $prepReader, $n): void {
    $prepReader();
    $r = new AuditReader($db);
    $c->loop($n(300), static fn () => $r->page([], 1, 50));
});
$h->case('audit.reader.pageDeep', 'pages/s (page at 75% depth: OFFSET cost)', function (Ctx $c) use ($db, $prepReader, $n, $auditRows): void {
    $prepReader();
    $r = new AuditReader($db);
    $deep = (int) ($auditRows * 0.75 / 50);
    $c->loop($n(100), static fn () => $r->page([], $deep, 50));
});
$h->case('audit.reader.filterType', 'pages/s (event type + actor filter)', function (Ctx $c) use ($db, $prepReader, $n): void {
    $prepReader();
    $r = new AuditReader($db);
    $c->loop($n(200), static fn () => $r->page(['eventType' => 'ticket', 'actorUserId' => 7], 1, 50));
});
$h->case('audit.reader.search', 'pages/s (free-text LIKE search, unindexed)', function (Ctx $c) use ($db, $prepReader, $n): void {
    $prepReader();
    $r = new AuditReader($db);
    $c->loop($n(20), static fn () => $r->page(['search' => 'Seeded row 99'], 1, 50));
});
$h->case('audit.reader.exportChunked', 'rows/s (iterate, keyset chunks of 1000)', function (Ctx $c) use ($db, $prepReader, $n): void {
    $prepReader();
    $r = new AuditReader($db);
    $max = $n(50000);
    $c->bulk($max, static function () use ($r, $max): void {
        $count = 0;
        foreach ($r->iterate([], $max) as $_) {
            $count++;
        }
        assert($count === $max);
    });
});

// ---- jobs -----------------------------------------------------------------------------------------------------------
$h->case('jobs.enqueue', 'jobs/s', function (Ctx $c) use ($db, $truncate, $n): void {
    $truncate('integration_jobs');
    $q = new JobQueue($db);
    $c->loop($n(600), static fn (int $i) => $q->enqueue('bench.job', ['i' => $i, 'to' => 'someone@example.test'], 1, 'ticket', $i % 3));
});
$h->case('jobs.claim', 'jobs/s claimed (batches of 10)', function (Ctx $c) use ($db, $truncate, $n): void {
    $truncate('integration_jobs');
    $q = new JobQueue($db);
    $total = $n(2000);
    for ($done = 0; $done < $total; $done += 500) {   // untimed setup: multi-row INSERT instead of one commit per job
        $take = min(500, $total - $done);
        $db->execute('INSERT INTO integration_jobs (job_type, payload) VALUES ' . implode(',', array_fill(0, $take, "('bench.job', '{}')")));
    }
    $c->bulk($total, static function () use ($q, $total): void {
        $got = 0;
        while (($rows = $q->claim(10)) !== []) {
            $got += count($rows);
        }
        assert($got === $total);
    });
});
$h->case('jobs.lifecycle', 'jobs/s enqueue+claim+complete (1 at a time)', function (Ctx $c) use ($db, $truncate, $n): void {
    $truncate('integration_jobs');
    $q = new JobQueue($db);
    $c->loop($n(300), static function (int $i) use ($q): void {
        $q->enqueue('bench.job', ['i' => $i]);
        foreach ($q->claim(1) as $job) {
            $q->markCompleted((int) $job['job_id'], ['ok' => true], (int) $job['attempts']);
        }
    });
});

// ---- cron guard / lock / rate limit (Redis) --------------------------------------------------------------------------
if ($redisPort > 0) {
    $h->case('redis.lock.acquireRelease', 'lock cycles/s', function (Ctx $c) use ($redisProvider, $n): void {
        $m = new LockManager($redisProvider, 'bench:');
        $c->loop($n(5000), static function (int $i) use ($m): void {
            $l = $m->acquire('job' . ($i % 50), 30);
            $l->release();
        });
    });
    $h->case('redis.cronguard.contended', 'acquire attempts/s while another holder has it', function (Ctx $c) use ($redisProvider, $n): void {
        $m = new LockManager($redisProvider, 'bench:');
        $holder = $m->acquire('cron:held', 60);
        $g = new CronGuard($m);
        $c->loop($n(5000), static fn () => $g->acquire('held', 60));
        $holder->release();
    });
    $h->case('redis.cronguard.acquire', 'guarded runs/s (acquire; release is deferred to shutdown)', function (Ctx $c) use ($redisProvider, $n): void {
        $m = new LockManager($redisProvider, 'bench:');
        $g = new CronGuard($m);
        $k = bin2hex(random_bytes(4));
        $c->loop($n(2000), static function (int $i) use ($g, $k): void {
            $g->acquire("bench-$k-$i", 5);
        });
    });
    $h->case('redis.ratelimit.hit', 'hits/s', function (Ctx $c) use ($redisProvider, $n): void {
        $r = new RateLimiter($redisProvider, 'bench:');
        $c->loop($n(5000), static fn (int $i) => $r->hit('api:' . ($i % 20), 1000000, 60));
    });
}
$h->case('redis.failopen.lockWhenDown', 'lock attempts/s with no Redis (fail open)', function (Ctx $c) use ($redisDown, $n): void {
    $m = new LockManager($redisDown, 'bench:');
    $c->loop($n(50000), static fn (int $i) => $m->acquire('x', 30));
});

// ---- webhooks -------------------------------------------------------------------------------------------------------
$h->case('webhook.signatureV2', 'signatures/s (HMAC-SHA256, 2 KB body)', function (Ctx $c) use ($n): void {
    $body = str_repeat('{"k":"v"}', 230);
    $c->loop($n(100000), static fn (int $i) => WebhookDispatcher::signatureV2(1760000000 + $i, $body, 'a-32-byte-shared-secret-for-bench'));
});
$subs = new class implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface {
    public function forEvent(string $eventType): array
    {
        return [$this->find(1)];
    }

    public function find(int $webhookId): ?WebhookSubscription
    {
        return new WebhookSubscription($webhookId, 'https://hooks.example.test/in', 'a-32-byte-shared-secret-for-bench');
    }
};
$fakeTransport = static fn (): array => ['status' => 200, 'body' => 'ok', 'error' => null];
$h->case('webhook.deliverTo.fakeTransport', 'deliveries/s (build + sign + log row; no network)', function (Ctx $c) use ($db, $truncate, $subs, $fakeTransport, $clock, $n): void {
    $truncate('webhook_deliveries');
    $d = new WebhookDispatcher($db, $subs, $clock, ['X-RivetCore'], $fakeTransport);
    $c->loop($n(500), static fn (int $i) => $d->deliverTo(1, 'ticket.created', ['ticket_id' => $i, 'subject' => 'Printer on fire', 'priority' => 'High']));
});
$h->case('webhook.deliverTo.slackFormat', 'deliveries/s (Slack format + sign + log row)', function (Ctx $c) use ($db, $truncate, $subs, $fakeTransport, $clock, $n): void {
    $truncate('webhook_deliveries');
    $d = new WebhookDispatcher($db, $subs, $clock, ['X-RivetCore'], $fakeTransport);
    $c->loop($n(500), static fn (int $i) => $d->deliverTo(1, 'ticket.created', ['ticket_id' => $i, 'subject' => 'Printer on fire'], 1, null, null, ['format' => 'slack']));
});

// ---- retention ------------------------------------------------------------------------------------------------------
$pruneRows = $n(200000);
$h->case('retention.plan', "plans/s (COUNT over $pruneRows audit rows, 3 tables)", function (Ctx $c) use ($db, $truncate, $seedAudit, $pruneRows, $n): void {
    $truncate('audit_events', 'webhook_deliveries', 'integration_jobs');
    $seedAudit($pruneRows, 120);   // everything is older than the horizon
    $svc = new RetentionService($db);
    $c->loop($n(10), static fn () => $svc->plan(90));
});
$h->case('retention.prune.audit', "rows/s deleted (batches of 5000, $pruneRows rows past the horizon)", function (Ctx $c) use ($db, $truncate, $seedAudit, $pruneRows): void {
    $truncate('audit_events', 'webhook_deliveries', 'integration_jobs');
    $seedAudit($pruneRows, 120);
    $svc = new RetentionService($db);
    $c->bulk($pruneRows, static function () use ($svc, $pruneRows): string {
        $r = $svc->prune(90);
        assert($r['audit_events'] === $pruneRows);

        return 'deleted ' . $r['audit_events'];
    });
});
$h->case('retention.prune.nothingToDo', 'prunes/s (3 tables, no rows past the horizon, 100k rows kept)', function (Ctx $c) use ($db, $truncate, $seedAudit, $n): void {
    $truncate('audit_events', 'webhook_deliveries', 'integration_jobs');
    $seedAudit($n(100000), 0);
    $svc = new RetentionService($db);
    $c->loop($n(20), static fn () => $svc->prune(90));
});

// ---- compliance -----------------------------------------------------------------------------------------------------
$mkCheck = static fn (int $i): CheckInterface => new class($i) implements CheckInterface {
    public function __construct(private int $i)
    {
    }

    public function id(): string
    {
        return 'check_' . $this->i;
    }

    public function title(): string
    {
        return 'Check number ' . $this->i;
    }

    public function category(): string
    {
        return 'Cat ' . ($this->i % 5);
    }

    public function why(): string
    {
        return 'Because control ' . $this->i . ' matters.';
    }

    public function controls(): array
    {
        return [Framework::SOC2 => ['CC' . $this->i], Framework::ISO27001 => ['A.' . $this->i], Framework::PCI => ['8.' . $this->i]];
    }

    public function run(): CheckResult
    {
        return $this->i % 7 === 0 ? CheckResult::fail('Not met', 'detail') : ($this->i % 3 === 0 ? CheckResult::warn('Partly') : CheckResult::pass('Fine'));
    }
};
$checks = array_map($mkCheck, range(1, 40));
$manual = [];
for ($i = 1; $i <= 25; $i++) {
    $manual[] = new ManualItem('manual_' . $i, 'Manual item ' . $i, 'Policy', 'Why ' . $i, [Framework::SOC2 => ['CC9.' . $i]], 365);
}
$attest = new class implements AttestationProviderInterface {
    public function latestPerItem(): array
    {
        $o = [];
        for ($i = 1; $i <= 25; $i += 2) {
            $o['manual_' . $i] = ['reviewed_on' => '2026-09-01', 'next_due_on' => null, 'reviewer_name' => 'R', 'note' => null];
        }

        return $o;
    }
};
$fixedClock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-05 12:00:00');
    }
};
$assessor = new ComplianceAssessor($checks, $manual, $attest, $fixedClock);
$h->case('compliance.assess', 'assessments/s (40 checks, 25 manual items, 3 frameworks)', function (Ctx $c) use ($assessor, $n): void {
    $c->loop($n(2000), static fn () => $assessor->assess());
});
$assessment = $assessor->assess();
$renderer = new ReportRenderer();
$h->case('compliance.report.html', 'reports/s (printable HTML)', function (Ctx $c) use ($renderer, $assessment, $n): void {
    $c->loop($n(2000), static fn () => $renderer->html($assessment, 'Example Org', Framework::SOC2, '1.0'));
});
$h->case('compliance.report.csv', 'reports/s (CSV)', function (Ctx $c) use ($renderer, $assessment, $n): void {
    $c->loop($n(3000), static fn () => $renderer->csv($assessment, Framework::SOC2));
});

// ---- URL policy / date ranges ---------------------------------------------------------------------------------------
$h->case('urlpolicy.vet.publicHost', 'vets/s (resolver stubbed: no DNS)', function (Ctx $c) use ($n): void {
    $p = new UrlPolicy(false, static fn (string $host): array => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);
    $c->loop($n(100000), static fn () => $p->vet('https://hooks.example.test:8443/in?x=1'));
});
$h->case('urlpolicy.vet.rejected', 'vets/s (private target, rejected)', function (Ctx $c) use ($n): void {
    $p = new UrlPolicy(false, static fn (string $host): array => ['10.0.0.5']);
    $c->loop($n(100000), static fn () => $p->vet('http://internal.example.test/in'));
});
$h->case('urlpolicy.vet.allowedNetwork', 'vets/s (private target admitted by allowedNetworks)', function (Ctx $c) use ($n): void {
    $p = new UrlPolicy(false, static fn (string $host): array => ['192.168.1.20'], ['192.168.1.0/24']);
    $c->loop($n(100000), static fn () => $p->vet('http://nas.example.test/in'));
});
$presets = array_keys(DateRange::presets());
$tz = new DateTimeZone('America/Chicago');
$now = new DateTimeImmutable('2026-10-05 12:00:00', $tz);
$h->case('daterange.resolve', 'resolutions/s (cycling every preset, DST zone)', function (Ctx $c) use ($presets, $tz, $now, $n): void {
    $np = count($presets);
    $c->loop($n(100000), static fn (int $i) => DateRange::resolve($presets[$i % $np], null, null, $now, $tz)->sqlBounds());
});
$h->case('daterange.resolveCustom', 'resolutions/s (custom from/to + previous())', function (Ctx $c) use ($tz, $now, $n): void {
    $c->loop($n(50000), static fn () => DateRange::resolve('custom', '2026-03-01', '2026-03-31', $now, $tz)->previous()->toQuery());
});

fwrite(STDERR, "RivetCore bench: PHP " . PHP_VERSION . ", {$mysqli->server_info}, runs=$runs, scale=$scale\n");
$h->run($runs, $only);

$report = [
    'meta' => [
        'php' => PHP_VERSION,
        'db' => $mysqli->server_info,
        'redis' => $redisPort > 0 ? 'port ' . $redisPort : 'not used',
        'runs' => $runs,
        'scale' => $scale,
        'cpu' => (function (): string {
            $i = @file_get_contents('/proc/cpuinfo');

            return $i && preg_match('/model name\s*:\s*(.+)/', $i, $m) ? trim($m[1]) : php_uname('m');
        })(),
        'os' => php_uname('s') . ' ' . php_uname('r'),
        'date' => gmdate('c'),
    ],
    'results' => $h->toArray(),
];
if (isset($opt['json'])) {
    file_put_contents((string) $opt['json'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (isset($opt['check'])) {
    $thresholds = json_decode((string) file_get_contents((string) $opt['check']), true, 16, JSON_THROW_ON_ERROR);
    $fails = $h->check($thresholds['cases'] ?? []);
    foreach ($fails as $f) {
        fwrite(STDERR, "REGRESSION $f\n");
    }
    exit($fails === [] ? 0 : 1);
}
