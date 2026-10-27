# Customizable compliance frameworks (phases 1 and 2 of #92)

Status: phases 1 and 2 built in 1.0.0-rc.44 (migration 0046). Phase 3 (integrity chain for hot data) and phase 4 (editions: settings page, backup integration, report page) are not built. Issue: [#92](https://github.com/TheTractorHacker/rivet-core/issues/92). Related: [#89](https://github.com/TheTractorHacker/rivet-core/issues/89) (the PostgreSQL RMM data store whose hot tables are not in the app's backup), the module guide [docs/modules/compliance.md](../modules/compliance.md), the edition prompts [compliance-edition-prompts.md](compliance-edition-prompts.md).

## 1. Goal

Today a compliance framework is five constants. `Framework::LABELS` names ISO 27001, SOC 2, PCI DSS, HIPAA and NIST 800-171; `RetentionPolicy::PROFILES` gives each one a minimum number of days for the audit trail; the checks are tagged with indicative control references. An administrator cannot say "my customer's contract wants seven years of logs, daily encrypted off-site backups and a restore test every quarter", and nothing checks the backups at all, although since #89 the audit-grade history (event logs, change records, patch, software and check history) can sit in a PostgreSQL database that is not in the application's backup.

The change: a framework is **data** an administrator can create, copy from a built-in, edit, version, export and import. It is a list of **requirements**. Core turns each requirement into a check with evidence, enforces the retention ones in the settings, and reports on all of it through the existing assessor, snapshots and reports.

Non-goals: this is still a self-assessment, not a certification (`ReportRenderer::DISCLAIMER` applies unchanged). No framework text is shipped beyond the five existing labels. Per-customer enforcement is not attempted (section 4.3).

## 2. Concepts

| Term | Meaning |
|---|---|
| **Data class** | A kind of data a requirement can be about: `audit_events`, `rmm_logs`, `change_records`, `check_history`, `software_history`, `patch_history`, `deployment_history`, `metrics_hourly`, `metrics_daily`, `backups`. Ids are stored in profiles and files: never renamed. `Compliance\Profile\DataClass`. |
| **Requirement type** | What is demanded: `retention_min` (a data class, days), `backup_rpo_max` (hours), `backup_covers_all_stores`, `backup_offsite`, `backup_encrypted` (yes/no), `restore_test_max_age` (days), `tamper_evidence` (a data class). `Compliance\Profile\RequirementType`. |
| **Requirement** | One type, optionally one data class, one number (or yes/no), an optional note ("contract clause 14.2"). At most one per type and class in a profile. |
| **Profile** | A key, a name, a description and its requirements. Built-in or custom. `Compliance\Profile\FrameworkProfile`, immutable and always valid. |
| **Version** | Every saved change to a custom profile is a new immutable version; the profile points at its current one. |
| **Selected profile** | The one profile the installation works to. None selected = nothing new is checked or enforced. |

## 3. Data model

Six tables, all additive, all `CREATE TABLE IF NOT EXISTS`, no foreign keys (like every Core table; the store deletes the children of a profile itself in one transaction).

```
compliance_profiles            one row per CUSTOM profile
  profile_id PK, profile_key UNIQUE, based_on, current_version, status (active|archived),
  created_by, created_at, updated_at, archived_at
compliance_profile_versions    immutable; one row per saved version
  version_id PK, profile_id, version, label, description, content_hash,
  change_note, changed_by, changed_by_name, created_at          UNIQUE (profile_id, version)
compliance_profile_requirements  the requirements of ONE version
  requirement_id PK, version_id, req_type, data_class ('' = none), value_int, note
                                                                UNIQUE (version_id, req_type, data_class)
compliance_profile_selection   one row (selection_id = 1): the installation's choice
  profile_key, selected_by, selected_by_name, selected_at
compliance_profile_selection_log  append only: select / clear, who, when, which version was current
compliance_restore_tests       append only evidence of restore tests (section 9)
```

Decisions:

* **Built-in profiles have no rows.** They are built on every call from `Framework::LABELS` and `RetentionPolicy::PROFILES` by `BuiltInProfiles::all()`. A table copy would be a second source of truth that can drift; a derived object cannot. They are listed, readable, selectable, exportable and copyable, never changed, archived or deleted (the store refuses with a clear message).
* **Versions are immutable and requirements are normalised per version**, rather than one JSON blob, so a version can be queried ("which profiles ever required off-site backups?") and the history is exact. `content_hash` (SHA-256 of label, description and canonical requirements) makes a save that changes nothing a no-op, so opening and saving a profile does not create versions.
* **History is never rewritten.** "Restore version 2" saves its content as version 5. A profile that was ever selected cannot be deleted (it is evidence of what the installation worked to); it is archived. A never-used profile can be deleted with all its rows.
* **Concurrent edits.** `update()` locks the profile row (`SELECT ... FOR UPDATE`) and takes an optional `expectedVersion`; an editor that started from version 3 when version 4 exists gets "changed by someone else" and nothing is written.
* **The selection follows the current version** of the selected profile. Editing the selected profile changes what is checked and enforced from that moment; the selection log records the version in force when it was selected, and every assessment records the profile key, name and version it was made against (`Assessment::$meta`, stored in the snapshot).

## 4. Choices that needed a decision

### 4.1 Requirement types and checks

| Type | Unit | Check (id) | Reads |
|---|---|---|---|
| `retention_min` on `audit_events` | days | `AuditRetentionCheck` (`audit_retention`) | the days the edition keeps audit events (a callback) |
| `retention_min` on an RMM class | days | `DataStoreRetentionCheck` (`data_store_retention`) | the effective retention of the ACTIVE store: settings as the housekeeping reads them, `StoreMaintenance::tiers()`, the metric store's own retention, oldest log event, row caps (store interfaces from #89, so MariaDB and PostgreSQL alike) |
| `retention_min` on `backups` | days | `BackupRetentionCheck` (`backup_retention`) | `BackupFacts::$retentionDays` |
| `backup_rpo_max` | hours | `BackupRecencyCheck` (`backup_recency`) | `BackupFacts::$lastSuccessAt` |
| `backup_covers_all_stores` | yes/no | `BackupCoverageCheck` (`backup_coverage`) | `BackupFacts::$includedStores` against the stores the active RMM data store requires (`BackupStore::required()`: control database; plus the PostgreSQL RMM store when `data_store = postgres`) |
| `backup_offsite` | yes/no | `BackupOffsiteCheck` (`backup_offsite`) | destinations and `lastOffsiteSuccessAt` |
| `backup_encrypted` | yes/no | `BackupEncryptedCheck` (`backup_encrypted`) | `BackupFacts::$encrypted` |
| `restore_test_max_age` | days | `RestoreTestRecencyCheck` (`restore_test_recency`) | `RestoreTestStore` and the edition's own scheduled test |
| `tamper_evidence` | yes/no | `IntegrityChainCheck` (`integrity_chain`) | nothing yet: phase 3. A profile that asks for it gets a failure that says "Not available: not implemented in this release", never a pass |
| `log_presets_baseline` | yes/no | `SecurityLogPresetsCheck` (`security_log_presets`) | `RemoteSettings::logPresets()` and the `logs` switch against `SecurityLogPresets::complianceBaseline()`. Cannot see the Windows audit policy (frozen protocol): a pass always carries that caveat. Not in the built-ins; `BuiltInProfiles::suggestedExtras()` |

`time-to-availability` (PCI "three months immediately available") from the issue is left out of phases 1 and 2: it needs a notion of online versus archived storage that does not exist yet. Adding a type is additive; an older Core rejects a file that uses it (strict import), which is the safe direction.

Status semantics. **Fail** = known unmet (including "no successful backup on record" and "required but not implemented"). **Warn** = cannot be proven or is incomplete (the edition does not report backup facts, a restore test without row counts, a restore that left out the PostgreSQL store, a row cap that will cut retention short, retention that meets the minimum but is not enforced by the settings). **Pass** = evidenced. **Not applicable** (left out of the score) = no profile selected, or the selected profile does not ask for this. A backup that is late by less than 10 % of the RPO (at least one hour) is a Warn so a nightly job does not flash red just before its run finishes.

Every result carries evidence lines in `CheckResult::$detail`, one per line, each starting `Met:`, `Not met:`, `Evidence:` or `Caveat:`. The HTML report shows them one per line; the CSV report keeps them in the cell.

### 4.2 Built-ins stay identical

A built-in profile has exactly one requirement: `retention_min` on `audit_events` with the preset's `min_days`. That is all `RetentionPolicy` has ever enforced, so selecting a built-in changes nothing about the RMM settings and adds no failing check beyond the audit retention (and the older `RetentionMeetsPresetCheck` keeps working untouched). To get more, copy a built-in and add requirements. `Framework::LABELS`, `Framework::all()`, `RetentionPolicy::PROFILES` and every `RetentionPolicy` function keep their values and behaviour; a golden fixture captured before this change (`tests/Fixtures/compliance/presets-golden.json`) pins them byte for byte, and a test asserts the built-in profiles are derived from those constants and nothing else. New code lives in new classes alongside; no existing signature or constant value changed (Roave).

### 4.3 One selected profile per installation, not per client

The existing responsibility model answers "who is responsible for this section" per installation (`compliance_responsibilities`), while `compliance_subjects` gives an MSP's customer its own *checklist frameworks* (built-in keys, used to tag a manual checklist, with no automatic checks because Core cannot see a customer's systems). Retention, backups and restore tests are properties of the installation's databases and its single backup run: the audit table, the log table and the backup set are shared by every client. A retention floor cannot be applied to one client's rows only (housekeeping prunes by age across the store), and a backup cannot be "off-site for customer A only".

