# RMM 10,000-device soak: runbook (S9, issue #87)

This is the procedure for the validation run that gates leaving beta ([SCALING.md](SCALING.md), "Targets for the 10,000-device validation run"). The tooling is
`scripts/rmm-load/soak.sh` (driver and sampler), `scripts/rmm-load/soak-report.php` (report) and `scripts/rmm-load/soak-selftest.sh` (proves the tooling on a scratch
install). The simulator and the S1 baseline numbers are in [CAPACITY.md](CAPACITY.md) sections 8 and 9.

**Status of this document: tooling and procedure only. No 24 h / 10,000-device run has been done. Nothing here claims the targets are met.** The self-test runs minutes at
200 devices on a laptop-class scratch host; its output says "SHORT RUN, NOT A VALIDATION" and means it.

Contents: 1 Scope - 2 Hardware - 3 Topology - 4 PHP-FPM and nginx - 5 MariaDB - 6 Redis and OS - 7 Pre-flight - 8 The 24 h run and the herd phase - 9 Manual checks - 10 Watching it -
11 Abort criteria - 12 What to attach to #87 - 13 Results template - 14 What this does not prove

## 1. Scope

The run is valid for the claim "this RMM install handles 10,000 simulated devices" only if **all** of these hold: 10,000 enrolled simulated devices, 300 s interval, 24 h of
measured time, queued ingest on, the reference hardware (or its documented difference, section 2), the real edition's front controller and web stack (not the `php -S`
harness), and the report's required rows measured and not FAIL. Anything shorter or smaller is a smoke test. `soak.sh` prints and writes `SHORT RUN, NOT A VALIDATION`
whenever hours < 24 or devices < 10,000.

## 2. Reference hardware and what to record

Reference (documented, not a minimum): **8 vCPU, 16 GB RAM, NVMe, MariaDB 11, PHP-FPM, Redis.**

| Layout | Web tier | Database | Notes |
|---|---|---|---|
| One host (the reference) | the same 8 vCPU / 16 GB box | same box | PHP-FPM 64 static (about 4 GB), MariaDB buffer pool 6 to 8 GB, Redis 256 MB, the rest is OS cache and headroom. The simulator is NOT on this box |
| Split | 4 vCPU / 8 GB, PHP-FPM 48 | 8 vCPU / 16 GB, buffer pool 10 to 11 GB | the realistic production shape; record the network RTT between them |
| Smaller than the reference | whatever you have | whatever you have | run it, but write the real hardware into the results table: the result then applies to that hardware only |

Disk: NVMe with at least 100 GB free. 24 h of growth at the Recommended profile is about 2.4 GB of raw samples (73 GB/month at 10,000 devices, [CAPACITY.md](CAPACITY.md)
section 6), 12 GB/day at the legacy Defaults profile (60 s collect), plus binlog, redo log, undo and temporary files: budget 3x the raw figure and never start under 50 GB free.

