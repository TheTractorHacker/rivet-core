# Reporting and Power BI: stable views, a read-only account, a feed, an edition page

Status: **phases 1 and 2 of [#91](https://github.com/TheTractorHacker/rivet-core/issues/91) are implemented** (1.0.0-rc.43, migration 0045 and PostgreSQL migration `0002_reporting_views`). Phases 3 to 5 are planned below. Related: [#89](https://github.com/TheTractorHacker/rivet-core/issues/89) ([rmm-data-store.md](rmm-data-store.md)). Operator guide: [../reporting/POWER_BI.md](../reporting/POWER_BI.md). Data dictionary: [../reporting/DATA_DICTIONARY.md](../reporting/DATA_DICTIONARY.md).

## 1. Goal

Let an administrator build Power BI (or any BI) reports on RivetIT / RivetMSP data without reading our schema, without a secret ever reaching a report, and without one client's rows reaching another client's report. Three ways in, in priority order: reporting views + a read-only account (this release), a read-only feed with scoped tokens (phase 3), CSV/Excel exports (phase 5). Plus documents, samples, a settings page in the editions (phase 4) and a starter `.pbit` (phase 5).

## 2. What Core owns, and what it does not

Found by listing every table Core creates (the 44 migrations before this one) and asking: is this reporting data, does it carry or reach a client, does it hold a secret?

| Core table(s) | Reporting data? | View | Decision |
|---|---|---|---|
| `endpoint_agent_devices` + `rmm_device_state` + `rmm_patch_scans` + `rmm_hypervisor_hosts` | yes | `rpt_devices` | Allow-list. Not exposed: `token_hash`, install id, machine GUID, serial, MAC addresses, last IP, logged-in user, inventory and metrics JSON, mesh and enrolment fields |
| `rmm_group_devices` + `rmm_groups`, `rmm_device_tags` + `rmm_tags` | yes | `rpt_device_groups`, `rpt_device_tags` | Names are admin labels |
| `rmm_metric_hourly`, `rmm_metric_daily` (hot) | yes | `rpt_device_health_hourly`, `_daily` | Keyed by **asset**; see 3.2 |
| `rmm_metric_latest` (hot) | not now | none | Current values are in the hourly tier; adding a view later is additive |
| `rmm_alert_meta` | yes | `rpt_alerts` | The alert text, group key, escalation state and user ids are not exposed |
| `endpoint_agent_checks` + `rmm_check_eval` | yes | `rpt_check_status` | Status, tier, flapping; detail text not exposed |
| `endpoint_agent_check_history` (hot) | yes, bounded | `rpt_check_results` | Last 31 days, detail not exposed |
| `rmm_patch_scans` + `rmm_patch_available` + `endpoint_agent_checks` | yes | `rpt_patch_compliance`, `rpt_patch_updates` | The built-in verdict plus the raw counts and ages so a report can use its own threshold |
| `rmm_patch_history` (hot) | yes | `rpt_patch_history` | Result code, no detail text, no job or user id |
| `rmm_device_software` | yes | `rpt_software_inventory` | Installed only (`removed_at IS NULL`); software key (a digest) not exposed |
| `rmm_software_history` (hot) | yes | `rpt_software_changes` | |
| `rmm_hypervisor_hosts`, `rmm_hypervisor_vms` | yes | `rpt_hypervisor_hosts`, `rpt_hypervisor_vms` | Tags, ignore reason, host error text not exposed |
| `endpoint_agent_change_records` (hot) | counts and types only | `rpt_change_events` | No target, item, old/new value, summary, hash, actor or process: the change monitor's values can contain user names, paths and command lines (CHANGE_MONITOR.md) |
| `audit_events` | summary only | `rpt_audit_events` | Event type, action, entity type, actor id, time. No summary, metadata, IP, user agent or entity id. **No client**: administrators only |
| `endpoint_agent_log_events` (hot) | no | none | Free text; a count-per-level summary could come later |
| `rmm_package_history`, `rmm_hypervisor_vm_history` (hot) | later | none | Small and easy to add (additive) |
| `changes`, `problems` (ITIL records) | no | none | No client, no device; the edition's change/problem pages own the relationships |
| Settings, tokens, enrolment, policies, scripts, jobs, approvals, credentials | **never** | none | Secrets and configuration |

**Not in Core:** clients (names), sites, assets, **tickets, SLA policies, invoices**. These tables belong to RivetIT / RivetMSP. So `rpt_tickets` and `rpt_sla` cannot be Core views; section 7 lists the edition views to add.

## 3. Design

### 3.1 The contract

* `ReportingCatalog` is the one list: for each view the name, store, grain, purpose, refresh, client scope, columns (name, logical type, meaning), keys, and the SQL for each dialect. `ReportingSchema` turns it into `CREATE OR REPLACE VIEW` statements with an **explicit column list** (so a renamed expression cannot rename a column), never `SELECT *`.
* **Frozen columns:** `tests/Fixtures/reporting_views.json` is compared with the catalog on every run; adding a column is a deliberate edit to the catalog, the fixture and a migration. The data dictionary and the Power Query samples are generated from the catalog and a test fails when they are stale.
* **No secrets:** a deny-list scan (token, secret, password, hash, key, credential, cookie, signature, private, salt, cipher, sealed, otp, seed, `_enc`; plus free-text and personal-data name parts such as output, script, command, detail, message, summary, ip, mac, serial, json) over every exposed column name **and** every base column the SQL reads. The three base columns that carry the word "key" because they identify a metric, check, software item or VM (`metric_key`, `check_key`, `software_key`, `vm_key`) are the only listed exceptions and are renamed `*_name` when exposed. A second test plants a marker in every excluded column and proves it never appears in any row of any view.
* **UTC** everywhere, flags as 0/1, durations in seconds.

### 3.2 Two stores, one set of names

The six views over hot tables exist in the control database (over the control copy of the hot tables) **and** in the PostgreSQL store. Same names, and the PostgreSQL ones are a column subset:

* The hot PostgreSQL tables carry `client_id` only for logs, change records and the patch/package history (migration 0043). The metric tables are keyed by **asset** (`asset_id`), check and software history by `device_id`, and the store has no device table to join (that was the point of the split). So:
  * MariaDB / MySQL views: `device_id` and `client_id` joined from the device table. Metrics resolve asset to device through the **newest device of the asset** (`MAX(device_id) GROUP BY asset_id`, a small derived table), because an asset can have been re-enrolled; a plain join would double the metric rows. Metrics of an asset no device owns are not published.
  * PostgreSQL views: `rpt_device_health_*` carry `asset_id`, `rpt_check_results` and `rpt_software_changes` carry `device_id`, `rpt_change_events` and `rpt_patch_history` carry `client_id`. Power BI relates them to `rpt_devices` in the model (needs two connections) and scopes by client there.
* **Decision for Blake:** this is the one place where "client_id on every row" is not met. The fix is to denormalise `client_id` into `rmm_metric_hourly/daily`, `endpoint_agent_check_history` and `rmm_software_history` the way 0043 did for logs, which touches the writers of the hottest tables, the dual-write, the verifier and the copy; I did not do that inside a reporting change. Until then the PostgreSQL copy cannot be filtered by client inside the database (phase 3 feed: the service adds the client's device ids from the control database).
* Reports on a install that moved to PostgreSQL must not use the control copy of the hot views (it is a frozen leftover until `--purge-old`).

### 3.3 Row semantics worth knowing

* `rpt_devices.status` mirrors the read model: `online` when the last check-in is within `offline_after_s`, `offline` until `stale_after_s`, then `stale`; `never`, `retired`, `revoked`. It uses the module's settings and `UTC_TIMESTAMP()`, so it is as fresh as the query.
* `rpt_alerts.duration_s` runs to now while an episode is open (`UTC_TIMESTAMP()`).
* `rpt_change_events.client_id` is the record's own client, else the device's current client (older rows written before 0043 have none).
* `rpt_check_results` is bounded to 31 days *in the view* (the table keeps 7 by default) so that a view over a table someone configured to keep a year is still safe to open.

### 3.4 Privileges

* MariaDB / MySQL views are `SQL SECURITY DEFINER` (the default) and owned by the account that ran the migration; the reporting account has `SELECT` on the views **only**. PostgreSQL views run with the owner's rights (`security_invoker` off, the default); the role has `SELECT` on the views only. Neither needs a grant on any base table. A view over joins is not updatable; the single-table views (audit, patch history, change events on PostgreSQL) are auto-updatable in principle, and are protected by the absence of `INSERT`/`UPDATE`/`DELETE` grants, which the role tests exercise.
* `ReportingRole` returns SQL; it never executes anything and never stores a password (`generatePassword()` returns one for the caller). Inputs are validated against strict patterns before they are placed in SQL. Re-running the statements rotates the password and removes grants added by hand.
* MariaDB / MySQL: `REQUIRE SSL` by default, `MAX_USER_CONNECTIONS 5`. PostgreSQL: `NOSUPERUSER ...`, `CONNECTION LIMIT 5`, `default_transaction_read_only`, `statement_timeout 120s`, `TimeZone UTC`; TLS is a `pg_hba.conf` `hostssl` matter and is documented, not enforced by SQL. MariaDB and MySQL have no per-account statement timeout: use the server-wide `max_statement_time` / `max_execution_time` if reports must be capped.
* The migration needs `CREATE VIEW` on the control database (every edition's application account has it: it creates tables). If a hardened install lacks it the migration fails loudly on the first statement and the update stops there: grant it, re-run.

### 3.5 Threats considered

| Threat | Control | Test |
|---|---|---|
| A secret reaches a report | Allow-list columns; deny-list scan of names and base columns; planted-marker test | `ReportingContractTest`, `ReportingViewsTest::testNothingSecret...`, PostgreSQL twin |
| A client sees another client's rows | `client_id` on every client-scoped row; per-client queries return exactly the client's rows; PostgreSQL rows with no client belong to nobody's filter | `ReportingViewsTest::testEveryClientScopedView...`, PostgreSQL twin |
| The reporting login writes or reads the base tables | Grants on views only; no `GRANT OPTION`; `REVOKE ALL` first | `ReportingRoleTest`, `ReportingPostgresRoleTest` (MariaDB 10.11, MySQL 8.4, PostgreSQL 18) |
| SQL injection through the role helper | Strict patterns on every identifier and the password | `ReportingRoleSqlTest` |
| A dashboard scans the raw history and hurts the database | Time-bounded check view; indexes used when filtered; statement timeout (PostgreSQL); guide says filter by date | `ReportingPerformanceTest`, `ReportingPostgresPerformanceTest` |
| The credential or the port is exposed | `REQUIRE SSL`, `hostssl`, host part, connection limit, guide's warnings; phase 4 page warns | docs |
| The audit view leaks to a client | No `client_id`, `clientScoped = false`, the only such view, asserted by test; docs say administrators only | `ReportingContractTest::testViewDefinitionsAreConsistent` |

## 4. Phase 3: the reporting feed

For installs that will not open a database port, and the base for per-client isolation that RLS cannot give (RLS protects viewers in the Power BI service, not the credential).

* **Service**, `RivetCore\Reporting\Feed\ReportingFeed`, with the same catalog: `list(view, filters, cursor, limit)` returns JSON rows with exactly the catalog columns. No SQL from the caller: a view name from the catalog, filters from a typed set (`client_id`, `device_id`, a time column `from`/`to`), keyset paging on the view key (never `OFFSET`), `limit` 1..10,000, a maximum time span per view (hot views: 31 days per request), `statement`/`row` caps and a timeout.
* **Tokens:** table `rmm_reporting_tokens` (migration 0046): `token_id`, `token_selector` (12 chars) and `token_digest` (SHA-256 of a 256-bit random secret; the secret is shown once and never stored), `label`, `scope_clients` (JSON list of client ids, or `all`), `scope_views` (allow-list), `expires_at` (required, maximum 1 year), `created_by`, `created_at`, `last_used_at`, `revoked_at`, `rate_per_min`. Constant-time comparison; a lookup by selector. A token can only be **narrower** than its creator: a technician scoped to some clients cannot create a token for others (the edition passes the creator's visible clients; the service intersects and refuses a wider scope).
* **Tenant enforcement in the service**, never from the request: the `client_id` filter comes from the token. For PostgreSQL views without `client_id` the service first resolves the client's device ids/assets from the control database and passes them as a bounded list; a client with too many devices is chunked.
* **Rate limit and audit:** the existing token-bucket store per token; `reporting.token.created/rotated/revoked` audit events with the creator and the scope (never the secret), `reporting.feed.read` aggregated per token and hour (count, rows, views) so a busy dataset does not flood `audit_events`.
* **Transport:** `GET /reporting/v1/{view}` with `Authorization: Bearer`, JSON (or CSV with `Accept: text/csv`), `ETag`/`If-None-Match` and `Last-Modified` where the view has a freshness column, an OpenAPI document (`docs/reporting/openapi-feed.yaml`). Power BI reads it with `Web.Contents` + the `Authorization` header and a `List.Generate` pager (samples in `docs/reporting/samples/power-query/feed/`). Verified only in the sandbox: Power BI service refreshes of `Web.Contents` with dynamic URLs need a fixed base URL with `RelativePath` (documented in the samples).
* **Tests:** the tenant matrix (token for client 7 gets no client 9 row, with a forged `client_id`, a forged view name, a wider scope than the creator, an expired and a revoked token), paging with concurrent inserts, the row cap, and the rate limit.

## 5. Phase 4: the edition settings page (beta only)

Reporting / Power BI page in both editions: view list and descriptions from `ReportingCatalog`; "Create / rotate reporting account" (runs the `ReportingRole` statements with the edition's admin database credentials, shows host, port, database, user and the generated password **once**, never stores it; an audit row); ready-to-copy connection strings and the M snippets for the chosen source (generated from the samples with the real host and database filled in); a connection test (connect as the reporting account and `SELECT 1 FROM rpt_devices LIMIT 1`); per-client scoping help; token management for phase 3 (create, list, rotate, revoke; shown once); warnings about TLS and exposed ports (detect `host = %`, no TLS on the server, and a non-private bind address); an audit row for creation and use. Ready-to-paste prompts: [reporting-edition-prompts.md](reporting-edition-prompts.md).

## 6. Phase 5: starter `.pbit`

Built **once by Blake in Power BI Desktop** from `docs/reporting/samples/STARTER_REPORT.md` (cannot be generated or verified without Power BI): six pages, the model of POWER_BI.md section 5, parameters `ServerName`, `DatabaseName`, `RangeStart`, `RangeEnd`, no data, no credentials. Committed as `docs/reporting/starter.pbit` with the Power BI Desktop version it was saved with. Plus a worked example (screenshots of the report on a scratch install with synthetic data) and the CSV/Excel exports of the same datasets.

## 7. Edition views (the edition adds these; Core cannot)

The DDL below is **proposed** against RivetIT's `db.sql` (the column names were read from it, read-only) and must be checked in each edition. Same rules: allow-list, `client_id` on every row, no free text, stable names, UTC. RivetIT writes its ticket timestamps with `current_timestamp()` (the database server's time zone, not necessarily UTC), so the edition must either run its database in UTC or convert in the view (`CONVERT_TZ(col, @@session.time_zone, '+00:00')` with an explicit named zone); the data dictionary says UTC and a test must hold the edition to it.

```sql
CREATE OR REPLACE VIEW rpt_clients (client_id, client_name, created_at, archived_at) AS
  SELECT client_id, client_name, client_created_at, client_archived_at FROM clients;

CREATE OR REPLACE VIEW rpt_sites (site_id, client_id, site_name, archived_at) AS
  SELECT location_id, location_client_id, location_name, location_archived_at FROM locations;

CREATE OR REPLACE VIEW rpt_assets (asset_id, client_id, site_id, asset_type, asset_name, created_at, archived_at) AS
  SELECT asset_id, asset_client_id, asset_location_id, asset_type, asset_name, asset_created_at, asset_archived_at FROM assets;

CREATE OR REPLACE VIEW rpt_tickets (ticket_id, client_id, site_id, asset_id, priority, status_name, is_open, created_at, first_response_at, resolved_at, closed_at,
                                    sla_response_due_at, sla_resolution_due_at, sla_response_met, sla_resolution_met, first_response_s, resolution_s, csat_rating) AS
  SELECT t.ticket_id, t.ticket_client_id, t.ticket_location_id, NULLIF(t.ticket_asset_id, 0), t.ticket_priority, s.ticket_status_name,
         CASE WHEN t.ticket_closed_at IS NULL AND t.ticket_resolved_at IS NULL THEN 1 ELSE 0 END,
         t.ticket_created_at, t.ticket_first_response_at, t.ticket_resolved_at, t.ticket_closed_at, t.ticket_sla_response_due, t.ticket_sla_resolution_due,
         t.ticket_sla_response_met, t.ticket_sla_resolution_met,
         TIMESTAMPDIFF(SECOND, t.ticket_created_at, t.ticket_first_response_at), TIMESTAMPDIFF(SECOND, t.ticket_created_at, t.ticket_resolved_at), t.ticket_csat_rating
  FROM tickets t LEFT JOIN ticket_statuses s ON s.ticket_status_id = t.ticket_status
  WHERE t.ticket_archived_at IS NULL;   -- no subject, details, replies, contact, billing or vendor columns

CREATE OR REPLACE VIEW rpt_alert_tickets (alert_id, client_id, ticket_id) AS
  SELECT id, client_id, ticket_id FROM rmm_alerts WHERE ticket_id IS NOT NULL;   -- not message, raw_data_json or the Tactical alert id

CREATE OR REPLACE VIEW rpt_sla (policy_id, policy_name, low_response_min, low_resolution_min, medium_response_min, medium_resolution_min, high_response_min, high_resolution_min) AS
  SELECT policy_id, policy_name, policy_low_response, policy_low_resolution, policy_medium_response, policy_medium_resolution, policy_high_response, policy_high_resolution
  FROM sla_policies WHERE policy_archived_at IS NULL;
```

The edition adds the same contract tests (frozen columns fixture, deny-list, client scope) for these views and grants them to the reporting account in the same statements (`ReportingRole::mariadbStatements(..., views: [...])` takes only Core view names, so the edition appends its own `GRANT SELECT` lines).

## 8. Open points and decisions

1. Denormalise `client_id` into the PostgreSQL metric, check-history and software-history rows (section 3.2). Recommended before phase 3, so the feed and the PostgreSQL views can filter by client in the database.
2. `rpt_audit_events` is the one unscoped view. Keep it in Core (administrators), or move it to the edition's settings page only. Default: keep, never granted to per-client accounts by the page.
3. MariaDB / MySQL have no per-account statement timeout; the guide recommends a server-wide limit. Phase 3's feed adds its own.
4. Whether `rpt_log_events` (counts per level and day, no text) is wanted. Not built.
5. The migration number: 0045 here; if another change took 0045 first, renumber (the class name and `id()`), update `CoreMigrations::all()` and `tests/Integration/MigrationAndAuditTest.php`.
