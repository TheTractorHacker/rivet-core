# RMM data store: where the hot RMM tables live

Status: phases 1 and 2 of [#89](https://github.com/TheTractorHacker/rivet-core/issues/89) are implemented. Phase 1: inventory, interfaces, the MariaDB implementation. Phase 2 (section 12): the PostgreSQL implementation (optionally TimescaleDB), its schema and migrator, the degraded behaviour and the contract, equivalence and failure tests. Phases 3 to 5 are not started. Roadmap: "Enterprise architecture track", item 8.

**Behaviour on MariaDB is identical to before.** The same SQL runs against the same tables; it moved behind interfaces (phase 1), and phase 2 added the client id to the event rows so that no store has to join the device table (migration 0043, section 12.2). The device protocol is untouched (golden replay unchanged). `rmm_data_store` is `mariadb` by default; `postgres` is selectable by hand and runs when the edition passes a connection (section 12.6); choosing it in the settings is phase 3.

## 1. Goal and shape

Let an installation keep the HOT RMM tables (metrics and rollups, check history, logs, change records, software/patch/deployment/hypervisor history) in MariaDB/MySQL (default) or in PostgreSQL (optionally TimescaleDB), while the CONTROL tables (devices, jobs, policies, tokens, alerts, settings and everything that joins with the edition's tickets and assets) always stay in MariaDB. A hot store is chosen by an administrator and never switches automatically.

```
 check-in ─► CheckinService ─► control writes (MariaDB, one transaction) ──┐
                 │                                                        │
                 └─► stores: MetricStore, CheckHistoryStore, LogStore,    │
                     ChangeEventStore, SoftwareHistoryStore,              │
                     PatchHistoryStore, DeploymentHistoryStore,           │
                     HypervisorHistoryStore, StoreMaintenance             │
                         │ rmm_data_store = mariadb (phase 1)             │
                         └─► same database, same connection ◄─────────────┘
```

## 2. Method

Every table created by Core's migrations whose name starts with `endpoint_agent_` or `rmm_` was listed (90 tables after migration 0042; the other 18 tables of Core, `audit_events`, `integration_jobs`, `webhook_deliveries` and so on, are not RMM tables). For each, the code was searched (`grep` for the table name in `src/`) for writers and readers. Where this document says "writes" and "reads" it names the Core class. Tables the tooling cannot reach (the editions' own) are listed in section 7.

Classification rules:

* **HOT**: append-heavy or time-ordered, large at fleet scale, read mostly by time window or by device page, never part of a join that decides control flow, and safe to delete by age.
* **CONTROL**: current state, configuration, credentials, queues, anything that joins with tickets, assets, clients or devices to make a decision, anything that must change in the same transaction as control data, and all bounded configuration.
* **UNCERTAIN** is written next to the classification where a reasonable person could decide the other way. Section 8 lists them again as decisions.

## 3. Totals

| Class | Count | Tables |
|---|---|---|
| HOT | 12 | `rmm_metric_latest`, `rmm_metric_hourly`, `rmm_metric_daily`, `endpoint_agent_check_history`, `endpoint_agent_log_events`, `endpoint_agent_log_state`, `endpoint_agent_change_records`, `endpoint_agent_change_state`, `rmm_software_history`, `rmm_patch_history`, `rmm_package_history`, `rmm_hypervisor_vm_history` |
| CONTROL | 78 | everything else (section 5) |

Two of the twelve are ledgers (`log_state`, `change_state`) that count the rows of their event table and are updated in the same transaction as every insert and delete; they must live with their events. Two more (`rmm_package_history`, `rmm_hypervisor_vm_history`) are UNCERTAIN: small compared with the rest.

## 4. HOT tables

| Table | Row growth | Written by | Read by | Joins | Needs a transaction with control data | MySQL-specific SQL | Why HOT |
|---|---|---|---|---|---|---|---|
| `rmm_metric_latest` | one row per (asset, key, instance), upserted; trimmed when an asset stops reporting | `DatabaseMetricSink::ingest` | `DatabaseMetricSink::latest`; `CapacityReport::tables` (size only) | no | no | `ON DUPLICATE KEY UPDATE` with `VALUES()`, `IF()`, `GREATEST()`; `DELETE ... LIMIT` | Written on every check-in and read together with the hourly tier. Bounded by series, but it moves with the metrics |
| `rmm_metric_hourly` | append/upsert, one row per series and hour, 14 days by default | `DatabaseMetricSink::ingest` | `DatabaseMetricSink::peak/series`, `MetricRollup`, `PartitionMaintenance`, `CapacityReport` | no | no | `ON DUPLICATE KEY UPDATE samples = samples + VALUES(samples)` with `LEAST/GREATEST`; `DELETE ... LIMIT`; `RANGE COLUMNS` partitions, `information_schema`, `GET_LOCK` | The biggest table of the module at fleet scale (about 12 rows per device per hour) |
| `rmm_metric_daily` | upsert, one row per series and UTC day, 400 days by default | `MetricRollup` (from the hourly tier) | `DatabaseMetricSink::peak/series`, `PartitionMaintenance` | reads `rmm_metric_hourly` | no (the watermark in `rmm_storage_state` is read with it) | `INSERT ... SELECT ... GROUP BY ... ON DUPLICATE KEY UPDATE`; `DELETE ... LIMIT`; partitions | Derived from the hourly tier by an aggregate that must see both in one database |
| `endpoint_agent_check_history` | append-only ring, `check_history_days` (7 by default) | `CheckEvaluator::apply` (via `CheckHistoryStore::append`) | `RmmReadModel::checkHistory`, `Housekeeping`, `PartitionMaintenance` | no | written at the end of the same `apply()` as the check state, but not atomic with it (no transaction wraps both) | multi-row `INSERT`; `DELETE ... LIMIT`; `RANGE COLUMNS` partitions with the primary key extended by `reported_at` | Pure time series, partitioned today |
| `endpoint_agent_log_events` | append-only, bounded by per-device and fleet row caps and `logs_retention_days` | `LogService::ingest` | `LogService::search`, `LogRuleService::windowCount`, `LogService::prune`, edition `rmm_log_ui.php` | search joins `endpoint_agent_devices` for client scope and host name | with its ledger (`log_state`), `FOR UPDATE` | `INSERT IGNORE` with `UNHEX()` dedupe key; `SELECT ... FOR UPDATE`; case-insensitive `LIKE ... ESCAPE` | Event stream, the largest growth after metrics when logs are on |
| `endpoint_agent_log_state` | one row per device | `LogStore::admit` and `enforceLimits` | `LogService::stats`, the same | search/stat join with devices | yes: it is the ledger of the events and is locked and updated in the same transaction as every insert and delete | `INSERT IGNORE`; `SELECT ... FOR UPDATE`; `IF()`, `GREATEST()` | HOT companion. The counters describe the rows of `log_events` and must change atomically with them, so they move together |
| `endpoint_agent_change_records` | append-only, bounded by per-device and fleet row caps and `change_retention_days` | `ChangeService::ingest` | `ChangeService::search/timeline/stats`, `ChangeRuleService::windowCount`, prune | search and timeline join `endpoint_agent_devices` | with its ledger (`change_state`), `FOR UPDATE` | `INSERT IGNORE` with `UNHEX()`/`HEX()` dedupe key; `FOR UPDATE`; `DATE()` grouping; case-insensitive `LIKE ... ESCAPE` | Event stream |
| `endpoint_agent_change_state` | one row per device | `MariaDbChangeEventStore::admit` and `enforceLimits` | `ChangeService::stats`, `CapacityReport::changeMonitor`, `Housekeeping` | stat queries join `endpoint_agent_devices` for client scope | yes, as for the log ledger | `INSERT IGNORE`; `FOR UPDATE`; `IF()`, `GREATEST()` | HOT companion of the change records. Also holds the agent's last status block and configuration version, written in the same statement |
| `rmm_software_history` | append-only, `software_history_days` (365 by default) | `SoftwareService::apply` | `RmmReadModel::softwareHistory`, `ChangeService::timeline`, `Housekeeping` | no | written inside the `SoftwareService::apply` transaction with the inventory update (the MariaDB store shares that transaction; a separate-database store would append after commit and must be idempotent) | multi-row `INSERT`; `DELETE ... LIMIT`; `LIKE` with `addcslashes` | Change log, time-ordered. The inventory it describes (`rmm_device_software`) is control |
| `rmm_patch_history` | append-only, `patch_history_days` (365 by default) | `PatchInstallService::history` | `PatchReader::deviceHistory`, `PatchScheduler::prune` | no | no | `DELETE ... LIMIT` | Install history, time-ordered |
| `rmm_package_history` | append-only, `software_deploy_history_days` | `DeployService` (install result) | `DeployReader::history` (joins `rmm_packages` for the name), `DeployScheduler::prune` | reader joins the package catalog (control) | written next to the install ledger updates, not in one transaction with them | `DELETE ... LIMIT` | **UNCERTAIN.** Bounded by deployments, far smaller than the others. Included because it is the deployment twin of patch history |
| `rmm_hypervisor_vm_history` | ring of N rows per VM (`hypervisor_history_per_vm`), N x VMs rows | `HypervisorService::recordTransitions` | `HypervisorReader::vm`, orphan sweep in `HypervisorService::prune` | the orphan sweep joins `rmm_hypervisor_vms` | no | `DELETE h FROM ... LEFT JOIN` (multi-table delete); `SELECT ... LIMIT 1 OFFSET` | **UNCERTAIN.** A bounded ring (control-like) but time-ordered and rewritten on every state change, and the issue lists hypervisor history as hot. Cheap to include; Blake may demote it |

Not HOT although append-heavy: `endpoint_agent_checkins` (the protocol replay guard), `endpoint_agent_jobs` (queue with state), `endpoint_agent_enroll_attempts` and `rmm_schedule_runs` (written with control data), see section 5.

### Where the hot tables are used outside the owning service

* `Housekeeping` prunes `check_history` and `software_history` (now through the stores) and calls `StorageMaintenance` for the rollup and the partitions.
* `CapacityReport::tables()` lists sizes of hot and control tables from `information_schema` (still MariaDB-specific; see "Follow-ups").
* `PartitionMaintenance` (public administrator tool, `RmmModule::partitions()`) converts `rmm_metric_hourly`, `rmm_metric_daily` and `endpoint_agent_check_history` to `RANGE COLUMNS` partitions. It is MariaDB-only by nature and stays, behind `StoreMaintenance`.

## 5. CONTROL tables

| Table | Row growth | Written by | Read by | Why CONTROL |
|---|---|---|---|---|
| `endpoint_agent_binaries` | bounded | `BinaryStore`, `UpdateService` | `AgentOs`, `DeviceApi`, `RmmAdmin`, `RmmReadModel` | agent build registry; joined by release logic |
| `endpoint_agent_change_rules` | bounded | `ChangeRuleService` | `Housekeeping` | alert rules for the change monitor; configuration |
| `endpoint_agent_change_settings` | bounded | `ChangeSettings` | `CapacityReport` | single-row configuration |
| `endpoint_agent_checkins` | append, time-ordered, pruned (`retention_days`) | `CheckinService`, `EnrollmentService` | `CapacityReport`, `Housekeeping`, `LoadShedder` | **UNCERTAIN.** The replay and sequence guard of the device protocol: `INSERT IGNORE (device_id, seq)` runs in the check-in transaction with the device update, `DELETE ... WHERE device_id` runs on re-enrolment, and LoadShedder and the capacity report count it per minute. Growth is high, but its role is control. A Redis candidate later, not a Postgres one |
| `endpoint_agent_checks` | bounded by fleet size | `AlertService`, `CheckEvaluator` | `AlertingActions`, `ChangeRuleService`, `CheckReconciler`, `HypervisorAlerts`, `HypervisorReader`, `LogRuleService` +4 | current state of each check of each device (upserted); alerting reads it together with tickets, escalation and flap state |
| `endpoint_agent_config_seq` | bounded | - | `ConfigSequence` | single-row counter |
| `endpoint_agent_devices` | bounded by fleet size | `CheckinService`, `DeviceService`, `DeviceTypes`, `EnrollmentService`, `HypervisorService`, `UpdateService` | `AgentOs`, `AlertService`, `AutoInstallService`, `AutomationReader`, `CapacityReport`, `CustomFieldService` +29 | the device registry; joined by almost every query and by assets, clients and tickets in the editions |
| `endpoint_agent_enrollment_tokens` | bounded by fleet size | `EnrollmentService` | `AutoInstallService`, `InstallerDownload`, `InstallerService`, `RmmReadModel`, `TechnicianActions` | credentials |
| `endpoint_agent_enroll_attempts` | append, pruned after 30 days | `AttemptLog` | `Housekeeping`, `RmmReadModel` | **UNCERTAIN.** Security log of enrolment attempts, written on the enrolment path and read for rate limits. Small; stays with the credentials it audits |
| `endpoint_agent_installer_options` | bounded | `InstallerService` | `DeviceTypes`, `InstallerDownload`, `MeshAfterApproval`, `RmmReadModel` | installer configuration |
| `endpoint_agent_jobs` | append, pruned by `job_retention_days` | `DeviceService`, `HostedFiles`, `JobExtras`, `JobService` | `AutoInstallService`, `AutomationReader`, `CapacityReport`, `DeployReader`, `DeployScheduler`, `DeployService` +12 | job queue with leases and state transitions; needs transactions with devices and approvals |
| `endpoint_agent_log_rules` | bounded | `LogRuleService` | `Housekeeping` | alert rules for logs; configuration |
| `endpoint_agent_mesh_agents` | bounded by fleet size | `MeshAgentService` | - | MeshCentral agent state per device |
| `endpoint_agent_mesh_nodes` | bounded by fleet size | `DeviceRepository` | `MeshAgentService`, `MeshService`, `RmmReadModel` | mesh node ids per device |
| `endpoint_agent_mesh_settings` | bounded | `MeshAgentSettings` | - | single-row configuration with sealed secrets |
| `endpoint_agent_msi_templates` | bounded | `BinaryStore` | `InstallerDownload`, `RmmAdmin`, `RmmReadModel` | installer templates |
| `endpoint_agent_releases` | bounded | `BinaryStore`, `UpdateService` | `AgentOs`, `RmmReadModel` | agent release channel registry |
| `endpoint_agent_remote_settings` | bounded | `RemoteSettings` | - | single-row configuration |
| `endpoint_agent_settings` | bounded | `RmmSettings` | `LoadShedder`, `RmmState` | single-row module settings (`data_store` lives here) |
| `endpoint_agent_transfers` | bounded, expires | `TransferService` | - | file transfer metadata; the files are on disk |
| `rmm_alerting_settings` | bounded | `StormControl` | - | single-row configuration |
| `rmm_alert_meta` | bounded by fleet size | `AlertService`, `EscalationService` | `StormControl` | alert bookkeeping that joins the edition's tickets and alerts |
| `rmm_approvals` | bounded | `ApprovalService` | `ApprovedRunExecutor` | two-person approvals; transactional with jobs |
| `rmm_cache` | bounded, TTL | `RmmCache` | `CapacityReport`, `RmmModule` | read cache; a Redis candidate, not a history-store one |
| `rmm_check_eval` | bounded by fleet size | `CheckEvalStore`, `CheckEvaluator` | `AlertingActions`, `AlertingEngine`, `CheckPipeline`, `FlapDetector`, `ThresholdResolverInterface`, `Thresholds` | alert evaluation state per device and check (flap, thresholds) |
| `rmm_custom_fields` | bounded | `CustomFieldService` | - | definitions |
| `rmm_custom_field_values` | bounded by fleet size | `CustomFieldService` | - | values per device |
| `rmm_daily_budget` | bounded, one row per UTC day and device | `DailyBudget` | - | abuse budget of the Panel API; atomic counters |
| `rmm_deploy_state` | bounded by fleet size | `DeployExpectations`, `DeployScheduler`, `DeployService` | - | deployment state per device |
| `rmm_device_apps` | bounded by fleet size | `AutoInstallService`, `DeployApprovalService` | `PackageCatalog` | apps wanted per device |
| `rmm_device_checkset` | bounded by fleet size | `CheckReconciler` | - | check set per device |
| `rmm_device_parents` | bounded by fleet size | `DependencyService` | - | dependency graph for alert suppression |
| `rmm_device_software` | bounded by fleet size (about 100 to 500 rows per device) | `SoftwareService` | `Housekeeping`, `RmmReadModel`, `SoftwareHistoryStore` | CURRENT software list per device. Joined with devices and clients by the catalog, the outdated-software report and the device filter, and written in the same transaction as the device state. Its HISTORY is `rmm_software_history` |
| `rmm_device_state` | bounded by fleet size | `DeviceState`, `SoftwareService` | `AutoInstallService`, `DeployScheduler`, `Housekeeping`, `PatchScheduler`, `RmmModule`, `RmmReadModel` | one row per device (presence, hashes, counters); row-locked with `FOR UPDATE` inside the software and check-in transactions |
| `rmm_device_tags` | bounded by fleet size | `TagService` | `DeployExpectations`, `GroupService`, `PatchPolicyStore`, `PatchReader`, `PolicyStore`, `RmmReadModel` +2 | tagging |
| `rmm_escalation_policies` | bounded | `EscalationService` | - | configuration |
| `rmm_escalation_steps` | bounded | `EscalationService` | - | configuration |
| `rmm_groups` | bounded | `GroupService` | `DeployActions`, `DeployScheduler`, `PatchPolicyStore`, `PolicyStore`, `ScopeMatcher`, `TargetResolver` | configuration |
| `rmm_group_devices` | bounded by fleet size | `GroupService` | `DeployExpectations`, `PatchPolicyStore`, `PatchReader`, `PolicyStore`, `ScopeMatcher` | membership |
| `rmm_group_tags` | bounded | `GroupService`, `TagService` | `DeployExpectations`, `PatchPolicyStore`, `PatchReader`, `PolicyStore`, `ScopeMatcher` | membership |
| `rmm_hosted_files` | bounded | `HostedFiles` | `PackageCatalog` | catalog of hosted installer files |
| `rmm_hosted_grants` | bounded, expires | `HostedFiles` | - | download grants |
| `rmm_hypervisor_hosts` | bounded by fleet size | `HypervisorActions`, `HypervisorService` | `Housekeeping`, `HypervisorAlerts`, `HypervisorReader` | one row per reporting host; joined with devices and clients |
| `rmm_hypervisor_storage` | bounded by fleet size | `HypervisorService` | `HypervisorAlerts`, `HypervisorReader` | storage pools per host; alerts read them |
| `rmm_hypervisor_vms` | bounded by VMs (up to 1000 per host) | `HypervisorActions`, `HypervisorService` | `HypervisorAlerts`, `HypervisorHistoryStore`, `HypervisorReader`, `MariaDbHypervisorHistoryStore` | VM inventory with current state; alerts, tickets and the history orphan sweep join it |
| `rmm_installer_apps` | bounded | `AutoInstallService` | `PackageCatalog` | configuration |
| `rmm_job_extra` | bounded by fleet size | `JobExtras`, `ScheduleRunner` | `AutomationReader`, `DeviceService`, `RmmReadModel`, `ScheduleService` | job side data (sealed secrets, schedule links); written with the job |
| `rmm_maintenance_windows` | bounded | `MaintenanceService` | - | configuration |
| `rmm_mesh_after_approval` | bounded by fleet size | `MeshAfterApproval` | - | follow-up state after device approval |
| `rmm_metric_outbox` | append then delete, row-capped | `ExternalMetricSink` | `CapacityReport` | **UNCERTAIN.** Delivery queue to an external metric store (VictoriaMetrics) with leases and retries (`lease_until`, `attempts`). It exists only when an external store is wired, which is the alternative to a Postgres data store, so it is unused when Postgres is selected. Two edition pages read it directly |
| `rmm_packages` | bounded | `PackageCatalog` | `AutoInstallService`, `CapacityReport`, `DeployActions`, `DeployExpectations`, `DeployReader`, `DeployService` +1 | software catalog |
| `rmm_package_assignments` | bounded | `DeployActions`, `DeployApprovalService`, `DeployScheduler` | `CapacityReport`, `DeployExpectations`, `PackageCatalog` | deployment assignments |
| `rmm_package_auto` | bounded | `AutoInstallService` | `PackageCatalog` | configuration |
| `rmm_package_auto_clients` | bounded | `AutoInstallService` | `PackageCatalog` | configuration |
| `rmm_package_installs` | bounded by fleet size | `DeployExpectations`, `DeployScheduler`, `DeployService`, `PackageCatalog` | `CapacityReport`, `DeployReader` | install ledger per device and package (current state) |
| `rmm_package_jobs` | bounded, pruned after 30 days | `DeployScheduler`, `DeployService` | `AutoInstallService`, `CapacityReport`, `DeviceService` | ledger of queued deployment jobs; written with the jobs |
| `rmm_package_params` | bounded | `PackageParams` | `PackageCatalog` | configuration |
| `rmm_package_versions` | bounded | `DeployApprovalService`, `PackageCatalog` | `AutoInstallService`, `DeployExpectations`, `DeployReader`, `PackageParams` | catalog |
| `rmm_patch_assignments` | bounded | `PatchPolicyStore` | - | policy assignments |
| `rmm_patch_available` | bounded by devices x pending updates; installed rows pruned after 14 days | `PatchApprovalService`, `PatchInstallService`, `PatchScanService`, `PatchScheduler` | `CapacityReport`, `PatchComplianceService`, `PatchReader` | current available-update list with approval status; joined with devices and tenancy |
| `rmm_patch_jobs` | bounded, pruned after 30 days | `PatchInstallService`, `PatchScanService`, `PatchScheduler` | `CapacityReport`, `DeviceService` | ledger of queued patch jobs; written with the jobs |
| `rmm_patch_policies` | bounded | `PatchPolicyStore` | `PatchInstallService` | configuration |
| `rmm_patch_rings` | bounded | `PatchPolicyStore` | `PatchApprovalService` | configuration |
| `rmm_patch_rules` | bounded | `PatchApprovalService` | `PatchPolicyStore` | configuration |
| `rmm_patch_scans` | bounded by fleet size | `PatchInstallService`, `PatchScanService`, `PatchScheduler` | `CapacityReport`, `DeployExpectations`, `PatchComplianceService`, `PatchReader` | last scan per device (current state); compliance input |
| `rmm_policies` | bounded | `PolicyStore` | `RmmModule`, `StarterInstaller`, `TargetResolver` | configuration |
| `rmm_policy_assignments` | bounded | `PolicyStore` | `RmmModule`, `TargetResolver` | configuration |
| `rmm_policy_versions` | bounded | `PolicyStore` | - | configuration (versions kept) |
| `rmm_schedules` | bounded | `ScheduleRunner`, `ScheduleService` | - | configuration |
| `rmm_schedule_runs` | append, pruned after `job_retention_days` (at least 30) | `ScheduleRunner`, `ScheduleService` | - | **UNCERTAIN.** Run ledger of scheduled scripts, written with the jobs each run creates, in one transaction. Bounded by schedules x runs |
| `rmm_scripts_v2` | bounded | `ScriptService` | `RmmReadModel`, `ScheduleService`, `StarterInstaller` | script library |
| `rmm_script_versions` | bounded | `ScriptService` | `StarterInstaller` | script library versions |
| `rmm_software_hosts` | bounded | `PackageCatalog` | `RmmModule` | host allow list for package sources |
| `rmm_storage_state` | bounded | `HerdControl`, `StorageState` | `MetricRollup` | **UNCERTAIN.** Named watermarks and notes with three owners: the daily rollup watermark (belongs with the metric store), the partition probe result (belongs to the MariaDB partition tool) and the warm-start state of `HerdControl` (control). Phase 2 has to split the first two out |
| `rmm_storm_summaries` | bounded | `StormControl` | - | alert storm summaries |
| `rmm_tags` | bounded | `TagService` | `DeployActions`, `DeployScheduler`, `GroupService`, `PatchPolicyStore`, `PolicyStore`, `RmmReadModel` +2 | configuration |
| `rmm_token_buckets` | bounded, TTL | `DbTokenBucketStore` | `CapacityReport`, `RmmModule` | rate limit buckets (Redis when configured) |
| `rmm_version_hosted` | bounded | `PackageCatalog` | `HostedFiles` | catalog |

Writers and readers are the names of the Core classes that mention the table, found by search; an entry such as `RmmModule` means a count or a probe, not a data path.

## 6. SQL that is MySQL-specific in the hot paths

All of it sits inside the `Store\MariaDb\*` classes, `DatabaseMetricSink` and the `Storage\*` classes after this change. The Postgres implementation has to replace each construct:

| MariaDB construct | Where | Postgres equivalent to use |
|---|---|---|
| `INSERT ... ON DUPLICATE KEY UPDATE x = VALUES(x)` and `x = x + VALUES(x)` | metric latest/hourly/daily, rollup | `INSERT ... ON CONFLICT (pk) DO UPDATE SET x = EXCLUDED.x` (and `t.x + EXCLUDED.x`) |
| `INSERT IGNORE` | log events, change records, ledger rows | `INSERT ... ON CONFLICT DO NOTHING` (affected rows are the inserted ones, as in MariaDB) |
| `IF(c, a, b)`, `GREATEST`, `LEAST`, `COALESCE` | ledger updates, metric merge | `CASE WHEN`, `GREATEST`, `LEAST` exist in Postgres; `IF` does not |
| `UNHEX(?)`, `HEX(dedupe)` on `BINARY(16)` | log and change dedupe | `bytea` with `decode(?, 'hex')` / `encode(dedupe, 'hex')` |
| `DELETE ... LIMIT n`, `DELETE ... ORDER BY ... LIMIT n` | every prune | `DELETE ... WHERE ctid IN (SELECT ctid ... LIMIT n)` or a keyed subselect; with TimescaleDB, `drop_chunks` |
| `SELECT ... FOR UPDATE` | ledger rows, `deleteBatch` | same syntax; use `FOR UPDATE SKIP LOCKED` only where the contract asks |
| `DELETE h FROM a h LEFT JOIN b ...` | hypervisor orphan sweep | `DELETE FROM a USING ... WHERE NOT EXISTS` |
| `LIKE ... ESCAPE '!'` (case-insensitive by collation) | log/change search | `ILIKE ... ESCAPE '!'` (the contract pins case-insensitivity) |
| `DATE(occurred_at)` grouping | change timeline | `occurred_at::date` |
| `LIMIT n OFFSET m` | pages | same |
| `AUTO_INCREMENT` ids, `insertId` | all history ids | `GENERATED ALWAYS AS IDENTITY`, `RETURNING id` |
| `PARTITION BY RANGE COLUMNS`, `REORGANIZE PARTITION`, `GET_LOCK`, `information_schema.PLUGINS` | `PartitionMaintenance` | declarative partitions or TimescaleDB hypertables; `pg_advisory_lock` |
| `information_schema.TABLES` (`TABLE_ROWS`, `DATA_LENGTH`) | `CapacityReport::tables` | `pg_stat_user_tables`, `pg_total_relation_size` |
| `datetime` without time zone, UTC strings | all | `timestamp without time zone` holding UTC, or `timestamptz`; the contract tests use UTC strings |

Not in the hot paths (and so not part of this work): `SKIP LOCKED` in `IngestQueue`, `GET_LOCK` in `RmmCache`, `information_schema` in `ConfigSequence` and `AgentOs`. All are control.

## 7. Edition-side readers and writers (counts and files only)

Searched: RivetIT (`/var/www/mw-itflow.foleyit.com`) and RivetMSP (`/home/sysadmin/rivetmsp-beta`), excluding `vendor/`, `tests/` and generated reports.

| Edition | Files that run SQL against a hot table | Files that call the MariaDB partition tool or read its tables by name | Other mentions (comments, DDL, labels) |
|---|---|---|---|
| RivetIT | 1: `includes/rmm_log_ui.php` (`SELECT MIN(occurred_at) FROM endpoint_agent_log_events`) | 4: `admin/post/settings_endpoint_agent.php` (`partitions()->probe()/convert('endpoint_agent_check_history')`), `admin/settings_endpoint_agent.php` (`partitions()->partitions()`), `includes/rmm_scale_admin.php`, `includes/rmm_ui_render.php` (read the probe/tier arrays by table name) | `admin/database_updates.php` (DDL for `endpoint_agent_checkins`, comments), `includes/rmm_scale_cards.php` (text) |
| RivetMSP | 1: `includes/rmm_log_ui.php` (same query) | 4: `admin/post/settings_endpoint_agent.php`, `admin/settings_endpoint_agent.php`, `includes/rmm_scale_admin.php`, `includes/rmm_ui_render.php`; plus 1 that constructs the metric store: `includes/rmm_bootstrap.php` (`new DatabaseMetricSink(...)`, also at line 229 for the reader) | `admin/database_updates.php`, `includes/rmm_scale_cards.php`, `includes/rmm_ui.php` and `agent/asset_details.php` (read through `RmmMetricReaderInterface`, no change needed) |

So a later phase has to move **5 files in RivetIT and 6 in RivetMSP** (the number is the direct SQL plus partition-tool files plus the bootstrap; the other mentions need no code). Both editions also run `SELECT ... FROM rmm_metric_outbox` directly in `admin/settings_endpoint_agent.php` (CONTROL, UNCERTAIN); that file is already in the count.

Everything else the editions do goes through `RmmModule` methods (`logService()`, `changeService()`, `readModel()`, `capacity()`, `housekeeping()`), which route through the stores by themselves.

## 8. Interfaces (phase 1)

Namespace `RivetCore\Rmm\Store`. All interfaces are `@api`; implementations are `@internal`.

| Interface | Table(s) | Operations |
|---|---|---|
| `MetricStore` (extends `RmmMetricSinkInterface` and `RmmMetricReaderInterface`) | metric latest, hourly, daily | `ingest`, `latest`, `peak`, `series`, `retentionDays`, `dailyRetentionDays`, `prune`, `rollUp`, `rollupWatermark`. `DatabaseMetricSink` is the MariaDB implementation and keeps its public behaviour; an edition that wires a sink keeps working |
| `CheckHistoryStore` | check history | `append`, `since`, `lastBefore`, `deleteOlderThan` |
| `LogStore` (+ `LogCriteria`) | log events + ledger | `admit`, `search`, `countMatching`, `fleetRowCount`, `deviceLedger`, `oldestEventAt`, `enforceLimits` |
| `ChangeEventStore` (+ `ChangeCriteria`) | change records + ledger | `admit`, `dedupeKeysReceivedAt`, `search`, `timeline`, `countMatching`, `fleetRowCount`, `deviceLedger`, `countsByWatch`, `ledgerTotals`, `hasLedger`, `enforceLimits` |
| `SoftwareHistoryStore` | software history | `append`, `forDevice`, `between`, `deleteOlderThan` |
| `PatchHistoryStore` | patch history | `record`, `forDevice`, `deleteOlderThan` |
| `DeploymentHistoryStore` | package history | `record`, `forDevice`, `deleteOlderThan` |
| `HypervisorHistoryStore` | VM history | `recordTransition`, `recent`, `deleteOrphans` |
| `StoreMaintenance` | rollup and partitions | `run` (rollup, then partitions, report which tiers were trimmed), `tiers` (the capacity panel figures) |

`RmmDataStores` holds one of each; `RmmDataStoreFactory::create($kind, ...)` builds them; `RmmModule::dataStores()` is the module's instance. It is **lazy**: `RmmDataStores::lazy()` hands the services small delegates (`Store\Lazy*`) that read the `rmm_data_store` setting and build the real stores on first use. Building the module must not query the database, because an off module answers a check-in from its state file with zero queries (pinned by `ModuleOffFastPathTest`; the first version of this change broke it by reading the setting while wiring). `RmmDataStoreKind` has the values (`mariadb`, `postgres`).

Design rules that came out of the inventory:

* Operations, not SQL: the criteria objects carry validated values and the store decides how to filter. The caller keeps all rules (what is worth recording, limits, time spans, text lengths).
* Retention is an operation of the store, and loops live in the caller where possible: `deleteOlderThan(cutoff, limit)` deletes one bounded batch, `Housekeeping` repeats it with its pause and run cap. The log and change stores keep the loop inside `enforceLimits` because each batch is atomic with the ledger update.
* The ledgers belong to the stores (`LogStore::admit` takes the caps and does the budget, the caps and the insert in one step).
* Times are explicit parameters, never read inside a store, except the metric store which uses the clock it was built with.

### Setting

`rmm_data_store` is the column `endpoint_agent_settings.data_store` (`varchar(16) NOT NULL DEFAULT 'mariadb'`, migration `0042_rmm_data_store`, additive and idempotent). `RmmSettings::dataStore()` reads it (and answers `mariadb` on an install that has not run the migration); `RmmSettings::update(['data_store' => ...])` accepts only a value that can be SELECTED in this release. In phase 1 and phase 2 that is `mariadb`: `postgres` returns `The RMM data store "postgres" cannot be selected in the settings yet...` and writes nothing (phase 3 adds the connection setup and the test that make selecting it safe). Phase 1 answered a hand-edited `postgres` with MariaDB; since phase 2 that would hide the problem (the data is not in MariaDB), so the module runs the PostgreSQL stores instead, on a connection that fails with the precise reason when the edition passed none (section 12.7).

### Contract tests

`RivetCore\Testing\RmmDataStoreContractTestCase` (in `src/Testing`, like the other conformance cases) pins every method of every store. The MariaDB runs are `tests/Integration/Rmm/MariaDbDataStoreContractTest.php` (the real stores) and `MariaDbLazyDataStoreContractTest.php` (the same cases through the lazy delegates). `RmmDataStoreRoutingTest` proves the callers reach the stores for the reads and prunes the older suites did not pin; both were written after mutation checks (break the implementation or the routing, see the tests fail) found the gaps. For phase 2, add a subclass that returns a Postgres-backed `RmmDataStores` from `stores()`; the control seeding methods (`device()`, `package()`, `vm()`, `deleteVm()`) stay MariaDB.

## 9. What phase 2 and 3 must do

Phase 2 (Postgres implementation and CI) - DONE, see section 12; the list below is what it was asked to do:

1. `Store\Postgres\*` classes for each interface, passing the same contract tests (CI matrix with a Postgres service, and TimescaleDB optional). A `DatabaseInterface` adapter for Postgres is needed; Core ships only the interface (the mysqli adapters live in the editions and in `tests/Support`), so that comes first.
2. DDL for the hot tables in Postgres (types in section 6), kept by a Postgres migration runner that is separate from `CoreMigrations` (those run on the control database).
3. Decide the device attributes of log/change search results (Decision 1 below).
4. Split `rmm_storage_state`: the rollup watermark goes with the metric store (Decision 4).
5. Make `deleteOrphans` work across databases (the MariaDB store joins `rmm_hypervisor_vms`; a Postgres store needs the VM inventory pushed to it or the sweep driven from the VM ids).
6. Package names in `DeploymentHistoryStore::forDevice` come from a join with the catalog; a Postgres store needs a name lookup passed in.
7. Software history is appended inside the inventory transaction today; a store in another database appends after commit and must tolerate a retry (add a deterministic key to the rows).
8. `CapacityReport::tables()` reads sizes from `information_schema`; add a store-level size report.

Phase 3 (setting, recommendation, migration command):

1. Allow `postgres` in `RmmSettings::update()` once the connection is configured (DSN, secret in the SecretBox, never in `endpoint_agent_settings` as plain text), with a connection test.
2. `rmm:data-store:migrate` both ways: copy by table in id order, verify counts per table and per device, a dual-write window (the registry returns a composite store that writes both, reads from the old one), cut over, keep the old store for the rollback period.
3. The capacity report recommends Postgres past a configurable device count X (set from the 10,000-device load test of phase 5), never automatically.
4. Backup, restore and update procedures cover the second database (`deploy/backup.sh`, the in-app backup, `restore.sh`).
5. Alert-to-ticket handoff through an outbox if an alert is ever written next to hot data. Phase 1 does not need it: alerts are CONTROL.

### Same-server PostgreSQL notes

Supported. A separate database and role (no superuser), listen on a unix socket or localhost only, conservative `shared_buffers` and `work_mem` so MariaDB and PHP-FPM keep their memory, both data directories on storage with enough IOPS, back up both. For large fleets a separate host is better. Postgres connections are per PHP-FPM worker unless a pooler is used; budget `max_connections` for the number of workers plus the cron jobs.

## 10. Decisions for Blake (phase 1; Blake's answers are applied in phase 2, section 12.1)

1. **Device attributes in search results.** Log and change search return host name and client id by joining `endpoint_agent_devices`, and scope by client with the same join. A Postgres store cannot join. Options: (a) denormalise `client_id` and `hostname` into the history rows at write time (stale when a device moves clients), (b) the caller passes the visible device ids (can be long) and hydrates names from MariaDB after the query. The interface and the rows already carry `hostname` and `client_id`, so (b) needs no interface change. Recommendation: (b) for names, (a) for `client_id`.
2. **Uncertain tables.** `rmm_package_history`, `rmm_hypervisor_vm_history` (small, bounded), `endpoint_agent_checkins` (large but a replay guard), `endpoint_agent_enroll_attempts`, `rmm_schedule_runs`, `rmm_metric_outbox`, `rmm_storage_state`. Phase 1 treats the first two as HOT and the rest as CONTROL.
3. **Change-monitor status block.** `endpoint_agent_change_state.monitor_json` (the agent's last status) is written in the same statement as the counters, so it moved with them. It is small control-like data; splitting it out costs a second statement per change batch.
4. **Rollup watermark location.** In phase 1 it stays in `rmm_storage_state` (control) and is read through `MetricStore::rollupWatermark()`. With Postgres it should live in the Postgres database next to the tiers it describes.

## 11. Follow-ups that phase 1 left on purpose

* `CapacityReport::tables()` still reads `information_schema` of the control database for every `endpoint_agent_*` table and the hot `rmm_*` tables.
* `StorageMaintenance::run()` reports table names (`rmm_metric_hourly`, ...) in `partitioned` and `retention`; `MetricStore::prune(skip:)` takes the same names. They are logical tier names in the interface docs; a Postgres store may use the same strings.
* `RmmModule::partitions()` and `PartitionMaintenance` stay public and MariaDB-only; the editions call them directly (section 7).
* No UI for the setting (phase 4).

## 12. Phase 2: the PostgreSQL implementation

Everything in this section is built and tested (contract tests on MariaDB, PostgreSQL 16 and TimescaleDB 2.31, an equivalence test that feeds identical event streams to both stores, concurrency, failure injection, a degraded-module test). The operator guide is [docs/rmm/POSTGRES.md](../rmm/POSTGRES.md).

### 12.1 Blake's decisions on phase 1 and how they were applied

| Decision | Applied as |
|---|---|
| 1. A store never joins `endpoint_agent_devices`; the store returns device ids and a denormalised `client_id`, the caller adds host names | Migration `0043_rmm_store_denormalisation` adds a nullable `client_id` to `endpoint_agent_log_events`, `endpoint_agent_change_records` and `endpoint_agent_change_state` (the ledger carries it for the scoped fleet count) and backfills it device by device. `LogStore::admit()` and `ChangeEventStore::admit()` take a trailing `?int $clientId`; their search and timeline rows carry `device_id` and `client_id` and no `hostname`; `Store\DeviceNames` adds the host name (and the current client for a row stored before the column existed) in one query per page, in `LogService::search()`, `ChangeService::search()` and `timeline()`. **Both** stores work this way, so the MariaDB result shape for the callers is unchanged and the contract is one. A device that changes client moves its rows with it: `LogStore::reassignClient()` and `ChangeEventStore::reassignClient()`, called through `Store\ClientMoves` by `DeviceService::transfer()` and by the approval that follows an asset in another client. If the hot store is unreachable at that moment the move is recorded in `rmm_pending_client_moves` (control database) and the housekeeping repeats it until it works, so a client never keeps seeing the history of a device that left it. An edition that moves devices between clients by other means must call `reassignClient()`. A row whose client is NULL (written by an older release during the upgrade window) is visible to an unscoped reader and to nobody scoped by client. The same rule removed the package name join from `DeploymentHistoryStore::forDevice()` (`DeployReader::history()` adds the name from the catalog) and made the hypervisor orphan sweep ask the caller which VM ids exist (`deleteOrphans(?Closure $existingVmIds)`). |
| 2. `rmm_package_history` and `rmm_hypervisor_vm_history` stay HOT, `endpoint_agent_checkins` stays CONTROL | Unchanged. |
| 3. The rollup watermark leaves `rmm_storage_state` and sits next to the tiers | MariaDB: table `rmm_metric_rollup_state` (migration 0043, the existing row copied); `StorageState` reads it first and falls back to the old row, and writes both, so a rollback to the previous release loses nothing. PostgreSQL: `rmm_metric_rollup_state` in the PostgreSQL database (`name`, `watermark timestamptz`, `detail jsonb`). `rmm_storage_state` keeps the partition probe, the maintenance note and the warm-start state. |
| 4. Migration 0042's column stays as is | Untouched. |

### 12.2 Connection port and credentials

`Store\Postgres\PostgresConnectionInterface` is the only thing the PostgreSQL stores know: `query`, `queryOne`, `value`, `execute`, `transaction` (nested calls are savepoints; a deadlock or serialization failure of the outermost transaction repeats the callback, at most `transaction_retries` times), `inTransaction`, `serverVersion`, `close`. Placeholders are positional `?`, values go as bound parameters of server-side prepared statements (a `?` inside a value is data), the session is UTC, and statement, lock and idle-in-transaction timeouts apply. Failures are mapped to `PostgresException` subclasses of Core's `DatabaseException`: `PostgresConnectionException` (unreachable, bad credentials, lost session, too many connections, not configured), `PostgresSerializationException` (retryable), `PostgresTimeoutException`, `PostgresQueryException` (everything else: a bug or a missing schema). Messages are the server's primary message only: no `DETAIL` line (it quotes the failing row), no bound value (quoted text and byte sequences of a data exception are masked), no password.

`Pdo\PdoPostgresConnection` is the shipped adapter and the only class in Core that mentions PDO (CI: "Core must not reference PDO outside the PostgreSQL adapter"). It opens its session on first use, drops it after a connection failure so the next call reconnects, keeps up to 64 prepared statements per session (preparing is a round trip and PDO deallocates a dropped statement with a third; the hot paths repeat a handful of statements; a statement that failed leaves the cache, and "cached plan must not change result type" is retried once), resends a standalone statement the server rolled back whole (a deadlock, or a row routed to the DEFAULT partition while a partition was attached under it), and replaces what PostgreSQL text cannot hold (a NUL byte by U+FFFD, invalid UTF-8 by `?`: history is telemetry and one odd byte must not cost a batch; the driver would otherwise cut the value at the NUL without a word). `socket_timeout_s` (default 30) sets TCP keepalives and the user timeout so a connection to a peer that stopped answering is given up in about that long instead of the kernel's quarter of an hour. `CircuitBreakerConnection` wraps any connection: after a connection failure it stays open for `breaker_open_s` (15 s) and fails every call at once, with the state in a small file in the temp directory so all workers of the host share it (one connect timeout per worker per window instead of one per statement or per check-in). The file is only believed when it belongs to the user the process runs as, a time further away than one window is cut to one window (a stray or planted value cannot hold the breaker open), and workers retry spread over the last third of the window. Under a systemd `PrivateTmp` each service has its own file, which is harmless. `PdoPostgresConnection::fromConfig()` returns the adapter behind the breaker.

`PostgresConfig` is built from the edition's own configuration (`fromArray`, or `fromSealed` with the `SecretBoxInterface` Core already uses for its other secrets). It validates every field (no `;`, quotes or whitespace can reach the connection string), and hides the password from `var_dump`, `print_r`, `var_export`, `json_encode`, serialization and traces (`#[\SensitiveParameter]`, and the value lives in a closure). **Core stores no PostgreSQL credential, in the control database or anywhere else**; phase 3's settings page will store the password sealed with the SecretBox and nowhere else.

### 12.3 Schema

`Store\Postgres\PostgresSchema`, created by `PostgresMigrator` (own ledger `rivet_core_pg_migrations`, own settings `rivet_core_pg_meta`; versioned by id with a checksum of the statements, one migration per transaction, serialized by an advisory lock, idempotent). The tables keep the MariaDB names and columns. Types: `bigint` surrogate ids (identity columns; a sequence on the partitioned table, because identity columns on partitioned tables are not portable across versions), `timestamptz` for every time, `double precision`, `bytea` for the dedupe digest (`CHECK (octet_length(dedupe) = 16)`), `varchar(n)` with the MariaDB sizes (no `char(n)`: PostgreSQL pads it), `text` for the change monitor's status block (the contract returns it byte for byte; `jsonb` would reformat it), `jsonb` for the rollup note. Strings longer than the column are refused, not truncated.

| Table | Layout | Indexes (and why) |
|---|---|---|
| `rmm_metric_latest` | plain, PK (asset, key, instance) | B-tree on `sampled_at` (the retention delete) |
| `rmm_metric_hourly`, `rmm_metric_daily` | native: `PARTITION BY RANGE (hour_start / day_start)`, a DEFAULT partition; timescale: hypertable, 1 day chunks. PK includes the time column | PK B-tree (series lookups); BRIN on the time column (retention and the rollup's time range cost nothing per insert, and the ingest is the hottest path) |
| `endpoint_agent_check_history` | same as above; PK (`hist_id`, `reported_at`) | B-tree (device, key, time, id) for the page lookups; BRIN on time |
| `endpoint_agent_log_events` | plain | unique (device, dedupe); (device, time); (time) |
| `endpoint_agent_log_state` | plain, PK device | the ledger row, locked `FOR UPDATE` in the admit transaction |
| `endpoint_agent_change_records`, `endpoint_agent_change_state` | plain | as the log tables, plus `client_id` on both |
| `rmm_software_history` | plain | (device, time, id); BRIN on time; unique over (device, key, type, time, old, new) so a retried append writes nothing twice |
| `rmm_patch_history`, `rmm_package_history` | plain | (device, time/id), (client, time), (job); BRIN on time |
| `rmm_hypervisor_vm_history` | plain | (vm, id), (device) |
| `rmm_metric_rollup_state` | plain | PK name |

The three big time series use the same primary keys and indexes in both modes, because a unique key of a partitioned table or hypertable must contain the partition column. The event tables are **not** partitioned: their retention is the row caps and the ledger, which count rows and must be updated in the same transaction as every delete; dropping a partition would desynchronize the counters.

### 12.4 Partitions, retention and TimescaleDB

`PostgresStoreMaintenance` implements `StoreMaintenance` with the same rollup, the same `run()`/`tiers()` shapes and the same administrator limits as MariaDB; a `partitioning` limit of 0 with no partitions means nothing physical happens and the caller prunes row by row (the contract test pins this for every store).

* **Native:** the day partitions are created ahead (`partition_ahead` spans of `partition_span_days`). A new partition is built in one transaction under a 3 s `lock_timeout`: `CREATE TABLE ... (LIKE parent INCLUDING ALL)`, move the rows the DEFAULT partition holds for that range, `ATTACH PARTITION`. Expired partitions (upper bound not after the retention cutoff) are dropped whole, oldest first, and an hourly partition is never dropped before the daily rollup watermark covered it. A year-long gap creates one wide catch-up partition (at most 100 per run). Rows past the last partition wait in DEFAULT; those past retention (and, for the hourly tier, past the rollup watermark) are deleted from it. The row move is one statement (`WITH moved AS (DELETE ... RETURNING *) INSERT ...`) under an `ACCESS EXCLUSIVE` lock on the DEFAULT partition, taken inside the same transaction as the `ATTACH`: writers that route a row to DEFAULT wait a moment, writers of other partitions do not, and a row that was routed just before the attach is refused by the server and sent again by the connection. A test runs a writer through three maintenance runs and counts every row. Two runs at once: a session advisory lock; the second reports `busy`.
* **TimescaleDB:** detected with `SELECT extname FROM pg_extension`, never created, never required. With `partitioning = 1` the run enables compression (segment by series/device, order by time, policy after 7 days; a table that cannot be compressed still gets its chunks dropped, and a table only counts as managed once the drop worked, so a failure leaves the caller's row-by-row prune running) and drops whole chunks past retention with the same rollup guard; with 0 it deletes row by row. Reads and late upserts through compressed chunks are tested. **Continuous aggregates were deliberately not used.** The daily tier is an ordinary table written by the same idempotent, watermarked rollup in every store, because a continuous aggregate changes when a day becomes visible, how late data is handled and what the watermark means, and the equivalence test would no longer hold. It can be offered as an experiment in phase 5 if the load test shows the rollup is a bottleneck.

### 12.5 MySQL idioms and their translation

Each construct of section 6 and what the PostgreSQL stores run instead: `ON DUPLICATE KEY UPDATE x = VALUES(x)` / `x = x + VALUES(x)` is `ON CONFLICT (pk) DO UPDATE SET x = EXCLUDED.x` / `t.x + EXCLUDED.x`; `INSERT IGNORE` is `ON CONFLICT DO NOTHING` (the affected rows are the inserted ones, as in MariaDB); `IF()` is `CASE` (or computed in PHP from the locked ledger row); `UNHEX(?)` / `HEX()` is `decode(?, 'hex')` / `encode(..., 'hex')` on `bytea`; `DELETE ... LIMIT n` is a delete joined to a bounded sub-select on the primary key (correct on a partitioned table and on a hypertable; `ctid` would not be); `SELECT ... FOR UPDATE` is the same, with rows locked in one order everywhere; `DELETE h FROM ... LEFT JOIN` is the closure-driven sweep; `LIKE ... ESCAPE '!'` is `ILIKE ... ESCAPE '!'`; `DATE(x)` is `(x AT TIME ZONE 'UTC')::date`; `AUTO_INCREMENT` / `insertId` is identity columns (no store needs the id back); `GET_LOCK` / `RELEASE_LOCK` is `pg_try_advisory_lock`; `information_schema.PARTITIONS` is `pg_inherits` and `pg_get_expr(relpartbound)`; `UNIX_TIMESTAMP` / `FROM_UNIXTIME` never appear in the hot paths (all times are computed in PHP and bound as `Y-m-d H:i:s` UTC strings; reads use `to_char(col AT TIME ZONE 'UTC', ...)`). Per-device counters (`log_state`, `change_state`) keep their same-transaction semantics: the budget, the caps, the insert and the ledger update are one PostgreSQL transaction, and every delete batch moves the counter in the same transaction.

### 12.6 Wiring

`RmmDataStoreFactory::create($kind, $sql, $metrics, $partitions, $postgres)` builds the stores; `postgres` needs the connection and **refuses** (`DataStoreUnavailableException`, naming the module option) without one. `RmmModule` takes the `postgres` option (a connection, a `PostgresConfig`, or a closure returning either; the closure runs once, on first use, and only while the setting is `postgres`, so a MariaDB install never builds, let alone opens, a PostgreSQL connection; `ModuleOffFastPathTest` still pins zero queries for an off module). The metric sink the edition passed is routed by a `LazyMetricStore` to the store of the chosen kind, so one wiring serves both; on `mariadb` the sink is the very object the edition passed. `RmmDataStoreFactory::createMetricStore()` takes the connection for editions that build their own sink.

### 12.7 Degraded behaviour

Design rule: **a check-in never fails because the history store is down, and nothing is ever written to MariaDB in its place.** `Postgres\Guard\HotStoreGuard` wraps every PostgreSQL store (the factory does it; the contract tests run the unwrapped classes through the factory's guards, and the failure tests run both):

| Operation | Behaviour | Why |
|---|---|---|
| `MetricStore::ingest`, `CheckHistoryStore::append`, `SoftwareHistoryStore::append`, `PatchHistoryStore::record`, `DeploymentHistoryStore::record`, `HypervisorHistoryStore::recordTransition`, the two `reassignClient()` | never throws; the data is **dropped and counted** | These run on the check-in or job-result path. The control-database part is already right (check state, inventory, jobs); the history entry is a trend dot or a log line. Buffering them would need a durable queue in the control database (the metric outbox exists only for the external metric store and has a different shape), which is exactly the load a PostgreSQL outage should not move onto MariaDB |
| `LogStore::admit`, `ChangeEventStore::admit` | throws `HotStoreUnavailableException`; `LogService` / `ChangeService` answer **503 `unavailable` with `Retry-After` 60 to 180 s** | The sender can retry (the agent keeps its batch on a 5xx and on the load-shedding 503 that already exists), so nothing is lost and nothing needs counting. The device protocol gains no new status or code |
| Reads | throw `HotStoreUnavailableException` (message names the store and the cause); the **metric** readers answer "no data" and count the fault | Every device page asks for the dials; a blank dial is better than a broken fleet page. Log, change and history reads are explicit queries and say why they failed |
| `StoreMaintenance::run`, prunes, `enforceLimits`, rollup | return the neutral answer and count the fault | The cron job must not die on an outage; the next run catches up |
| `reassignClient()` | throws; `ClientMoves` records the move in the control database and the housekeeping repeats it | Silently skipping it would leave the old client's technicians seeing a moved device's history |
| every lossy write above, when a control database transaction is open | held until the outermost transaction commits (`Guard\WriteBehind`, fed by `Support\TrackedDatabase`, which the module wraps around the control database); discarded if it rolls back | A check-in runs in ONE transaction that holds the device row locked from its first statement to the commit, and the history writes happen inside it. With the hot store in another database that would make the device row wait for PostgreSQL's latency, or for a connect timeout during an outage, and would write history for a check-in that then rolls back. On MariaDB nothing is ever held: the write is part of the transaction, as before. A request that dies between the commit and the flush loses the queued history, the same trade as a dropped write; the software history's unique index makes a repeated append harmless |
| rule evaluation after a stored log or change batch | skipped if the store cannot be read | The batch is stored; answering 5xx would only make the agent resend a duplicate |

Counting: `rmm_hot_store_faults` (control database, migration 0043; per store: writes dropped, failures, first and last time, last error), flushed with one atomic upsert per store at the end of the process; an `error_log` line at most once a minute per store; the capacity panel's `data_store` block and a `data_store` warning (`CapacityReport::dataStore()`, `RmmModule::dataStoreHealth()`, `clearDataStoreFaults()`). Cost of an outage: the circuit breaker (12.2) bounds it to one connect timeout per process per window. **No connection configured** (the setting says `postgres`, the edition passed none, or its factory threw): the module runs the PostgreSQL stores on `UnconfiguredPostgresConnection`, which fails every call with the precise reason, so the table above applies unchanged; the factory itself refuses with the same text.

### 12.8 Equivalence rules

The equivalence test feeds identical pseudo-random streams (seeds in the failure messages) to both stores and compares every read result. What is **the same**: rollups, peaks, series (hourly and daily tier), latest readings, prune counts, admission decisions (rate, device cap + 20 %, fleet cap + 10 %), ledgers, `kept` lists, searches with every filter and paging, totals, timelines, counts, retention results, `enforceLimits`. What is **allowed to differ**, each written down and pinned:

1. `MetricStore::rollUp()['rows']` counts rows written once on PostgreSQL; MariaDB's affected-rows counts an updated row twice. Nothing reads it.
2. Comparison of keys is case-sensitive on PostgreSQL and case-insensitive (and ignores trailing spaces) on MariaDB's `utf8mb4_general_ci`. Metric, check and watch keys are validated to a lower-case pattern before they reach a store, so this only shows for `instance` (device-chosen text) and `actor`: `GROUP BY actor` in the change timeline merges `Alice` and `alice` on MariaDB, not on PostgreSQL. Orderings of keys and instances use `upper(x) COLLATE "C"`, which reproduces the MariaDB order for ASCII.
3. `ILIKE` is case-insensitive but accent-sensitive; MariaDB's collation also folds accents (`e` matches `é`).
4. Software history `append()` is idempotent on PostgreSQL (the inventory commits in the control database and the history in PostgreSQL, so the control transaction may be retried), not on MariaDB (it shares that transaction).
5. `HypervisorHistoryStore::deleteOrphans()` on PostgreSQL deletes nothing without the closure that tells it which VMs exist.
6. Text longer than the column is always refused on PostgreSQL; MariaDB truncates silently under a non-strict `sql_mode`. The services cut text to the column sizes first.
7. A NUL byte in a text value is replaced by U+FFFD and invalid UTF-8 by `?` by the PostgreSQL connection (MariaDB keeps a NUL and refuses invalid UTF-8 in strict mode); the services already replace control characters in what devices send.
8. Sums of doubles are added in the order the engine chooses; the equivalence test compares to nine significant digits.
9. Change search rows from MariaDB also carry the raw `dedupe` column; PostgreSQL does not return it (nothing reads it).
10. Both stores now show the rows of a device that no longer exists in unscoped searches until retention (the old join hid them), with an empty host name; a row whose `client_id` is NULL (written during the upgrade window) is visible to an unscoped reader and to nobody scoped by client.

### 12.9 What phase 3 must do (updated)

1. Setting and connection: allow `postgres` in `RmmSettings::update()` behind a connection test (`PostgresMigrator::status()` through the configured connection, schema at the latest version, `SHOW server_version`), a settings page for the connection with the password sealed by the SecretBox, and a "storage mode" read-out. Teach the editions to pass the `postgres` option from that setting.
2. `rmm:data-store:migrate` both ways, as in section 9, with the counts verified per table and per device, the dual-write window, cut over and the rollback period; the ledgers (`log_state`, `change_state`) are copied, not recomputed, and re-verified against the rows. Preserve event ids only where something refers to them (nothing does today).
3. The capacity recommendation: `CapacityReport::tables()` still reads `information_schema` of the control database; add a store-level size report (`StoreMaintenance::sizes()`), the threshold X, and the "consider PostgreSQL" line.
4. Backup coverage: `deploy/backup.sh`, the in-app backup and `restore.sh` learn the second database (section 8 of POSTGRES.md is the manual procedure), and the update procedure runs `PostgresMigrator::migrate()` after the MariaDB migrations.
5. Flip the `partitioning` default for new PostgreSQL installs (the schema starts partitioned with a DEFAULT partition, so it is safe), and decide whether the housekeeping should auto-enable it.
6. Editions (phase 4): catch `HotStoreUnavailableException` in the pages that read history; call `reassignClient()` wherever a device changes client outside `DeviceService::transfer()`; replace `partitions()->convert()`/`probe()` calls by the interface where the store is PostgreSQL (`RmmModule::partitions()` is MariaDB-only).

### 12.10 Throughput, measured (one shared, noisy host)

`scripts/rmm-store-bench.php` ingests check-in sized batches (about 25 samples, 10 with an hourly rollup, one device per batch), then rolls the days up, reads and prunes, on both stores with the same input. Run on the development machine (containers on one disk that also ran other work; MariaDB 10.11.19 and PostgreSQL 16.15 with default settings, PHP 8.5.12, PDO). **Compare the two columns of one run, never two runs**: the same command gave MariaDB 40, 52, 101 and 135 check-ins per second on different minutes of the same afternoon, because with default durability every commit waits for an fsync of a shared disk.

| Path | MariaDB | PostgreSQL | Setting |
|---|---|---|---|
| check-ins ingested per second | 40 | 90 | default durability (`innodb_flush_log_at_trx_commit=1`, `synchronous_commit=on`), 100 devices x 2 days, median of 3 |
| samples ingested per second | 994 | 2,255 | same run |
| check-ins ingested per second | **212** | **600** | commit flush relaxed on both (`innodb_flush_log_at_trx_commit=2`, `synchronous_commit=off`), 200 devices x 2 days, median of 3 |
| samples ingested per second | 5,294 | 14,994 | same run |
| daily rollup of 2 days, seconds | 0.71 | 0.31 | same run |
| point reads per second (`latest`, `peak`, `series`) | 1,390 | 2,096 | same run |
| rows pruned per second | 58,065 | 43,163 | same run |

What the numbers say, and what they do not: the write path is **fsync-bound** on this host in both engines (relaxing the flush gives 5 to 12 times more), so the durable column mostly measures the disk of the minute. With the flush taken out, PostgreSQL's check-in write (one statement: a data-modifying CTE that upserts the latest readings and the hourly rollups, one round trip, one commit) is about 2.8 times faster than MariaDB's two autocommitted multi-row upserts, the rollup and the point reads are faster, and the batched delete is slower. Nothing here is a 5,000 or 10,000 device figure: that is the phase 5 load test, which must run on a quiet machine with realistic storage. The conclusion for phase 3's recommendation is only that PostgreSQL is not slower on these paths, not how many devices either store carries.

### 12.11 How this was checked

* **Tests (all executed).** On MariaDB 10.11, Redis 7 and PostgreSQL 16 in throwaway Docker containers (random passwords, removed afterwards; a Docker image of `php:8.5` with `pdo_pgsql` and `mysqli`, because the development host's PHP has no `pdo_pgsql`): the full suite in four parallel shards, 3,690 tests; the PostgreSQL directory again in TimescaleDB mode on `timescale/timescaledb:latest-pg16` (2.31.0), 139 tests, one skip (the "TimescaleDB mode is refused without the extension" case). The golden replay, `SchemaDiffTest` and `ModuleOffFastPathTest` (zero queries for an off module; no PostgreSQL connection for a MariaDB install) pass. `composer lint`, `phpstan` (level of the repository, no baseline growth) and the two CI grep rules pass.
* **Mutation checks.** 90 one-line mutations of the PostgreSQL stores, the guards, the connection, the migrator, the partition maintenance, the module wiring and the MariaDB changes were applied one at a time to a copy of the tree and the matching test sets run. 81 were killed at first; the survivors were analysed: the grace period of the rollup, the guard on the DEFAULT-partition expiry, the catch-up partition, the "blank" metric readers, the rule-evaluation failure, the rollup state's new table, and the tie rule of the peak had no test that failed, and now have one; two are equivalent mutants (the write-behind flush at a nested level, which the guard re-checks) and one is not testable (the lock order of two pruners, which only a deadlock would show); two are informational. The first run of the mutation also found that the contract never combined two ingest batches (the SQL `LEAST`/`GREATEST`/`+` of the upserts were untested) and never sent a late older reading on its own; both cases are in the contract now and run against MariaDB too.
* **Independent review.** A fresh reviewer who saw only the diff found two high and several medium problems, all real: rows could be lost while a partition was built (now one atomic statement under a lock, with a test that runs a writer through the maintenance), TimescaleDB retention could be skipped when compression failed (separated, tested on 2.31), a device move during an outage could leave the old client seeing the history (recorded and repeated), PostgreSQL I/O ran inside the check-in's control transaction (write-behind), the breaker file could be planted (owner check, clamp), exception chains carried row data (not kept), and a rule evaluation could fail a stored batch (caught). Its low findings were handled or documented.