Record in the hardware description (attached to #87): CPU model and vCPU count (physical or shared/burstable: a burstable cloud instance invalidates the CPU numbers), RAM,
the disk model or volume type and its measured `fio` 4k random-write sync IOPS (`fio --name=t --rw=randwrite --bs=4k --iodepth=1 --fsync=1 --size=1G --runtime=30`), kernel, distro,
MariaDB / PHP / nginx / Redis versions, whether web and database share the box, the simulator host's specification, and the network path between simulator and web tier.

## 3. Topology

```
 simulator host (separate)  --https-->  nginx -> PHP-FPM  -->  MariaDB (same or separate)        Redis (rate limits, locks)
 soak.sh, rmm-sim, php, mysql client                   ^ ingest worker loop (drain() every few seconds)
```

Run the simulator (and `soak.sh`) from a **separate host** if you can: (1) the simulator's 10,000 device goroutines, TLS and JSON encoding then do not take CPU from the system
under test; (2) loopback traffic has no latency, no packet loss and no TLS/connection-reuse behaviour of a real network, so latency numbers from the same host flatter the server;
(3) the simulator must use https against a trusted certificate (`-insecure` is refused for any non-loopback host), which exercises the real TLS termination. Use a certificate the
simulator host trusts (a public CA, or a private CA exported with `SSL_CERT_FILE=/path/ca.pem`). If you must run it on the same host, say so in the results and expect optimistic latency.

What the sampler can reach: it needs the **mysql client** to reach the database (`--db-host/--db-user/--db-pass`) and, for CPU, either a local database process (`pgrep`), a
`--db-pid/--db-pidfile` readable on the sampler host, or `--db-container` (docker stats). With a remote database on another host the CPU of the database is NOT measurable
by `soak.sh`: run `sar -u 60` / `vmstat 60` on the database host for the whole run, attach the output, and the report row stays NOT MEASURED honestly. `load1` and
`mem_available_mb` in samples.csv are those of the sampler host.

### 3.1 The single-IP trap (rate limits, WAF, fail2ban) and `-client-ip-header`

All simulated devices connect from ONE source address. Anything that limits per source IP therefore sees 10,000 devices as one abusive client:

* **nginx** `limit_req` / `limit_conn` keyed on `$binary_remote_addr`, a WAF/CDN rule, `fail2ban` jails on 4xx/5xx, `mod_security`: **disable or exempt the simulator's address for the test.**
  Verify by a 60-second smoke run and a look at `access.log` for 429/403/444.
* **The enrollment limiter** inside RivetCore (`RmmProtocol::ENROLL_RATE_MAX_ATTEMPTS = 60` per 600 s per client address, 10 failures) also counts per address. Enrolling 10,000
  devices from one address trips it after 60 enrollments (`429 rate_limited`). The device check-in path has no per-IP limit (only per-device: 40 per 60 s).
* **How the harness avoids it.** `rmm-sim -client-ip-header X-Sim-Client-Ip` sends, on every request, that header with a distinct fake address per device (`10.<100..163>.x.y`).
  Only `scripts/rmm-load/router.php` honours it, and only when started with `RMM_LOAD_TRUST_SIM_IP=1`: it then replaces the client address handed to RivetCore with the header's value.
  **No edition honours this header and none should.** A real edition's front controller takes the address from the connection (or from a trusted reverse proxy it configures).
* **What to do on a real edition test install.** Use a PRIVATE test install that is not reachable from outside, and for the duration of the run make its device API bridge take the client
  address from `X-Sim-Client-Ip` only when the connection comes from the simulator host's address (a 3-line temporary patch in the `api/v1` entry point where the `RmmRequest` is built;
  see `router.php` for the exact equivalent), pass `--client-ip-header X-Sim-Client-Ip` to `soak.sh`, and **revert the patch afterwards**; record in `env.txt`'s notes that it was applied.
  Alternatively enroll in batches of 50 per 10 minutes from the one address (too slow: 33 hours) or restart enrollment with the limiter's table pre-cleared (`DELETE FROM endpoint_agent_enroll_attempts`
  between batches of 50; works but changes the table the test is measuring). Never ship the header handling.

## 4. PHP-FPM and nginx checklist

Sizing rule ([CAPACITY.md](CAPACITY.md) section 6): busy workers = check-ins/s x service time. At 10,000 devices, 300 s: 33.3/s x 0.15 s = **5 busy**, peak (jitter, restarts, retries) x 3 = **15**.
The herd is the real sizing case: after a 30-minute outage the devices return as fast as the server admits them, so size the pool for the simulator's `--concurrency` (64) with
headroom for the admin UI and the cron, and let the shedder (503 + `Retry-After`) do the rest.

