# Core security audit 2026-10: compatibility results

Release 1.0.0-rc.46 against RivetIT (/var/www/mw-itflow.foleyit.com, pinned rc.42) and RivetMSP (rivetmsp-beta, pinned rc.37; live site rc.30). The edition trees were only read (grep and file reads); nothing in them was edited.

## Machine checks

| Check | Result |
|---|---|
| Roave backward-compatibility-check 8.23.0, `--from=v1.0.0-rc.44 --to=HEAD` (branch core-audit, src unchanged after commit 82a61db) | 210 lines, all of them `[BC] SKIPPED ... PHPUnit\Framework\TestCase could not be found` (the filter CI applies). Real `[BC]` lines: 0. |
| `docs/api-surface.md` regenerated | 3 additive lines: `RedisConnectionConfig::jsonSerialize()`, `EnrollmentService::assetClientId()`, `RmmProtocol::matches()` |
| Conformance kit (`src/Testing/*Conformance*`, run through `tests/Conformance`, `KitMutationTest`, `RmmTenancyKitTest`) | see "Verification" in the final run table below |
| Protocol freeze: golden replay, `SchemaDiffTest`, `ModuleOffFastPathTest` (zero queries) | see "Verification" below |

## Matrix: change x edition x call sites x result

| # | Shared API or behaviour changed | RivetIT (rc.42 pin) | RivetMSP (rc.37 pin / live rc.30) | Result |
|---|---|---|---|---|
| 1 | `UrlPolicy::vet()` refuses hosts that are not plain DNS label characters | 34 references (webhook delivery, automation, metric store, Mesh); none builds hosts with `%` or non-ASCII | 54 references, same use | No impact for normal hosts. An admin who typed a Unicode host must switch to punycode (it could not be pinned reliably before). |
| 2 | Mesh probes use `WebhookDispatcher::pinnedUrl()` | edition only calls `MeshService::probe/probePin` through `RmmAdmin::saveMesh` | same | No impact |
| 3 | `RedisConnectionConfig`: `read_write_timeout` key, `JsonSerializable`, `__serialize()` throws | `includes/Redis/RedisSettings.php` calls `fromArray()` and `RedisAdmin::client()`; never serialises it | `RedisSettings::config()` returns it to callers; `client()` via `RedisAdmin`; no `json_encode`/`serialize` of it found | No impact. If an edition ever caches the object it now gets a clear `LogicException` (rebuild from settings). |
| 4 | `PostgresConfig` password holder | edition builds via `fromArray/fromSealed`; reads `password()` only | same | No impact (private property type) |
| 5 | `VictoriaMetricsStore` `__debugInfo`, `#[\SensitiveParameter]` | 3 `new VictoriaMetricsStore(...)` sites with positional arguments | 3 sites | No impact (positions unchanged) |
| 6 | `SecretBoxInterface` parameters `#[\SensitiveParameter]` | 1 implementation (`EndpointSecretBox`) | 2 implementations | No impact (attributes are not part of the signature; implementations may omit them). Optional: add the attribute. |
| 7 | `TechnicianActions::resolvePending`, `transfer`; `InventoryActions` tag/group mutators; `ScriptActions` update/retire: 403 for client-limited users | The edition's technician API/UI passes `ActionResult` through (`http`, `message`); no class extends these | same | Behaviour change for client-limited technicians only; unrestricted users unchanged. No code change needed; UI may want to hide the buttons for limited users (optional prompt A). |
| 8 | `EnrollmentService::assetClientId()` (new public method) | no subclass or decorator of `EnrollmentService` | none | No impact |
| 9 | `Redactor::redact()` masks more and returns `[REDACTED]` if PCRE fails | RivetIT has no direct call (Core calls it when storing job output/logs) | 10 references in `vendor/` copies and edition code that go through Core | No code impact; stored output can show more `[REDACTED]`. Any edition test that asserts raw `DB_PASS=...` in job output must change (none found). |
| 10 | `RmmProtocol::matches()` used by validators | edition does not call the validators directly | same | No impact; agents never send a trailing newline (the Go agent validates with anchored RE2) |
| 11 | Lock names (`PartitionMaintenance`, `JobClaimer`, `RmmCache`) carry the database hash | no edition code references the old lock names (grep) | only `vendor/` copies reference them | No impact. During a rolling upgrade one maintenance pass can run twice. |
| 12 | `DataStoreSwitch::testConnection()['warnings']`, `RmmAdmin::saveMesh()` `data['warnings']` may have one more entry | both render the `warnings` list generically | same | No impact |
| 13 | `RmmAdmin::saveMesh` warning text | edition Mesh wizard displays Core's warnings | same | No impact |
| 14 | CurlHttpTransport header check, PanelAttachments names | not called by name | not called by name | No impact |
| 15 | Locks/validators/secret scanner files, CI, `.gitleaks.toml` | n/a | n/a | Not shipped to editions except `scripts/` in the package (unchanged behaviour) |
| 16 | `build-host.sh` (symlink refusal, image pull), `build-host.conf.example` | an installed build host keeps its own config; only a fresh copy of the example changes | same | No impact. Check an existing `/etc` build-host config does not point at `github.com/rivet/rivet-core`. |

Breaking changes: none. Needs an edition change: none. Optional edition changes: prompts A and B below.

## Edition prompts (paste-ready, nothing here was applied)

**Prompt A (RivetIT and RivetMSP, optional UI polish).** "In the RMM technician screens, hide or disable the actions 'delete/rename tag', 'delete/rename group', 'edit/retire library script' and 'link pending device to an asset of another client' when `RmmAuthorizer::visibleClientIds($userId) !== null` (the user is limited to some clients). Core now answers 403 for them; show the message from `ActionResult->message` instead of a generic error. No data or schema change."

**Prompt B (RivetIT and RivetMSP, optional hardening).** "(1) Add `#[\SensitiveParameter]` to the parameters of your `SecretBoxInterface::encrypt()/decrypt()` implementation. (2) Never `serialize()`, `json_encode()` or `var_export()` a `RivetCore\Redis\RedisConnectionConfig`; if you cache connection settings, cache the settings array (sealed) and call `RedisConnectionConfig::fromArray()` per request. (3) After updating Core, open Administration > Endpoint agent > MeshCentral and read the new warning if the account name has no `{user_id}`; switch to a per-technician account and restrict each Mesh account to the device groups of the clients that technician may reach."

**Prompt C (RivetMSP live site on rc.30, when it adopts a newer pin).** "Core rc.46 adds no migration. Before adopting, search your own tests for assertions on raw credential-looking strings in stored job output, and for Unicode webhook hosts; read UPGRADING.md section 'Security audit 2026-10'."
