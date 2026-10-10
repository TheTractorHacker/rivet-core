# RivetCore roadmap to 1.0.0

Written 2026-10-05 from the state of `v0.7.1`; status brought up to date on 2026-10-06 (through `v0.21.0` and the working tree after it). The fact table under "Measured at v0.7.1" is historical and was not re-measured. Gate evidence lives in [docs/RELEASE_GATE.md](docs/RELEASE_GATE.md). Estimates are effort for one maintainer
(S is up to a day, M is 2 to 4 days, L is 1 to 2 weeks), not calendar promises; the order and the gates matter more than the dates.

## What 1.0.0 means

RivetCore 1.0.0 is a promise, not a feature count. It means:

1. **A stable public API.** The contracts, services and tables listed as public are covered by semantic versioning, enforced by a backward-compatibility check in CI.
2. **A supported platform matrix, tested.** PHP 8.2, 8.3, 8.4 and 8.5; MariaDB 10.11 and 11; MySQL 8.0 and 8.4; Redis 7 (optional everywhere).
3. **Production proof.** Both editions run the 1.0 release candidate in production for at least 30 days with every module they use actually exercised, and no open P1 or P2 bug.
4. **Quality gates that stay green.** Static analysis, measured coverage, dependency audit, and a security review with no open HIGH or MEDIUM finding.
5. **Documentation an outside developer can use.** How to write an edition adapter, what each module owns and how it fails, how to upgrade, how to report a vulnerability.
6. **Retired risk.** The known bugs found while building 0.x are fixed and have regression tests (see "Defects already found and fixed").

Principles that do not change on the way: Core never knows which edition it runs in; no `global`, superglobal or edition bootstrap inside Core; storage only through
`DatabaseInterface`; additive, idempotent migrations that only touch Core-owned tables; every module off by default until an edition switches it on; Redis is optional and everything fails open.

## Where we are

### Shipped since 0.7.1 (status at v0.21.0)

The release numbers do not match the milestone numbers below: the 0.8.0 to 0.14.0 releases went to compliance work, and the milestone items
were delivered in 0.15.0 to 0.18.1. Nothing was dropped; what is still open is listed under "Remaining" in each milestone.

| Version | What shipped |
|---|---|
| 0.8.0 | `RetentionPolicy` presets (ISO/IEC 27001, SOC 2, PCI DSS, HIPAA) and a separate audit-trail retention horizon |
| 0.9.0 | Compliance status engine: `ComplianceAssessor`, `AttestationStore`, `SnapshotStore`, `ReportRenderer`, migration 0008 |
| 0.10.0 | `SharedReport`: publish one saved snapshot, reduced, for portal users |
| 0.11.0 | `SubjectCompliance` and `ClientChecklist`: compliance for a customer (subject) of an MSP |
| 0.12.0 | NIST SP 800-171 / CMMC Level 2 as a fifth framework (`Nist171Map`) |
| 0.13.0 | Retention preset `nist171` |
| 0.14.0 | `ResponsibilityStore`: who is responsible for a section or item (the organization or a managed service provider) |
| 0.15.0, 0.15.1 | `JobWorker`, single-endpoint webhook delivery (`deliverTo`), event automation store and executor, `AuditService` `afterLog` fan-out |
| 0.16.0 | **Milestone 0.8.0 (quality gates):** CI matrix, PHPStan level 6, coverage gate, converter corpus tests, migration runner lock and `status()`, Dependabot, project files, releases from tags, docs first pass |
| 0.17.0 | **Milestone 0.9.0 (contracts):** `@api`/`@internal` marking, PSR-3 logging, `AccessPolicyInterface` (ADR-003), `AuditReader`, `UrlPolicy` and signature V2, job timeouts and heartbeat (migration 0012), Redis auth and TLS configuration, retention horizons and dry run |
| 0.17.1 | CI fix for the backward-compatibility job |
| 0.18.0 | Webhooks may reach listed private networks (`allowedNetworks`, `NetworkList`, `LocalNetworks`) |
| 0.18.1 | Security hardening from the 2026-10 review (UrlPolicy ranges, no proxy, job fencing, runner directory, audit clamping and redaction, retention floors) |
| 0.19.0 | `Ui\IconCatalog` |
| 0.20.0 | `Ui\DateRange` |
| 0.21.0 | Webhook destinations (24 presets), payload formats and templates, outgoing authentication, event catalog |
| after 0.21.0 (working tree, unreleased) | API freeze review, array-shape docblocks, `MigrationInProgressException`, migration 0013 (retention indexes), adapter conformance kit (`src/Testing`), security review documents, module pages, ADR-004 to 009, `UPGRADING.md`, `docs/EDITION_CHECKLIST.md`, `docs/PERFORMANCE.md` with `scripts/bench.php`, `docs/RELEASE_GATE.md` |