| Setting | Value | Why |
|---|---|---|
| `pm` | `static` | no spawn latency in the herd; memory is predictable. Dynamic is acceptable: `pm.start_servers=24`, `pm.min_spare_servers=16`, `pm.max_spare_servers=40` |
| `pm.max_children` | single host 64; split web tier 48 (4 vCPU) | never above the RAM budget: children x (measured RSS, typically 40 to 70 MB) <= 50 % of web-tier RAM; and <= MariaDB `max_connections` minus 60 |
| `pm.max_requests` | 2000 | recycle against leaks without churn |
| `request_terminate_timeout` | `30s` (check-in is a few hundred ms) | matches the simulator's 30 s `-timeout`; a stuck request must not hold a worker for minutes |
| `pm.status_path` | `/fpm-status` (allow only localhost) | `curl -s 'http://127.0.0.1/fpm-status?full'` shows active/idle/max-active/slow requests; `listen queue` > 0 means the pool is too small |
| `request_slowlog_timeout` | `1s` with `slowlog = /var/log/php-fpm/slow.log` | any check-in over 1 s gets a stack trace |
| `opcache.enable` | `1` | |
| `opcache.memory_consumption` | `256` | RivetCore + edition are well under; check `opcache_get_status()` for 0 free-memory restarts |
| `opcache.interned_strings_buffer` | `32` | |
| `opcache.max_accelerated_files` | `20000` | |
| `opcache.validate_timestamps` | `0` | no stat calls per request; remember to reload FPM on deploy |
| `opcache.jit` | `off` for the validation (default), or `tracing` with `opcache.jit_buffer_size=64M` as a recorded variant | do not mix: record which |
| `realpath_cache_size` / `realpath_cache_ttl` | `4096K` / `600` | |
| `memory_limit` | `256M` (check-in bodies are capped at 1 MiB) | |
| nginx `worker_processes` | `auto`; `worker_connections 4096` | |
| nginx `fastcgi_buffers` / `fastcgi_buffer_size` | `16 16k` / `16k`; `fastcgi_read_timeout 35s` | responses are small JSON; a read timeout above FPM's terminate timeout |
| nginx upstream | `upstream fpm { server unix:/run/php/fpm.sock; keepalive 32; }` with `fastcgi_keep_conn on;` | keepalive to FPM; a unix socket needs `net.core.somaxconn` >= `listen.backlog` |
| nginx access log | `access_log ... buffer=64k flush=5s;` or log only non-2xx during the run | 33 req/s x 24 h = 2.9 M lines otherwise |
| TLS | `ssl_session_cache shared:SSL:20m;` `keepalive_timeout 75s;` | the simulator reuses connections like the real agent |
| per-IP limits | **off / exempt the simulator** (section 3.1) | |

Deploy hygiene: `systemctl reload php-fpm` after any edition code change (validate_timestamps=0), confirm the deployed git revision in `env.txt` matches the one you mean to validate.

## 5. MariaDB checklist

| Setting | Value | Why / what it means for the claim |
|---|---|---|
| `innodb_buffer_pool_size` | 60 to 70 % of the DB host's RAM (single host: 6 to 8 GB) | hot set: devices, checkins indexes, metric tail |
| `innodb_buffer_pool_instances` | 4 (8 with >= 16 GB) | |
| `innodb_log_file_size` | 2 GB (the whole redo log; on 11.x it is one file) | a redo log that is too small forces checkpoint stalls that look like latency spikes every few minutes |
| `innodb_flush_log_at_trx_commit` | **1** for the validation run | fsync on every commit: ACID durable. This is what the "no data loss" part of the claim assumes |
| ... the value `2` | allowed ONLY as a recorded variant | flushes once a second: an OS crash or power loss can lose up to ~1 s of acknowledged check-ins. Faster, and a weaker durability claim; write it in the results table. A MariaDB crash alone does not lose data with `2` |
| `sync_binlog` | 1 if the binlog is on (replication / PITR), 0 if no binlog | binlog fsync cost belongs in the measurement if production has it |
| `innodb_flush_method` | `O_DIRECT` | no double buffering with the page cache |
| `innodb_io_capacity` / `innodb_io_capacity_max` | 2000 / 4000 on NVMe (match the `fio` result, section 2) | background flushing keeps up with ~700 rows/s |
| `innodb_flush_neighbors` | 0 on NVMe | |
| `max_connections` | `pm.max_children` + 100 (cron/worker/admin/sampler), e.g. 200 | `Too many connections` shows up as 500/503 in the simulator: size it above the pool |
| `table_open_cache` | 4000 | |
| `thread_handling` / `thread_pool_size` | `one-thread-per-connection` is fine at <= 200 connections; try `pool-of-threads` with `thread_pool_size` = vCPU only as a recorded variant | |
| `slow_query_log` | `1`, `long_query_time = 1`, `log_slow_verbosity = query_plan`, `slow_query_log_file` on a disk with space | **the pruning row only means something with `long_query_time <= 1`** (`SET GLOBAL long_query_time=1`; the report reads it and refuses to PASS otherwise) |
| `innodb_lock_wait_timeout` | default 50 | the target is zero timeouts |
| `performance_schema` | off by default on MariaDB. `ON` costs a few percent CPU and memory (needs a restart to enable) but gives `db_max_stmt_ms_seen` and digest tables. Choose one for the whole run and record it; if on, `TRUNCATE performance_schema.events_statements_summary_by_digest` right before the start so the maximum is the run's | |
| `innodb_stats_persistent` | default (on) | table sizes in the report come from these statistics; run `ANALYZE TABLE` on the hot tables before and after the run for accurate sizes |
| binlog | record whether it is on, the format (`ROW`/`MIXED`) and `expire_logs_days` / `binlog_expire_logs_seconds` | 24 h of row-format binlog is a large write and disk consumer |
| sampler account | `CREATE USER soak_ro ...; GRANT SELECT ON <db>.* TO soak_ro; GRANT SELECT ON performance_schema.* TO soak_ro;` (`SHOW GLOBAL STATUS/VARIABLES` and `information_schema` need nothing more) | the sampler only reads |