So the selection is installation-wide (`selection_id = 1`). A contract with one customer is modelled by selecting a custom profile that carries the strictest values the installation must meet. Per-subject checklists are untouched. Selecting several profiles at once with a "strictest wins" merge is possible later without a schema change (the selection table would gain rows); it is not built because one profile is enough for the acceptance criteria and because "which of the two profiles failed this requirement" is a reporting problem worth its own design.

### 4.4 Retention floors above a setting's maximum

The retention settings have their own maxima (RMM logs 90 days, change records 730, check history 365, hourly metrics 400). Contract X asks for seven years. The constants cannot change value (compatibility), so the floor is **applied over** the setting: `limits()` never answers less than the floor, even when the floor is above the setting's maximum, and a save is not refused for a value that could never satisfy the floor (a form that posts every limit must not be blocked by a field it cannot fix). The check then reports the real, floored retention and, for logs and change records, warns when the row caps (`logs_fleet_max_rows`, `change_fleet_max_rows`) are nearly reached, because a full store deletes its oldest rows long before seven years. Raising a hot table to seven years is a storage decision the administrator makes with the capacity panel; Core does not hide it.

## 5. Versioning

* `FrameworkProfile::$version` is the profile's version, starting at 1. `schema_version` is the version of the **file format** (section 6), also 1.
* A save adds version N+1 only if the content hash changed. Version rows record who (`changed_by` and a name that survives user deletion), when and a change note of up to 300 characters.
* `diff($key, $from, $to)` returns added, removed and changed requirements and whether the name or description changed, for a "what changed" view.
* `revertTo($key, $version, ...)` saves an old version's content as a new version.
* Archived profiles are hidden from the default list, cannot be selected or edited, and can be restored.

