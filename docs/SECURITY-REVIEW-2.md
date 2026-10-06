# Security review 2 and threat model (deliverable 41)

Second review pass of rivet-core, read against the code in `src/` at commit `cef1495` (0.21.0, branch
`quality-gates-1.0`). It builds on the 2026-10 review whose fixes shipped in 0.18.1 (see `CHANGELOG.md`); nothing fixed
there is re-reported. Every LOW-or-higher finding has a test under `tests/Security/` that asserts the CURRENT
behaviour; when a finding is fixed, flip the assertion named in that test's docblock. No file under `src/` was changed
by this review.

Reviewer note: severities are for the library in its intended deployment (RivetIT / RivetMSP behind an authenticated
admin UI). "Edition" means the application that embeds the library.

## 1. Scope, assets, actors, trust boundaries

Assets: the Redis password and TLS material; webhook signing secrets and outgoing auth tokens; URLs that are secrets
(Slack, Discord, Telegram bot token, Zapier); the audit trail (integrity and completeness); the MCP identity-to-agent
mapping; the job queue and what handlers do with payloads; the KB (uploaded DOCX/PDF become article HTML); the compliance
evidence shown to portal users.

Actors:

| Actor | Capability assumed |
|---|---|
| Anonymous network user | Can reach the public site and the MCP endpoint (401 without a token). |
| Portal user | Reads the one shared compliance report. |
| Authenticated MCP caller | Holds a valid IdP token for the MCP audience and `mcp:read`; may or may not be linked to an agent. |
| Technician / KB editor | Uploads DOCX/PDF to the KB importer. |
| Administrator | Configures Redis, webhooks (URL, auth, templates, allowed networks), cron manager, MCP issuer. Trusted, but the library still limits what a mistake or a hijacked admin session can reach (SSRF policy, allow-listed cron scripts). |
| Hostile webhook receiver / hostile IdP | Controls response bodies, redirects, timing, discovery documents. |
| Hostile upload | Crafted DOCX/PDF bytes. |
| Local user on the same host / other tenant on the same DB or Redis server | Can create files in `/tmp`, call `GET_LOCK`, reach a shared Redis. |

Trust boundaries: (1) browser/HTTP to edition; (2) edition to library (callers pass trusted ids, the library does not
authenticate); (3) library to Redis (TCP, optionally TLS, shared server possible); (4) library to MySQL/MariaDB
(prepared statements, shared server possible); (5) library to the network (webhooks, MCP diagnostics) and to subprocesses
(poppler, cron scripts); (6) uploaded bytes to parsers; (7) stored data back out to admins/portal/receivers.

## 2. Threat model by surface (STRIDE)

S spoofing, T tampering, R repudiation, I information disclosure, D denial of service, E elevation of privilege.

### 2.1 Redis (`src/Redis`)

| STRIDE | Threat | Existing mitigation | Residual | Ref |
|---|---|---|---|---|
| S/E | Another client on a shared Redis forges or deletes a lock or counter | 128-bit random lock token; release and extend are compare-and-delete/expire Lua; password/ACL user and TLS supported | Anyone with Redis access can delete `lock:` keys or write `rl:` keys; Redis must be private and authenticated | - |
| T | Key collisions between environments (prod/beta sharing a Redis with the same prefix) | Prefix is a constructor argument | Cross-environment lock sharing is possible if prefixes match; not detectable by the library | - |
| I | Password leaks into logs/errors | `#[\SensitiveParameter]`, `__debugInfo`, `RedisAdmin::test()` returns canned text and redacts, `validate()` rejects CR/LF/NUL | Password is a public property: `json_encode`, `serialize`, `var_export`, `(array)` expose it | RC-SR2-15 |
| I/T | MITM on Redis traffic | `tls` scheme, `verify_peer` and `verify_peer_name` default true, CA/client cert supported | `verifyPeer=false` (offered in the admin test message) disables the host-name check as well | RC-SR2-21 |
| D | Redis down: locks, rate limits | Fail-open by design, documented in `LockManager`/`RateLimiter`; readiness reports Redis but never fails on it | A cron job run twice while Redis is down or flapping; MCP rate limit absent while Redis is down | RC-SR2-02 |
| D | Lock lost while a job still runs (TTL expiry, eviction, over-broad clear) | `extend()`; CronGuard TTL 900 s | Second runner acquires the lock; rate-limit counter without a TTL never expires | RC-SR2-23 |
| E | `RedisAdmin::clear` / `setMemory` misuse | `clear()` accepts only group names from the constructor allow-list; `setMemory` validates range and policy against a fixed list; no raw command pass-through | Patterns are globs supplied by the edition: a prefix containing `*`/`[` widens the clear. `CONFIG SET` needs the Redis user to hold it (fails cleanly otherwise) | - |
| I | Admin-supplied host/port used as a scanner | `validate()` restricts host syntax and port range | `test()` is a connect oracle (live/closed/auth) with no destination policy | RC-SR2-21 |

