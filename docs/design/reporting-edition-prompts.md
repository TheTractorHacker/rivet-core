# Phase 4 of #91: ready-to-paste prompts for the editions (Reporting / Power BI settings page)

Phases 1 and 2 (RivetCore 1.0.0-rc.43) ship the reporting views, the read-only account SQL, the documents and the Power BI samples. Each edition now needs the **Reporting / Power BI** settings page, its own views (clients, sites, assets, tickets, SLA, alert-to-ticket) and their tests. Paste one prompt into the chat of the edition. **Beta only; never production** (production deploys stay yours). Read RivetCore `UPGRADING.md` "Adopting the reporting views (1.0.0-rc.43; migration 0045; optional)", `docs/design/reporting.md` and `docs/reporting/POWER_BI.md` first.

Checklist of #91:

- [x] Phase 1: guide, data dictionary, Power Query and DAX samples, starter report layout (rc.43)
- [x] Phase 2: `rpt_` views for MariaDB / MySQL and PostgreSQL, migration 0045 + PostgreSQL `0002_reporting_views`, `ReportingRole`, contract / secrets / tenant / role / EXPLAIN tests (rc.43)
- [ ] Phase 3: reporting feed with scoped, expiring tokens (Core)
- [ ] Phase 4 RivetIT beta: settings page, edition views, tests
- [ ] Phase 4 RivetMSP beta: same
- [ ] Phase 5: starter `.pbit` built in Power BI Desktop, worked example, CSV / Excel exports
- [ ] Production rollout of each edition after beta (separate decision)

---

## Prompt for the RivetIT chat (beta: /var/www/mw-itflow.foleyit.com)

```
Implement phase 4 of RivetCore issue #91 (Power BI / BI reporting) in RivetIT, BETA ONLY (mw-itflow.foleyit.com). Do not touch production, do not run tests against the live DB, do not push/tag/release; deploys to beta follow our usual beta procedure.

Read first: RivetCore UPGRADING.md "Adopting the reporting views (1.0.0-rc.43; migration 0045; optional)", docs/design/reporting.md (sections 3, 5 and 7), docs/reporting/POWER_BI.md, docs/reporting/DATA_DICTIONARY.md, src/Reporting/*.php.

1. Adopt rivet/rivet-core 1.0.0-rc.43 (composer pin, real composer install). Run Core migration 0045 on the BETA database through our normal DB-update step (it needs CREATE VIEW; it creates the 17 rpt_ views and nothing else). Mirror it in db.sql WITHOUT a user-specific DEFINER (mysqldump writes DEFINER=`user`@`host`; a fresh install must create the views as whoever installs). A fresh install and an upgraded one must have identical view definitions (watch the collation and the trailing-newline traps from the release procedure). If data_store = postgres on this install, also run `php vendor/rivet/rivet-core/scripts/rmm-postgres.php migrate` (applies 0002_reporting_views).
2. Edition views (RivetCore cannot own these tables): create rpt_clients, rpt_sites, rpt_assets, rpt_tickets, rpt_alert_tickets and rpt_sla exactly as proposed in docs/design/reporting.md section 7, after CHECKING every column against this edition's db.sql. Rules: an allow-list of columns, no ticket subject / details / replies / contact / billing / vendor columns, no alert message or raw_data_json, client_id on every row, stable names, and UTC timestamps (if the database server's time zone is not UTC, convert in the view with CONVERT_TZ and a named zone, and prove it in a test). Add them to an edition migration (DB-update step + db.sql), a frozen-column fixture (like RivetCore's tests/Fixtures/reporting_views.json) and a deny-list/no-secrets test like RivetCore's ReportingContractTest, a tenant test (two clients; a query filtered to one returns only its rows), and extend the reporting account's grants with SELECT on these views (append GRANT lines after ReportingRole::mariadbStatements()).
3. Page Administration > Reporting / Power BI (admins with access to ALL clients only; CSRF token; no GET side effects):
   a. Status: the list of views from ReportingCatalog::views() (name, grain, purpose, client-scoped yes/no, store), whether each exists in the database (information_schema.VIEWS), whether the PostgreSQL store has its six (when data_store = postgres), the edition views, and the Core migration level.
   b. "Create / rotate reporting account": a form for the account name (default ReportingRole::DEFAULT_USER), the host part (default: not '%': require a value, warn on '%'), "require TLS" (default on) and a field for a temporary DATABASE ADMIN login (user + password) that is used ONCE over a separate connection and never stored, logged or echoed. Generate the password with ReportingRole::generatePassword(); run ReportingRole::mariadbStatements() (+ the edition grants) with that admin connection; show host, port, database, user and the new password ONCE in a no-store response (Cache-Control: no-store; not in the session, the database, the audit row, the logs or the error messages). For data_store = postgres do the same with postgresStatements() on the PostgreSQL connection of the data store setting (the sealed one; do not ask for another). Rotation = running it again. A "Drop account" action (dropMariadbStatements / dropPostgresStatements) with a typed confirmation.
   c. Ready-to-copy connection details and Power Query snippets for the chosen source (MySQL/MariaDB or PostgreSQL): read the files of vendor/rivet/rivet-core/docs/reporting/samples/power-query/** and fill ServerName / DatabaseName with this install's real host and database (the host the operator enters: the app's DB host may be 'localhost' and useless to Power BI); copy buttons; links to POWER_BI.md.
   d. "Test connection": connect AS the reporting account (password typed by the admin, not stored), run SELECT COUNT(*) FROM rpt_devices and a refused SELECT on a base table, and show PASS/FAIL per check with the exact error text (without the password).
   e. Per-client scoping help (the text of POWER_BI.md "Row-level security per client", including the warning that RLS is not a tenant boundary).
   f. Warnings, computed not static: account host part '%', server without TLS (SHOW VARIABLES LIKE 'have_ssl' / 'ssl_*', or the connection's Ssl_cipher), database bound to a non-private address, port reachable from outside (documentation only; do not scan).
   g. Audit rows (AuditService) for create / rotate / drop / test with the actor and the account name and host, NEVER the password.
4. Permissions and security: the page and its POST handlers require the admin ability; a technician scoped to some clients must not reach it (it exposes all clients); inputs validated (ReportingRole already rejects hostile names and passwords, catch InvalidArgumentException and show the message); no raw SQL from the request; error messages never include the admin password or the generated one; the generated password never appears in the page source after the first response.
5. Tests, on a SCRATCH database + throwaway Docker MariaDB 10.11 (and PostgreSQL 18 for the postgres path; never live): the page renders, non-admin and client-scoped users are refused (403), CSRF is enforced, the create flow produces an account that can SELECT the views and nothing else (and the edition views), rotation invalidates the old password, drop removes it, the password is in no log, audit row, session or later response, XSS in a hostile account name is escaped, the connection test reports failures correctly, the module-only logins and the Admin/Technician roles are unchanged. Run the edition's full suite and the live smoke test bar from our verification discipline on BETA only; report what you did not run.
6. Update the edition's docs/changelog and say which RivetCore version it needs.
```

