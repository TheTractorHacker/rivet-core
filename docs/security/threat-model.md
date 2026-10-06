# RivetCore threat model

Status: written for the 1.0.0-rc review (2026-10, issue #41). It describes RivetCore 0.21.x plus the fixes of
[review-2026-10.md](review-2026-10.md). Line numbers are as of that review (search for the symbol if they have moved).
Editions use the companion [edition-checklist.md](edition-checklist.md) to verify their side.

RivetCore is a library. It has no HTTP surface, no sessions and no users of its own: every request, identity and
secret reaches it through an edition (RivetIT, RivetMSP, or any other host application). The threat model is
therefore about two questions: what can a hostile *input* do to Core code that processes it, and what must the
*edition* guarantee so that Core's guarantees hold.

## 1. Assets

| Asset | Where it lives | Why it matters |
|---|---|---|
| Webhook secrets and outgoing auth (bearer, basic, header values) | edition table, passed to `WebhookSubscription` | Forging deliveries, impersonating the platform to receivers |
| Redis password / client key | edition settings, `RedisConnectionConfig` | Access to locks, rate limits, metadata cache and whatever else shares the instance |
| Audit trail (`audit_events`) | Core table | Evidence for compliance; must be complete, truthful and secret-free |
| Webhook delivery log (`webhook_deliveries`) | Core table | Holds full request bodies (event data, PII) and 1000 bytes of each response |
| Integration jobs (`integration_jobs`) | Core table | Payloads (event data), error text, retry state |
| MCP identity links, pending identities | edition `users` + Core `mcp_unlinked_identities` | Decides which agent a bearer token acts as |
| Compliance snapshots, attestations, the shared report | Core tables | What a portal user can read |
| Uploaded DOCX/PDF files | request upload, temp dir | Parsed by Core; become KB articles |
| The server's network position | the PHP process | SSRF target: cloud metadata, loopback services, the LAN |

## 2. Actors and trust

| Actor | Trust | Notes |
|---|---|---|
| Edition application code | trusted | Calls Core with already-authenticated, already-authorised requests. Core never re-authenticates. |
| Admin (edition role) | trusted with caveats | Configures webhooks, Redis, MCP issuer, cron. Admin-supplied URLs and hosts are still validated (an admin account can be phished, and a "Send test" button is an SSRF primitive). |
| Agent / technician | semi-trusted | Writes KB articles, tickets, free text that ends up in webhook bodies, audit summaries, chat messages. All of it is data, never code. |
| Portal user (client) | untrusted-authenticated | May only read what an edition shows them. Core's only portal-facing output is `SharedReport::view()` / `SubjectCompliance::shared()`. |
| MCP client (AI agent behind OAuth) | untrusted-authenticated | Arguments and call volume are attacker-influenced; the identity is not (it comes from a validated token). |
| External webhook receiver | untrusted | Chosen by an admin, but can be hostile, slow, or return gigabytes. Receives signed bodies. |
| Identity provider (OIDC) | trusted for identity, untrusted for content | Its discovery document and JWKS are fetched from URLs it controls. |
| Attacker-controlled upload | untrusted | A DOCX/PDF from any user who may import. |
| Redis, MariaDB/MySQL | trusted infrastructure | Assumed reachable only from the application. Core is written so that Redis being *down* is safe; Redis being *hostile* is out of scope. |
| Network attacker | untrusted | Between Core and Redis (TLS option), and between Core and webhook receivers (TLS verification always on). |

## 3. Trust boundaries

```
  browser / MCP client / portal            external receivers            IdP (OIDC)
            |                                   ^                           ^
   [edition: authn, authz, CSRF, tenant]        | HTTPS, signed, pinned     | HTTPS, no redirects
            v                                   |                           |
   +-------------------------------- RivetCore (library, in the PHP process) -----------------+
   |  Webhooks  Mcp  Jobs  Cron  Audit  Compliance  Retention  KB converters  Redis helpers  |
   +----------------+----------------------------+----------------------+-----------------+
                    |                            |                      |
              MariaDB/MySQL                    Redis                 /usr/bin/pdf*   (child process, no shell)
```

1. Edition to Core: *the* authorisation boundary. Core trusts its caller.
2. Core to the network: webhook receivers, OIDC endpoints, Redis. Everything outbound is treated as hostile.
3. Upload to Core: converters parse hostile bytes.
4. Core to Redis/DB: parameterised, prefixed, fail-safe.
5. Core to child processes: only `PdfConverter` (poppler, argv array, no shell) and `JobRunner` (allow-listed cron script).

## 4. Data flows and STRIDE per module

Legend: S spoofing, T tampering, R repudiation, I information disclosure, D denial of service, E elevation of privilege.
"Residual" is what remains after the mitigation; "(SR-nn)" refers to a finding in the review report.

### 4.1 Webhooks

Flow: edition event -> `WebhookDispatcher::deliver()/deliverTo()` -> `PayloadFormatter`/`PayloadTemplate` build the
body -> HMAC V1 + V2 over the exact bytes -> `UrlPolicy::vet()` (every attempt) -> curl pinned to the vetted IPs ->
response status + capped snippet logged to `webhook_deliveries`.

| STRIDE | Threat | Mitigation (code) | Residual |
|---|---|---|---|
| S | Receiver cannot tell a genuine delivery from a forged one | HMAC-SHA256 of the exact bytes (`WebhookDispatcher::sendOne`, `signatureV2` ~l.287); receivers verify with a constant-time compare (snippets in `Destinations::verifySnippets` use `timingSafeEqual` / `hmac.compare_digest` / `hash_equals`) | The secret is the edition's to store and rotate. Legacy `-Signature` header has no timestamp (SR-16, accepted) |
| T | Replay of a captured delivery | V2 header `t=<ts>,v1=<hmac(ts.body)>`; snippets enforce a 300 s tolerance | Receiver must implement the check; V1 stays replayable |
| T | Header injection (CRLF) via admin-configured headers or auth | `Authentication::isValidHeaderName` (now `\z/D`, SR-01), `validate()` control-char checks, `WebhookDispatcher::mergeExtraHeaders` (token names, no CR/LF/NUL, forbidden framing/signature names, 4096-byte values); event name stripped of control chars in the `-Event` header (SR-03) | None known |
| T | Template injection / code execution | `PayloadTemplate` is a substitution engine: no eval, loops or calls, `PATH_RE` limits paths, 8 KB template, 64 KB output, 100 placeholders, encoding-aware escaping (`evaluate()`), `{{x|json}}` must be last | A template can read any key of the event data (secrets inside event data are the edition's problem; `template` and `form` formats redact `SECRET_KEY` names, `json` does not) |
| T | Markup/mention injection into chat platforms | `PayloadFormatter::slackEscape`, `markdownEscape`, Discord `allowed_mentions: parse []`, Telegram/Matrix `htmlspecialchars`, `EventSummary::clean` strips control and bidi characters, `isSafeUrl` (https? only, no credentials, `\z`) | Platform parsers can change; escaping is best effort per platform |
| I | SSRF: dispatcher reaches loopback, metadata, LAN | `UrlPolicy::vet` (l.55): scheme http/https, no userinfo, no backslash/whitespace/control, every resolved address must be public (`isPublicIp`, `NON_PUBLIC_V4/V6` incl. CGNAT, 6to4, Teredo, NAT64, documentation), IPv4-embedded forms judged by the embedded address, optional `allowedNetworks` (`NetworkList`) for private ranges only and never loopback/link-local/metadata (`NEVER_ALLOWED_*`) | Any TCP port on an allowed host/network is reachable (SR-17); `UrlPolicy` is opt-in on the dispatcher (SR-15) |
| I | DNS rebinding between check and connect | `curlOptions` pins the vetted IPs with `CURLOPT_RESOLVE`; `pinnedUrl` gives curl the vetted host spelling; proxy disabled for pinned requests; fragment dropped | Hosts that are IP literals need no pin (nothing to rebind) |
| I | Redirect to an internal address | `CURLOPT_FOLLOWLOCATION=false`, `MAXREDIRS=0`, protocols limited to http/https | None |
| I | Secrets in logs | Request body is logged by design (retention applies); `Authentication::redact` for display; response snippet capped to 1000 bytes | Request bodies contain whatever event data the edition emits |
| I | TLS downgrade / MITM | `SSL_VERIFYPEER=true`, `SSL_VERIFYHOST=2`, not configurable | None |
| D | Hostile receiver streams gigabytes or stalls | `CURLOPT_TIMEOUT` (10 s), connect timeout 5 s, response capture limited to 16 KB and the read aborted after 1 MiB (`curlTransport`, SR-04) | A receiver can still hold one request for up to the timeout; use the queue (`JobQueue`) for delivery off the request path |
| D | Pathological template / payload | size limits above; `PayloadFormatter` clips every field | None known |
| R | Delivery attempt not recorded | `logAttempt` inserts one row per attempt, best effort (a logging failure never breaks the caller) | A failed insert is silent by design |
| E | A destination preset smuggles forbidden headers | `Authentication::FORBIDDEN` + `x-rivet-*` / `*-signature` prefix rules | None |

### 4.2 Redis (locks, rate limiter, admin, connection config)

Flow: edition settings -> `RedisConnectionConfig` (validate) -> Predis client -> `LockManager`, `RateLimiter`,
`RedisMetadataCache`, `RedisAdmin`.

| STRIDE | Threat | Mitigation | Residual |
|---|---|---|---|
| S/T | Lock stolen or released by another holder | Random 128-bit token (`random_bytes`, `LockManager::acquire`), compare-and-delete / compare-and-extend Lua (`Lock::RELEASE/EXTEND`) | A lock whose TTL expires mid-job is no longer exclusive; call `extend()` (documented). No fencing token |
| T | Rate-limit race | Single atomic Lua `INCR` + `EXPIRE` (`RateLimiter::HIT`) | Buckets are edition-named; an attacker who controls the bucket string creates keys (each with a TTL) |
| D | Redis down | **Fail-open** by design: locks report `degraded()`, the limiter allows (see section 5) | Cron may run twice, limits are not enforced while Redis is down |
| I | Password leaks into logs/errors | `#[\SensitiveParameter]`, `redact()`, `__debugInfo` masks it, `RedisAdmin::test` returns fixed messages and classifies errors on a redacted copy | Predis exception messages may still contain the host:port |
| T | Cross-protocol injection through host/password | `validate()`: host pattern (`\z/D`, SR-02), password with CR/LF/NUL rejected, username pattern, port/db ranges | None |
| I/S | TLS misconfiguration | `verify_peer` and `verify_peer_name` default true; CA/client cert/key paths validated (no `://`, no control chars, must be readable when connecting) | `tls_verify=false` is allowed and unflagged (edition should warn); a relative or `data:` CA path is accepted (SR-23, accepted) |
| E | Admin Redis "Test"/"Clear" abused | `clear()` only touches patterns the edition configured per group (`clearable`); `setMemory` clamps 64 MB to 64 GB and a policy allow-list; the host/port of `test()` is admin-chosen (a TCP connect and a RESP `AUTH`/`PING`) | Admin can probe internal hosts/ports (edition authz) |
| D | `RedisAdmin::groupCounts` / `clear` on huge keyspaces | `SCAN` with a count of 500, counts capped at 5000, deletes in batches of 200 | Long scans on very large instances |

### 4.3 MCP (OAuth-protected tools)

Flow: edition validates the JWT (signature, issuer, expiry, `RS256`) -> `TokenClaimsGuard::acceptable` ->
edition maps `sub` to an agent -> `ToolPipeline::run` (rate limit, permission, tool, audit).

| STRIDE | Threat | Mitigation | Residual |
|---|---|---|---|
| S | Token minted for another resource reused | `TokenClaimsGuard::hasDedicatedAudience` requires `aud` to be exactly the MCP audience (string or one-element list); scope `mcp:read` must be present; lifetime <= 1 h, `iat` not in the future | The guard does **not** verify signature, issuer, `exp > now` or `nbf` (documented: the edition's JWT library must; checklist) |
| S | Wrong person linked to a token | `IdentityLinker::link` runs in a transaction with the pending row and the agent row locked (`FOR UPDATE`), refuses already-linked agents/identities | Pending identities use a case-insensitive collation (SR-14); the edition's `users.user_oidc_subject` must be binary |
| I | Tool arguments leak secrets into audit | `ToolPipeline::record` truncates string args to 100 chars; `AuditService` redacts secret-named keys and caps the row (SR-05..07) | Secrets under innocuous key names are stored (up to 100 chars) |
| R | Tool calls not audited | Every outcome (ok, denied, not_found, error, rate_limited) is recorded; an oversized argument can no longer make the audit INSERT fail (SR-05) | Audit is best effort: a database outage loses the row (logged) |
| D | Call flooding | `RateLimiter` per user and source (default 60/60 s) before any permission or tool work | Fail-open when Redis is down; rate-limited calls are still audited (write amplification, accepted) |
| E | A throwing permission check treated as allow | `ToolPipeline::run` catches it and denies (SR-08) | None |
| S/I | Issuer/JWKS fetch abused (SSRF) in `McpDiagnostics` | HTTPS only for issuer and `jwks_uri`, no userinfo/query/fragment, redirects off, 5 s timeout, only an admin-triggered page | Blind SSRF to any https host an admin names or an issuer document points at (SR-13, accepted); `baseHost` must come from configuration, never from the request |
| T | Poisoned discovery cache | `RedisMetadataCache` hashes keys, JSON only (depth 32), no `unserialize`; `clear()` refuses to flush the shared DB | Anyone with Redis write access can poison it (infrastructure trust) |

### 4.4 Jobs and cron

| STRIDE | Threat | Mitigation | Residual |
|---|---|---|---|
| T | Two workers run one job | `claim()` `UPDATE ... WHERE status='pending'` and checks `affectedRows === 1` (atomic); `markCompleted/markFailed` require `status='running'` and the claimed attempt number (fence) | Handlers must be idempotent: a job running longer than the stale window without heartbeats is requeued and can run twice |
| T | Zombie worker overwrites a newer attempt | attempt fence, see above | None |
| D | Poison job loops forever | `max_attempts`, back-off, `requeueStale` dead-letters exhausted jobs, unknown types dead-letter | A handler that never returns (no timeout, no `checkpoint()`) holds a worker |
| E | Job type picks code to run | Job type is only a lookup key into handlers the edition registered (`JobWorker::$handlers`); nothing is `include`d, instantiated or `eval`ed from the row | None |
| I | Handler exception text (maybe with secrets) stored and shown | message cut to 2000 chars, stored in `integration_jobs.error`, returned by `recent()` | Handlers must not put secrets in exceptions (SR-20) |
| E | Command injection in `JobRunner::start` | Script must resolve (`realpath`) under `<appRoot>/cron/` or `/scripts/`; php binary must match `/usr/bin/php[0-9.]*` or `PHP_BINARY`; arguments only `--name[=value]` (`\z/D`, SR-02); everything passed through `escapeshellarg`; state dir must be a real directory owned by the user, not group/world writable; log created with mode `x` after unlinking any symlink | The edition decides who may call `start()` and which scripts exist |
| D | Cron overlap | `CronGuard::acquire` (Redis lock) | Fail-open when Redis is down (two runs) |

### 4.5 Audit

| STRIDE | Threat | Mitigation | Residual |
|---|---|---|---|
| T | SQL injection through filters | All values bound; `LIMIT`/`OFFSET` are clamped ints (`AuditReader::page`, `iterate`); `LIKE` input escaped (`escapeLike`); dates matched with `\d` patterns | None |
| I | Secrets/PII in metadata | `AuditService::redact` + `isSecretKey` (exact names and `*password/secret/token/apikey` suffixes), arrays deeper than 8 levels replaced, fan-out gets the redacted copy (SR-06/07) | Secrets under other key names; IP address and user agent are stored on purpose (PII, subject to retention) |
| T | Event lost because metadata is too large | 60 000-byte cap with graceful shrinking (SR-05); every column clamped to its width | None |
| T | Log forging by summary text | Stored as data; the edition must escape on output and in CSV exports | Edition |
| D | Unbounded export | `iterate()` default cap 50 000 rows, chunks of 1000 | Search is `LIKE '%x%'` over `metadata_json` (full scan) |
| R | Audit tampering through retention | `RetentionService` second argument raises horizons to the compliance floor | Only if the edition passes the profile (RivetIT passes effective days instead; both work) |

### 4.6 Compliance and the shared report

| STRIDE | Threat | Mitigation | Residual |
|---|---|---|---|
| I | Portal user sees internal notes, reviewers, details | `SharedReport::view()` is an allow-list projection: title, category, state/status label, review date, responsible party name, framework tags, and scores. No notes, reviewer names, detail text or free text | The `responsible` party name is shown on purpose |
| I | Portal user of client A sees client B's report | `SubjectCompliance::shared($subjectId)` filters by subject; **the edition passes the subject** | Edition must take it from the session, never from a request parameter (RivetMSP does, `client/compliance.php:18`) |
| T | Stored XSS / CSV injection in reports | `ReportRenderer::html` escapes everything (`htmlspecialchars` ENT_QUOTES), `csvCell` neutralises `= + - @ TAB` and line breaks | None |
| T | Attestation forgery | `AttestationStore::record` validates item id (`\z/D`), dates, no future review dates; rows are append-only, latest wins | Who may attest is the edition's authorisation |
| I | Snapshot JSON trusted on read | `Assessment::fromArray` on decoded JSON; invalid JSON yields null | Snapshots are written only by Core |

### 4.7 Retention

| STRIDE | Threat | Mitigation | Residual |
|---|---|---|---|
| T/D | Over-deletion | Table names are constants, horizons are ints, `DELETE ... LIMIT n` batches of 5000, cut-off from the **database** clock, optional compliance floor, `plan()` dry run | A 0 or negative horizon disables that table (keeps data), never deletes everything |

### 4.8 KB converters (DOCX, PDF)

Flow: upload -> `DocxConverter::convert` / `PdfConverter::convert` -> sanitised HTML subset + image bytes + warnings ->
the **edition** purifies, names and stores the files.

| STRIDE | Threat | Mitigation | Residual |
|---|---|---|---|
| D | Zip bomb | entry count <= 1024, declared total <= 48 MiB checked from the central directory, per-entry ratio <= 500:1 above 4 MiB, every read capped with `getFromName($name, $cap+1)` and re-checked (`readEntry`), caps per part (8 MiB `document.xml`), 4 MiB HTML out | LibXML DOM memory is outside `memory_limit` (documented, ~170 MB worst case at the cap) |
| I/T | XXE / entity expansion | `loadXml`: any `<!DOCTYPE`/`<!ENTITY` byte match rejected, external entity loader returns null, `LIBXML_NONET`, no `LIBXML_NOENT`, parsed `doctype` re-checked (this also catches UTF-16 encoded DOCTYPEs, tested) | None |
| T | Zip slip / path traversal | no entry is ever written to disk by Core; media targets must resolve to `word/media/<simple name>` (`resolveMediaEntry`), no `%`, `\`, `..`, control chars | Edition must generate its own file names |
| T | Active content in the output | closed tag set emitted from literals, every text/attribute escaped, links limited to `http`, `https`, `mailto` with control characters rejected (`safeUrl`); PDF output has no `href` at all | Edition purifier remains the second layer |
| T | Disguised media | `finfo` and `getimagesizefromstring` must agree on a raster type; extension derived from the sniff | |
| D | Image decompression bomb | pixel cap 64 MP and 30 000 px per side from the header (SR-10) | |
| E | Command injection (PDF) | `proc_open` with an **argv array** (no shell), absolute binaries, fixed input name `in.pdf` copied into a 0700 random scratch dir, `env` = `LC_ALL`, `PATH=/usr/bin`, stdin `/dev/null`, wall-clock kill (5/20/45 s), pipes drained and capped, scratch dir removed in `finally`, page cap 200, `-nodrm`/`-hidden` not passed | No rlimits on poppler memory/disk (SR-18); poppler CVEs apply |
| D | PDF XML bomb | poppler writes `out.xml`, capped at 2 MiB before parsing; same XXE defences as DOCX | |

### 4.9 Knowledge (`CredentialReferenceRenderer`), Ui, Support

| Module | Threat | Mitigation | Residual |
|---|---|---|---|
| CredentialReferenceRenderer | A `[[credential:N]]` token inside an attribute becomes markup, or the badge leaks a secret | Tokens are replaced only in text nodes, removed inside tags (`TAG_PATTERN`); the badge callback is the edition's and receives an int only | On regex failure (JIT limit for a single tag over ~400 KB) the HTML is returned unrendered: tokens stay visible, nothing is revealed. No ReDoS found (tested to 800 KB) |
| IconCatalog | Class injection into `class=""` | `normalize()` accepts one token matching `^fa-[a-z0-9]+(-[a-z0-9]+)*\z`, max 50 chars, else a default | None |
| DateRange | SQL via dates, overflow | Dates validated with `\z` + `checkdate`, clamped to 1970..2099, half-open bounds returned for binding | None |
| AccessPolicy | Default allows everything | `AllowAllPolicy` exists for tests and migration; `DenyAllPolicy` for fail-closed hosts | Editions must inject a real policy (checklist) |
| ErrorLogLogger | Log forging | control characters escaped (`oneLine`, SR-09) | |
| Automation | Event data steers URLs/recipients | `interpolate` never touches `url`, `secret`, `user_id`, `client_id`, `priority`; conditions are equality only; rule fields validated (`\z/D`); webhook URL shape checked (SR-12) | The send handler must re-vet the URL with `UrlPolicy` |
| MigrationRunner | Two runners, or a runner starved by an unrelated database | per-schema server-side lock (SR-11), idempotent steps | If `GET_LOCK` is unsupported the runner proceeds unlocked (fail-open, steps are idempotent) |

## 5. Deliberate fail-open decisions

| Where | Behaviour | Why this is acceptable | When it is not |
|---|---|---|---|
| `LockManager::acquire` / `CronGuard` when Redis is unreachable | returns a held, `degraded()` lock | A cron job that never runs is worse than one that runs twice; jobs must be idempotent anyway | Jobs that are not idempotent: check `degraded()` |
| `RateLimiter::hit` when Redis fails | allows the call | A cache outage must not lock every agent out; limits are abuse control, not authorisation | If rate limits protect an expensive or costly action, add an HTTP-layer limit |
| `MigrationRunner` when `GET_LOCK` is unsupported/throws | runs without the lock | Migrations are idempotent; a stuck upgrade is worse | Databases with parallel deploys: serialise externally |
| `WebhookDispatcher` without a `UrlPolicy` | no SSRF check (legacy behaviour) | Backward compatibility with 0.x | Always pass `$requireUrlPolicy=true` (both editions do for their main path) |
| `AuditService::afterLog`, `WebhookDispatcher::logAttempt` failures | swallowed | The business action must not fail because a log or fan-out failed | Where audit is mandatory (some compliance regimes), the edition should alarm on the PSR-3 log |
| `ToolPipeline::record` failure | swallowed, logged | An audit outage must not turn a permitted read into an error | Same as above |
| `UnlinkedIdentityStore::record` failure / cap | silently drops | Best-effort onboarding aid, never grants access | |
| `RetentionService` with no profile | deletes by the numbers given | The edition owns the compliance floor | Pass the profile, or compute effective days (RivetIT does) |
| `CredentialReferenceRenderer` regex failure | returns the input | Shows a token, never a secret | |

Everything else fails closed: malformed URLs, unresolvable hosts, invalid header names, undecodable JSON (`null`/default),
unknown job types (dead-lettered), unreadable archives, a throwing permission callback, an oversized template.

## 6. What Core deliberately does NOT protect

These are the editions' responsibilities. [edition-checklist.md](edition-checklist.md) turns them into checks.

1. **Authentication and authorisation.** Core never checks who is calling. `AccessPolicyInterface` is a contract; the
   default `AllowAllPolicy` allows everything. Every admin action (webhooks, Redis, MCP linking, cron start, retention,
   compliance attestation, publishing the shared report) must be gated by the edition.
2. **Tenant isolation.** Core tables have no tenant column except `subject_id` in compliance. The edition passes the
   subject/client id and must derive it from the session. Audit rows, jobs and delivery logs are global.
3. **CSRF, sessions, cookies, rate limiting of the HTTP layer, request size limits.**
4. **Key storage.** Webhook secrets, outgoing auth values and the Redis password arrive in plaintext; the edition
   encrypts them at rest (RivetIT: `encryptSetting`) and never logs them. Core never persists them itself.
5. **JWT validation.** Signature, issuer, expiry, `nbf`, algorithm pinning and key rotation are the JWT library's job;
   `TokenClaimsGuard` only adds audience, scope and lifetime rules on top.
6. **Output encoding in the UI and in exports** (audit summaries, job errors, delivery snippets, KB HTML after
   conversion; run the HTMLPurifier/CSP layer).
7. **File handling after conversion.** Generating names for extracted media, storing outside the web root or with a
   non-executable handler, serving with the sniffed type and `nosniff`.
8. **Process and resource isolation for poppler** (cgroup/ulimit, a sandboxed temp dir, no network namespace).
9. **Secrets inside event data and tool arguments.** Core redacts well-known key names; it cannot recognise a secret
   under an arbitrary key.
10. **Infrastructure:** Redis and database network exposure, TLS to Redis in untrusted networks (use `tls=true`),
    backups, clock accuracy (replay windows and JWT lifetimes rely on it).
11. **Which webhook ports/hosts are acceptable once `allowedNetworks` is used:** Core allows any port.
12. **Pending-identity collation and the linked-identity column** (case-sensitive `sub`): the edition's schema.
