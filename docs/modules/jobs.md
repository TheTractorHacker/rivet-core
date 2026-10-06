# Jobs

`RivetCore\Jobs`: a database-backed job queue (`JobQueue`) and a worker loop (`JobWorker`) with per-type handlers, timeouts and
heartbeats.

## What it owns

Table `integration_jobs` (migration 0002, heartbeat column in 0012):

`job_id`, `integration_id` (nullable), `job_type`, `resource_type`, `status` (`pending`, `running`, `completed`, `failed`,
`dead_letter`), `priority`, `attempts`, `max_attempts` (default 5), `available_at`, `started_at`, `heartbeat_at`, `completed_at`,
`payload`, `result`, `error`, `created_at`. Index `(status, available_at)`.

All timestamps come from the database clock (`NOW()`), so enqueue, due checks and back-off agree whatever time zone PHP runs in.

## You supply

Handlers: `callable(array $payload, array $job[, JobContext $ctx]): array` registered per job type.

## Flags

None. Run `JobWorker::run()` from your cron (or a loop) to switch the queue on.

## Use it

<!-- run -->
```php
use RivetCore\Jobs\{JobQueue, JobWorker};

$queue = new JobQueue($db);
$queue->enqueue('demo.greet', ['name' => 'Ada']);

$worker = (new JobWorker($queue))->register('demo.greet', fn (array $payload) => ['greeting' => 'Hello ' . $payload['name']]);
$summary = $worker->run(10, 5);
echo json_encode($summary), "\n";
```

## How it fails

- A handler that throws fails the attempt; the queue retries with back-off (1, 5, 30, then 120 minutes) and after `max_attempts`
  moves the job to `dead_letter`. `PermanentJobFailure` skips the retries. An unknown job type is dead-lettered at once with a clear error.
- `claim()` is safe for concurrent workers: each candidate is claimed by a conditional `UPDATE ... WHERE status = 'pending'` and only rows
  this call won are returned.
- `markCompleted()`/`markFailed()` only write while the job is `running` and accept the claimed attempt number as a fence, so a worker
  whose job was reclaimed cannot overwrite the new owner's result. Both return `bool`.
- `requeueStale()` reclaims `running` jobs with no heartbeat for N minutes and dead-letters those that used all their attempts.
  **Handlers must be idempotent**: a job can run twice if a worker dies after doing the work but before recording it.
- Timeouts are cooperative: a handler that does not call `checkpoint()` is not interrupted, only recorded as failed if it returns late.
- Before migration 0012 is applied everything falls back to `started_at` (`supportsHeartbeat()`).
