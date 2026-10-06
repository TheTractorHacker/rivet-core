# RivetCore roadmap to 1.0.0

Written 2026-10-05 from the state of `v0.7.1`. Everything under "Where we are" was measured, not assumed. Estimates are effort for one maintainer
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

### Shipped since 0.7.1 (status at v0.14.0)

The releases after 0.7.1 went to compliance work, not to the milestones below, so the milestone numbers here no longer match the version numbers. Nothing below was dropped; the planned work is still open unless it says otherwise.

| Version | What shipped |
|---|---|
| 0.8.0 | `RetentionPolicy` presets (ISO/IEC 27001, SOC 2, PCI DSS, HIPAA) and a separate audit-trail retention horizon |
| 0.9.0 | Compliance status engine: `ComplianceAssessor`, `AttestationStore`, `SnapshotStore`, `ReportRenderer`, migration 0008 |
| 0.10.0 | `SharedReport`: publish one saved snapshot, reduced, for portal users |
| 0.11.0 | `SubjectCompliance` and `ClientChecklist`: compliance for a customer (subject) of an MSP |
| 0.12.0 | NIST SP 800-171 / CMMC Level 2 as a fifth framework (`Nist171Map`) |
| 0.13.0 | Retention preset `nist171` |
| 0.14.0 | `ResponsibilityStore`: who is responsible for a section or item (the organization or a managed service provider) |

Status update on the branch `quality-gates-1.0` (2026-10-06, not yet merged or released): the CI matrix, PHPStan level 6, coverage gate, `SECURITY.md`,
`CONTRIBUTING.md` and the compatibility job already existed. This branch adds, as docs, tests and tooling only (one small `src/` fix, `RedisAdmin::setMemory`):

| Issue | Delivered | Still open |
|---|---|---|
| #42 performance baselines | `bench/` harness, measured numbers in `docs/PERFORMANCE.md`, optional weekly CI job with generous thresholds | numbers come from a shared, loaded dev box; re-measure on quiet hardware before quoting them |
| #43 upgrade guide | `docs/UPGRADING.md`, verified from 12 old tags by `scripts/verify-upgrade.php` | none |
| #19 docs first pass | quickstart, adapters guide, one page per module, runnable example, ADR-004 (ADR-003 was already taken by the authorization contract); every sample executed in CI | owner sign-off on ADR-004 |
| #38 conformance kit | `tests/Conformance/` with reference in-memory and MySQL runs and negative tests | where it ships to editions (decision #49); no edition runs it yet |
| #30 API freeze | `docs/PUBLIC-API.md`, `tests/api-surface.json`, `api-surface` CI job that fails on any difference | the 10 open design questions in PUBLIC-API.md |
| #28 Redis auth/TLS | real-server tests for helpers, `docs/REDIS.md`; one bug found and fixed | editions' installers generating the secret (outside Core) |
| #41 second security review | `docs/SECURITY-REVIEW-2.md`: threat model, 27 findings (no HIGH/MEDIUM), 36 pinned tests | triage of the LOW findings; external review |
| #37 browser smoke tests | `tests/smoke/` scaffold and RivetIT spec, syntax-checked only | a run against a live scratch instance, and an MSP spec |
| #36 installers on clean Ubuntu 24.04 | `docker/ubuntu24/smoke.sh` and `docs/testing/installers.md` | systemd, nginx, cron and the editions' own installers need real VMs |

Still open from the plan: freezing the public contracts (review decisions above), edition adoption, the 30-day soak and the release gate. The fact table below was measured at 0.7.1 and has not been re-measured since.

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

### Adoption by edition

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

### 0.8.0 Quality gates and hygiene (no new features)

Goal: make every later change safe. Nothing user-visible.