Still open overall: the compatibility check becoming required, the second security review and threat model, edition adoption (milestone 0.10.0), the
clean-VM installer runs, the release candidate and its 30 day soak. See [docs/RELEASE_GATE.md](docs/RELEASE_GATE.md) for each with its evidence and status.

### Measured at v0.7.1

| Fact | Value |
|---|---|
| Size | 54 source files, about 5,300 lines (about 2,600 are the DOCX and PDF converters) |
| Public contracts | 9 interfaces: `DatabaseInterface`, `ClockInterface`, `SettingsInterface`, `RequestContextInterface`, `RedisClientProviderInterface`, `MigrationInterface`, `TicketProblemLinkInterface`, `WebhookSubscriptionsInterface`, `AgentDirectoryInterface` |
| Modules | Audit, Redis and health, Cron, Jobs, MCP, ITSM, Webhooks, Automation, Workflow, Retention, KB converters, Knowledge |
| Core-owned tables | 12 (`audit_events`, `integration_jobs`, `mcp_unlinked_identities`, `problems`, `changes`, `webhook_deliveries`, `automation_rules`, 4 `workflow_*`, plus `rivet_core_migrations`), migrations 0001 to 0007 |
| Tests | 92 tests, 354 assertions, passing on PHP 8.2, 8.4 and 8.5 locally; GitHub CI is green on 8.3 and 8.4 |
| Static analysis | PHPStan finds 14 issues at level 5 and 98 at level 8; 39 of the 98 are in the two converters |
| Dependency audit | `composer audit`: no advisories |
| Not measured | Code coverage (no coverage driver in CI), MySQL 8 behavior, PHP 8.5 in CI, performance |
| Not present | `SECURITY.md`, `CONTRIBUTING.md`, issue templates, GitHub Releases, API reference, adapter guide, backward-compatibility check |
| Release channel | Git tags consumed through Composer VCS repositories (not on Packagist). In 0.x a caret like `^0.7` accepts only the same minor, so every 0.x minor needs a constraint bump in both editions |

### Adoption by edition (as of 0.7.1; not re-measured)

Since then RivetMSP moved to `^0.21` and RivetIT to `^0.18`; the module usage in each edition is described on the "Used by" section of every page in [docs/modules/](docs/modules/README.md), which is current as of 2026-10-06.

| Module | RivetIT (beta, pushed to `main`) | RivetMSP (live, switches all on) |
|---|---|---|
| Audit | Used at about 35 call sites through a shim | Used: login events only |
| Redis, health | Used (locks, rate limits, readiness) | Services wired, nothing calls them yet; health endpoint on |
| Cron runner | Used by the Cron Manager | Not used |
| Jobs | Used by the integration worker (no real job types yet) | Wired, no callers |
| MCP | Used (read-only tools) | **Not wired** (needs agent single sign-on columns) |
| ITSM | Used by the Problem and Change pages | Wired, no pages or API |
| Webhooks | Used by the synchronous dispatcher | Wired, no callers |
| Automation | Evaluation only, one caller | Wired, no callers |
| Workflow | Used by the employee lifecycle checklists | Wired, no pages |
| Retention | Hooked into the hourly cron | Hooked into the hourly cron |
| KB converters | Used by KB import | Not used |

