# Phase 4 of #89: ready-to-paste prompts for the editions

Phase 3 (RivetCore 1.0.0-rc.41) ships the services. Each edition now needs the settings card, the capacity card, its pages on the store interfaces, its backup scripts and its update procedure. Paste one prompt into the chat of the edition. **Beta only; never production** (the production deploys stay yours). Read `UPGRADING.md` "Adopting the data store setting and migration (1.0.0-rc.41)" and `docs/rmm/POSTGRES.md` first; the design is `docs/design/rmm-data-store.md` section 13.

Checklist of #89 after phase 3 (tick as the editions land):

- [x] Phase 1: inventory and interface (rc.39)
- [x] Phase 2: PostgreSQL implementation, contract tests, CI (rc.40)
- [x] Phase 3: setting + connection test, migration both ways with verification/rollback/purge, capacity recommendation, backup/restore helpers, update ordering (rc.41)
- [ ] Phase 4 RivetIT beta: card, capacity card, pages through the interfaces, backup, tests
- [ ] Phase 4 RivetMSP beta: same
- [ ] Phase 5: load test at 5,000 and 10,000 devices on both stores, set the default `rmm_pg_recommend_devices`
- [ ] Production rollout of each edition after beta (separate decision)

---

## Prompt for the RivetIT chat (beta: /var/www/mw-itflow.foleyit.com)

```
Implement phase 4 of RivetCore issue #89 (optional PostgreSQL data store for the hot RMM tables) in RivetIT, BETA ONLY (mw-itflow.foleyit.com). Do not touch production, do not touch the live DB with tests, do not push/tag/release; deploys to beta follow our usual beta procedure.

Read first: RivetCore docs/design/rmm-data-store.md (sections 7 and 13), docs/rmm/POSTGRES.md, UPGRADING.md "Adopting the data store setting and migration (1.0.0-rc.41)", docs/design/rmm-data-store-phase4-edition-prompts.md.

1. Adopt rivet/rivet-core 1.0.0-rc.41 (composer pin; real composer install). Run Core migrations 0042, 0043 and 0044 on the BETA database through our normal DB-update step and mirror them in db.sql (endpoint_agent_settings.data_store, .data_store_phase; tables rmm_metric_rollup_state, rmm_pending_client_moves, rmm_hot_store_faults, rmm_data_store_connection, rmm_store_migration; the client_id columns of 0043). Regenerate/verify db.sql so a fresh install equals an upgraded one (watch the collation and row-size traps from the release procedure).
2. Install php8.5-pgsql (pdo_pgsql) for BOTH the PHP-FPM that serves the web and the CLI used by cron, then reload PHP-FPM (check `php -m` for both and a phpinfo-free probe through the web). Note any nginx/FPM changes needed (none expected: PostgreSQL is a database connection, not a web route; if a new route is needed for the migration progress poll, add it to the shared snippet through our web-routes-apply procedure, root-owned copy + dry-run).
3. Settings card Administration > RMM > Data store, calling $module->dataStoreSwitch(): connection form (host, port, database, user, password, sslmode, schema; the password field stays blank when one is stored and is NEVER echoed or logged; the admin must retype it when host, port, user or database change, the service enforces this), a 'Test connection' button (testConnection($values), show every check and warning), a 'Switch to PostgreSQL' button that requires a checkbox next to the exact text DataStoreSwitch::NO_DATA_MOVE_NOTICE ('does not move data'), 'Switch back to MariaDB', and the status() read-out (active store, last test, newest run, the left_behind banner). CSRF, admin-only (server side), audit through the existing audit trail. Use the Tabler styles already in use; no inline secrets.
4. 'Migrate data' with progress and 'Roll back' / 'Purge old store': run $module->storeMigrator() (or scripts/rmm-store-migrate.php with an edition bootstrap file returning database, clock, secretBox, audit) from a background job/CLI, NEVER inside the web request; the page polls status() and shows the JSON events. Buttons for resume, abort, rollback (within the period), purge-old with a typed confirmation. Explain: retention is paused during a migration; the old store is kept 7 days by default and deleted only by purge.
5. Capacity card: show capacity()->build()['data_store_recommendation'] (message when present, devices, thresholds, growth/day) and ['store_sizes'] (rows and bytes per hot table of the ACTIVE store). Never switch automatically.
6. Go through the store interfaces in the 5 files from the phase 1 inventory: includes/rmm_log_ui.php (SELECT MIN(occurred_at) FROM endpoint_agent_log_events -> LogStore::oldestEventAt()), admin/post/settings_endpoint_agent.php, admin/settings_endpoint_agent.php, includes/rmm_scale_admin.php, includes/rmm_ui_render.php (partitions()->probe()/convert()/partitions() are MariaDB-only: use dataStores()->maintenance()->tiers(), hide 'convert' on PostgreSQL). Also grep the whole edition for any other direct SQL on the 12 hot tables (endpoint_agent_check_history, endpoint_agent_log_events/_state, endpoint_agent_change_records/_state, rmm_metric_latest/_hourly/_daily, rmm_software_history, rmm_patch_history, rmm_package_history, rmm_hypervisor_vm_history) and move it too. Where a page reads history, catch HotStoreUnavailableException and show a clear 'history store unreachable' state; add host names to rows from a store (Store\DeviceNames). Anything that changes a device's client outside DeviceService::transfer() must call logs()->reassignClient()/changes()->reassignClient().
7. deploy/backup.sh and restore.sh (age/gpg pipeline): add the RMM PostgreSQL dump with scripts/rmm-postgres-backup.sh / rmm-postgres-restore.sh exactly as in docs/rmm/POSTGRES.md section 8 (only when data_store = postgres; pipefail; PGPASSFILE, never an argument), the in-app backup via Store\BackupHook (descriptor without password in the manifest), and run scripts/rmm-store-verify.php after a restore. Update the update procedure/docs: MariaDB migrations, then scripts/rmm-postgres.php migrate, then the switch.
8. Tests, on a SCRATCH database + throwaway Docker PostgreSQL 18 (postgres:18; the 16 image as a second run if the install may have started on 16; never live): switch/test/refusal flows (changed host with blank password refused, nothing sent), the card permissions (non-admin refused), migration progress page against a small synthetic fleet both ways, rollback, the 5 pages on both stores (empty, populated, store down -> HotStoreUnavailableException handled), backup/restore round trip with row counts, module-off fast path still zero queries, MariaDB-only install never connects to PostgreSQL. Run the edition's full suite and the live smoke test bar from our verification discipline on BETA. Report real results, what you did not run, and anything needing my decision.
```