Capture before the run: `SHOW GLOBAL VARIABLES` (secrets removed) into the results; `soak.sh` also writes the key variables to `env.txt` and the full `SHOW GLOBAL STATUS`
to `status_before.txt` / `status_after.txt`.

## 6. Redis and the OS

Redis ([CAPACITY.md](CAPACITY.md) section 7): `maxmemory 512mb`, `maxmemory-policy allkeys-lru` (it is a cache and a lock store, not a database); `appendonly no` (rate limits and locks are disposable);
`tcp-keepalive 60`; `save ""`. Check `redis-cli --latency` stays under 1 ms during the run. It backs the global check-in counter and the maintenance lock; without it the run still works but is not the recommended shape.

| OS setting | Value | Applies to |
|---|---|---|
| `LimitNOFILE` (systemd) / `ulimit -n` | 65536 for nginx, php-fpm, mariadb; **simulator host: 65536** (10,000 sockets worst case in the herd) | all |
| `net.core.somaxconn` | 4096 (and the matching `listen` backlog in nginx and `listen.backlog` in FPM) | web |
| `net.ipv4.ip_local_port_range` | `1024 65000` | simulator host (and nginx -> FPM over TCP) |
| `net.ipv4.tcp_tw_reuse` | 1 | simulator host |
| `net.ipv4.tcp_max_syn_backlog` | 8192 | web |
| `vm.swappiness` | 1 | db host |
| transparent huge pages | `madvise` or `never` | db host |
| time | `chrony` running and `chronyc tracking` within 50 ms on **every** host | the sampler compares its clock with the database's (`herd_pending_devices`) and the report orders events by wall-clock time |
| I/O scheduler | `none` / `mq-deadline` on NVMe | db host |
| cron / backups | **paused** (section 7) | all |

## 7. Pre-flight checklist

Do these in order, tick them in the issue.