The consequence for 1.0: **most of Core has never run under real MSP traffic**, so a 30-day soak proves little until the MSP actually uses the modules. That is why adoption is a milestone of its own (0.10.0) rather than something that happens after release.

### Defects already found and fixed on the way to here

These were found by review, static analysis and testing, and each has a regression test: the job queue crashed on a job's fifth failed attempt (the default `max_attempts`); PDF import failed on
PHP 8.2 because of how the exit code is read; audit rows stored a client-chosen request id; the MSP deleted every log older than today when retention was 0; the MCP link list preselected an agent from an unverified email claim;
the in-app Update used a predictable shared temp directory for composer; the MSP `db.sql` was missing 39 tables and two tables existed only on one server.

## Milestones

### 0.8.0 Quality gates and hygiene (no new features): done (delivered in 0.16.0, docs in the tree after 0.21.0)

Goal: make every later change safe. Nothing user-visible.

| # | Item | Acceptance | Effort |
|---|---|---|---|
| 1 | CI matrix: PHP 8.2 to 8.5, MariaDB 10.11 and 11, MySQL 8.0 and 8.4, Redis 7 **Done (0.16.0).** | All combinations green; any MySQL-only or MariaDB-only DDL fixed or documented | M |
| 2 | Static analysis gate **Done (0.16.0).** Level 6 required; the converters keep a baseline. | PHPStan level 6 required on every PR; a baseline file holds the 39 converter findings; zero new findings; level 8 clean outside the converters | M |
| 3 | Measured coverage **Done (0.16.0).** | pcov in CI; gate at 85% lines outside the converters, report published on every run | S |
| 4 | Converter corpus and security tests **Done (0.16.0), 34 tests.** | At least 10 DOCX and 10 PDF fixtures including malformed, encrypted, oversized, XXE payload, path-traversal entry names and zip-bomb ratios; each limit asserted | L |
| 5 | `declare(strict_types=1)` in the four converter files **Done (0.16.0).** | Output identical on the whole corpus before and after | S |
| 6 | Migration runner hardening **Done (0.16.0).** | Concurrency lock so two updaters cannot race; every migration run twice in a test; a `status()` that lists applied and pending | M |
| 7 | Dependency hygiene **Done** except the weekly scheduled audit (open). | `composer audit` in CI and on a weekly schedule; Dependabot for Composer and Actions; a `--prefer-lowest` job | S |
| 8 | Project files **Done (0.16.0).** | `SECURITY.md` (private reporting, response targets), `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, issue and PR templates, `.gitattributes` to keep tests out of installs | S |
| 9 | Releases **Done (0.16.0).** | A tag builds a GitHub Release with the changelog excerpt | S |
| 10 | Docs, first pass **Done**: README quickstart, adapter guide, one page per module (`docs/modules/`), ADR-004 on versioning (this was planned as ADR-003; ADR-003 became the authorization contract). | README quickstart; "Writing an edition adapter" guide; one page per module (what it owns, its contracts, how it fails); ADR-003 on versioning and the backward-compatibility policy | M |
| 11 | Clear the level-5 findings **Done (0.16.0).** | The 14 findings fixed or justified | S |

Exit: all gates required on `main`; the full matrix green for two weeks of ordinary changes.

### 0.9.0 Complete and freeze the contracts: done in Core (delivered in 0.15.0 to 0.18.1), sign-off and edition parts open

Goal: everything an edition must implement or call is final. After this milestone the API only grows.

| # | Item | Why | Effort |
|---|---|---|---|
| 1 | Public API marking **Done (0.17.0; array shapes and narrowing in the freeze review after 0.21.0).** | Tag every type `@api` or `@internal`; move test helpers out of the runtime package or mark them clearly; remove accidental surface | M |
| 2 | Backward-compatibility check in CI **Done as an advisory CI job (0.17.0);** becomes required after `v1.0.0`. | Fails a PR that breaks the last tag's public API | S |
| 3 | Logging contract **Done (0.17.0).** | Inject a PSR-3 logger; replace the remaining `error_log` calls so editions control where messages go | M |
| 4 | **Decision, then build:** authorization and tenant contract **Decided and built (0.17.0, ADR-003):** minimal `AccessPolicyInterface`, opt-in; no service enforces it yet. | Needed the moment Automation executes actions, ITSM gets approvals, or MCP serves more than reads. Either ship a minimal `AccessPolicyInterface` now or declare it post-1.0 (see decisions) | M |
| 5 | Webhook hardening **Done (0.17.0, 0.18.0, 0.18.1):** URL policy, signature V2, retries through the queue. | A URL policy that blocks loopback, link-local and private ranges by default (today only the edition checks); signed timestamp to prevent replay (new header, old headers kept for existing receivers); retries through the job queue | L |
| 6 | Audit read side **Done (0.17.0).** | An `AuditReader` with filters and pagination so editions stop querying the table directly; documented columns and event-name conventions | M |
| 7 | Job worker **Done (0.15.0, 0.17.0).** | A worker loop with a handler registry, timeouts and the lock; today the editions' worker can only fail jobs, so the queue is unusable for real work | M |
| 8 | Redis authentication and TLS **Core side done (0.17.0);** the installers' secret generation is edition work and open. | Password and TLS options end to end (installer generates the secret, both editions read it). The MSP's connection is hard-coded today, so this needs an MSP change first | M |
| 9 | ~~Retention policy options~~ **Done in 0.8.0** | Separate horizons for audit and delivery logs, and compliance presets with enforced minimums (`Compliance\RetentionPolicy`); both editions get an Administration > Compliance page | S |
| 11 | ~~Compliance status~~ **Done in 0.9.0** | A checklist engine (`Compliance\ComplianceAssessor`, `AttestationStore`, `SnapshotStore`, `ReportRenderer`) scoring ISO/IEC 27001, SOC 2, PCI DSS and HIPAA; each edition supplies its own checks and an Administration > Compliance status page with an auditor export | M |
| 10 | Freeze review **Written (`docs/api-freeze-review.md`); awaits sign-off.** | A written pass over every public method signature, error behavior and table column | S |

Exit: public API list reviewed and committed; compatibility check enforcing it; an alpha of 1.0 behavior with no open design questions.

Remaining: sign-off of the freeze review, the compatibility job required, the editions' Redis secret generation. The conformance kit (0.10.0 item 8) and the decisions below were completed in Core after 0.21.0.

### 0.10.0 Edition adoption (this is what makes a soak meaningful): open, mostly edition work

Goal: every module the editions claim to use is used for real, in both, and both can be installed and upgraded from scratch.

| # | Item | Effort |
|---|---|---|
| 1 | **RivetIT production rollout.** Run the update on the separate production box using the same procedure proven on beta (dump, update, migrations, reload, smoke test) | S |
| 2 | **RivetMSP: first real consumers.** Problem and change pages and API (RivetMSP issues #4 and #5); workflow runbooks for client onboarding (#11); an audit viewer and a retention setting in Administration; webhooks for new events; accounting and RMM sync as job types on the queue | L |
| 3 | **RivetMSP: Redis-backed rate limiting** on the API and the job queue worker running under cron | M |
| 4 | **MCP in the MSP.** After agent single sign-on lands (#14), wire the MCP module and the read tools (#13) | L |
| 5 | **Shims: deprecate, do not break.** Mark the `ITFlow\...` compatibility shims `@deprecated`, switch new code to RivetCore directly, keep the shims through the 1.x line **Decided (ADR-005);** the `@deprecated` tags are edition work. | M |
| 6 | **Installers proven end to end.** Fresh install and upgrade-from-previous-release on clean Ubuntu 24.04 VMs, for both editions, in an automated run | L |
| 7 | **Browser smoke tests** (sign in, tickets, the pages above) on both editions against scratch data | M |
| 8 | **Shared adapter conformance kit** that each edition's CI runs, so a Core release cannot be tagged if an edition adapter would fail it **Kit built in Core (`RivetCore\\Testing`, ADR-009);** each edition must run it in its CI. | M |
| 9 | Fix the Admin Update page on the MSP (it runs `git pull` as the web user against a tree the CLI user owns) and ship the installers' Redis authentication | S |

Exit: every module has at least one production consumer in each edition that uses it; both installers pass the clean-VM run; the browser smoke suite is green.

### RMM module (added 2026-10-07): the endpoint agent becomes a full RMM in Core

RivetIT's built-in endpoint agent (Windows today) moves into RivetCore so RivetIT and RivetMSP share one RMM, and it grows into a complete
remote monitoring and management product. Owner decisions: **Windows and Linux** agents (macOS later, no test Mac), the module is **built into Core's
public API** (so the 1.0 soak restarts after it lands), it is a **module that can be switched on and off** (off by default for new installs, zero cost
when off, with capacity limits and load shedding because it is compute heavy), and Phase 0 is a faithful port of the existing code plus fix-ups, byte
compatible with already enrolled agents. Design: [docs/design/endpoint-module-extraction.md](docs/design/endpoint-module-extraction.md), ADR-010.

| Phase | Content | Size | Issues | Status |
|---|---|---|---|---|
| **0** | Move the PHP server side, the Go agent and its CI into Core; Linux agent; module switch; capacity controls; both editions adopt it | L | [#60 T1 baseline](https://github.com/TheTractorHacker/rivet-core/issues/60) · [#61 T2 skeleton](https://github.com/TheTractorHacker/rivet-core/issues/61) · [#62 T3 pure classes](https://github.com/TheTractorHacker/rivet-core/issues/62) · [#63 T4 device API](https://github.com/TheTractorHacker/rivet-core/issues/63) · [#64 T5 technician/admin](https://github.com/TheTractorHacker/rivet-core/issues/64) · [#65 T6 Go move + Linux](https://github.com/TheTractorHacker/rivet-core/issues/65) · [#66 T9 switch + capacity](https://github.com/TheTractorHacker/rivet-core/issues/66) · [#67 T7 RivetIT adopts](https://github.com/TheTractorHacker/rivet-core/issues/67) · [#68 T8 RivetMSP adopts](https://github.com/TheTractorHacker/rivet-core/issues/68) · [#77 T10a asset page + fleet dashboard (RivetIT)](https://github.com/TheTractorHacker/rivet-core/issues/77) · [#78 T10b (RivetMSP)](https://github.com/TheTractorHacker/rivet-core/issues/78) | **in progress**: T1–T7 and T9 done (Core v1.0.0-rc.5; RivetIT beta runs on it); T8 built on a local RivetMSP branch awaiting owner answers; T10a/T10b (asset page UI) next; docs: [feature list](docs/rmm/FEATURES.md), [asset page redesign](docs/rmm/ASSET_PAGE_REDESIGN.md), [mockup](docs/rmm/mockups/asset-page.html) |
| **Scale** | **5,000 devices supported in beta; 10,000 proven before the RMM leaves beta**: load simulator, cheaper write path, partitioned storage and rollups, optional external metric store, worker scaling, read-path caching, herd control, 24 h soak | L | [#79 S1](https://github.com/TheTractorHacker/rivet-core/issues/79) · [#80 S2](https://github.com/TheTractorHacker/rivet-core/issues/80) · [#81 S3](https://github.com/TheTractorHacker/rivet-core/issues/81) · [#82 S4](https://github.com/TheTractorHacker/rivet-core/issues/82) · [#83 S5](https://github.com/TheTractorHacker/rivet-core/issues/83) · [#84 S6](https://github.com/TheTractorHacker/rivet-core/issues/84) · [#85 S7](https://github.com/TheTractorHacker/rivet-core/issues/85) · [#86 S8](https://github.com/TheTractorHacker/rivet-core/issues/86) · [#87 S9 validation gate](https://github.com/TheTractorHacker/rivet-core/issues/87) | S1, S2 and S7 done in Phase 0 (T9; first measurements in [CAPACITY.md](docs/rmm/CAPACITY.md)); S3–S6, S8, S9 planned. Plan: [docs/rmm/SCALING.md](docs/rmm/SCALING.md) |
| 1 | Software inventory with history, sites/groups/tags, `rmm.*` events, MSP metric sink | L-XL | [#69](https://github.com/TheTractorHacker/rivet-core/issues/69) | **built in Core (v1.0.0-rc.9), not adopted by an edition yet**: software inventory (Windows registry, Linux dpkg/rpm/snap/flatpak; baseline, deltas, history, events), tags and static groups, nine `rmm.*` events (`RmmEventsInterface`), `DatabaseMetricSink` plus `RmmMetricReaderInterface`, per-check history, the live polling document, capability announcement, migration 0018. **Remaining:** normalised hardware tables, site/group/tag at enrollment and auto-tagging, `RmmSitesInterface`, capability filtering of job and check offers, the approved/revoked/module on-off events, the Windows collector on a real Windows host, and edition adoption (UI, wiring). See [FEATURES.md](docs/rmm/FEATURES.md) |
| 2 | Policies and check templates, script library, scheduled scripts, approvals, custom fields | XL | [#70](https://github.com/TheTractorHacker/rivet-core/issues/70) | planned |
| 3 | Alerting maturity: thresholds, maintenance windows, suppression, escalation, more check types | L | [#71](https://github.com/TheTractorHacker/rivet-core/issues/71) | planned |
| 4 | Patch management (Windows Update, Linux apt/dnf, rings, reboot policy, compliance) | XL | [#72](https://github.com/TheTractorHacker/rivet-core/issues/72) | planned (needs Windows test machines) |
| 5 | Software deployment and reporting | XL | [#73](https://github.com/TheTractorHacker/rivet-core/issues/73) | planned |
| 6 | Remote tools: service/process manager, file transfer, embedded Mesh terminal/files | M-L | [#74](https://github.com/TheTractorHacker/rivet-core/issues/74) | planned |
| 7 | macOS agent, Authenticode-signed binaries, MSI | XL | [#75](https://github.com/TheTractorHacker/rivet-core/issues/75) | blocked: no Mac, no signing certificates |
| 8 | Event-log / syslog collection (optional, off by default) | L | [#76](https://github.com/TheTractorHacker/rivet-core/issues/76) | planned |

Effect on 1.0: the RMM module adds public API, so `1.0.0-rc.N` is re-cut after Phase 0 and the 30-day soak starts from that candidate; Phases 1 to 8 are 1.x minor releases.

### 1.0.0-rc.1 and onward (feature freeze and soak)

- Tag `v1.0.0-rc.1` when 0.10.0 is done. Only bug fixes after that; any API change restarts the clock.
- Pin both editions to the release candidate and run them in production for **at least 30 consecutive days** with no open P1 or P2 bug.
- A second security review of everything changed since the first one, plus a threat-model pass over the Redis, webhook and MCP surfaces. External review if budget allows. In progress: `docs/security/review-2026-10.md` and `docs/security/threat-model.md`.
- Performance baselines recorded (audit write, lock acquire, queue claim, retention prune on a large table) so regressions are visible. **Done in draft:** [docs/PERFORMANCE.md](docs/PERFORMANCE.md) and `scripts/bench.php`; to be re-recorded on a quiet machine.
- Upgrade guide for 0.x users (constraint changes, migration notes, deprecations). **Done in draft:** [UPGRADING.md](UPGRADING.md) and [docs/EDITION_CHECKLIST.md](docs/EDITION_CHECKLIST.md); the section covering the release candidate is filled in when it is tagged.

### 1.0.0 release gate (all must be true)

1. Every item above is closed or explicitly deferred with a written reason (status per criterion: [docs/RELEASE_GATE.md](docs/RELEASE_GATE.md)).
2. The CI matrix, static analysis, coverage and compatibility gates are green on the release commit.
3. Thirty days of production use of the release candidate in both editions, no open P1 or P2.
4. Security review: no open HIGH or MEDIUM finding.
5. Documentation complete; `CHANGELOG`, upgrade guide and release notes written.
6. Both editions' constraints moved to `^1.0` in a tested change, rollback tag recorded.
7. Tag `v1.0.0`, publish the GitHub Release. (Packagist submission moved to 1.1 by ADR-006.)

## After 1.0 (the 1.x backlog)

In rough priority order; each ships as a backward-compatible minor.

1. **Metrics** pipeline shared by both editions once they agree on one device and RMM contract (RivetIT's subsystem is about 4,000 lines tied to its RMM tables).
2. **Automation execution** (create ticket, send webhook, notify) on top of the access-policy contract, with dry-run and an audit trail.
3. **ITSM depth:** approvals and a change advisory board, risk scoring, SLA engine with business-hours clocks (the competitor gap noted in the product roadmap).
4. **Workflow depth:** task dependencies, approvals, scheduled due dates.
5. **KB:** media tokens and the HTML importer behind interfaces so both editions can share them.
6. **A provider interface for accounting and payments**, only if a second edition needs one (see the Stripe discussion: not before).
7. **PostgreSQL adapter** investigation, only on a concrete requirement (ADR-001).
8. **Shared AI-assist pipeline** (summaries, reply drafts, triage) with the same audit and approval rules as MCP tools.
9. **Audit tamper-evidence** (hash chaining) if compliance work needs it.

## Test strategy (what runs where)

| Layer | What | Where |
|---|---|---|
| Unit | Pure logic with fakes | Every PR, PHP 8.2 to 8.5 |
| Integration | Real MariaDB and MySQL, real Redis, a local HTTP server for webhooks | Every PR |
| Contract | `DatabaseContractTestCase` run against each edition's adapter | Core CI and each edition's CI |
| Migration | Fresh install, full upgrade chain, run-twice, concurrent run | Every PR; upgrade chains from the last two releases |
| Edition regression | Each edition's existing test scripts against scratch databases | Before every Core release |
| End to end | Installers on clean VMs; browser smoke tests | Before every release candidate |
| Security | Static analysis, `composer audit`, targeted converter and webhook abuse cases, reviews | Every PR plus a review per milestone |

Rules for test data: scratch databases only, schema-only copies of live schemas, throwaway users, no live rows and no copied secrets.

## Release process and policies

- **Semantic versioning.** A breaking change to a public type, to a table's columns or to a migration's meaning is a major bump. Additive tables, columns and methods are minors. Migrations are forward-only, additive and idempotent; a rollback is the edition's database backup plus a version pin.
- **Deprecation.** Deprecated in a minor, kept for the rest of the major, removed only in the next major, with at least one minor of notice; listed in the changelog and `UPGRADING.md` ([ADR-004](docs/architecture/ADR-004-versioning-and-compatibility.md), which supersedes this section).
- **Supported versions.** The latest minor of the current major, plus security fixes for the previous major for 12 months after a new major.
- **PHP.** Core supports every PHP version that still receives security fixes upstream; dropping a version is announced one minor ahead.
- **Branches.** `main` is always releasable; releases are annotated tags; the editions pin a tag, never a branch.
- **Per-release checklist:** changelog, full matrix green, edition regression run on both editions, constraint bump PRs for both editions, production rollout notes, rollback pointers (a branch, never a tag on a commit the live site runs).

## Production rollout plan for each Core release

1. Core tag and GitHub Release.
2. Edition branch bumps the constraint, regenerates the committed vendor files where the edition tracks them, adds any new Core migration to its `db.sql` records.
3. Full test run (the same script used for 0.7.1) against scratch databases built from `db.sql` and from a schema-only copy of the live database.
4. Fresh database dump and rollback branch on the target.
5. Update using the project's updater; verify health, sign-in, and one flow per module in use.
6. Watch the logs and the hourly cron for one cycle.

## Decisions

Resolved on 2026-10-06 and recorded as ADRs (each has a "Reversal cost"):

| # | Decision | Outcome | Record |
|---|---|---|---|
| 1 (#45) | Keep the `ITFlow\...` shims for the life of 1.x? | Yes: deprecated, kept through 1.x, removed in 2.0 | [ADR-005](docs/architecture/ADR-005-itflow-shims-stay-for-1x.md) |
| 2 | Authorization and tenant contract before or after 1.0? | Before: minimal opt-in `AccessPolicyInterface` shipped in 0.17.0 | [ADR-003](docs/architecture/ADR-003-authorization-contract.md) |
| 3 (#46) | Publish on Packagist? | Not yet; editions keep VCS repositories; revisit at 1.1 (changed from "after the first release candidate") | [ADR-006](docs/architecture/ADR-006-packagist.md) |
| 4 (#47) | Webhook signature change | V2 default; V1 legacy kept for all of 1.x (deprecated), removed in 2.0; receivers verify V2 with a 5 minute tolerance | [ADR-007](docs/architecture/ADR-007-webhook-signatures.md) |
| 5 (#48) | Database support promise | MariaDB 10.11/11 and MySQL 8.0/8.4 tested; others best effort; PostgreSQL out of scope for 1.x (#55) | [ADR-008](docs/architecture/ADR-008-database-support.md) |
| 6 | Soak length | 30 days in each production; shorten only with a documented reason (unchanged) | [docs/RELEASE_GATE.md](docs/RELEASE_GATE.md) |
| 7 (#49) | Test helpers: split or keep? | Keep in the package as the public conformance kit; revisit if it grows beyond about 20 files (changed from "split before 1.0") | [ADR-009](docs/architecture/ADR-009-test-helpers-in-package.md) |
| - | Versioning and compatibility policy | semver, `@api`/`@internal`, deprecation rules, supported matrix and support window | [ADR-004](docs/architecture/ADR-004-versioning-and-compatibility.md) |

Open for the maintainer: sign-off of the freeze review, whether to commission an external security review, and what to do about the
case-insensitive collation of `mcp_unlinked_identities` (fix by migration before 1.0 or defer in writing).

## Risks

- **Maintainer bandwidth.** One person owns two products and Core. The plan therefore front-loads automation (CI gates, compatibility checks) so later work does not depend on remembering rules.
- **Dormant modules.** Without real MSP consumers a clean soak is empty. 0.10.0 exists to prevent that.
- **Hidden edition coupling.** Each extraction so far found one (request ids, vendor tracking, file ownership, PHP-version assumptions). The edition regression run before every release is the control.
- **Two installers that diverged.** RivetIT's and RivetMSP's installers handle Redis, vendor files and the schema snapshot differently. The clean-VM runs are the control.
- **Caret behavior in 0.x.** `^0.7` will not pick up `0.8.0`; every 0.x release needs an explicit bump in both editions. This ends at 1.0.

## Effort summary

| Milestone | Items | Rough effort |
|---|---|---|
| 0.8.0 Quality gates | 11 | 2 to 3 weeks |
| 0.9.0 Contracts | 10 | 3 to 4 weeks |
| 0.10.0 Adoption | 9 | 5 to 7 weeks (the MSP pages dominate) |
| RC soak | n/a | 30 days of waiting plus fixes |
| 1.0.0 | gate | 2 to 3 days |
| **Total to 1.0.0** | | **about 3 to 4 months** of focused work, most of it overlapping the soak |
