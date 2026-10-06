<?php

declare(strict_types=1);

/**
 * RivetCore performance baselines (issue #42).
 *
 *   RIVETCORE_TEST_DB_NAME=rivetcore_scratch_docs RIVETCORE_TEST_DB_USER=... RIVETCORE_TEST_DB_PASS=... \
 *   [RIVETCORE_TEST_DB_HOST=127.0.0.1] [RIVETCORE_TEST_DB_PORT=3306] [RIVETCORE_TEST_REDIS_PORT=6390] php scripts/bench.php [--json]
 *
 * Needs the dev install (it uses tests/Support/MysqliDatabase). Prints a markdown table; iteration counts are fixed so runs
 * are comparable. Skips cleanly (exit 0) when the scratch database variables are unset. Redis benchmarks are skipped when
 * RIVETCORE_TEST_REDIS_PORT is unset or the server is unreachable.
 *
 * SAFETY: the script DROPS EVERY TABLE in the target database, so it refuses to run unless the database name contains
 * "scratch". Never point it at a database that holds data you want.
 */

require __DIR__ . '/../vendor/autoload.php';

use RivetCore\Audit\AuditReader;
use RivetCore\Audit\AuditService;
use RivetCore\Jobs\JobQueue;
use RivetCore\Jobs\JobWorker;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Redis\LockManager;
use RivetCore\Redis\RateLimiter;
use RivetCore\Retention\RetentionService;
use RivetCore\Support\NullRequestContext;
use RivetCore\Support\SystemClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\TestRedis;
use RivetCore\Ui\DateRange;
use RivetCore\Webhooks\EventCatalog;
use RivetCore\Webhooks\PayloadFormatter;
use RivetCore\Webhooks\PayloadTemplate;

$name = (string) getenv('RIVETCORE_TEST_DB_NAME');
if ($name === '') {
    fwrite(STDOUT, "bench: RIVETCORE_TEST_DB_NAME is not set; skipping (see the header of scripts/bench.php).\n");
    exit(0);
}
if (!str_contains($name, 'scratch')) {
    fwrite(STDERR, "bench: refusing to run: the database name must contain 'scratch' because every table in it is dropped.\n");
    exit(2);
}

/** @var list<array{name:string,n:int,median:float,p95:float,ops:float,note:string}> $results */
$results = [];

/**
 * Time $n calls of $fn individually (microseconds), after $warmup untimed calls.
 *
 * @param callable(int):mixed $fn
 */
function bench(string $name, int $n, callable $fn, string $note = '', int $warmup = 20): void
{
    global $results;
    for ($i = 0; $i < $warmup; $i++) {
        $fn(-1 - $i);
    }
    $t = [];
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $s = hrtime(true);
        $fn($i);
        $t[] = (hrtime(true) - $s) / 1000.0;
    }
    $total = (hrtime(true) - $start) / 1e9;
    sort($t);
    $results[] = [
        'name' => $name,
        'n' => $n,
        'median' => $t[(int) floor(count($t) / 2)],
        'p95' => $t[(int) floor(count($t) * 0.95)],
        'ops' => $total > 0 ? $n / $total : 0.0,
        'note' => $note,
    ];
}