Fail-open and duplicate cron execution: `CronGuard::acquire()` returns a held `Lock` with `degraded() === true` when Redis
is unreachable or errors, so two overlapping invocations both run. That equals the behaviour before locks existed, is
stated in the class comment, and `Lock::degraded()` lets a caller that cannot tolerate it refuse to run. Verdict: accepted
design, recorded as INFO (RC-SR2-02) with a test; recommended follow-up is an opt-in fail-closed argument on
`CronGuard::acquire()` for non-idempotent jobs.

### 2.2 Webhooks (`src/Webhooks`)

| STRIDE | Threat | Existing mitigation | Residual | Ref |
|---|---|---|---|---|
| S/T | Receiver cannot tell real from forged or replayed deliveries | HMAC-SHA256 body signature (legacy) and `X-Rivet-Signature-V2` (`t=<ts>,v1=hmac(ts.body)`); the timestamp is per attempt; receiver snippets in `Destinations` use `hash_equals` and a 300 s tolerance | Replay window is enforced by the receiver only; body stays byte-identical across retries by design | - |
| T | Header/CRLF injection through admin-set headers | `Authentication` and `mergeExtraHeaders` validate RFC 7230 tokens, reject control characters, framing/hop-by-hop headers and anything that looks like a signature/timestamp header; `UrlPolicy` rejects whitespace and control characters in URLs | None found | - |
| I/E | SSRF to internal services | `UrlPolicy`: http/https only, no userinfo, rejects loopback/private/link-local/metadata/CGNAT/reserved/6to4/Teredo/NAT64-local/multicast; every resolved address must pass; `allowedNetworks` can only admit private space (`NetworkList`) and never loopback/link-local/multicast; odd numeric host spellings (`0x7f.1`, `2130706433`, `127.1`) were tested and are refused | No port allow-list; a few IANA special ranges are missing (INFO) | RC-SR2-19 |
| I/E | DNS rebinding between check and connect | The policy resolves once and the dispatcher pins `CURLOPT_RESOLVE` to those addresses, uses the vetted host spelling (trailing dot cannot bypass), and disables any proxy | None found (guard test) | - |
| I/E | Redirect to a private address | `CURLOPT_FOLLOWLOCATION=false`, `MAXREDIRS=0`, `PROTOCOLS` and `REDIR_PROTOCOLS` limited to http/https; a 3xx is just a failed attempt (guard test against a live local server) | None found | - |
| I | Secrets in the delivery log | `webhook_deliveries` stores the request body, status, duration and at most 1000 bytes of the response or curl's generic error, never the URL, headers or signing secret (guard test with a token in path and query) | A receiver that echoes secrets back lands them in the snippet; response text is untrusted, edition must escape it when displaying | - |
| I | Secrets in payloads | Chat/form/template formats redact secret-looking keys (substring match); `json` is the raw event | The raw `json` body and `request_payload_json` contain whatever the event carried; the audit fan-out passes unredacted metadata | RC-SR2-09 |
| T | Template engine abuse | `PayloadTemplate` is substitution only: strict path regex, five fixed filters, limits (8 KB template, 64 KB output, 100 placeholders), output escaped for the chosen encoding, `data` redacted before rendering | `text` encoding inserts values as-is by design (chat markup injection is the receiver's concern) | - |
| D | Hostile receiver | 10 s total timeout, 5 s connect timeout | Whole response buffered in memory with no size cap; invalid UTF-8 snippet silently drops the log row | RC-SR2-04, RC-SR2-05 |
| T | Platform URL validation | `Destination::urlMatches` patterns | `$` accepts a trailing newline | RC-SR2-06 |

### 2.3 MCP (`src/Mcp`)

| STRIDE | Threat | Existing mitigation | Residual | Ref |
|---|---|---|---|---|
| S | Token for another audience or without the scope; over-long tokens | `TokenClaimsGuard`: audience must be exactly the MCP audience, scope present, lifetime shape (iat not in the future, exp after iat, at most 1 h), subject non-empty and at most 255 | The guard never compares `exp`/`nbf` to now and ignores `iss`; the edition's JWT verifier must do both (documented) | RC-SR2-20 |
| S/E | Identity linking | Linking is an explicit admin action in a transaction with `SELECT ... FOR UPDATE` on the pending row; `identityTaken` and `linked` checks | Pending table collation is case-insensitive and PAD SPACE (known, carried over); the cap of 200 pending rows can be filled by valid-but-unlinked users | RC-SR2-13, RC-SR2-20 |
| D | Abuse by a valid caller | `ToolPipeline` rate limit per user before the permission check | Fails open if Redis is down; every limited request still writes an audit row | RC-SR2-11 |
| R/T | Audit evasion | Audit written for ok, denied, not found, rate-limited and error outcomes | A nested argument over ~64 KB makes the audit INSERT fail and the read succeeds unaudited | RC-SR2-10 |
| I | Errors leak internals | Envelope returns generic messages; details only go to the logger | Exception text goes to `error_log` unsanitised (log forging) | RC-SR2-12 |
| I/E | Diagnostics page SSRF | `allow_redirects=false`, 5 s timeout, `issuerValid` requires https | IdP-supplied `jwks_uri` fetched with no destination policy; error text echoed | RC-SR2-14 |

### 2.4 Audit (`src/Audit`, `src/Retention`)

| STRIDE | Threat | Existing mitigation | Residual | Ref |
|---|---|---|---|---|
| T | SQL injection into audit rows | Prepared statements everywhere; the only interpolated SQL is clamped integers (`AuditReader`) and a fixed table name (`RetentionService`) | None found | - |
| T | Over-long fields | Every column clamped (0.18.1) | `metadata_json` is not bounded | RC-SR2-10 |
| I | Secrets in metadata | Exact-key redaction for 14 key names | Variants and depth over 8 are stored in clear; fan-out hook sees the original | RC-SR2-07, -08, -09 |
| T/R | Log forging via summary/user agent | Stored as data, JSON-encoded; display and CSV escaping belong to the edition | Newlines are kept in `summary`/`user_agent`; `ErrorLogLogger` does not strip them | RC-SR2-12 |
| R | Early deletion of evidence | `RetentionService` deletes only three tables with fixed SQL, never pending/running jobs; compliance profile raises every horizon to the preset floor; snapshots are never pruned | None found | - |
| D | Large tables | Per-page cap 200, export cap 50 000, batched DELETE | No `created_at`-leading index; OFFSET paging; `%LIKE%` search over TEXT | RC-SR2-03 |

### 2.5 Job queue and cron (`src/Jobs`, `src/Cron`)

| STRIDE | Threat | Existing mitigation | Residual | Ref |
|---|---|---|---|---|
| T/E | Hostile job payload or type | Handlers are an in-process registry: an unknown type is dead-lettered, never evaluated; payload is `json_decode`d to an array | Payloads and `job_type` are trusted DB content; anyone with DB write can enqueue only types the edition registered | - |
| T | Claim races, double completion | Conditional `UPDATE ... WHERE status='pending'`; `markCompleted`/`markFailed` fenced on status and attempt; heartbeat; stale jobs dead-lettered when attempts are exhausted | Handlers can run twice after a reclaim (documented: idempotency required) | - |
| I | Error text persisted | 2000-character clamp | Exception text stored verbatim | RC-SR2-16 |
| E | `JobRunner` command execution | No shell input from requests: script must `realpath` under `<root>/cron` or `<root>/scripts`; PHP binary must match `/usr/bin/php*` or `PHP_BINARY`; arguments match `--name[=[A-Za-z0-9_.,-]+]`; every piece goes through `escapeshellarg`; state dir must be a real, private, user-owned directory; log is created exclusively after unlinking; `start()` refuses while the job runs | Option names unrestricted; keys collide after sanitising; shared-`/tmp` squatting makes `start()` refuse (fail closed); no rlimits on the child | RC-SR2-22 |
| D | Migration lock | `GET_LOCK` serialises runs | Lock name is server-wide | RC-SR2-01 |

### 2.6 Compliance shared report (`src/Compliance`)

`SharedReport::view()` exposes framework scores, manual item titles/categories/states/review dates, automatic check
titles, categories, status and status labels, the framework tags and the `responsible` party label. It excludes details,
counts, notes, reviewer names, next-due dates and settings values (guard test). Only subject-0 snapshots can be
published (`SnapshotStore` filters on `subject_id`). `ReportRenderer` escapes every value in HTML (`htmlspecialchars`,
quotes included, no scripts or external assets) and neutralises CSV formula starters (`= + - @ TAB`, CR/LF collapsed). No
finding. Note for editions: the `responsible` label is shown to portal users, so it must not hold anything private.

### 2.7 KB converters (`src/KB`)

| STRIDE | Threat | Existing mitigation | Residual | Ref |
|---|---|---|---|---|
| T/I | XXE / entity expansion | DOCTYPE/ENTITY byte scan, then entity loader nulled, `LIBXML_NONET`, no `LIBXML_NOENT`, post-parse `doctype` check (a UTF-16 DOCTYPE that evades the byte scan is still rejected, guard test) | None found | - |
| D | Zip bomb | Entry count 1024, declared total 48 MiB, ratio check, and a hard byte cap on every real read | None found | - |
| T | Zip slip | Entries are only read by literal name or by a `word/media/<simple name>` key; nothing is extracted to disk | None found (guard test) | - |
| T | Active content in output | Closed tag set, escaped text and attributes, link schemes limited to http/https/mailto, media sniffed twice and extension derived from the sniff | Caller must still purify (documented) | - |
| E | PDF subprocess argument injection | `proc_open` with an argv array (no shell), literal `in.pdf` inside a 0700 scratch directory, fixed absolute binaries, env `LC_ALL=C PATH=/usr/bin`, stdin `/dev/null`, `%PDF-` magic check, 32 MiB cap, `-l 200` page cap, no `-hidden` | None found | - |
| D | Hostile PDF resource use | Wall-clock timeouts 5/20/45 s with SIGKILL, capped output reads, scratch removed in `finally` | No CPU/memory/file-size rlimit and no concurrency cap | RC-SR2-17 |
| D | Image bombs | Byte caps and type sniff | No pixel-dimension cap | RC-SR2-18 |
| I | Poppler text in logs | stderr clipped to 300 characters | Can carry CR/LF (names from the PDF) into `error_log` | RC-SR2-12 |

## 3. Findings

Severity key: HIGH, MEDIUM, LOW, INFO. No HIGH or MEDIUM issues were found. "Test" paths are under `tests/Security/`.

### RC-SR2-01 LOW: migration lock name is server-wide

- File: `src/Migration/MigrationRunner.php:556` (`LOCK_NAME`), `:601` (`GET_LOCK(?, ?)`).
- Severity: LOW. Availability only (an update is blocked for up to `lockWaitSeconds`, then fails with a clear message); no data exposure.
- Evidence: MySQL/MariaDB user-level lock names are global to the server. A connection with no database selected that holds `rivet_core_migrations` makes the runner on database `rivetcore_scratch_k_sec` throw `Another Core migration run is in progress`. The same contention was observed between scratch databases during development.
- Reproduction: `JobsCronSurfaceTest::testRc01MigrationLockNameIsServerWide`.
- Fix: include the schema in the name, `'rivet_core_migrations:' . DATABASE()` (hash it if longer than 64 characters; MariaDB 10.0.2+ and MySQL 5.7+ allow 64).

### RC-SR2-02 INFO: Redis fail-open locks allow duplicate cron execution (accepted design)

- File: `src/Redis/LockManager.php:123-132`, `src/Redis/CronGuard.php:23-29`.
- Severity: INFO. Documented contract; same exposure as before locks existed; callers can read `degraded()`.
- Evidence: with Redis unreachable, or reachable but failing every command, two `acquire()` calls both return held locks with `degraded() === true`.
- Reproduction: `RedisSurfaceTest::testRc02FailOpenLocksLetTwoCronRunsOverlap`.
- Suggested follow-up: `CronGuard::acquire($job, $ttl, failClosed: false)` so non-idempotent jobs can refuse to run unguarded.

### RC-SR2-03 INFO: audit table access patterns do not scale linearly (assessment)

- Files: `src/Audit/AuditReader.php:215-221` (COUNT per page, `LIMIT ... OFFSET`), `:309` (`LIKE '%x%'` over `summary`, `event_type`, `entity_id`, `ip_address` and the TEXT `metadata_json`), `src/Retention/RetentionService.php:509` (`created_at < ?`), `src/Audit/Migration/Migration0001AuditEvents.php:403-405` (indexes: `(event_type, created_at)`, `(entity_type, entity_id)`, `(actor_user_id)`; none starts with `created_at`).
- Severity: INFO. Only an administrator who can open the audit page triggers it; page size is capped at 200 and exports at 50 000 rows; retention batches (5000 rows per DELETE) keep locks short.
- Evidence (scratch MariaDB 11.8, box under heavy load, so treat as order of magnitude): at 300 000 rows the deepest page, a date-only filter and a search miss took 0.28 s, 0.29 s and 0.57 s, and `EXPLAIN` of the retention predicate showed a full scan of `idx_audit_events_type_created` (about 299 000 rows examined); pruning 92 500 of 100 000 rows in 5000-row batches took 9 s. A single 1 000 000-row run (box load average about 25) took 0.36 s for page 1, 64.8 s for page 5000 of 200 and 217 s for a search miss, so cost grows with table size. Retention keeps tables bounded by design (the default horizon is finite).
- Reproduction: `AuditSurfaceTest::testRc03AuditEventsHasNoCreatedAtLeadingIndexAndPagesWithOffset` (asserts the index set and that `page()` issues OFFSET and COUNT). Benchmarks were one-off scripts, not committed.
- Fix (when needed): add `KEY idx_audit_events_created (created_at)` in a new migration (the table definition must stay identical to RivetIT's, so coordinate), switch the UI to keyset paging (`iterate()` already does), and make the free-text search opt-in or limited to indexed columns.

### RC-SR2-04 LOW: webhook response is buffered with no size cap

- File: `src/Webhooks/WebhookDispatcher.php:293` (`CURLOPT_RETURNTRANSFER`), `:347`; `curlOptions()` sets no `CURLOPT_MAXFILESIZE` or write callback.
- Severity: LOW. A hostile or compromised receiver can make a delivery allocate until the 10 s timeout or `memory_limit`; the resulting fatal error breaks the "never throws" promise and the calling request. Admin-configured endpoints only.
- Evidence: a local server answering 24 MiB made the process peak memory grow by more than 20 MiB although 1000 bytes are logged.
- Reproduction: `WebhookSurfaceTest::testRc04ResponseBodyIsBufferedWithoutACap`.
- Fix: use `CURLOPT_WRITEFUNCTION` to keep the first 1000 bytes and abort the transfer (return a smaller count than given) after, say, 64 KiB.

### RC-SR2-05 LOW: an invalid-UTF-8 response silently drops the delivery log row

- File: `src/Webhooks/WebhookDispatcher.php:251` (`substr($r['body'], 0, 1000)`), `:260-272` (insert error swallowed).
- Severity: LOW. A receiver (or any proxy in front of it) can remove its own delivery record, and failed deliveries become invisible in the log; strict SQL mode makes the INSERT fail for a non-UTF-8 value or a multi-byte character cut by the byte slice.
- Evidence: of three attempts (binary body, 999 ASCII bytes plus a two-byte character, plain text) only the plain one produced a `webhook_deliveries` row.
- Reproduction: `WebhookSurfaceTest::testRc05InvalidUtf8ResponseDropsTheDeliveryLogRow`.
- Fix: `mb_strcut(mb_scrub($body, 'UTF-8'), 0, 1000, 'UTF-8')` before inserting; apply the same to curl error text.

### RC-SR2-06 LOW: platform URL patterns accept a trailing newline

- File: `src/Webhooks/Destination.php:52` and every `urlPattern` ending in `$` in `src/Webhooks/Destinations.php` (for example line 399, Telegram).
- Severity: LOW. Validation gap only: `UrlPolicy::vet()` rejects control characters at send time, so no request is made.
- Evidence: `urlMatches("https://api.telegram.org/bot1:abc/sendMessage\n")` returns true; `vet()` of the same string returns null.
- Reproduction: `WebhookSurfaceTest::testRc06UrlPatternAcceptsTrailingNewline`.
- Fix: end the patterns with `\z` (or add the `D` modifier) and `trim()` nothing silently.

### RC-SR2-07 LOW: audit redaction stops at depth 8 and returns the rest unredacted

- File: `src/Audit/AuditService.php:164` (`if (!is_array($value) || $depth > 8) return ...$value`).
- Severity: LOW. Requires a caller that nests secret-bearing arrays more than eight levels deep, but then the secret is stored in clear in `metadata_json`.
- Evidence: a `password` key at depth 11 is stored verbatim while the same key at depth 1 is `[redacted]`.
- Reproduction: `AuditSurfaceTest::testRc07SecretKeyBelowDepthEightIsStoredInClear`.
- Fix: at the depth limit replace the subtree with a marker (as `PayloadFormatter::redact()` already drops it) instead of returning it.

### RC-SR2-08 LOW: audit redaction list is an exact match of 14 names

- File: `src/Audit/AuditService.php:85` (`SECRET_KEY` anchored with `^...$`).
- Severity: LOW (hardening). Callers decide the keys; common variants are not covered.
- Evidence: `new_password`, `current_password`, `webhook_secret`, `mfa_secret`, `api-key`, `x-api-key`, `bearer`, `cookie`, `session_id` are stored in clear; `Password` (case) is caught. `PayloadFormatter::SECRET_KEY` is a substring match covering these.
- Reproduction: `AuditSurfaceTest::testRc08SecretKeyVariantsAreNotRedacted`.
- Fix: share one substring pattern between `AuditService` and `PayloadFormatter`.

### RC-SR2-09 LOW: the audit fan-out hook receives unredacted metadata

- File: `src/Audit/AuditService.php:135` (`($this->afterLog)(..., $summary, $metadata)` passes the originals, not `redact($metadata)`).
- Severity: LOW. The stored audit row hides a secret that the webhook/automation fan-out then sends (the default `json` format forwards `data` raw, and `request_payload_json` keeps the body).
- Evidence: the hook sees `password => hunter2` while the row holds `[redacted]`.
- Reproduction: `AuditSurfaceTest::testRc09AfterLogHookReceivesUnredactedMetadata`.
- Fix: pass the redacted metadata to the hook (and clamp `summary` the same way as the row).

### RC-SR2-10 LOW: oversize metadata makes the audit write fail and MCP reads go unaudited

- File: `src/Audit/AuditService.php:126` (metadata not clamped to the 64 KB TEXT column), `src/Mcp/ToolPipeline.php:120-127` (`record()` swallows the failure), `:121` (only top-level strings are shortened).
- Severity: LOW. An authenticated MCP caller who can send a nested argument larger than about 64 KB suppresses the audit row of a permitted read. Rise to MEDIUM if the edition forwards raw client JSON as `$args`.
- Evidence: a 70 KB nested argument returns `success: true`, the log shows `MCP audit failed: Data too long for column 'metadata_json'`, and `audit_events` is empty; a normal call writes one row.
- Reproduction: `AuditSurfaceTest::testRc10OversizeNestedArgumentSuppressesTheAuditRow`.
- Fix: bound the encoded metadata (store a truncated marker plus its size when it exceeds, say, 8 KB) and have `ToolPipeline` truncate nested values recursively.

### RC-SR2-11 LOW: every rate-limited MCP request writes an audit row

- File: `src/Mcp/ToolPipeline.php:85-89`.
- Severity: LOW. The limiter caps work but not audit growth; a valid-token caller can add rows without bound and push genuine events past the retention window.
- Evidence: limit 1 per minute, six calls: one served, five audited as `rate_limited`, six rows in total.
- Reproduction: `AuditSurfaceTest::testRc11RateLimitedRequestsAreEachAudited` (needs Redis).
- Fix: audit only the first rejection per window (use the limiter's `retry_after` or a second counter).

### RC-SR2-12 LOW: log lines can be forged through exception text

- File: `src/Support/ErrorLogLogger.php:26` (`error_log(strtr($message, ...))`); sources include `src/Mcp/ToolPipeline.php:102`, `src/Mcp/IdentityLinker.php:48`, `src/Mcp/UnlinkedIdentityStore.php:249`, `src/KB/PdfConverter.php:259,307,342` (poppler stderr, which can carry names decoded from the PDF).
- Severity: LOW. A CR/LF in an exception message becomes a second, attacker-shaped log line.
- Evidence: logging `"boom\n[06-Oct-2026 00:00:00 UTC] PHP Notice: admin login ok"` produced two lines.
- Reproduction: `AuditSurfaceTest::testRc12ErrorLogLoggerAllowsLogForging`.
- Fix: replace `[\r\n\x00-\x1F]` with a space or its escape in `ErrorLogLogger::log()`.

### RC-SR2-13 LOW: pending identities merge case-insensitively (carried over, known)

- File: `src/Mcp/Migration/Migration0003McpUnlinkedIdentities.php:310-311` (unique key on `(issuer, subject)`, `utf8mb4_general_ci`), `src/Mcp/UnlinkedIdentityStore.php:229-232`.
- Severity: LOW. Already listed as "known, not changed" in the 0.18.1 notes; recorded here with a test so it is tracked to its fix. Requires a valid IdP token; the effect is that the email/name an administrator sees for a pending identity can come from a different identity whose subject differs only by case or trailing spaces. The identity that gets linked is the first spelling.
- Evidence: three sightings (`AbC123`, `abc123`, `abc123   `) give one row with `attempts = 3` and the last writer's email and name.
- Reproduction: `McpSurfaceTest::testRc13PendingIdentitiesAreMergedCaseInsensitively`.
- Fix: a new migration changing `issuer`/`subject` to `utf8mb4_bin` (RivetIT 2.6.123 owns the same table, so the edition migration must match), plus a case-sensitive comparison in the edition's linked-identity column.

### RC-SR2-14 LOW: MCP diagnostics fetch an IdP-supplied URL without a destination policy

- File: `src/Mcp/McpDiagnostics.php:69-73` (`jwks_uri` from the discovery document), `:73` (exception text echoed); `McpConfig::issuerValid()` accepts any https host.
- Severity: LOW. Admin-only page; needs a hostile or misconfigured IdP document; the response is not shown, but connect errors reveal host/port reachability.
- Evidence: a discovery document with `jwks_uri: https://127.0.0.1:9/keys` caused a request to 127.0.0.1 and the check detail contains `Failed to connect to 127.0.0.1 port 9`.
- Reproduction: `McpSurfaceTest::testRc14DiagnosticsFetchesAnyHttpsJwksUri`.
- Fix: run `jwks_uri` (and the issuer) through `UrlPolicy::vet()` and show a fixed message instead of `getMessage()`.

### RC-SR2-15 LOW: Redis password is reachable through the config object's public property

- File: `src/Redis/RedisConnectionConfig.php:391-392` (`public readonly ?string $password`), `:517` (`__debugInfo` only).
- Severity: LOW. The class promises the password is never part of dump output; `var_dump`/`print_r` honour that, but `json_encode`, `serialize`, `var_export`, `(array)` and `get_object_vars` do not, so logging or caching the object leaks it.
- Evidence: all five outputs contain the password; `var_dump` and `print_r` do not.
- Reproduction: `RedisSurfaceTest::testRc15PasswordSurvivesJsonSerializeAndVarExport`.
- Fix: make the property private with a `passwordValue()` accessor used by `toPredisParameters()`, and implement `__serialize()`/`JsonSerializable` (masked). This is an API-surface change, so decide it before 1.0.

### RC-SR2-16 LOW: handler exception text is persisted verbatim in `integration_jobs.error`

- File: `src/Jobs/JobWorker.php:149,152`.
- Severity: LOW. HTTP-client exceptions routinely include the request URL with query tokens; the text is shown to admins and kept for the job-retention horizon.
- Evidence: a handler throwing `... https://api.vendor.example/v1/users?api_key=SUPERSECRETKEY123 ...` leaves the key in the `error` column of a dead-lettered job.
- Reproduction: `JobsCronSurfaceTest::testRc16HandlerExceptionTextIsPersistedVerbatim`.
- Fix: scrub URL userinfo/query strings and common `key=value` secret patterns before storing; document that handlers should throw sanitised messages.

### RC-SR2-17 LOW: poppler children run without CPU, memory or file-size limits

- File: `src/KB/PdfConverter.php:1046-1136` (`exec()`; only the wall-clock timeout of 5/20/45 s).
- Severity: LOW. Authenticated KB editors only; bounded by the timeouts, but there is no rlimit and no cap on concurrent imports.
- Evidence: `ulimit -t/-v/-f` inside the child are all `unlimited`; a 292 KB PDF containing one 10000x10000 flate image kept `pdftohtml -xml` busy for 8.4 s (peak RSS 20 MB, output PNG 292 KB). The timeout does fire (a sleeping child is killed after 0.3 s).
- Reproduction: `KbConverterSurfaceTest::testRc17PopplerChildRunsWithoutResourceLimits`.
- Fix: launch through `prlimit --cpu=30 --as=1500000000 --fsize=64000000` (or a `ulimit` wrapper via an argv `sh -c` with fixed text), and let the edition rate-limit imports (the library already has `RateLimiter`).

### RC-SR2-18 LOW: imported images have no pixel-dimension cap

- File: `src/KB/DocxConverter.php:1250-1278` (`extractMedia`), `src/KB/PdfConverter.php:735-770` (`acceptImage`): byte size and type are checked, `getimagesizefromstring()` dimensions are not.
- Severity: LOW. A small file decodes to hundreds of megapixels; a caller that thumbnails or re-encodes media with GD/Imagick can be pushed out of memory, and browsers refuse such images.
- Evidence: a 16000x16000 grey PNG (well under 1 MB) is accepted by `DocxConverter`; a 5000x5000 RGB flate image in a PDF (25 Mpx, under 1 MB PDF) is imported by `PdfConverter`.
- Reproduction: `KbConverterSurfaceTest::testRc18ImageWithHugePixelDimensionsIsAccepted` and `testRc18PdfImageWithHugePixelDimensionsIsAccepted`.
- Fix: skip images whose `width * height` exceeds a cap (for example 40 Mpx) with a warning, in both converters.

### RC-SR2-19 INFO: no port allow-list in `UrlPolicy`; a few special ranges missing

- File: `src/Webhooks/UrlPolicy.php:482-497`.
- Severity: INFO. Any port on a public host, or on a host inside an admin-listed internal network, can be targeted (for example `10.x:6379`); `3fff::/20` (RFC 9637 documentation) and a few IANA special-purpose ranges are not listed. Only administrators configure webhooks, and loopback/link-local/metadata are unreachable regardless.
- Reproduction: `WebhookSurfaceTest::testRc19UrlPolicyHasNoPortRestriction`.
- Suggested: optional `allowedPorts` (default 80/443/8000-8999 for listed internal networks) and add the missing ranges.

### RC-SR2-20 INFO: `TokenClaimsGuard` trusts the upstream verifier for expiry and issuer; pending list is capped

- File: `src/Mcp/TokenClaimsGuard.php:24-36`, `src/Mcp/UnlinkedIdentityStore.php:206,236`.
- Severity: INFO. Documented ("already passed signature, issuer and expiry validation"); recorded so editions keep verifying `exp`, `nbf` and `iss` themselves. Valid-but-unlinked users can fill the 200 pending slots for 30 days.
- Reproduction: `McpSurfaceTest::testRc20ClaimsGuardTrustsTheUpstreamVerifierAndPendingListIsCapped`.

### RC-SR2-21 INFO: Redis connection test is a host/port oracle; `verifyPeer=false` also drops the host-name check

- File: `src/Redis/RedisAdmin.php:244-274`, `src/Redis/RedisConnectionConfig.php:494`.
- Severity: INFO. Admin-only; no password ever appears in the output.
- Reproduction: `RedisSurfaceTest::testRc21AdminConnectionTestIsAHostPortOracle`.

### RC-SR2-22 INFO: JobRunner notes

- File: `src/Cron/JobRunner.php:451` (`files()` key sanitising), `:439,507` (argument form), `:407-409` (shared temp dir default).
- Severity: INFO. Keys that differ only in removed characters share state; argument option names are not restricted per script; a local user who pre-creates the default state directory makes `start()` refuse (fail closed); launched jobs have no rlimit. The command-construction path is sound: allow-listed script under the app root, `escapeshellarg` throughout, exclusive log creation.
- Reproduction: `JobsCronSurfaceTest::testRc22JobRunnerNotes`.

### RC-SR2-23 INFO: TTL edge cases (lock outliving its TTL; rate-limit key with no TTL)

- File: `src/Redis/LockManager.php:127`, `src/Redis/RateLimiter.php:164` (EXPIRE only when INCR returns 1).
- Severity: INFO. A job running past its TTL without `extend()` loses the lock to a second runner; a counter key that exists without a TTL blocks forever.
- Reproduction: `RedisSurfaceTest::testRc23LockExpiresUnderALongRunningJob`, `testRc23RateLimitCounterWithoutTtlNeverExpires`.
- Suggested: in the Lua, set the expiry when `redis.call('ttl', key) == -1`.

## 4. Verified controls (no finding)

Each has a guard test in `tests/Security/`:

- Redirects are never followed and protocols are restricted (live local server answering 302 to a path that would record a hit: no hit, attempt reported as HTTP 302).
- Query/path tokens and the signing secret are not written to `webhook_deliveries` or the returned error (connection-refused case).
- DNS rebinding: the transport is pinned to the first (vetted) answer; a second, loopback answer is never used; proxies are disabled for pinned requests.
- Numeric loopback spellings (`0x7f.1`, `2130706433`, `017700000001`, `127.1`, `::ffff:7f00:1`) and userinfo tricks are refused by `UrlPolicy`.
- Redis: connection-test messages never contain the password; locks are token-owned; keys carry the edition prefix.
- Job queue: a running job is not claimed twice; a reclaimed worker cannot overwrite the newer attempt.
- Compliance: the portal view contains no notes, reviewer names, details or next-due dates; HTML is escaped; CSV starters neutralised.
- DOCX: a UTF-16 encoded DOCTYPE is rejected; a `../../etc/passwd` media target and a `javascript:` hyperlink are dropped.

## 5. Not reviewed

`src/ITSM`, `src/Workflow`, `src/Automation` (rule evaluation and executor), `src/Ui` and `src/Health` beyond the readiness
checker were only skimmed for injection sinks; they were not part of the requested surfaces. `src/Testing` is test support.
Edition-side code (authentication, JWT verification, encryption of webhook secrets, rendering of stored text) is out of
scope and several residual risks above depend on it.

## 6. Tool runs

Run on 2026-10-06 in the review worktree (PHP 8.5.11, Composer 2.9.5, PHPStan 2.3.0, PHPUnit 11.5.57,
predis/predis 3.6.1, guzzlehttp/guzzle 8.2.0, psr/log 3.0.2, curl 8.18.0, poppler pdftotext 26.01.0, MariaDB 11.8.6,
Redis 8.0.5 on a throwaway 127.0.0.1 port).

- `composer audit`: `No security vulnerability advisories found.` (exit code 0; also with `--locked`).
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: `[OK] No errors` at the configured level 6 (exit code 0).
- `vendor/bin/phpunit tests/Security`: see the result recorded below.

PHPUNIT_RESULT_PLACEHOLDER
