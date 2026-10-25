# RMM data store: where the hot RMM tables live

Status: phase 1 of [#89](https://github.com/TheTractorHacker/rivet-core/issues/89) is implemented (inventory, interfaces, MariaDB implementation, setting that refuses `postgres`). Phases 2 to 5 are not started. Roadmap: "Enterprise architecture track", item 8.

**Behaviour in phase 1 is identical to before.** The same SQL runs against the same tables; it moved behind interfaces. The device protocol is untouched (golden replay unchanged). `rmm_data_store` is `mariadb`, and `postgres` is refused with "not available yet".

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

`rmm_data_store` is the column `endpoint_agent_settings.data_store` (`varchar(16) NOT NULL DEFAULT 'mariadb'`, migration `0042_rmm_data_store`, additive and idempotent). `RmmSettings::dataStore()` reads it (and answers `mariadb` on an install that has not run the migration); `RmmSettings::update(['data_store' => ...])` accepts only a value this release can run. `postgres` returns the message `The RMM data store "postgres" is not available yet in this release...` and writes nothing. If the column is hand-edited to `postgres`, `RmmModule::dataStores()` logs the same message and uses MariaDB, because the data is there and check-ins must not fail.

### Contract tests

`RivetCore\Testing\RmmDataStoreContractTestCase` (in `src/Testing`, like the other conformance cases) pins every method of every store. The MariaDB runs are `tests/Integration/Rmm/MariaDbDataStoreContractTest.php` (the real stores) and `MariaDbLazyDataStoreContractTest.php` (the same cases through the lazy delegates). `RmmDataStoreRoutingTest` proves the callers reach the stores for the reads and prunes the older suites did not pin; both were written after mutation checks (break the implementation or the routing, see the tests fail) found the gaps. For phase 2, add a subclass that returns a Postgres-backed `RmmDataStores` from `stores()`; the control seeding methods (`device()`, `package()`, `vm()`, `deleteVm()`) stay MariaDB.

## 9. What phase 2 and 3 must do

Phase 2 (Postgres implementation and CI):

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

## 10. Decisions for Blake

1. **Device attributes in search results.** Log and change search return host name and client id by joining `endpoint_agent_devices`, and scope by client with the same join. A Postgres store cannot join. Options: (a) denormalise `client_id` and `hostname` into the history rows at write time (stale when a device moves clients), (b) the caller passes the visible device ids (can be long) and hydrates names from MariaDB after the query. The interface and the rows already carry `hostname` and `client_id`, so (b) needs no interface change. Recommendation: (b) for names, (a) for `client_id`.
2. **Uncertain tables.** `rmm_package_history`, `rmm_hypervisor_vm_history` (small, bounded), `endpoint_agent_checkins` (large but a replay guard), `endpoint_agent_enroll_attempts`, `rmm_schedule_runs`, `rmm_metric_outbox`, `rmm_storage_state`. Phase 1 treats the first two as HOT and the rest as CONTROL.
3. **Change-monitor status block.** `endpoint_agent_change_state.monitor_json` (the agent's last status) is written in the same statement as the counters, so it moved with them. It is small control-like data; splitting it out costs a second statement per change batch.
4. **Rollup watermark location.** In phase 1 it stays in `rmm_storage_state` (control) and is read through `MetricStore::rollupWatermark()`. With Postgres it should live in the Postgres database next to the tiers it describes.

## 11. Follow-ups that phase 1 left on purpose

* `CapacityReport::tables()` still reads `information_schema` of the control database for every `endpoint_agent_*` table and the hot `rmm_*` tables.
* `StorageMaintenance::run()` reports table names (`rmm_metric_hourly`, ...) in `partitioned` and `retention`; `MetricStore::prune(skip:)` takes the same names. They are logical tier names in the interface docs; a Postgres store may use the same strings.
* `RmmModule::partitions()` and `PartitionMaintenance` stay public and MariaDB-only; the editions call them directly (section 7).
* No UI for the setting (phase 4).