/** Time one operation run once (milliseconds go into the median column as microseconds). */
function once(string $name, callable $fn, string $note = ''): void
{
    global $results;
    $s = hrtime(true);
    $fn();
    $us = (hrtime(true) - $s) / 1000.0;
    $results[] = ['name' => $name, 'n' => 1, 'median' => $us, 'p95' => $us, 'ops' => $us > 0 ? 1e6 / $us : 0.0, 'note' => $note];
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli(
    getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost',
    getenv('RIVETCORE_TEST_DB_USER') ?: 'root',
    getenv('RIVETCORE_TEST_DB_PASS') ?: '',
    $name,
    (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 3306)
);
$mysqli->set_charset('utf8mb4');
$db = new MysqliDatabase($mysqli);
$clock = new SystemClock();

// Start from an empty database so the migration run is the real cold path.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$tables = $mysqli->query('SHOW TABLES');
while ($row = $tables->fetch_row()) {
    $mysqli->query('DROP TABLE `' . str_replace('`', '``', (string) $row[0]) . '`');
}
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

$migrationCount = count(CoreMigrations::all());
once("MigrationRunner::run, empty database ({$migrationCount} migrations)", static function () use ($db, $clock): void {
    (new MigrationRunner($db, CoreMigrations::all(), $clock))->run();
});
once('MigrationRunner::run, already current (no-op)', static function () use ($db, $clock): void {
    (new MigrationRunner($db, CoreMigrations::all(), $clock))->run();
});

// ---- Audit write ----------------------------------------------------------------------------------------------------
$audit = new AuditService($db, new NullRequestContext());
bench('AuditService::log (one INSERT)', 2000, static function (int $i) use ($audit): void {
    $audit->log('ticket.update', 7, 'ticket', $i, 'update', 'Changed priority', ['from' => 'Low', 'to' => 'High']);
}, 'metadata + summary');

// ---- Seed 100k audit rows (multi-row inserts), a third of them old ---------------------------------------------------
$mysqli->query('TRUNCATE TABLE audit_events');
$types = ['ticket.create', 'ticket.update', 'user.login', 'asset.edit', 'settings.edit', 'client.create'];
for ($batch = 0; $batch < 100; $batch++) {
    $values = [];
    for ($j = 0; $j < 1000; $j++) {
        $k = $batch * 1000 + $j;
        $type = $types[$k % 6];
        $ageDays = $k % 3 === 0 ? 400 + ($k % 50) : ($k % 300);
        $values[] = "('{$type}', " . (1 + $k % 40) . ", 'ticket', '" . $k . "', 'update', 'Seeded row " . $k . "', NULL, '10.0.0.1', 'bench', 'rid" . $k . "', NOW() - INTERVAL {$ageDays} DAY)";
    }
    $mysqli->query('INSERT INTO audit_events (event_type, actor_user_id, entity_type, entity_id, action, summary, metadata_json, ip_address, user_agent, request_id, created_at) VALUES ' . implode(',', $values));
}
$mysqli->query('ANALYZE TABLE audit_events');
$rows = (int) $mysqli->query('SELECT COUNT(*) FROM audit_events')->fetch_row()[0];

$reader = new AuditReader($db);
bench("AuditReader::page, unfiltered, page 1 ({$rows} rows)", 100, static function () use ($reader): void {
    $reader->page([], 1, 50);
});
bench("AuditReader::page, unfiltered, page 1000 ({$rows} rows)", 50, static function () use ($reader): void {
    $reader->page([], 1000, 50);
}, 'OFFSET 49950');
bench("AuditReader::page, filtered by event type ({$rows} rows)", 100, static function () use ($reader): void {
    $reader->page(['event_type' => 'user.login'], 1, 50);
});

// ---- Retention plan on 100k rows --------------------------------------------------------------------------------------
$retention = new RetentionService($db);
bench("RetentionService::plan (90 day horizon, {$rows} audit rows)", 30, static function () use ($retention): void {
    $retention->plan(90);
});

// ---- Job queue ----------------------------------------------------------------------------------------------------
$queue = new JobQueue($db);
bench('JobQueue::enqueue', 1000, static function (int $i) use ($queue): void {
    $queue->enqueue('bench.noop', ['n' => $i]);
});
$claimedJobs = [];
bench('JobQueue::claim (limit 1)', 500, static function () use ($queue, &$claimedJobs): void {
    foreach ($queue->claim(1) as $job) {
        $claimedJobs[] = $job;
    }
});
$done = 0;
$jobsToComplete = $claimedJobs;
bench('JobQueue::markCompleted', max(1, count($jobsToComplete) - 40), static function (int $i) use ($queue, $jobsToComplete, &$done): void {
    $job = $jobsToComplete[max(0, $i) % count($jobsToComplete)];
    $queue->markCompleted((int) $job['job_id'], ['ok' => true], (int) $job['attempts']);
    $done++;
}, 'includes the 20 warmup calls on already-finished jobs');

$mysqli->query('TRUNCATE TABLE integration_jobs');
for ($i = 0; $i < 1000; $i++) {
    $queue->enqueue('bench.noop', ['n' => $i]);
}
$worker = (new JobWorker($queue))->register('bench.noop', static fn (array $payload): array => ['ok' => true]);
$workerStart = hrtime(true);
$workerStats = $worker->run(1000, 120);
$workerUs = (hrtime(true) - $workerStart) / 1000.0;
$perJob = $workerUs / max(1, $workerStats['completed']);
$results[] = [
    'name' => 'JobWorker::run, trivial handler (claim + run + complete)',
    'n' => $workerStats['completed'],
    'median' => $perJob,
    'p95' => $perJob,
    'ops' => 1e6 / max(0.000001, $perJob),
    'note' => 'mean per job over one run; claims in batches of 10',
];

// ---- Webhook formatting (no network) -------------------------------------------------------------------------------------
$event = [
    'event' => 'ticket.created',
    'timestamp' => '2026-10-06T12:00:00Z',
    'data' => PayloadTemplate::sampleContext('ticket.created')['data'],
];
foreach (PayloadFormatter::formats() as $format) {
    $opts = match ($format) {
        'telegram' => ['chat_id' => '12345'],
        'template' => ['template' => '{"title":"{{summary.title}}","id":{{data.ticket_id|json}}}', 'template_encoding' => 'json'],
        default => [],
    };
    bench("PayloadFormatter::format {$format}", 3000, static function () use ($format, $event, $opts): void {
        PayloadFormatter::format($format, $event, $opts);
    });
}
$ctx = PayloadTemplate::sampleContext('ticket.created');
$tpl = '{"title":"{{summary.title|truncate:80}}","ticket":{{data.ticket_id|json}},"client":"{{data.client_name|upper}}","note":"{{data.missing|default:"none"}}"}';
bench('PayloadTemplate::render (4 placeholders, json)', 5000, static function () use ($tpl, $ctx): void {
    PayloadTemplate::render($tpl, $ctx, 'json');
});

// ---- Pure functions ---------------------------------------------------------------------------------------------------
$eventCount = count(EventCatalog::all());
$queries = ['tick', 'sla.breach', 'password', 'backup', 'zzz'];
bench("EventCatalog::search ({$eventCount} events)", 5000, static function (int $i) use ($queries): void {
    EventCatalog::search($queries[abs($i) % 5]);
});
$tz = new DateTimeZone('America/Chicago');
$presets = ['today', 'last30', 'thismonth', 'lastquarter', 'last12months'];
bench('DateRange::resolve', 20000, static function (int $i) use ($presets, $tz): void {
    DateRange::resolve($presets[abs($i) % 5], null, null, null, $tz)->sqlBounds();
}, 'resolve + sqlBounds');

// ---- Redis ---------------------------------------------------------------------------------------------------------------
$redisNote = 'skipped (RIVETCORE_TEST_REDIS_PORT unset or Redis unreachable)';
$redis = new TestRedis();
$client = TestRedis::available() ? $redis->client() : null;
$alive = false;
if ($client !== null) {
    try {
        $client->ping();
        $alive = true;
    } catch (Throwable) {
        $alive = false;
    }
}
if ($alive) {
    // TestRedis builds a new Predis client on every call; the editions' providers keep one per request, so cache it here.
    $shared = new class ($client) implements RivetCore\Redis\RedisClientProviderInterface {
        public function __construct(private \Predis\Client $c)
        {
        }

        public function client(): ?\Predis\Client
        {
            return $this->c;
        }
    };
    $locks = new LockManager($shared, 'bench:');
    $limiter = new RateLimiter($shared, 'bench:');
    bench('LockManager::acquire + release', 3000, static function (int $i) use ($locks): void {
        $locks->acquire('l' . $i, 30)->release();
    });
    bench('RateLimiter::hit', 3000, static function () use ($limiter): void {
        $limiter->hit('bucket', 1000000, 60);
    });
}

// ---- Output ---------------------------------------------------------------------------------------------------------------
$version = (string) $mysqli->query('SELECT VERSION()')->fetch_row()[0];
$cpu = 'unknown';
if (is_readable('/proc/cpuinfo')) {
    foreach (file('/proc/cpuinfo') ?: [] as $line) {
        if (str_starts_with($line, 'model name')) {
            $cpu = trim(explode(':', $line, 2)[1]);
            break;
        }
    }
}
$cores = is_readable('/proc/cpuinfo') ? substr_count((string) file_get_contents('/proc/cpuinfo'), "processor\t") : 0;
$mem = 'unknown';
if (is_readable('/proc/meminfo') && preg_match('/MemTotal:\s+(\d+) kB/', (string) file_get_contents('/proc/meminfo'), $m)) {
    $mem = round(((int) $m[1]) / 1048576, 1) . ' GiB';
}
$opcache = (function_exists('opcache_get_status') && ini_get('opcache.enable_cli')) ? 'on' : 'off';

if (in_array('--json', $argv, true)) {
    echo json_encode(['php' => PHP_VERSION, 'db' => $version, 'cpu' => $cpu, 'results' => $results, 'redis' => $alive], JSON_PRETTY_PRINT), "\n";
    exit(0);
}

echo 'RivetCore benchmark, ' . gmdate('Y-m-d H:i') . " UTC\n\n";
echo '- PHP ' . PHP_VERSION . ' (' . PHP_OS_FAMILY . ", opcache CLI {$opcache})\n";
echo "- Database: {$version}\n";
echo "- Machine: {$cpu}, {$cores} logical CPUs, {$mem} RAM\n";
echo '- Load average at the end of the run: ' . (function_exists('sys_getloadavg') ? implode(' ', array_map(static fn (float $l): string => number_format($l, 2), sys_getloadavg() ?: [])) : 'unknown') . " (a busy host inflates every number)\n";
echo '- Redis: ' . ($alive ? 'throwaway instance on port ' . getenv('RIVETCORE_TEST_REDIS_PORT') : $redisNote) . "\n\n";
echo "| Benchmark | Iterations | Median (us) | p95 (us) | Ops/s |\n|---|---:|---:|---:|---:|\n";
foreach ($results as $r) {
    $note = $r['note'] !== '' ? ' (' . $r['note'] . ')' : '';
    printf("| %s%s | %d | %s | %s | %s |\n", $r['name'], $note, $r['n'], number_format($r['median'], 1, '.', ''), number_format($r['p95'], 1, '.', ''), number_format($r['ops'], 0, '.', ''));
}
echo "\nMedian and p95 are per call in microseconds; for single-shot rows (iterations = 1) they are the wall time of the whole operation.\n";