- [ ] Hardware and software versions recorded (section 2). Time sync verified on all hosts.
- [ ] Deployed revision of RivetCore and the edition noted. A clean install or a database restored from a known snapshot (so the starting table sizes are known). `ANALYZE TABLE` on the hot tables.
- [ ] RMM module **on** (master switch and the edition's switch). `max_devices` set to **at least 10,010** (new installs suggest 500: enrollment refuses beyond it with `403 device_limit`).
- [ ] Settings of the validation profile: `check_in_interval_s = 300`, `collect_interval_s = 300`, `retention_days = 7`, **`ingest_mode = queued`**; features monitoring, metrics, jobs, updates. Record the capacity panel JSON (below).
- [ ] The ingest **drain loop is running**: `ingestQueue()->drain()` every 2 to 5 seconds (a systemd service looping `drain(); sleep 2`, or the edition's job runner with `registerHandlers`). Verify: enroll one device, check in once, and `SELECT status, COUNT(*) FROM integration_jobs WHERE job_type='rmm.ingest' GROUP BY status` drains to `completed`. If nothing drains, the backlog row will (correctly) FAIL after minutes.
- [ ] Shed thresholds: defaults (`shed_backlog_l1/l2/l3 = 500/2000/8000`, `shed_db_ms = 100/300/1000`), `shed_rate_per_min = 0` or 3x the steady rate (6,000), `max_checkins_per_min` = 0 or 4 x steady (8,000). The steady-state target is **shed level 0**; the herd phase is where shedding is expected.
- [ ] Capacity panel: save `RmmModule::capacity()->build()` as JSON (`panel_before.json`); no warnings other than the ones you accept and write down.
- [ ] Retention/pruning: with `retention_days = 7` nothing is old enough to prune in a 24 h run, so the Pruning row then only shows that no statement (including the maintenance cron's) exceeded 1 s. To exercise real pruning, start from a database that already holds 7+ days of history (the simulator cannot create it) or set `retention_days = 1`. Say which in the results.
- [ ] Disk: free space >= 3 x the 24 h growth estimate (section 2). `df -h` for the data dir, binlog dir, and the log directory.
- [ ] Backups and any heavy cron **paused** for the duration (the nightly backup, `OPTIMIZE`, log rotation of the slow log mid-run is fine if the counters are not reset). Monitoring agents on the hosts left running (they are part of reality).
- [ ] `long_query_time = 1`, slow log on and its file noted (section 5). If `performance_schema` is on, digest table truncated.
- [ ] Per-IP limits off / simulator exempt (section 3.1); the temporary header patch applied to the private test install if used.
- [ ] Two enrollment tokens (a token is capped at 5,000 uses): `max_uses` = 5,000 each, valid for > 26 h. Pass them comma separated.
- [ ] Smoke test: `soak.sh --minutes 5 --devices 200 --interval 10s ...` against the same URL; the report must show real numbers in every sampled column. Then delete those devices (or restore the snapshot) so the 10,000 starts from a known count.
- [ ] Capture `SHOW GLOBAL STATUS` (`soak.sh` does it) and `php-fpm` status baseline.

## 8. Running the 24 h soak

### 8.1 Command

On the simulator host, inside `tmux` (or a systemd transient unit, which survives an SSH disconnect and logs to the journal):

```bash
export RMM_SIM_TOKEN='rvte1.AAAA...,rvte1.BBBB...'      # two tokens for 10,000 devices; never put them on the command line
export RMM_SOAK_DB_PASS='...'                             # a read-only account
tmux new -s soak
scripts/rmm-load/soak.sh \
    --url https://rmm.example.org/api/v1/ \
    --devices 10000 --hours 24 --interval 300s --concurrency 64 \
    --db-host db1.example.org --db-user soak_ro --db-name rivetit \
    --sample-every 60 --herd-after-minutes 720 --outage-minutes 30 \
    --dashboard-url 'https://rmm.example.org/admin/rmm/dashboard' --dashboard-cookie 'PHPSESSID=...' \
    --out /var/soak/run-$(date -u +%Y%m%dT%H%M%SZ)
```

or

```bash
systemd-run --unit=rmm-soak --collect --property=LimitNOFILE=65536 \
    --setenv=RMM_SIM_TOKEN --setenv=RMM_SOAK_DB_PASS \
    /opt/rivet-core/scripts/rmm-load/soak.sh --url ... (same arguments)
journalctl -fu rmm-soak
```

Database on the same host as the sampler: add `--db-container NAME` (docker) or nothing (pgrep finds `mariadbd`). Database on another host: no CPU column (section 3).
Pass `--db-cores N` when the container is limited to fewer cores than the host has.

What the script does: validates everything, prints the plan, enrolls the 10,000 devices (a few minutes; it is not part of the 24 h), starts the 24 h measurement, samples the server every 60 s into
`samples.csv`, handles Ctrl-C (foreground terminal, tmux) and SIGTERM (`kill PID`, `systemctl stop`, `systemd-run`'s stop): it stops the simulator (which prints its summary), waits for the queue to drain, and builds the report anyway. A SIGINT sent with `kill -INT` to a script that was started with `&` is ignored by bash itself, use `kill PID`, and writes `summary.md` / `summary.json`. Exit code: 0 PASS, 1 FAIL, 3 INCOMPLETE (a required row not measured).
A run killed with SIGKILL has no report; run `php scripts/rmm-load/soak-report.php DIR` afterwards on whatever is in the directory.

### 8.2 The herd phase

The thundering-herd target ([SCALING.md](SCALING.md)): after a **30-minute outage** all 10,000 devices are caught up within 15 minutes, load shedding visible, no data loss.
Inject it at the **12th hour** (`--herd-after-minutes 720 --outage-minutes 30`), when the system is in steady state and there is still half a day of observation after it. `soak.sh` **never touches
the server**: it records the window in `herd_plan.txt`, marks it in the `event` column of `samples.csv` (`outage`, then `recovery` for 15 minutes), and prints (and rings the terminal bell) at the exact moments:

```
>>> OPERATOR: START THE OUTAGE NOW (stop the web tier or switch the RMM module off) for 30 minutes. <<<
>>> OPERATOR: END THE OUTAGE NOW (restart the web tier / switch the module back on). Recovery is measured from this moment. <<<
```

Pick one way to cause the outage, do it within a minute of the message, and write down which:

1. **Stop the web tier** (the realistic one: connection refused or 502 for every device): `systemctl stop nginx` (or stop php-fpm) ... `systemctl start nginx`. Keep MariaDB and the drain loop running.
2. **Switch the module off** (what the gate answers: `503 module_disabled`, `Retry-After: 3600`, devices wait at least the simulator's disabled floor of 15 minutes, scaled by `-retry-scale`): turn the edition's RMM kill switch off and back on, or `RmmSettings::disable()` / `enable()`. Slower to recover by design.

During the outage the simulator keeps each device's in-flight check-in (same `seq`) and retries with `max(Retry-After, full-jitter backoff)`: transport errors or 5xx during the window are **expected and excluded**
from the errors row, as is the 15 minutes after it. Recovery is measured by the sampler's `herd_pending_devices` column (devices whose last check-in is older than the outage end); the report's herd row passes when it reaches 0
within 15 minutes of the end. `shed_level` and `queue_pending` in the recovery samples (every 10 s during recovery) show whether shedding engaged; the report prints the maximum level.
Level-1 shedding **drops buffered samples older than 15 minutes by design** ([CAPACITY.md](CAPACITY.md) section 3): after a 30-minute outage some buffered metric samples are intentionally not stored. "No data loss" can therefore only
mean no lost check-ins (contiguous `seq` per device, section 9) and no lost samples within the shed policy; state this plainly in the results.

If you cannot be at the console at hour 12, run the herd as a separate, shorter run (for example 10,000 devices, `--minutes 90 --herd-after-minutes 20 --outage-minutes 30`) and say so.

## 9. Manual checks (not automated, listed MANUAL in every report)

**Dashboard latency under load** (target: fleet dashboard and device list under 2 s with 10,000 devices; a device page's graphs under 1 s). Do it at hour 1, hour 12 (before the outage), and after the run:

```bash
COOKIE='PHPSESSID=...'           # an admin session
for i in 1 2 3 4 5; do
  curl -sS -o /dev/null -b "$COOKIE" -w '%{http_code} %{time_total}s\n' 'https://rmm.example.org/admin/rmm/dashboard'
  curl -sS -o /dev/null -b "$COOKIE" -w '%{http_code} %{time_total}s\n' 'https://rmm.example.org/admin/rmm/devices?page=1'
  curl -sS -o /dev/null -b "$COOKIE" -w '%{http_code} %{time_total}s\n' 'https://rmm.example.org/admin/rmm/device/123/graphs?range=24h'
done
```

(The URLs are the edition's; use the real ones. `time_total` includes TLS and transfer; first request after idle is the worst, report the median and the max.) `soak.sh --dashboard-url URL ... --graph-url URL ... --dashboard-cookie 'name=value'` runs the same
three GETs every 30 minutes and once at the end into `dashboard.csv` and the report shows the median and max, still as MANUAL. The cookie is passed to curl through a config pipe and is never written down.

**Disabled module issues zero queries**, on an otherwise idle server (simulator stopped, cron and drain loop stopped, module switched off):

```bash
mysql -e "SHOW GLOBAL STATUS LIKE 'Questions'"                      # Q0
for i in $(seq 1 200); do curl -s -o /dev/null -X POST -H 'Authorization: Bearer x' https://rmm.example.org/api/v1/agent_checkin; done
mysql -e "SHOW GLOBAL STATUS LIKE 'Questions'"                      # Q1
```

Q1 - Q0 must be **1** (the second `SHOW` itself). Any more is a query the gate did not prevent. Switch the module back on afterwards.

**Replication or flush lag**: with a replica, `SHOW REPLICA STATUS\G` (`Seconds_Behind_Master`) every minute for the run (`while sleep 60; do mysql -e 'SHOW REPLICA STATUS\G' | grep -E 'Seconds_Behind|Running'; done >> replica.log`). Without one, the flush side:
`SHOW ENGINE INNODB STATUS\G` (`Log sequence number` minus `Last checkpoint at` stays far below the redo size; "Pending flushes" near 0) before, during, and after.

**No lost check-ins after the outage** (contiguous `seq` per device within the retention window):

```sql
SELECT COUNT(*) AS devices_with_gaps FROM (
  SELECT device_id FROM endpoint_agent_checkins GROUP BY device_id HAVING MAX(seq) - MIN(seq) + 1 <> COUNT(*)) g;
SELECT COUNT(*) AS seq_mismatch FROM endpoint_agent_devices d
  WHERE d.last_seq <> COALESCE((SELECT MAX(seq) FROM endpoint_agent_checkins c WHERE c.device_id = d.device_id), 0);
SELECT COUNT(*) AS duplicates FROM (SELECT device_id, seq FROM endpoint_agent_checkins GROUP BY device_id, seq HAVING COUNT(*) > 1) x;
```

All three must be 0. (Expensive over 2.9 M rows: run it once at the end, off-peak.) The same gap query on the edition's metric table is edition-specific.

## 10. What to watch live

```bash
tail -f OUT/sim.log                                          # per 10 s: req/s, p50/p95/p99, in flight, cumulative status counts
watch -n 10 "tail -n 3 OUT/samples.csv | column -s, -t"     # the server-side sample
mysqladmin -uroot -p -i 5 extended-status | grep -E 'Threads_running|Questions|Slow_queries|Innodb_row_lock_waits|Innodb_log_waits'
mysql -e 'SHOW FULL PROCESSLIST' | grep -vc Sleep            # statements running right now
curl -s 'http://127.0.0.1/fpm-status?full' | head -20        # on the web host: active / idle / listen queue / max children reached
ss -s ; ss -tn state established '( sport = :443 )' | wc -l   # connections
vmstat 5 ; iostat -x 5 ; sar -u 5                              # CPU, disk await, run queue on web and db hosts
docker stats NAME                                              # containerised database
redis-cli --latency ; redis-cli info memory | grep used_memory_human
tail -f /var/log/nginx/error.log /var/log/php-fpm/slow.log     # worker exhaustion, upstream timeouts, slow requests
mysql -e "SELECT status, COUNT(*) FROM integration_jobs WHERE job_type='rmm.ingest' GROUP BY status"
```

Healthy at 10,000 devices: about 33 req/s, in flight in single digits, `queue_pending` low hundreds at most, `queue_oldest_pending_age_s` under a few seconds, `shed_level` 0, `listen queue` 0, CPU well below 60 %.

## 11. Abort criteria

Stop the run (Ctrl-C in the soak terminal, or `kill PID`: the report is still produced), keep the directory, and write down why, when any of these holds for 10 consecutive minutes outside the herd window:

* more than 1 % of requests are non-200, or any `5xx`/transport error burst (the errors row will FAIL anyway; do not burn 20 more hours on a known failure unless you are collecting data on purpose);
* `queue_oldest_pending_age_s` above 300 and still growing (the worker cannot keep up; nothing will recover);
* `shed_level` above 0 outside the herd window (the target is 0), or the shed level oscillating;
* p99 above 1 s sustained, or the web tier `max children reached` growing;
* database CPU above 90 %, `Too many connections`, or disk free under 10 GB / 10 %, the redo log or binlog filling a partition;
* the simulator host itself is the bottleneck (its CPU saturated, `inflight` stuck at the concurrency limit while the server is idle): the run measures the wrong thing; fix the simulator host and restart;
* the web or database host reboots, or the network path drops (note the time; this is an infrastructure failure, not a verdict).

## 12. What to attach to issue #87

The whole out directory (it contains no secrets by construction, but look before attaching), and:

- [ ] `summary.md` and `summary.json`
- [ ] `samples.csv` (24 h x 60 s = about 1,440 rows) and `dashboard.csv` if collected
- [ ] `sim.jsonl` (the simulator's JSON summary), `sim.log` (progress lines), `env.txt`, `run.kv`, `herd_plan.txt`
- [ ] `status_before.txt` / `status_after.txt` (`SHOW GLOBAL STATUS` at both ends), the `SHOW GLOBAL VARIABLES` output
- [ ] the capacity panel JSON before and after (`panel_before.json`, `panel_after.json`)
- [ ] the slow log excerpt for the run window (`mariadb-dumpslow` summary, or `awk` over the time range), even if empty
- [ ] `php-fpm` status page output before, mid-run (hour 12) and after, plus `php -i | grep -E 'opcache|memory_limit'`
- [ ] the configs with secrets removed: nginx vhost + `snippets`, `php-fpm` pool, `php.ini` fragment, `my.cnf`/`conf.d`, `redis.conf`, `sysctl` settings
- [ ] the hardware description (section 2), the deployed git revisions, and which outage method was used (section 8.2)
- [ ] the results table below, filled in, and the manual-check results (section 9)

## 13. Results template (paste into [CAPACITY.md](CAPACITY.md) section 8)

```markdown
**S9 validation run, <date>, <revision>.** Hardware: <vCPU / RAM / disk / layout>. Software: MariaDB <v> (`innodb_flush_log_at_trx_commit=<1|2>`, buffer pool <n> GB), PHP <v> FPM `static` <n>, nginx <v>, Redis <v>.
Simulator on <separate host / same host>, <https / loopback>. Queued ingest on, drain loop every <n> s. Attached to #87: <link>.

| Target | Requirement | Result | Measured |
|---|---|---|---|
| Sustained check-ins | 10,000 devices x 300 s, 24 h, within 5 % | PASS/FAIL | <accepted/h vs expected> |
| Errors outside the herd window | zero 5xx / transport | PASS/FAIL | <histogram> |
| Latency (client-side) | p95 < 250 ms, p99 < 1 s | PASS/FAIL | <p95> / <p99> ms |
| Database CPU | average < 60 % | PASS/FAIL | avg <x> %, max <y> % |
| Lock-wait timeouts | 0 | PASS/FAIL | <n> |
| Backlog | oldest pending < 120 s, drains to 0 | PASS/FAIL | max <n> s, left <n> |
| Pruning | no statement over 1 s (long_query_time=1) | PASS/FAIL | <n> slow statements |
| Shed level outside the herd | 0 | PASS/FAIL | max <n> |
| Thundering herd | 30 min outage, caught up < 15 min | PASS/FAIL | recovered after <n> s, max shed level <n> |
| Dashboard / device list | < 2 s | PASS/FAIL (manual) | median <n> s, max <n> s |
| Graphs | < 1 s | PASS/FAIL (manual) | median <n> s, max <n> s |
| Disabled module | 0 queries | PASS/FAIL (manual) | delta <n> |
| Table growth | information | - | <MB/h per table>, <GB/30 days> |
```

## 14. What this does not prove

* **Not the real fleet.** Simulated devices send one realistic payload shape, with a fixed set of checks and metrics, from one machine with one network path. Real agents differ in payload size, inventory churn, clock skew, retries from flaky networks, and arrival patterns (offices that switch on at 08:00, laptops that wake together).
* **Client-side latency.** `rmm-sim` times each request end to end, including network and its own scheduling, and it counts failures. It is not server processing time, and with the simulator on the same host it is optimistic about the network.
* **One configuration.** One tuned MariaDB on one hardware class with one pool setting. It does not prove that a differently tuned or smaller install meets the targets, a replicated/clustered database, ProxySQL, or several web nodes.
* **Short-horizon storage.** 24 h cannot show 30-day growth, fragmentation, long-running pruning on a table that really holds 7 days of data, backup duration, or a restore. Table-size extrapolations are linear and information-schema-estimated.
* **Not the technician side under real use.** Dashboards are timed by hand with a few GETs, not under concurrent users, and the edition's own page weight is not controlled by this tooling.
* **Not the full alerting/jobs/scripts load.** Check-ins dominate. Concurrent script runs, bulk jobs, update rollouts, mesh sessions and the alert/ticket pipeline downstream are not exercised at 10,000 devices.
* **Not durability.** With `innodb_flush_log_at_trx_commit=2` the claim is weaker; neither setting is tested by pulling power. "No data loss" here means contiguous `seq` and no unexplained gaps, within the shed policy that drops old buffered samples.
* **Not a security or abuse test.** Per-IP limits were deliberately disabled for the run (section 3.1); the run says nothing about behaviour under an attack from one address.
* **The sampler is approximate.** One-minute resolution; CPU from `/proc` or `docker stats` deltas; `Questions` includes the sampler's own statements; the pruning row sees only what `Slow_queries` counts; lock timeouts depend on the server exposing the counter (MariaDB: `INNODB_METRICS.lock_timeouts`, enabled by default; if disabled the row says NOT MEASURED).

See also: [SCALING.md](SCALING.md) (plan and targets), [CAPACITY.md](CAPACITY.md) (limits, shedding, queued ingest, measurements, simulator).
