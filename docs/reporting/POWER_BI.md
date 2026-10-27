# Reporting with Power BI

A practical guide for the person who runs RivetIT / RivetMSP and wants charts of their fleet in Power BI (or Excel, Grafana, any tool that speaks SQL). You do not need to be a database expert. Phase 1 and 2 of [#91](https://github.com/TheTractorHacker/rivet-core/issues/91).

> **What was and was not verified.** The views, their columns, the read-only account, tenant separation, the generated SQL and the query plans are tested against real MariaDB 10.11, MySQL 8.4 and PostgreSQL 18 (see "Verification" at the end). **Power BI Desktop and the Power BI service were not available when this was written**, so every Power BI menu name, connector option, limit and behaviour below is from Microsoft's documentation and experience, not observed here; the Power Query (M) files and the DAX file are structure-checked (balanced brackets, column names exist) but never executed. Places where this matters are marked **[unverified in Power BI]**. Microsoft changes menus and limits: when this guide and your screen disagree, trust your screen and tell us.

## Contents

1. [What you can connect to](#1-what-you-can-connect-to)
2. [Before you start: three decisions](#2-before-you-start-three-decisions)
3. [Create the read-only reporting account](#3-create-the-read-only-reporting-account)
4. [Connect Power BI Desktop](#4-connect-power-bi-desktop)
5. [Build the model](#5-build-the-model)
6. [Import or DirectQuery](#6-import-or-directquery)
7. [Incremental refresh](#7-incremental-refresh)
8. [Publish, gateway and scheduled refresh](#8-publish-gateway-and-scheduled-refresh)
9. [Security: TLS, network exposure, least privilege](#9-security-tls-network-exposure-least-privilege)
10. [Row-level security per client](#row-level-security-per-client)
11. [Performance](#11-performance)
12. [Time zones](#time-zones)
13. [Troubleshooting](#13-troubleshooting)
14. [Verification](#14-verification)

## 1. What you can connect to

| Way in | Status | Good for |
|---|---|---|
| **Reporting views over a direct database connection** (`rpt_` views) | **Available now** (this guide) | Everything. Import for history and dashboards, DirectQuery for a live current-state page. Needs a database port reachable from Power BI (or the on-premises data gateway). |
| **Reporting feed** (read-only JSON over HTTPS with scoped, expiring tokens) | Planned, phase 3 | Installs that must not open a database port; one token per client. Not available yet. |
| **CSV / Excel exports** | Planned, phase 5 of the edition work; by hand now | A quick start. Today: export a view with your SQL client (below) and use *Get data > Text/CSV*. |

A CSV by hand (add TLS options to match your server, see section 9):

```bash
# MariaDB / MySQL
mysql --host=DB_HOST --user=rivet_reporting --password --ssl-mode=VERIFY_IDENTITY --batch \
  --execute="SELECT * FROM rpt_devices" itflow > devices.tsv      # MariaDB: --ssl-verify-server-cert instead of --ssl-mode
# PostgreSQL
psql "host=DB_HOST dbname=rivet_rmm user=rivet_reporting sslmode=verify-full" \
  -c "\copy (SELECT * FROM rpt_devices) TO 'devices.csv' CSV HEADER"
```

The data itself: seventeen views, all described in the [data dictionary](DATA_DICTIONARY.md): devices, groups and tags, health metrics (hourly and daily), alerts, check status and results, patch compliance, available updates and patch history, installed software and software changes, hypervisor hosts and VMs, configuration change events, and an audit summary. Client, site and asset **names**, tickets and SLAs belong to the edition and come with it (phase 4); until then, type a `client_id, client_name` lookup table into Power BI.

## 2. Before you start: three decisions

1. **Where is your RMM data?** With the default (`data_store = mariadb`) everything is in one database and Power BI needs **one** connection. If you moved the history to PostgreSQL (`data_store = postgres`), the live history views are in PostgreSQL and the rest are in MariaDB / MySQL: **two connections**, related in the model on `device_id` / `asset_id`. Ask the administrator (it is the "RMM data store" setting).
2. **How will Power BI reach the database?** From Power BI Desktop on your own computer: the database must accept your computer's address. From the Power BI service (the web, scheduled refresh): through the **on-premises data gateway** installed on a server that can reach the database, which is the recommended and the safest way: the database never needs to be reachable from the internet.
3. **Which account?** A **dedicated, read-only reporting account that can read the `rpt_` views and nothing else**. Never put the application's database password, or an administrator's, into a `.pbix` file or a data source.

## 3. Create the read-only reporting account

RivetCore generates the SQL; **it does not run it and never stores the password**. In the editions, the Reporting / Power BI settings page (phase 4) runs it for you with a temporary admin credential and shows the password once. Until then, an administrator runs the statements once.

From PHP (any script that has RivetCore loaded; this prints the statements, it runs nothing):

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
use RivetCore\Reporting\ReportingRole;

$password = ReportingRole::generatePassword();           // show it once, never store it
// MariaDB / MySQL: the control database
foreach (ReportingRole::mariadbStatements('itflow', $password, 'rivet_reporting', '10.0.5.20', requireTls: true) as $sql) { echo $sql, ";\n"; }
// PostgreSQL (only when data_store = postgres): the RMM store
foreach (ReportingRole::postgresStatements('rivet_rmm', $password, 'rivet_reporting') as $sql) { echo $sql, ";\n"; }
foreach (ReportingRole::notes('postgres') as $note) { echo "-- $note\n"; }
```

What it produces on MariaDB / MySQL (the password is a placeholder here):

```sql
CREATE USER IF NOT EXISTS 'rivet_reporting'@'10.0.5.20' IDENTIFIED BY '<generated>';
ALTER USER 'rivet_reporting'@'10.0.5.20' IDENTIFIED BY '<generated>' REQUIRE SSL WITH MAX_USER_CONNECTIONS 5;
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'rivet_reporting'@'10.0.5.20';
GRANT SELECT ON `itflow`.`rpt_devices` TO 'rivet_reporting'@'10.0.5.20';
-- ... one GRANT SELECT per rpt_ view
```

and on PostgreSQL:

```sql
DO $do$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'rivet_reporting') THEN CREATE ROLE "rivet_reporting" LOGIN; END IF; END $do$;
ALTER ROLE "rivet_reporting" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION CONNECTION LIMIT 5 PASSWORD '<generated>';
ALTER ROLE "rivet_reporting" SET default_transaction_read_only = on;
ALTER ROLE "rivet_reporting" SET statement_timeout = '120s';
ALTER ROLE "rivet_reporting" SET idle_in_transaction_session_timeout = '60s';
ALTER ROLE "rivet_reporting" SET TimeZone = 'UTC';
REVOKE ALL ON ALL TABLES IN SCHEMA "public" FROM "rivet_reporting";
GRANT CONNECT ON DATABASE "rivet_rmm" TO "rivet_reporting";
GRANT USAGE ON SCHEMA "public" TO "rivet_reporting";
GRANT SELECT ON "public"."rpt_device_health_hourly" TO "rivet_reporting";   -- ... one per hot view
```

* Run them as the database administrator (MariaDB / MySQL: a user with `CREATE USER` and `GRANT`; PostgreSQL: a role that may create roles and that owns the views).
* **Why this is safe:** the views run with their owner's rights, so the reporting account needs **no** access to the tables behind them. It cannot read a password hash, a token or the audit log, and it cannot write anything: tests create the account from this SQL on MariaDB, MySQL and PostgreSQL and check that `SELECT` on every base table and every `INSERT`/`UPDATE`/`DELETE`/DDL is refused.
* Running the statements again **rotates the password** and re-applies exactly the listed grants (a privilege someone added by hand is removed).
* The host part (`'10.0.5.20'`) should be the gateway's address. Use `%` only on a private network behind a firewall.
* Keep the account that ran the RivetCore migration: the views belong to it (MariaDB / MySQL `DEFINER`); if that account is deleted the views stop working until the migration is run again by another account.

## 4. Connect Power BI Desktop

### MySQL / MariaDB connector

1. Install **MySQL Connector/NET** on the computer (and on the gateway server). Power BI's MySQL connector needs it. It works with MariaDB too. **[unverified in Power BI]** which version; use a current 8.x.
2. *Home > Get data > MySQL database*. Server: `db.example.com:3306` (host and port). Database: your control database (for example `itflow`). Data Connectivity mode: **Import** (see section 6).
3. Credentials on the **Database** tab: user `rivet_reporting` and its password. Leave "Encrypt connection" ticked; if the server uses a private certificate, the computer must trust that certificate's authority (install it in Windows' trusted root store), otherwise you will see a certificate error.
4. The Navigator lists the `rpt_` views (nothing else, because the account sees nothing else). Tick the ones you need, then *Transform data* (not *Load* yet) so you can set types and filters.

### PostgreSQL connector (only when `data_store = postgres`)

1. *Home > Get data > PostgreSQL database*. Server: `db.example.com:5432`. Database: `rivet_rmm`. Import or DirectQuery. Current Power BI Desktop includes the Npgsql driver; on an old version or on a gateway server install Npgsql. **[unverified in Power BI]**
2. Credentials: *Database* tab, user `rivet_reporting`. Tick "Encrypt connection" and make the computer trust the server's certificate.
3. Pick the six hot views (`rpt_device_health_hourly`, `rpt_device_health_daily`, `rpt_check_results`, `rpt_change_events`, `rpt_patch_history`, `rpt_software_changes`). The other eleven come from the MariaDB / MySQL connection above.

### Ready-to-paste queries

Instead of clicking, open *Home > Transform data > New source > Blank query > Advanced editor* and paste one of the files in [samples/power-query](samples/power-query/): one per view and per source (`mysql-mariadb/` and `postgresql/`). Create two text parameters first (*Manage parameters > New*): `ServerName` (`host:3306` or `host:5432`) and `DatabaseName`. The files do the navigation, set the column types, and (for the history views) contain the commented incremental-refresh filter of section 7. They are generated from the view catalog, so they always match the columns. See [samples/README.md](samples/README.md).

## 5. Build the model

1. Load the views as tables with the **view names** (`rpt_devices`, ...): the DAX measures in [samples/measures.dax](samples/measures.dax) use exactly those names.
2. Relationships (*Model view*), all **one to many, single direction** from `rpt_devices` to the other table:
   * `rpt_devices[device_id]` to `device_id` of: `rpt_device_groups`, `rpt_device_tags`, `rpt_alerts`, `rpt_check_status`, `rpt_check_results`, `rpt_patch_compliance`, `rpt_patch_updates`, `rpt_patch_history`, `rpt_software_inventory`, `rpt_software_changes`, `rpt_change_events`, `rpt_hypervisor_hosts`, `rpt_hypervisor_vms[host_device_id]`.
   * Health metrics: on **MariaDB / MySQL** `rpt_devices[device_id]` to `rpt_device_health_hourly[device_id]` and `_daily`. On **PostgreSQL** those two tables have `asset_id` only: add the helper query [samples/power-query/model/DeviceAssets.pq](samples/power-query/model/DeviceAssets.pq) (one row per asset with its newest device and client) and relate `DeviceAssets[asset_id]` to `asset_id` of the two tables, and `DeviceAssets[device_id]` to `rpt_devices[device_id]`.
   * A **date table**: *Modeling > New table* `DimDate = CALENDAR(DATE(2025,1,1), TODAY()+1)`, mark it as a date table, and relate `DimDate[Date]` to the date of the history you chart (for example a calculated column `rpt_alerts[opened_date] = DATE(YEAR([opened_at]), MONTH([opened_at]), DAY([opened_at]))`). Keep these relationships inactive for tables you slice some other way and use `USERELATIONSHIP`.
3. Hide the key columns from the report view and set `client_id` to *Don't summarize*.
4. Paste the measures from [samples/measures.dax](samples/measures.dax) (each is a *New measure*). A step-by-step first report (pages, visuals, slicers) is in [samples/STARTER_REPORT.md](samples/STARTER_REPORT.md).

## 6. Import or DirectQuery

| | **Import** (default) | **DirectQuery** |
|---|---|---|
| Where the data is | Copied into the Power BI file / dataset, refreshed on a schedule | Stays in your database; every visual runs SQL when you open or click |
| Speed for the viewer | Fast (in memory) | As fast as your database answers; every slicer click is a query |
| Freshness | As old as the last refresh (up to 8 times a day on a Pro workspace, more on Premium **[Microsoft limits change]**) | Live |
| Load on the RivetIT database | One refresh at a time, at a time you choose | Every viewer, every click: can be heavy and the database port must always be reachable |
| History longer than the tables keep | Yes (incremental refresh keeps what the database already deleted) | No: you see only what is in the table |
| DAX | Everything | Some functions are restricted; complex measures become many queries |

**Recommendation.** Import for everything. Use DirectQuery only for a small "right now" page (`rpt_devices`, `rpt_alerts` open) and never for `rpt_check_results` or the hourly health table. A *composite model* (Import for history, DirectQuery for the live tables) is possible. **[unverified in Power BI]**

**Query folding.** Power Query tries to turn your filter steps into SQL that runs in the database ("folds") so only the needed rows travel. The Power Query files in `samples/` do only folding-friendly steps (navigation, type change, filter by date). Right-click a step and check that *View Native Query* is not greyed out. If it is greyed out, a step could not be folded: reorder it after the date filter or remove it. Do your date filters **first**. Calculated columns in Power Query that use text functions or `DateTime.LocalNow()` often stop folding; do those in DAX.

## 7. Incremental refresh

For the large append-only history tables, Import with **incremental refresh** loads only the newest days on each refresh and keeps the rest: it is also the way to keep history **longer than RivetIT keeps it** (the hourly tier is 14 days by default, check history 7 days).

Applies to: `rpt_device_health_hourly` (`hour_start`), `rpt_device_health_daily` (`day_start`), `rpt_check_results` (`reported_at`), `rpt_patch_history`, `rpt_software_changes`, `rpt_change_events` (`occurred_at`), `rpt_audit_events` (`created_at`). **Not** for tables whose old rows change (`rpt_alerts` is updated when an alert resolves, `rpt_devices`, `rpt_patch_updates`): refresh those fully; they are small.

1. *Manage parameters > New*: `RangeStart` and `RangeEnd`, type **Date/Time**, any current values. The names must be exactly these.
2. In the query of the history view, apply the filter that is commented out at the bottom of every sample (`Table.SelectRows(Typed, each [hour_start] >= RangeStart and [hour_start] < RangeEnd)`): the filter must use `>=` on one side and `<` on the other and **must fold** (section 6).
3. Right-click the table in the Fields pane > *Incremental refresh*. Suggested: **store** rows for 400 days (hourly: whatever you want to keep; the source holds 14 days, so the first refresh loads 14 days and the dataset then grows), **refresh** rows for the last 3 days. Optionally tick *Only refresh complete days*.
4. Publish. The first refresh in the service loads the stored range (it can take a while; the service partitions it); later refreshes touch only the refresh window.

Because the source deletes old rows, **do not** let a full refresh run against a dataset whose history is longer than the source: a full re-process would drop what the source no longer has. Incremental refresh policies do not do that; *Process full* in a tool such as SSMS or Tabular Editor does. **[unverified in Power BI]**

All timestamps are UTC: `RangeStart` / `RangeEnd` are compared with UTC values as they are, with no conversion (section 12).

## 8. Publish, gateway and scheduled refresh

1. *Home > Publish* to a workspace. The data source credentials are **not** stored in the `.pbix`; set them again in the service.
2. **On-premises data gateway.** Install the gateway ("standard mode", not "personal mode") on a Windows server that can reach the database port, sign in, and register it. In the service: *Settings > Manage gateways*, add a data source of type MySQL or PostgreSQL with the **same server and database text as in Desktop** (the gateway matches by text), authentication *Basic*, user `rivet_reporting`; tick *Use SSL* (and "Encrypted connections only" on the data source). Check "Connection status: Successful".
   * The gateway server needs MySQL Connector/NET (MySQL source) or Npgsql (PostgreSQL source) as well. **[unverified in Power BI]**
   * Put the gateway's address in the database account's host part (section 3) and in the firewall's allow list.
3. Dataset settings > *Gateway connection*: map each data source to the gateway; *Data source credentials*: set; *Scheduled refresh*: on, choose times in the time zone you pick, and a failure notification to yourself.
4. Choose the times when RivetIT is quiet. The roll-up of the daily tier is done by housekeeping; a refresh just after it sees the full previous day.
5. A dataset that is Import uses the gateway only during refresh; viewers never touch your database.

## 9. Security: TLS, network exposure, least privilege

* **Require TLS on the connection.** MariaDB / MySQL: the account is created with `REQUIRE SSL`, so the server refuses it without TLS (the server needs a certificate; MySQL 8 makes one automatically, MariaDB needs `ssl_cert`, `ssl_key`, `ssl_ca` in its configuration). PostgreSQL cannot require TLS by SQL: put a `hostssl` line for the role in `pg_hba.conf` and set `ssl = on`. Prefer a certificate from your own CA and verify it (`sslmode=verify-full`) wherever the client allows.
* **Network exposure is your decision, and it is a real one.** The reporting views are a copy of your whole fleet's inventory and health, and of every client's. Opening the database port to the internet puts a login prompt for it in front of the world. Do not. Preferred, in order: (1) the gateway on a server in the same private network as the database; (2) a VPN; (3) a firewall rule that allows only the gateway's fixed address. Keep `MAX_USER_CONNECTIONS` low, monitor failed logins, rotate the password (re-run the statements) on a schedule and when someone who knew it leaves.
* **Least privilege is built in**, but only if you use the account: never connect Power BI as the application user or as `root`/`postgres`. The views expose no tokens, hashes, keys, passwords, IP addresses, serial numbers, script output, command lines, registry or file values, alert or check text, or audit details; a test fails if a column like that is ever added.
* **`rpt_audit_events` is not client-scoped** (audit events carry no client). Do not include it in a dataset that clients' people can open, and do not grant it to a per-client account.
* **A `.pbix` file holds your data.** Treat it like a database backup: share the report in the service with the right audience, not the file.

## Row-level security per client

If one report serves several clients (an MSP's customers, or your own departments), Power BI **row-level security (RLS)** limits each viewer to their client's rows. Every client-scoped view carries `client_id`; the PostgreSQL copy of the metric, check and software views reaches the client through `rpt_devices`.

**Model.** Create a small dimension from the devices, and let it filter everything:

```dax
Clients = DISTINCT ( SELECTCOLUMNS ( rpt_devices, "client_id", rpt_devices[client_id] ) )
```

Relate `Clients[client_id]` (one) to `rpt_devices[client_id]` (many), single direction. Because every other table relates to `rpt_devices` on `device_id` (section 5), a filter on `Clients` flows to all of them. Add the client's name by merging a `client_id, client_name` lookup into `Clients` (typed in, or the edition's `rpt_clients` later).

**A role for one client** (*Modeling > Manage roles > Create*, table `Clients`, filter expression):

```dax
[client_id] = 12
```

**One role for everyone, by sign-in.** Create a table `UserClients` (`email`, `client_id`; typed in or from a spreadsheet), relate nothing to it, and use this filter on `Clients`:

```dax
[client_id] IN
    SELECTCOLUMNS (
        FILTER ( UserClients, UserClients[email] = USERPRINCIPALNAME () ),
        "client_id", UserClients[client_id]
    )
```

Then *Modeling > View as* to test with a role and a user, and assign people to the role in the service (*Dataset > Security*).

**Read this before relying on RLS:**

* RLS limits what **report viewers in the Power BI service** can see. It does **not** limit the database account: whoever holds the `rivet_reporting` password, or can edit the dataset (workspace members with Contributor / Member / Admin roles bypass RLS), sees every client. RLS is a convenience for one dataset, not a tenant boundary.
* For a boundary, use **one dataset and one credential per client**: the reporting feed's per-client tokens (phase 3) or a separate account whose views are filtered by client (not built in phase 2). Until then, treat the whole database as one trust zone.
* Put RLS on the dimension (`Clients`), not on each fact table. A fact table with no relationship to `rpt_devices` or `Clients` is **not** filtered at all (so is a duplicate table you added later): check *View as* on every page.
* `rpt_audit_events` has no `client_id`: exclude it from RLS datasets.
* The tenant behaviour of the views themselves is tested: for every client-scoped view, a query filtered to one client returns that client's rows and no other, on MariaDB, MySQL and PostgreSQL (tests/Integration/Reporting, tests/Integration/Rmm/Postgres).

## 11. Performance

* **Filter by date.** The big views are `rpt_device_health_hourly`, `rpt_device_health_daily`, `rpt_check_results`, `rpt_change_events` and `rpt_audit_events`. A query that names a time range (and a device, asset or client) uses an index and reads only those rows; a query with no filter reads everything. In the tests, on a synthetic 600,000-row dataset, filtered reads took 2 to 200 ms. Measured, EXPLAIN-checked.
* **Use the rollups.** For anything longer than two weeks use `rpt_device_health_daily` (one row per day) instead of the hourly table (24 per day per series). Charting 90 days of CPU from hourly data is 24 times more rows than needed.
* **Avoid the raw check history** (`rpt_check_results`) except for a short window on one device. For "how noisy is this check" count `rpt_alerts` by `check_name`; for "what is the state now" use `rpt_check_status`. The view only returns the last 31 days in any case.
* **Select only the columns you need** (remove columns in Power Query before loading); on DirectQuery fewer columns means smaller SQL results.
* **Do not turn a status into a row filter on the whole table** in DirectQuery without a date filter.
* **Import, then relate**, rather than merging big tables in Power Query (merges often do not fold).
* **One metric at a time.** `rpt_device_health_*` holds many metrics in one table (`metric_name`): filter on `metric_name` (for example `cpu.utilization`) in the query, not in a visual, to import only that metric.
* **Refresh outside business hours** and keep `MAX_USER_CONNECTIONS` at the default.
* **MySQL vs MariaDB:** no difference in the views; MariaDB users on the MySQL connector may see a harmless server-version warning.

## Time zones

**Every timestamp in the views is UTC** (the one exception, `rpt_audit_events.created_at`, is the database server's time zone, UTC on a server set up as recommended). The Power Query samples load them as plain `datetime` values *without* a time zone, which means "UTC wall-clock time". Power BI shows them as they are.

To show **local time**, convert in Power Query (so charts, slicers and the date table agree). A fixed offset (does not follow daylight saving time; fine for a lab, wrong for half the year elsewhere):

```m
// replace the "-5" with your offset from UTC in hours
Local = Table.TransformColumns(Typed, {{"opened_at", each DateTimeZone.RemoveZone(DateTimeZone.SwitchZone(DateTime.AddZone(_, 0), -5)), type nullable datetime}})
```

For **daylight saving time** the robust way is a lookup, because the service runs in UTC and `DateTimeZone.ToLocal` gives UTC there (it converts to the time zone of the machine that runs the refresh, which on the service and on a gateway server is not your office):

1. Make a small table `TzOffsets` with columns `from_utc` (datetime), `offset_hours` (number): one row per daylight-saving change for the years you report on (for US Central: `2026-03-08 08:00` → -5, `2026-11-01 07:00` → -6, ...).
2. For each timestamp, take the last row with `from_utc <= timestamp` and add its `offset_hours` (a merge with a "fuzzy" or an `List.Last(List.Select(...))` per row, or a DAX `LOOKUPVALUE` over `CALCULATE(MAX(TzOffsets[from_utc]), FILTER(TzOffsets, TzOffsets[from_utc] <= ...))`). **[unverified in Power BI]**
3. Group days **after** converting (a "day" in a client's report is their day).

The reporting role on PostgreSQL sets the session `TimeZone` to UTC for you; on MariaDB / MySQL the views produce UTC values regardless of the session or server zone. The alert view's `duration_s` and `time_to_ack_s` are seconds and do not depend on any zone.

## 13. Troubleshooting

| Symptom | Likely cause | What to do |
|---|---|---|
| "Unable to connect" / timeout | Firewall, the port is not reachable from the computer or gateway, wrong host:port | Test from the same machine: `mysql -h HOST -P 3306 -u rivet_reporting -p` or `psql`. Allow the gateway's address in the firewall and in the account's host part. |
| "Access denied for user 'rivet_reporting'@'x.x.x.x'" (MySQL) | The account's host part does not match the address the connection comes from; wrong password; TLS required but the client connects without | Create the account for the address in the message (re-run the statements with that host), or use the right password. |
| `no pg_hba.conf entry for host ... SSL off` (PostgreSQL) | `hostssl` rule exists but the client did not use TLS, or no rule matches | Tick *Encrypt connection*; add the `hostssl` line; reload PostgreSQL. |
| Certificate error / "The remote certificate is invalid" | The client does not trust the server's certificate | Install the CA certificate on the computer / gateway server (trusted root store), or use a certificate from a CA it trusts. Do not turn verification off permanently. |
| "SELECT command denied to user ... for table 'rpt_x'" | That view was not granted | Run the account statements again with that view in the list (the default grants all). |
| The Navigator shows no `rpt_` views, or fewer than expected | The migration that creates the views has not run (check the edition's database update page), or on PostgreSQL the PostgreSQL migration has not run | Run the database update. The control views arrive with the RivetCore migration `0047_reporting_views`; the PostgreSQL ones with `rmm-postgres.php migrate`. |
| A view errors "references invalid table(s) or column(s) or function or definer/invoker of view lack rights" | The account that created the views was removed | Run the RivetCore migration again as a user that exists, or re-run the view statements. |
| Rows missing in the health tables for a device | The device is not linked to an asset (`asset_id` NULL), or the asset belongs to no device. Metrics are keyed by asset | Link the device in the edition. In PostgreSQL the view shows the asset's rows even without a device. |
| `client_id` missing in a PostgreSQL table | By design: the PostgreSQL copy of the health, check-result and software-change views has no device table to join | Relate to `rpt_devices` (section 5). |
| Health numbers look doubled | Two devices share an asset (re-enrolment) and the model relates on `asset_id` without the helper query | Use `DeviceAssets.pq` (one device per asset) or relate on `device_id` (MariaDB). |
| The refresh is slow or times out | A view without a date filter (raw check history), DirectQuery on a big table, folding broken | Filter by date first; use the daily table; Import; check *View Native Query*. |
| "Query folding is not possible" / *View Native Query* greyed | A step before the date filter cannot become SQL | Move the date filter earlier; remove custom columns before it. |
| Times are off by hours | Everything is UTC | Convert in Power Query ([Time zones](#time-zones)). |
| Numbers show as text | The type step did not apply | Use the samples (they set the types) or *Transform > Data type*. |
| Incremental refresh: "the query cannot fold" or all data reloads | The `RangeStart`/`RangeEnd` filter did not fold | Make it the first step after navigation; check *View Native Query*. |
| `Table.TransformColumns ... datetimezone` error on PostgreSQL | An older connector returns `datetime` not `datetimezone` | The PostgreSQL samples handle both; if you wrote your own conversion, test both. **[unverified in Power BI]** |
| Gateway: "The data source ... credentials are invalid" | The data source text in the service differs from Desktop (server name, database) | Make them identical, character for character, including `:port`. |
| An RLS viewer sees every client | The role's filter is on a table that is not related to the facts, or the viewer is a workspace Contributor+ | Put the filter on `Clients`, test with *View as*, make viewers *Viewer* role only. |

## 14. Verification

Executed for this release, on throwaway Docker databases with random passwords:

* The `rpt_` views were created by the real migrations on MariaDB 10.11, MySQL 8.4 and PostgreSQL 18; their columns and type families are checked against the frozen contract.
* Tenant separation: seeded data for two clients and, for every client-scoped view, a query for one client returns exactly that client's rows and none of the other's, on both stores.
* No secret or free text: a marker planted in every column the views must not expose never appears in any view; the column names and every base column the SQL reads are scanned against a deny-list.
* The read-only account was created from the helper SQL and exercised: `SELECT` on the views works; `SELECT` on base tables and `INSERT`/`UPDATE`/`DELETE`/DDL are refused (MariaDB 10.11, MySQL 8.4, PostgreSQL 18).
* EXPLAIN and timings on ~600,000 synthetic rows (both stores): filtered reads use an index and take milliseconds.
* The Power Query files are generated from the catalog; a test checks balanced brackets and quotes, the navigation target and that every column named in the type step exists in the view; another test checks that every `table[column]` in the DAX files exists in the catalog.

**Not verified** (no Power BI available): that Power BI Desktop and the service accept the M files and the DAX measures, the exact menu names and connector options, the query-folding behaviour of each step, the incremental refresh behaviour, the gateway behaviour and Microsoft's current limits. Report anything that fails and it will be fixed in the catalog, so the samples regenerate.