## 6. Import and export

The export of a profile (built-in or custom, any version) is one JSON document:

```json
{
  "format": "rivetcore-compliance-profile",
  "schema_version": 1,
  "profile": {
    "key": "contract_x",
    "label": "Contract X",
    "description": "7-year retention, daily encrypted off-site backups, quarterly restore test",
    "based_on": "hipaa",
    "version": 1,
    "requirements": [ ... ]
  }
}
```

Fields: `key` (3 to 48 characters, lower-case letters, digits and underscores, starting with a letter; not a built-in key and not `none`), `label` (1 to 120 characters), `description` (up to 1000, line breaks allowed), `based_on` (a key or null; informational), `version` (informational), `requirements` (at most 64). A requirement is `{ "type": ..., "data_class": ..., "value": ..., "note": ... }`: `data_class` only for types that take one, `value` a whole number in the type's range (days or hours), left out (or `true`) for yes/no types, `note` up to 300 characters.

<!-- example:contract-x -->
```json
{
  "format": "rivetcore-compliance-profile",
  "schema_version": 1,
  "profile": {
    "key": "contract_x",
    "label": "Contract X",
    "description": "7-year retention, daily encrypted off-site backups, quarterly restore test",
    "based_on": null,
    "version": 1,
    "requirements": [
      { "type": "retention_min", "data_class": "audit_events", "value": 2555, "note": "Contract X clause 9.1: seven years" },
      { "type": "retention_min", "data_class": "rmm_logs", "value": 2555 },
      { "type": "retention_min", "data_class": "change_records", "value": 2555 },
      { "type": "retention_min", "data_class": "check_history", "value": 2555 },
      { "type": "retention_min", "data_class": "patch_history", "value": 2555 },
      { "type": "retention_min", "data_class": "software_history", "value": 2555 },
      { "type": "retention_min", "data_class": "backups", "value": 2555 },
      { "type": "backup_rpo_max", "value": 24, "note": "daily backups" },
      { "type": "backup_covers_all_stores" },
      { "type": "backup_offsite" },
      { "type": "backup_encrypted" },
      { "type": "restore_test_max_age", "value": 92, "note": "quarterly restore test" }
    ]
  }
}
```

