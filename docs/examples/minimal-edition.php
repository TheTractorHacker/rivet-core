<?php

declare(strict_types=1);

/*
 * A minimal, runnable "edition": everything an application has to provide to use RivetCore, in one file.
 *
 *   RIVETCORE_EXAMPLE_DB_HOST/NAME/USER/PASS   a SCRATCH MariaDB/MySQL database (falls back to RIVETCORE_TEST_DB_*); the Core tables are
 *                                              created in it. The name must contain "scratch", "example" or "test".
 *   php docs/examples/minimal-edition.php
 *
 * It wires: the database adapter, settings, request context, clock, audit trail, a job queue with a worker, signed webhooks (with a fake
 * transport so nothing leaves the machine), a lock that fails open without Redis, and prints one line per step. Read it top to bottom;
 * docs/adapters.md explains each piece.
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/MysqliDatabaseAdapter.php';

use Example\Edition\MysqliDatabaseAdapter;
use RivetCore\Audit\{AuditReader, AuditService};
use RivetCore\Contracts\{RequestContextInterface, SettingsInterface};
use RivetCore\Jobs\{JobQueue, JobWorker};
use RivetCore\Migration\{CoreMigrations, MigrationRunner};
use RivetCore\Redis\{LockManager, RedisClientProviderInterface};
use RivetCore\Support\SystemClock;
use RivetCore\Webhooks\{WebhookDispatcher, WebhookSubscription, WebhookSubscriptionLookupInterface, WebhookSubscriptionsInterface};

// ---- 1. Storage ---------------------------------------------------------------------------------------------------------------
$env = static fn (string $k): string => (string) (getenv('RIVETCORE_EXAMPLE_' . $k) ?: getenv('RIVETCORE_TEST_' . $k) ?: '');
$name = $env('DB_NAME');
if (!preg_match('/scratch|example|test/i', $name)) {
    fwrite(STDERR, "Set RIVETCORE_EXAMPLE_DB_NAME (or RIVETCORE_TEST_DB_NAME) to a scratch database.\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($env('DB_HOST') ?: '127.0.0.1', $env('DB_USER') ?: 'root', $env('DB_PASS'), $name);
$mysqli->set_charset('utf8mb4');
$db = new MysqliDatabaseAdapter($mysqli);

// ---- 2. The small contracts: settings, request context, clock --------------------------------------------------------------------
$settings = new class(['site.name' => 'Example Co']) implements SettingsInterface {
    public function __construct(private array $values)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
};
$request = new class implements RequestContextInterface {
    private string $id;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(8));   // server-chosen, never a client header
    }

    public function ipAddress(): ?string
    {
        return '203.0.113.7';                    // an edition reads this from its trusted proxy configuration
    }

    public function userAgent(): ?string
    {
        return 'example-edition/1.0';
    }

    public function requestId(): ?string
    {
        return $this->id;
    }
};
$clock = new SystemClock();

// ---- 3. Migrations: run from your updater, after your own -------------------------------------------------------------------------
$applied = (new MigrationRunner($db, CoreMigrations::all(), $clock))->run();
echo 'migrations: ', $applied === [] ? 'already current' : count($applied) . ' applied', "\n";

// ---- 4. Audit ---------------------------------------------------------------------------------------------------------------------
$audit = new AuditService($db, $request);
$audit->log('settings.edit', 1, 'settings', 1, 'edit', 'Renamed the site to ' . $settings->get('site.name'), ['api_key' => 'never stored in clear']);
$page = (new AuditReader($db))->page(['eventType' => 'settings'], 1, 10);
echo 'audit: ', $page->total, ' settings event(s); metadata api_key stored as ', $page->rows[0]['metadata']['api_key'], "\n";

// ---- 5. Jobs ----------------------------------------------------------------------------------------------------------------------
$queue = new JobQueue($db);
$queue->enqueue('example.greet', ['name' => 'Ada']);
$summary = (new JobWorker($queue))->register('example.greet', fn (array $payload): array => ['greeting' => 'Hello ' . $payload['name']])->run(10, 5);
echo 'jobs: claimed ', $summary['claimed'], ', completed ', $summary['completed'], "\n";

// ---- 6. Webhooks (fake transport: nothing is sent) -------------------------------------------------------------------------------
$subscriptions = new class implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface {
    public function forEvent(string $eventType): array
    {
        return [$this->find(1)];
    }

    public function find(int $webhookId): ?WebhookSubscription
    {
        return new WebhookSubscription($webhookId, 'https://hooks.example.test/in', 'shared-secret');
    }
};
$signature = null;
$transport = function (string $url, string $body, array $headers) use (&$signature): array {
    foreach ($headers as $h) {
        if (str_starts_with($h, 'X-Rivet-Signature-V2:')) {
            $signature = $h;
        }
    }

    return ['status' => 204, 'body' => '', 'error' => null];
};
$policy = new RivetCore\Webhooks\UrlPolicy(false, fn (string $host): array => ['93.184.216.34']);   // stubbed DNS for the example
$result = (new WebhookDispatcher($db, $subscriptions, $clock, ['X-Example'], $transport, 10, $policy))->deliverTo(1, 'ticket.created', ['ticket_id' => 7]);
echo 'webhook: ok=', var_export($result['ok'], true), ', signed=', $signature !== null ? 'yes' : 'no', "\n";

// ---- 7. Redis is optional: a provider that returns null makes every helper fail open -------------------------------------------------
$noRedis = new class implements RedisClientProviderInterface {
    public function client(): ?\Predis\Client
    {
        return null;
    }
};
$lock = (new LockManager($noRedis, 'example:'))->acquire('nightly', 60);
echo 'lock without Redis: held=', var_export($lock->held(), true), ' degraded=', var_export($lock->degraded(), true), "\n";

echo "done\n";