## Prompt for the RivetMSP chat (beta: /home/sysadmin/rivetmsp-beta)

```
Implement phase 4 of RivetCore issue #91 (Power BI / BI reporting) in RivetMSP, BETA ONLY (rivetmsp-beta). Do not touch production or any live DB, do not push/tag/release.

Read first: RivetCore UPGRADING.md "Adopting the reporting views (1.0.0-rc.43; migration 0045; optional)", docs/design/reporting.md (sections 3, 5 and 7), docs/reporting/POWER_BI.md, docs/reporting/DATA_DICTIONARY.md, src/Reporting/*.php.

Do the same as the RivetIT prompt (docs/design/reporting-edition-prompts.md), against this edition's schema, with these differences:
- RivetMSP is multi-tenant by design: technicians are scoped to clients. The Reporting / Power BI page is for administrators with access to every client only. Show the per-client scoping help prominently and the warning that a reporting account sees ALL clients; the per-client tokens of phase 3 are the real boundary and are not built yet, so do not offer per-client accounts or tokens.
- Check every column of the edition views (rpt_clients, rpt_sites, rpt_assets, rpt_tickets, rpt_alert_tickets, rpt_sla) against THIS edition's db.sql: names, the client column of each table and the SLA model differ from RivetIT. Add contracts, deny-list and tenant tests for them, and include the MSP-only data (contracts, billing, invoices) ONLY if you add an explicit allow-list for them with the same tests; the default is not to expose billing data.
- Keep the page's wording, audit events (reporting.account.created / rotated / dropped / tested) and permission names identical to RivetIT so the two editions stay interchangeable.
- Beta only. Tests on a scratch database and throwaway Docker MariaDB 10.11 (PostgreSQL 18 for the postgres path). Report what you did not run.
```