**Import is strict, because the file is untrusted input.** `ProfileCodec::parse()`:

* at most 64 KiB, nesting depth 6, valid JSON and valid UTF-8 (a leading byte-order mark is tolerated);
* the top level is exactly `format`, `schema_version`, `profile`; the profile exactly the fields above; a requirement exactly its four. **An unknown key at any level is an error**, not ignored (a typo or a smuggled field never silently disappears);
* `format` must match; a `schema_version` newer than this release refuses with "written by a newer RivetCore";
* exact types: no numeric strings, no floats, no big integers, `true` only for yes/no types;
* every number inside the bounds of its type; a type that does not take a data class refuses one; a data class the type does not allow is refused;
* no duplicate type and class; at most 64 requirements;
* text rules for label, description and notes: no control characters (line breaks only in the description), and nothing that looks like a credential: a PEM header, `password:`/`token=`/`api key:` followed by a value, a cloud access key id, a GitHub or Slack token prefix, a 32 or more character hex string, a 40 or more character base64 run, or a URL with embedded credentials;
* **the file's `version` is informational.** An import always creates version 1 of a new profile, or a new version of an existing custom profile; it can never overwrite history or a built-in. A key collision is refused unless the caller imports under another key or as a new version.
* all problems are reported together (at most 20) in plain text that never repeats the rejected value.

An export contains the profile's content and nothing else: no ids, no user names, no dates, no settings, no installation data. Markup in a label is stored as text and escaped by every renderer (a test renders `<img onerror>` as `&lt;img ...`).

## 7. Retention enforcement

`Rmm\Settings\RetentionFloorsInterface` (`floorDays($dataClass)`, `requiredBy()`) is implemented by `Compliance\Profile\RetentionFloors` from the selected profile and handed to the module as the option `retention_floors`. With it set:

