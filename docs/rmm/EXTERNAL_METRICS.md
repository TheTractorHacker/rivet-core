# External metric store (S4)

An optional way to keep RMM metric history in a time-series database instead of MariaDB. **Nothing in Core uses it unless an edition wires it**:
the default is `DatabaseMetricSink`, one hourly rollup row per metric instance in `rmm_metric_hourly`, and that stays the right answer for almost
every install. Plan and targets: [SCALING.md](SCALING.md) (row S4).

## 1. When to use it

Only when the S9 validation on the reference hardware shows MariaDB cannot meet the targets with the rollup tables, the daily rollup (S5) and the
capacity controls tuned, and in practice beyond about 10,000 devices. Below that a second database engine is more to run, back up and secure than
it saves. If you are unsure, you do not need it.

What it buys: raw one-second-resolution points (the rollup keeps one row per hour), long retention without growing the Core database, queries by an
engine built for series. What it costs: another service, another credential, and a buffer you must monitor.

What stays in MariaDB regardless: the **latest** reading of every metric (`rmm_metric_latest`, the device page's live numbers) and the outbox. The
external store is never used for "latest".

## 2. The contract

`RivetCore\Rmm\Contracts\ExternalMetricStoreInterface`:

| Method | Rules |
|---|---|
| `write(list<point> $points): void` | One batch. Throws `ExternalStoreException` on any failure (`->permanent` is true only when the batch can never be accepted). Idempotent: the same batch twice changes nothing, so the outbox may redeliver after a crash or a lost response |
| `series($asset, $key, $instance, $since, $until, $stepSeconds)` | One point per step that has data, oldest first: `at` (start of the step), `min`, `max`, `avg`, `samples`. Steps are aligned to the Unix epoch and hold `[start, start + step)`; the step that holds `$until` is included |
| `peak($asset, $key, $instance, $since)` | `min`, `max`, `avg`, `samples`, `peak_at` for `$since` (aligned down to the hour, to the day beyond two days) up to now, or null. `peak_at` is the start of the step that held the maximum (1 hour up to two days, else 1 day; the latest step on a tie) |
| `ping(): bool` | Never throws; false when the store does not answer |

A point is `{asset_id, key, instance, value, at, label}`. The series is `(asset, key, instance)`. Instance `null` and `''` are the same series. A
point with the same series and second is one point. Out-of-range values (a utilization outside 0..100, a negative, a non-finite value) are dropped by
the sink **before** the store; an adapter must also cope with them being absent and must not invent values. Reads for an unknown asset, key or
instance are `[]` / `null`, not errors. Keys, instances and labels are untrusted text: an adapter must never let them change a query, never let one
series leak into another, and throws only `ExternalStoreException`.

The reference adapter is `VictoriaMetricsStore`. A transport is a `HttpTransportInterface` (`CurlHttpTransport` by default).

## 3. Reference setup: VictoriaMetrics

Single node: `victoria-metrics -httpListenAddr=127.0.0.1:8428 -retentionPeriod=12 -dedup.minScrapeInterval=1s -search.latencyOffset=0s`, behind a
TLS-terminating reverse proxy that checks a bearer token (or basic auth). Cluster: write base `https://vm.example/insert/0/influx`, read base
`https://vm.example/select/0/prometheus` (the adapter appends `/write`, `/api/v1/query_range`, `/api/v1/query`; `/health` of the origin is the ping).

```php
$store = new VictoriaMetricsStore(
    writeUrl: 'https://vm.example/insert/0/influx',
    urlPolicy: $urlPolicy,                       // required
    readUrl: 'https://vm.example/select/0/prometheus',
    bearerToken: $token,                         // or basicAuth: ['user', 'password']; never both
    timeoutSeconds: 10,
);
```

### Series naming

Write: InfluxDB line protocol, `POST <write>/write?precision=s`:

```
rmm,asset=42,metric=disk.utilization,instance=C:,label=System value=61.5 1700000000
```

VictoriaMetrics names the series `<measurement>_<field>` = **`rmm_value`**, with the tags as labels: `asset`, `metric` (the registry key), `instance`
(omitted when there is none; PromQL `instance=""` matches that), `label` (only when the sample has one). Tag values are escaped (backslash, comma,
space, equals sign). A value with control characters or invalid UTF-8, an instance over 256 bytes, a key outside `[a-z0-9_.]{1,64}`, a non-positive
asset or a non-finite value is skipped, never stored. Batches are split into requests of at most 2000 points.

Read: the Prometheus HTTP API with `min_over_time`, `max_over_time`, `avg_over_time` and `count_over_time` over
`rmm_value{asset="42",metric="cpu.utilization",instance=""}[<step>s]`. A bucket `[b, b+step)` is evaluated at `b+step-1`. `series()` is four
`query_range` calls (min, max, avg, count) merged by timestamp; `peak()` is four instant queries over the whole window plus one `query_range` at 1 hour
(or 1 day) to find `peak_at`. If one asset/key/instance has several `label` values their series are combined (count-weighted mean).

## 4. Security

- **URL policy.** `UrlPolicy` is required and every request series is vetted with `vet()`: no userinfo, no loopback, link-local or cloud-metadata
  addresses, no private ranges unless listed in the policy's allowed networks. The default transport then **pins the connection** to the addresses
  that were vetted (`CURLOPT_RESOLVE`, the same options as `WebhookDispatcher`), which defeats DNS rebinding; it never follows a redirect and never uses
  a proxy. A refused URL is never contacted and is a *transient* failure (the buffer keeps the data until the configuration is fixed).
- **https only.** Plain `http://` is refused unless the host is a private address the policy admits **and** `allowInsecureHttp: true` is passed. TLS
  is always verified.
- **Credentials.** The bearer token or basic-auth password is sent in the `Authorization` header only. It is never logged, never in an exception message
  (the adapter does not chain transport exceptions, whose text could echo a request) and never in `last_error`, which also masks `Bearer ...` and URL
  userinfo. Keep it in the edition's secret box, not in the database in clear. A 401 or 403 is treated as transient so an expired token does not
  throw the buffered history away.
- **Label injection.** Keys must match `[a-z0-9_.]{1,64}`. Instances and labels are escaped for line protocol and, in PromQL, backslash and double
  quote are escaped inside the quotes, and control characters make the series unrepresentable (skipped on write, empty on read). A hostile instance
  such as `"} or vector(1) #` therefore stays a value; the unit tests and the conformance kit assert it.
- **Response size.** Responses are read up to 1 MiB; more is a failure. Timeouts are bounded to 1..60 seconds.
- **Resource use.** One `series()` call asks for at most 5000 buckets (a longer range gets a proportionally wider step).

## 5. The buffer: retry, backoff, bounds

`ExternalMetricSink::ingest()` never talks to the network. It validates exactly like `DatabaseMetricSink` and writes **one** multi-row `INSERT` into
`rmm_metric_outbox` (JSON, 500 points per row). Any database or encoding failure is swallowed and counted (`droppedCount()` points): a metrics problem
never fails a check-in.

`flush()` (from cron, every minute) works through the due rows:

| Rule | Value |
|---|---|
| Claim | conditional `UPDATE ... SET lease_until = now + 60s WHERE outbox_id = ? AND (lease_until IS NULL OR lease_until < now)`; only the worker that changed the row sends it. A crashed worker's rows come back after 60 s; the store is idempotent so a re-send is harmless |
| Success | row deleted |
| Transient failure (network, timeout, 429, 5xx, 3xx, 401/403/407/408, URL refused, unexpected exception) | `attempts + 1`, `next_attempt_at = now + min(900, 5 * 2^attempts)` seconds with +/-20% jitter (5, 10, 20, 40, 80, 160, 320, 640, then 900 s; at most 1080 s with jitter), lease cleared, `last_error` = short sanitized message. The flush then **stops**: the store is probably down, so one failed attempt per run, not one timeout per row |
| Permanent failure (other 4xx) or unreadable payload | row deleted and counted in `permanent`: a poison batch never blocks the queue |
| `max_attempts` (default none) | when set, a row that failed that many times is dropped |
| Bound | `trim()` runs first in every flush (also callable from housekeeping): rows older than `max_age_s` (24 h) and the oldest rows beyond `max_rows` (20,000, about 10 million points) are deleted, at most 100,000 per call |
| Work per call | `flush($maxBatches = 20)` rounds of `batch_rows` (20) rows |

`flush()` returns `{sent, failed, permanent, dropped, pending}` (rows). Alert on `pending` growing and on `failed` staying above zero.

### Failure modes and what is lost

| Event | Result |
|---|---|
| Store down for less than 24 h and less than 20,000 rows of backlog | nothing lost; the backlog drains after it returns (idempotent) |
| Store down longer, or a backlog beyond `max_rows` | the **oldest** buffered rows are deleted; the gap exists in the external store only. The local rollup still has the data for its retention (14 days), and `ExternalMetricReader` falls back to it |
| Database down at ingest | those samples are lost (counted in `droppedCount()`); the check-in itself succeeds |
| Permanent rejection (bad request) | that batch is lost; the rest continues |
| Web node dies mid-flush | the lease expires in 60 s and another worker sends the row again |
| Outbox table missing | ingest drops silently; flush throws (run the migrations) |

## 6. Wiring it in an edition

```php
use RivetCore\Rmm\Metrics\{CompositeMetricSink, ExternalMetricReader, ExternalMetricSink, VictoriaMetricsStore};
use RivetCore\Rmm\Support\DatabaseMetricSink;

$local    = new DatabaseMetricSink($database, $clock);          // "latest" and the fallback history
$store    = new VictoriaMetricsStore($writeUrl, $urlPolicy, null, $readUrl, $token);
$external = new ExternalMetricSink($database, $clock, $store, ['max_rows' => 20000, 'max_age_s' => 86400]);

$sink   = new CompositeMetricSink([[$local, true], [$external, false]]);   // the module's metric sink
$reader = new ExternalMetricReader($store, $local);                        // series and peak from the store, latest from $local
```

`CompositeMetricSink` runs every sink; an exception from the secondary (`false`) is collected in `failures()` and never propagates; a primary
(`true`) exception is rethrown after all sinks ran. Hand the module the composite as its metric sink and the reader as the `metric_reader` option:

```php
$module = new RmmModule($database, $clock, $tenancy, $assets, $bridge, $box, $audit, $sink, $moduleState, ['metric_reader' => $reader, /* ... */]);
```

`RmmModule` looks inside the composite: it finds the `DatabaseMetricSink` (for the daily rollup, the partitions and the retention of
[STORAGE.md](STORAGE.md)) and the `ExternalMetricSink`, and **`Housekeeping::run()` flushes the buffer** (five rounds per run, so the edition's
maintenance cron is enough; its result reports `external_sent`, `external_failed`, `external_pending`). The capacity panel shows `external_store`
(`pending_batches`, `dropped_points`). Without the `metric_reader` option the pages read history from the database sink inside the composite.
Calling `$external->flush()` yourself from a separate cron entry is also fine.

Two overlapping flushes are safe (leases). The reader never throws to the page: if the store throws or has nothing, the answer comes from the
local sink. The step is one hour for ranges up to two days, one day beyond.

## 7. Writing another adapter

Implement `ExternalMetricStoreInterface` (InfluxDB v2: line protocol to `/api/v2/write` and Flux or InfluxQL `GROUP BY time()` for the read;
ClickHouse: `INSERT ... FORMAT JSONEachRow` into a `ReplacingMergeTree` ordered by `(asset, key, instance, ts)` and `GROUP BY toStartOfInterval`;
TimescaleDB: `INSERT ... ON CONFLICT (asset, key, instance, ts) DO UPDATE` and `time_bucket()`). The one design rule is **idempotence by
(series, second)**: use the store's native upsert or de-duplication key. Use a prepared statement or the store's escaping for every identifier you
build into a query, and decide what to do with a value you cannot represent (skip it).

Then run the kit against it:

```php
final class MyStoreKitTest extends \RivetCore\Testing\ExternalMetricStoreConformanceTestCase
{
    protected function store(): ExternalMetricStoreInterface { return $this->store ??= new MyStore(/* test instance */); }
    protected function settle(): void { /* wait until written points are queryable, if the store is eventually consistent */ }
    protected function breakStore(): ?callable { return fn () => /* make every call fail */ null; }   // may return a callable that repairs it
}
```

The kit checks: empty write, read back of series and peak (samples, min, max, mean, oldest first, bounded by the window), re-delivery of a batch,
unknown asset/key/instance, null versus empty instance, instances with special characters, hostile keys and instances (no leak, no injection, only
`ExternalStoreException`), `ping()`, and that a broken store makes `write()` and the reads throw `ExternalStoreException`. See
`tests/Conformance/VictoriaMetricsStoreKitTest.php` (adapter over HTTP against `tests/Support/FakeVictoriaMetricsServer.php`) and
`tests/Conformance/Reference/InMemoryExternalMetricStore.php`; `KitMutationTest` proves each rule is caught by an adapter that breaks it.