## Prompt for the RivetMSP chat (beta: /home/sysadmin/rivetmsp-beta)

```
Implement phase 4 of RivetCore issue #89 (optional PostgreSQL data store for the hot RMM tables) in RivetMSP, BETA ONLY (rivetmsp-beta). Do not touch production or any live DB, do not push/tag/release.

Read first: RivetCore docs/design/rmm-data-store.md (sections 7 and 13), docs/rmm/POSTGRES.md, UPGRADING.md "Adopting the data store setting and migration (1.0.0-rc.41)", docs/design/rmm-data-store-phase4-edition-prompts.md.

Do items 1 to 5, 7 and 8 of the RivetIT prompt above for RivetMSP (adopt rc.41; migrations 0042-0044 on the beta DB and db.sql; php8.5-pgsql for web and CLI PHP + FPM reload; the Data store settings card with Test connection / Switch (with the 'does not move data' text and checkbox) / Switch back / status; Migrate data with progress, Roll back, Purge old; the capacity recommendation card; backup.sh/restore.sh/in-app backup/verify; tests on scratch DBs), and for item 6 go through the store interfaces in the 6 files of the phase 1 inventory:
 - includes/rmm_log_ui.php (SELECT MIN(occurred_at) FROM endpoint_agent_log_events -> LogStore::oldestEventAt()),
 - admin/post/settings_endpoint_agent.php, admin/settings_endpoint_agent.php, includes/rmm_scale_admin.php, includes/rmm_ui_render.php (partition tool and tier arrays are MariaDB-only: dataStores()->maintenance()->tiers(); hide 'convert' on PostgreSQL),
 - includes/rmm_bootstrap.php: the metric store is constructed with new DatabaseMetricSink(...) in two places (the sink and the reader at about line 229): use RmmDataStoreFactory::createMetricStore() / let the module route the sink so metrics follow the store setting, and pass nothing PostgreSQL-specific (the module reads the stored connection),
 plus a grep for any other direct SQL on the 12 hot tables. Catch HotStoreUnavailableException wherever history is read, hydrate host names with Store\DeviceNames, and call reassignClient() wherever a device changes client outside DeviceService::transfer(). includes/rmm_ui.php and agent/asset_details.php read metrics through RmmMetricReaderInterface and need no change, but verify them with the store set to postgres.
Extra for MSP: tenant isolation. Prove with a test that a technician scoped to client A never sees client B rows from a PostgreSQL store (logs and change search use the denormalised client_id; rows with a NULL client_id are visible to nobody scoped by client), and that moving a device between clients moves its history (reassignClient, including the case where PostgreSQL is down and rmm_pending_client_moves repeats it).
Report real results, what you did not run, and anything needing my decision.
```