| # | Item | Acceptance | Effort |
|---|---|---|---|
| 1 | CI matrix: PHP 8.2 to 8.5, MariaDB 10.11 and 11, MySQL 8.0 and 8.4, Redis 7 | All combinations green; any MySQL-only or MariaDB-only DDL fixed or documented | M |
| 2 | Static analysis gate | PHPStan level 6 required on every PR; a baseline file holds the 39 converter findings; zero new findings; level 8 clean outside the converters | M |
| 3 | Measured coverage | pcov in CI; gate at 85% lines outside the converters, report published on every run | S |
| 4 | Converter corpus and security tests | At least 10 DOCX and 10 PDF fixtures including malformed, encrypted, oversized, XXE payload, path-traversal entry names and zip-bomb ratios; each limit asserted | L |
| 5 | `declare(strict_types=1)` in the four converter files | Output identical on the whole corpus before and after | S |
| 6 | Migration runner hardening | Concurrency lock so two updaters cannot race; every migration run twice in a test; a `status()` that lists applied and pending | M |
| 7 | Dependency hygiene | `composer audit` in CI and on a weekly schedule; Dependabot for Composer and Actions; a `--prefer-lowest` job | S |
| 8 | Project files | `SECURITY.md` (private reporting, response targets), `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, issue and PR templates, `.gitattributes` to keep tests out of installs | S |
| 9 | Releases | A tag builds a GitHub Release with the changelog excerpt | S |
| 10 | Docs, first pass | README quickstart; "Writing an edition adapter" guide; one page per module (what it owns, its contracts, how it fails); ADR-003 on versioning and the backward-compatibility policy | M |
| 11 | Clear the level-5 findings | The 14 findings fixed or justified | S |

Exit: all gates required on `main`; the full matrix green for two weeks of ordinary changes.

### 0.9.0 Complete and freeze the contracts

Goal: everything an edition must implement or call is final. After this milestone the API only grows.

| # | Item | Why | Effort |
|---|---|---|---|
| 1 | Public API marking | Tag every type `@api` or `@internal`; move test helpers out of the runtime package or mark them clearly; remove accidental surface | M |
| 2 | Backward-compatibility check in CI | Fails a PR that breaks the last tag's public API | S |
| 3 | Logging contract | Inject a PSR-3 logger; replace the remaining `error_log` calls so editions control where messages go | M |
| 4 | **Decision, then build:** authorization and tenant contract | Needed the moment Automation executes actions, ITSM gets approvals, or MCP serves more than reads. Either ship a minimal `AccessPolicyInterface` now or declare it post-1.0 (see decisions) | M |
| 5 | Webhook hardening | A URL policy that blocks loopback, link-local and private ranges by default (today only the edition checks); signed timestamp to prevent replay (new header, old headers kept for existing receivers); retries through the job queue | L |
| 6 | Audit read side | An `AuditReader` with filters and pagination so editions stop querying the table directly; documented columns and event-name conventions | M |
| 7 | Job worker | A worker loop with a handler registry, timeouts and the lock; today the editions' worker can only fail jobs, so the queue is unusable for real work | M |
| 8 | Redis authentication and TLS | Password and TLS options end to end (installer generates the secret, both editions read it). The MSP's connection is hard-coded today, so this needs an MSP change first | M |
| 9 | ~~Retention policy options~~ **Done in 0.8.0** | Separate horizons for audit and delivery logs, and compliance presets with enforced minimums (`Compliance\RetentionPolicy`); both editions get an Administration > Compliance page | S |
| 11 | ~~Compliance status~~ **Done in 0.9.0** | A checklist engine (`Compliance\ComplianceAssessor`, `AttestationStore`, `SnapshotStore`, `ReportRenderer`) scoring ISO/IEC 27001, SOC 2, PCI DSS and HIPAA; each edition supplies its own checks and an Administration > Compliance status page with an auditor export | M |
| 10 | Freeze review | A written pass over every public method signature, error behavior and table column | S |

Exit: public API list reviewed and committed; compatibility check enforcing it; an alpha of 1.0 behavior with no open design questions.

### 0.10.0 Edition adoption (this is what makes a soak meaningful)

Goal: every module the editions claim to use is used for real, in both, and both can be installed and upgraded from scratch.

| # | Item | Effort |
|---|---|---|
| 1 | **RivetIT production rollout.** Run the update on the separate production box using the same procedure proven on beta (dump, update, migrations, reload, smoke test) | S |
| 2 | **RivetMSP: first real consumers.** Problem and change pages and API (RivetMSP issues #4 and #5); workflow runbooks for client onboarding (#11); an audit viewer and a retention setting in Administration; webhooks for new events; accounting and RMM sync as job types on the queue | L |
| 3 | **RivetMSP: Redis-backed rate limiting** on the API and the job queue worker running under cron | M |
| 4 | **MCP in the MSP.** After agent single sign-on lands (#14), wire the MCP module and the read tools (#13) | L |
| 5 | **Shims: deprecate, do not break.** Mark the `ITFlow\...` compatibility shims `@deprecated`, switch new code to RivetCore directly, keep the shims through the 1.x line | M |
| 6 | **Installers proven end to end.** Fresh install and upgrade-from-previous-release on clean Ubuntu 24.04 VMs, for both editions, in an automated run | L |
| 7 | **Browser smoke tests** (sign in, tickets, the pages above) on both editions against scratch data | M |
| 8 | **Shared adapter conformance kit** that each edition's CI runs, so a Core release cannot be tagged if an edition adapter would fail it | M |
| 9 | Fix the Admin Update page on the MSP (it runs `git pull` as the web user against a tree the CLI user owns) and ship the installers' Redis authentication | S |

Exit: every module has at least one production consumer in each edition that uses it; both installers pass the clean-VM run; the browser smoke suite is green.

### 1.0.0-rc.1 and onward (feature freeze and soak)

- Tag `v1.0.0-rc.1` when 0.10.0 is done. Only bug fixes after that; any API change restarts the clock.
- Pin both editions to the release candidate and run them in production for **at least 30 consecutive days** with no open P1 or P2 bug.
- A second security review of everything changed since the first one, plus a threat-model pass over the Redis, webhook and MCP surfaces. External review if budget allows.
- Performance baselines recorded (audit write, lock acquire, queue claim, retention prune on a large table) so regressions are visible.
- Upgrade guide for 0.x users (constraint changes, migration notes, deprecations).

### 1.0.0 release gate (all must be true)

1. Every item above is closed or explicitly deferred with a written reason.
2. The CI matrix, static analysis, coverage and compatibility gates are green on the release commit.
3. Thirty days of production use of the release candidate in both editions, no open P1 or P2.
4. Security review: no open HIGH or MEDIUM finding.
5. Documentation complete; `CHANGELOG`, upgrade guide and release notes written.
6. Both editions' constraints moved to `^1.0` in a tested change, rollback tag recorded.
7. Tag `v1.0.0`, publish the GitHub Release; submit to Packagist.

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
- **Deprecation.** A deprecated API stays for at least two minor releases and is listed in the changelog.
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

## Decisions needed from you

1. **Keep the `ITFlow\...` shims for the life of 1.x?** Recommended: yes, deprecated but supported; removing them gains little and risks the 35 audit call sites.
2. **Authorization and tenant contract: before or after 1.0?** Recommended: a minimal contract in 0.9.0 only if the first consumer (automation actions or MCP writes) is also in 1.0; otherwise post-1.0.
3. **Publish on Packagist?** Recommended: after the first release candidate, so tags are stable and consumers get normal Composer behavior.
4. **Webhook signature change.** Adding a signed timestamp needs receivers to opt in. Recommended: send both the old and new headers for the whole of 1.x.
5. **Database support promise.** Recommended: MariaDB 10.11+ and MySQL 8.0+ in the matrix; PostgreSQL stays out of 1.0.
6. **Soak length.** Recommended: 30 days in each production; shorten only with a documented reason.
7. **Test helpers.** Keep `Testing\DatabaseContractTestCase` in the runtime package, or split to a `rivet-core-testing` dev package? Recommended: split before 1.0.

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
