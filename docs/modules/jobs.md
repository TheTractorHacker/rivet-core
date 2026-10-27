# Jobs

## Overview

`RivetCore\Jobs` is a database-backed job queue with a worker, for asynchronous work such as webhook delivery, directory sync or RMM calls. No long-running daemon is required: a cron tick can call the worker.

- **Owns:** `integration_jobs`, created by migration `0002_integration_jobs`.
- **Migration `0012_job_heartbeat`** adds the nullable `heartbeat_at` column. Before it is applied everything falls back to `started_at`; `JobQueue::supportsHeartbeat()` detects this at runtime.
- Both migrations are additive and idempotent (`IF NOT EXISTS` / column check). RivetIT already had the table from its own 2.6.52 migration.

States (`status` enum): `pending`, `running`, `completed`, `failed`, `dead_letter`. The worker itself uses `pending`, `running`, `completed` and `dead_letter`.

## Contracts an edition must implement

- `Database\DatabaseInterface` ([../adapters.md](../adapters.md)). All timestamps come from the database clock (`NOW()`), so the adapter's connection time zone must be consistent with the column values.
- **Handlers**, registered per job type: `callable(array $payload, array $job, JobContext $context): array|null`. The returned array is stored as the job result. Throw any exception to fail the attempt, or `PermanentJobFailure` for no retry. A handler may declare fewer parameters than three.
- Handlers must be idempotent: a reclaimed job can run twice.

## Key classes

```php
use RivetCore\Jobs\{JobQueue, JobWorker, JobContext, PermanentJobFailure};

$queue = new JobQueue($database);
$id = $queue->enqueue('sync.directory', ['id' => 5], integrationId: null, resourceType: null, priority: 0, maxAttempts: 5);

$worker = (new JobWorker($queue))
    ->setDefaultTimeout(120)
    ->register('sync.directory', function (array $payload, array $job, JobContext $ctx): array {
        foreach ($payload['batches'] ?? [] as $batch) {
            $ctx->checkpoint();                 // heartbeat, and throws JobTimeout when the budget is spent
            // ... work ...
        }
        if (empty($payload['id'])) {
            throw new PermanentJobFailure('The record no longer exists.');
        }
        return ['synced' => 1];
    }, 300);                                    // per-type timeout in seconds

$summary = $worker->run(limit: 20, seconds: 50);
// ['claimed','completed','retrying','dead','released','deferred']
```

`JobQueue` methods:

- `enqueue()`: returns the new `job_id`.
- `claim($limit)`: atomically claims pending, due jobs (highest priority first, then oldest) with a conditional `UPDATE`; returns only rows this call won, already in the `running` state with the incremented `attempts`.
- `markCompleted($id, $result, $attempt)` and `markFailed($id, $error, $attempts, $maxAttempts, $claimedAttempt)`: only write while the job is `running` and, when a fence is given, the attempt number still matches; return false when nothing was written.
- `heartbeat(int|list<int>)`: refreshes `heartbeat_at` on running jobs and returns how many are still running.
- `release($id)`: gives a claimed-but-not-started job back without spending an attempt.
- `requeueStale($minutes = 15)`: running jobs whose last heartbeat (or start) is older than that go back to `pending`; those with all attempts used go to `dead_letter`.
- `retry($id)` (dead-letter or failed back to pending with 0 attempts), `stats()`, `recent($limit, $status)`, `purgeCompleted($days)`.

`JobWorker` also has `has()`, `types()` and `timeoutFor()`. `JobContext` gives the handler `jobId()`, `jobType()`, `remainingSeconds()`, `expired()`, `heartbeat()` and `checkpoint()`.

### Backoff

On a failed attempt with attempts left, the job returns to `pending` with `available_at` pushed out by `[1, 5, 30, 120]` minutes for attempts 1 to 4; later attempts reuse 120. When `attempts >= max_attempts` it becomes `dead_letter` (default `max_attempts` is 5).

## Configuration

- `enqueue(... $priority, $maxAttempts = 5)`.
- `JobWorker::run($limit = 20, $seconds = 50)`: stops after `$limit` jobs or when the time budget is spent. No job is started after the budget; claimed-but-unstarted jobs are released with `released`/`deferred` counted.
- `register($type, $handler, ?$timeoutSeconds)` and `setDefaultTimeout(?int)`; null or below 1 means no limit.
- `requeueStale()` runs at the start of every `run()` with the default 15 minutes.

## How it fails

- Handler throws: attempt recorded as failed, message truncated to 2000 characters, retried with backoff, then dead-lettered.
- `PermanentJobFailure`: dead-lettered at once.
- Unknown job type: dead-lettered at once with "No handler registered for job type ..." so it is neither retried nor dropped. `retry()` it after registering a handler.
- Timeout is cooperative: `checkpoint()` throws `JobTimeout` once the per-job budget is spent, and a handler that returns after exceeding its timeout is recorded as a failed attempt. `JobTimeout` is retried like any failure. PHP cannot interrupt a running handler.
- A worker that dies leaves jobs `running`; `requeueStale()` recovers them. A long job keeps itself alive through `checkpoint()` or `heartbeat()`.
- If another worker reclaimed a job, a late `markCompleted`/`markFailed` from the old run writes nothing (attempt fence), and the worker skips jobs it can no longer heartbeat.

## Security notes

- All queries are prepared; payload and result are JSON text.
- Payloads are stored in clear in `integration_jobs.payload`; do not enqueue secrets, enqueue a reference and resolve it in the handler.
- Error text is stored and shown in the admin queue view; do not put secrets in exception messages.
- Retention deletes only finished jobs (`completed`, `dead_letter`); see [retention.md](retention.md).

## Used by

- **RivetIT:** `includes/event_bus.php` (enqueue for webhook delivery, worker with handlers, `PermanentJobFailure` when an endpoint is gone; gated by the `core.jobs.enabled` module flag), `admin/job_queue.php` and `admin/post/job_queue.php` (queue view, retry, run now), `src/Jobs/JobQueue.php` (compatibility wrapper).
- **RivetMSP:** `includes/event_bus.php` (same), `src/Core/CoreBridge.php` (`jobs()`), `admin/job_queue.php`, `admin/post/job_queue.php`.

## Links

- CHANGELOG: 0.3.0 (queue, migration 0002, atomic claim), 0.7.1 (markFailed fifth-attempt fix), 0.15.0 (worker, PermanentJobFailure), 0.17.0 (timeouts, heartbeat, migration 0012), 0.18.1 (requeue dead-lettering, fenced writes). See [../../CHANGELOG.md](../../CHANGELOG.md).
- Related: [../webhooks.md](../webhooks.md) (delivery retries via Jobs), [migration.md](migration.md), [retention.md](retention.md).
- Tests: `tests/Integration/JobQueueTest.php`, `tests/Integration/JobWorkerTest.php`, `tests/Integration/JobsAutomationWebhookTest.php`.