* `RmmSettings::update()`, `RemoteSettings::update()` and `ChangeSettings::update()` refuse a retention below its floor, naming the framework and the requirement ("Retention of RMM logs cannot be set below 30 days: the compliance framework Contract X (version 1) requires at least 30 days (requirement: minimum retention of RMM logs). Use 30 days or more, or choose another framework."). Nothing of a refused save is written. Zero ("record none" for check history) is below any floor.
* `limits()` raises a stored value that is below a newly selected floor, so the housekeeping can never delete rows younger than the minimum. The stored value is left as the administrator saved it (selecting another profile later gives it back).
* `RmmSettings::effectiveExplicitLimits()` does the same for the metric tiers, including when the administrator stored nothing and the metric store's own retention is shorter; `Housekeeping` uses it.
* Without the option nothing changes: the setters default to `null`, and every code path then runs the old statements.

It is the same class of rule as `RetentionPolicy::effectiveDays()` (a floor, never a ceiling: more retention is always allowed). Reading the floors costs one indexed query per request that reads a limit; `RetentionFloors` memoizes it and an edition may pass its own cached loader. If the profile tables cannot be read (code deployed before migration 0046) it logs once and answers "no floor" so the module keeps working.

## 8. What the edition provides

Core cannot read `deploy/backup.sh`, the in-app backup or an off-site upload. The edition reports facts (`Backup\BackupFactsProviderInterface`, `@api`) and Core judges them:

| Fact | Meaning |
|---|---|
| `available` | false when the edition cannot say (then every backup check is a Warn "Not available") |
| `lastSuccessAt` | when the newest SUCCESSFUL backup finished |
| `includedStores` | `control_db`, `rmm_postgres` |
| `destinations`, `lastOffsiteSuccessAt` | `local` and/or `offsite`; when a copy last reached the off-site destination |
| `encrypted`, `encryptionMethod` | every file encrypted before it left the server; a label such as `age`, never a key |
| `retentionDays` | how long backup sets are kept |
| `lastRestoreTest` | optional: the edition's own scheduled restore test |

`Testing\BackupFactsProviderConformanceTestCase` checks the contract (never throws, repeatable, no future timestamps, nothing claimed without a successful backup, an off-site arrival needs an off-site destination, no secret in any field); mutation tests prove each rule is checked.

Edition responsibilities, in short (ready-to-paste prompts: [compliance-edition-prompts.md](compliance-edition-prompts.md)): run migration 0046; implement and wire the facts provider from the real backup record; put the PostgreSQL store in the real backup with encryption and the off-site upload (`BackupHook`); pass `retention_floors` to the module and apply `RetentionFloors::effectiveDays('audit_events', ...)` before pruning the audit trail; add `ProfileChecks` to the assessor; the settings UI; the restore-test form; gate every mutation behind the highest administrative permission; wire the audit callback.

## 9. Restore-test evidence

`Backup\RestoreTestStore::record()` stores who, when, which stores were restored, the data store kind, which backup was restored, the duration, the row counts (`store`, `table`, `expected`, `restored`, at most 200) and a reduced reconciliation report. The store enforces what makes a record trustworthy: a test cannot be recorded as passed when any row count differs; counts only for stores that were part of the test; no future date; plain-text notes without secrets. Records are append-only and never pruned.

`RestoreTestStore::verifySummary($json)` accepts the JSON printed by `php scripts/rmm-store-verify.php --json`, checks its shape and its consistency (`clean` must match the findings), and keeps the store kind, the time, whether it was clean, the number of findings per code and the row count of each hot table; device ids and finding text are dropped. `RowCounts::control()` / `::postgres()` count the (allow-listed) tables before the backup and after the restore, and `RestoreTestStore::pairCounts()` pairs them (a table missing from the restore counts as zero rows, so it is a mismatch and not silently skipped).

The restore-test check passes only when the newest test passed, is recent, restored every store in use, and compared row counts that matched. Without counts, or without the PostgreSQL store on a PostgreSQL installation, it is a Warn with the reason. A newer failed test beats an older pass.

## 10. Migration plan

* Migration `0046_compliance_framework_profiles` (`Compliance\Migration\Migration0046FrameworkProfiles`), appended to `CoreMigrations::all()`. Numbers 0045 (reporting views) and 0046 were taken in parallel branches; if 0046 is taken at merge time, renumber the class and the id and update the three lists in `MigrationAndAuditTest`. The migration only creates tables; it is idempotent (a test runs the body repeatedly).
* **Code before migration is safe.** Nothing reads the new tables unless the edition wires them (the checks, the store, `RetentionFloors`); `RetentionFloors` swallows a missing table, and the RMM settings behave as before without the option. Rolling back the code leaves six unused tables.
* No data migration: an install that does nothing has no selection, so nothing is checked or enforced. To adopt, the edition selects the built-in whose key equals its existing preset (`$store->select('hipaa', ...)`), which asks for exactly what the preset asked for.
* Editions run the migration through `MigrationRunner` as for every Core migration and add the six tables to their `db.sql`; they need not copy their rows anywhere.

## 11. Security

* **No secrets in profiles.** The format has no free-form fields except label, description and notes, all plain text and all passed through the credential detector above on creation, import and edit; backup facts accept only a short label for the encryption method and the conformance case fails a provider that reports a key, passphrase or URL with credentials. Exports contain nothing but profile content (tested).
* **Who may edit.** A profile decides what the system enforces and reports, and a weaker selection lowers a safeguard, so every mutating method assumes the caller proved the user has the highest administrative permission. Core has no permission model; the edition must gate the settings page and each POST (and CSRF-protect them), not only hide buttons. Each write is attributed (user id and a name that survives account deletion) and sent to an optional audit callback (action, key, version, name; never text); a failing callback is logged and does not undo the change. Selecting or clearing a profile is logged with the version in force.
* **Untrusted input.** Import is strict (section 6); every query uses placeholders; keys are validated by a pattern before they reach SQL; text is escaped by every renderer; file size, depth and requirement counts are capped, so a hostile file costs a bounded parse.
* **Fail closed on evidence, fail open on enforcement.** Facts that cannot be read are Warn, never Pass. Enforcement that cannot read its floors (missing table, database trouble) applies none and logs, because refusing to run the module is a worse outcome than a missed floor; the compliance check reads the profile directly and reports the failure.
* **Errors do not leak.** A check that throws becomes "could not run" with no message (assessor behaviour); store errors are caught and reduced to a fixed phrase (tested with a message containing a password and a host).
* **Tampering.** Version rows and selection log rows are append-only and carry who and when, but a database administrator can edit them: tamper evidence for this data is phase 3.

## 12. Phases

1. (done) This design, the data model, migration 0046, `FrameworkProfileStore`, built-ins, import and export.
2. (done) The requirement types, the nine checks, `BackupFactsProviderInterface` and its kit, retention enforcement, restore-test evidence, assessor/snapshot/report integration.
3. Integrity chain for audit, log and change data (hash-chained or sealed batches, verified on demand); replaces the `IntegrityChainCheck` placeholder. Also: time-to-availability.
4. Editions: settings page, backup integration, report page.

## 13. Decisions for Blake

1. **One profile per installation** (section 4.3) or "strictest of several" later? Built with one.
2. **Floors above a setting's maximum are applied over the setting** (section 4.4) so Contract X's seven years is representable. The alternative is to raise the maxima, which needs new constants (the old ones may not change value) and is a storage decision. Row caps still bound logs and change records.
3. **A built-in asks only for audit retention.** Should selecting HIPAA also require, say, encrypted backups and a yearly restore test? That would change what an install that selects HIPAA is judged on; the current choice keeps built-ins identical and puts the extra in copies.
4. **Fail on unknown?** Unavailable backup facts are a Warn (0.5 in the score), not a Fail.
5. **Migration number.** 0046 assumes the reporting branch takes 0045.
