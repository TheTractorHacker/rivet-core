# Public API (issue #30)

What an edition may rely on, at which stability level, and how a change to it is caught. This is the document the 1.0 freeze is
reviewed against. The signature-level list is [api-surface.md](api-surface.md) (generated for reading); the machine-checked source of
truth is [tests/api-surface.json](../tests/api-surface.json). The policy is in
[ADR-004](architecture/ADR-004-versioning-and-compatibility.md). Earlier review notes: [api-freeze-review.md](api-freeze-review.md).

Status: written against 0.21.0. Every stability level below is a **proposal for the owner to confirm**; nothing here changes code.

## What counts as public

| Part | Public means | Where it is recorded |
|---|---|---|
| **Types** | every class, interface, enum and trait whose docblock says `@api`: its kind (final/abstract/readonly), parents, interfaces, public constants and their values, every public method signature, public properties | `types` in the snapshot |
| **Internal types** | named `@internal` (migration classes, `Testing\DatabaseContractTestCase`): outside the promise, but moving a type between public and internal is a visible change | `internal` |
| **Tables** | the 16 Core tables and `rivet_core_migrations`: names, columns, column types and nullability. Editions may read them; writing them is only supported through Core's services | `tables` (captured from a database where the migrations ran) |
| **Migrations** | the ordered ids from `CoreMigrations::all()`; ids and meaning never change, new ones are appended | `migrations` |
| **Vocabularies** | identifiers editions persist or send: the 113 webhook event ids, the 24 webhook destination ids, the payload formats, date-range preset ids, compliance framework ids, retention profile ids | `vocabularies` |
| **Wire formats** | webhook request headers (`X-Rivet-Signature-V2`, `X-Rivet-Timestamp`, legacy `<prefix>-Signature`/`-Event`), the JSON body shape `{event, timestamp, data}`, the MCP envelope `{success, request_id, data, errors}` | tests (`WebhookHardeningTest`, `McpTest`) and [webhooks.md](webhooks.md) |
| **Flags and settings** | see below | this document |

### Flags, settings and environment

Core has very few switches by design: a module is off until the edition calls it.

| Name | Read by | Meaning |
|---|---|---|
| `mcp.enabled`, `mcp.issuer`, `mcp.audience` (settings keys) | `Mcp\McpConfig` through `SettingsInterface` | module switch, token issuer, token audience |
| `<PREFIX>ENABLED`, `<PREFIX>ISSUER`, `<PREFIX>AUDIENCE` (environment, prefix chosen by the edition) | `Mcp\McpConfig` | override the settings; `<PREFIX>ENABLED=0` is a hard off switch |
| `RetentionService` horizons and profile; `WebhookDispatcher` `requireUrlPolicy`; `UrlPolicy` `allowPrivate` / `allowedNetworks`; per-rule `is_enabled` | constructor/method arguments | the edition passes them in from its own settings |
| `RIVETCORE_TEST_*` | the test suite only | not API |

Core never reads `$_SERVER`, `$_ENV` (other than through the edition-supplied environment callable), superglobals or edition functions
(CI greps for this).

## Stability levels

- **Stable.** Covered by semantic versioning from 1.0: no removal or incompatible change before 2.0. A bug fix that changes behaviour
  to match the documented contract is a patch.
- **Provisional.** Public and supported, but the shape may still change in a minor release before 1.0 is tagged, with a changelog
  entry. Each one has a stated reason below. The aim is zero Provisional at `1.0.0-rc.1`: each is either promoted to Stable or
  moved to `@internal`.
- **Internal.** Not part of the promise.

## Contracts an edition implements

These are the interfaces an edition writes adapters for. The [conformance kit](adapters.md#conformance-kit) checks them.

| Interface | Purpose | Notes |
|---|---|---|
| `Database\DatabaseInterface` | all storage | verified by `Testing\DatabaseContractTestCase` and the kit; parameters are `string|int|float|bool|null` |
| `Contracts\ClockInterface` | time | |
| `Contracts\SettingsInterface` | read edition settings | |
| `Contracts\RequestContextInterface` | IP, user agent, request id for audit rows | request id must be server-generated |
| `Redis\RedisClientProviderInterface` | a Predis client or `null` | `null` means "Redis unavailable"; everything fails open |
| `Webhooks\WebhookSubscriptionsInterface` (+ optional `WebhookSubscriptionLookupInterface`) | endpoints for an event | |
| `ITSM\TicketProblemLinkInterface` | tickets belong to the edition | |
| `Mcp\AgentDirectoryInterface` | agents (staff users) for identity linking | identities are case sensitive |
| `Compliance\CheckInterface`, `AttestationProviderInterface` | what the edition can prove | |
| `Contracts\AccessPolicyInterface` | authorization (ADR-003) | Provisional: nothing enforces it yet |
| `Migration\MigrationInterface` | only Core implements it | edition migrations are the edition's |

## Error contract

- Storage failures: `Database\DatabaseException`. Callers that must not fail (audit in a request) catch it.
- Refusals: the module's own exception (`PermanentJobFailure`, `DocxConversionException`, `PdfConversionException`, `NotFoundException`,
  `Contracts\AccessDenied`) or `InvalidArgumentException`/`RuntimeException` for an illegal state transition or invalid input.
- Redis and webhook delivery: never throw; they report ("not acquired", `degraded`, `ok: false`).

## Per-type table

Generated by `php scripts/public-api-table.php --write` from the snapshot; do not edit between the markers.

<!-- types:begin -->
| Type | Kind | Edition does | Proposed stability | Why provisional |
|---|---|---|---|---|
| `Audit\AuditPage` | final class | call | Stable |  |
| `Audit\AuditReader` | final class | call | Stable |  |
| `Audit\AuditService` | final class | call | Stable |  |
| `Automation\AutomationExecutor` | final class | call | Stable |  |
| `Automation\AutomationRuleEvaluator` | class | call | Stable |  |
| `Automation\AutomationRuleStore` | final class | call | Stable |  |
| `Automation\EventContext` | final class | call | Stable |  |
| `Compliance\Assessment` | final class readonly | call | Stable |  |
| `Compliance\AttestationProviderInterface` | interface | implement | Stable |  |
| `Compliance\AttestationStore` | final class | call | Stable |  |
| `Compliance\Backup\BackupFacts` | final class readonly | call | Stable |  |
| `Compliance\Backup\BackupFactsProviderInterface` | interface | implement | Stable |  |
| `Compliance\Backup\BackupStore` | final class | call | Stable |  |
| `Compliance\Backup\RestoreTestFact` | final class readonly | call | Stable |  |
| `Compliance\Backup\RestoreTestRecord` | final class readonly | call | Stable |  |
| `Compliance\Backup\RestoreTestStore` | final class | call | Stable |  |
| `Compliance\Backup\RowCounts` | final class | call | Stable |  |
| `Compliance\Backup\UnavailableBackupFactsProvider` | final class | call | Stable |  |
| `Compliance\CheckInterface` | interface | implement | Stable |  |
| `Compliance\CheckResult` | final class readonly | call | Stable |  |
| `Compliance\Check\AuditRetentionCheck` | final class | call | Stable |  |
| `Compliance\Check\AuditTrailRecordingCheck` | final class | register as a check | Stable |  |
| `Compliance\Check\BackupCoverageCheck` | final class | call | Stable |  |
| `Compliance\Check\BackupEncryptedCheck` | final class | call | Stable |  |
| `Compliance\Check\BackupOffsiteCheck` | final class | call | Stable |  |
| `Compliance\Check\BackupRecencyCheck` | final class | call | Stable |  |
| `Compliance\Check\BackupRetentionCheck` | final class | call | Stable |  |
| `Compliance\Check\DataStoreRetentionCheck` | final class | call | Stable |  |
| `Compliance\Check\IntegrityChainCheck` | final class | call | Stable |  |
| `Compliance\Check\ProfileChecks` | final class | call | Stable |  |
| `Compliance\Check\RestoreTestRecencyCheck` | final class | call | Stable |  |
| `Compliance\Check\RetentionMeetsPresetCheck` | final class | register as a check | Stable |  |
| `Compliance\ClientChecklist` | final class | call | Provisional | one consumer so far |
| `Compliance\ComplianceAssessor` | final class | call | Stable |  |
| `Compliance\Framework` | final class | call | Stable |  |
| `Compliance\ManualItem` | final class readonly | call | Stable |  |
| `Compliance\Nist171Map` | final class | call | Stable |  |
| `Compliance\Profile\BuiltInProfiles` | final class | call | Stable |  |
| `Compliance\Profile\DataClass` | final class | call | Stable |  |
| `Compliance\Profile\FixedRequirementsProvider` | final class | call | Stable |  |
| `Compliance\Profile\FrameworkProfile` | final class readonly | call | Stable |  |
| `Compliance\Profile\FrameworkProfileStore` | final class | call | Stable |  |
| `Compliance\Profile\ProfileCodec` | final class | call | Stable |  |
| `Compliance\Profile\ProfileValidationException` | final class | catch / throw | Stable |  |
| `Compliance\Profile\Requirement` | final class readonly | call | Stable |  |
| `Compliance\Profile\RequirementType` | final class | call | Stable |  |
| `Compliance\Profile\RequirementsProviderInterface` | interface | implement | Stable |  |
| `Compliance\Profile\RetentionFloors` | final class | call | Stable |  |
| `Compliance\ReportRenderer` | final class | call | Stable |  |
| `Compliance\ResponsibilityStore` | final class | call | Provisional | one consumer so far |
| `Compliance\RetentionPolicy` | final class | call | Stable |  |
| `Compliance\SharedReport` | final class | call | Stable |  |
| `Compliance\SnapshotStore` | final class | call | Stable |  |
| `Compliance\Status` | enum | call | Stable |  |
| `Compliance\SubjectCompliance` | final class | call | Provisional | MSP-only consumer so far |
| `Contracts\AccessDenied` | final class | catch / throw | Provisional | ADR-003 |
| `Contracts\AccessPolicyInterface` | interface | implement (optional) | Provisional | ADR-003: no service enforces it yet |
| `Contracts\ClockInterface` | interface | implement | Stable |  |
| `Contracts\RequestContextInterface` | interface | implement | Stable |  |
| `Contracts\SettingsInterface` | interface | implement | Stable |  |
| `Cron\JobCatalog` | final class | call | Stable |  |
| `Cron\JobRunner` | class | call | Provisional | subclassed by editions; process-spawning details may change |
| `Crypto\CryptoException` | class | catch / throw | Stable |  |
| `Crypto\DecryptionFailed` | final class | call | Stable |  |
| `Crypto\Envelope` | final class | call | Stable |  |
| `Crypto\EnvelopeInterface` | interface | implement | Stable |  |
| `Crypto\EnvelopeReencryptor` | final class | call | Stable |  |
| `Crypto\FileRewrapStateStore` | final class | call | Stable |  |
| `Crypto\InvalidKeyMaterial` | final class | call | Stable |  |
| `Crypto\KdfParams` | final class | call | Stable |  |
| `Crypto\KeyFile` | final class | call | Stable |  |
| `Crypto\KeyGenerator` | final class | call | Stable |  |
| `Crypto\KeyPurpose` | final class | call | Stable |  |
| `Crypto\KeyRing` | final class | call | Stable |  |
| `Crypto\KeyRingLoadResult` | final class | call | Stable |  |
| `Crypto\KeyRingLoader` | final class | call | Stable |  |
| `Crypto\KeyUnavailable` | final class | call | Stable |  |
| `Crypto\LegacyOtpReader` | final class | call | Stable |  |
| `Crypto\LegacyReader` | interface | implement | Stable |  |
| `Crypto\LegacyReaders` | final class | call | Stable |  |
| `Crypto\LegacySettingsCbcReader` | final class | call | Stable |  |
| `Crypto\LegacySettingsGcmReader` | final class | call | Stable |  |
| `Crypto\LegacyUserKeyReader` | final class | call | Stable |  |
| `Crypto\LegacyVaultFieldReader` | final class | call | Stable |  |
| `Crypto\PlaintextPassthroughReader` | final class | call | Stable |  |
| `Crypto\Reencryptor` | interface | implement | Stable |  |
| `Crypto\RewrapItem` | final class | call | Stable |  |
| `Crypto\RewrapOptions` | final class | call | Stable |  |
| `Crypto\RewrapReport` | final class | call | Stable |  |
| `Crypto\RewrapSource` | interface | implement | Stable |  |
| `Crypto\RewrapState` | final class | call | Stable |  |
| `Crypto\RewrapStateStore` | interface | implement | Stable |  |
| `Crypto\Rewrapper` | final class | call | Stable |  |
| `Crypto\RotationPlan` | final class | call | Stable |  |
| `Crypto\SettingRead` | final class | call | Stable |  |
| `Crypto\SettingsVault` | final class | call | Stable |  |
| `Crypto\UnknownKeyId` | final class | call | Stable |  |
| `Crypto\VaultCipher` | final class | call | Stable |  |
| `Crypto\VaultFieldReencryptor` | final class | call | Stable |  |
| `Crypto\VaultKeyWrap` | final class | call | Stable |  |
| `Database\DatabaseException` | class | catch / throw | Stable |  |
| `Database\DatabaseInterface` | interface | implement | Stable |  |
| `Database\ExecutionResult` | final class readonly | call | Stable |  |
| `Health\ReadinessChecker` | final class | call | Stable |  |
| `ITSM\ChangeService` | class | call | Stable |  |
| `ITSM\ProblemService` | class | call | Stable |  |
| `ITSM\TicketProblemLinkInterface` | interface | implement | Stable |  |
| `Jobs\JobClaimer` | final class | call | Stable |  |
| `Jobs\JobContext` | final class | call | Stable |  |
| `Jobs\JobQueue` | final class | call | Stable |  |
| `Jobs\JobTimeout` | final class | catch / throw | Stable |  |
| `Jobs\JobWorker` | final class | call | Stable |  |
| `Jobs\PermanentJobFailure` | final class | catch / throw | Stable |  |
| `KB\DocxConversionException` | class | catch / throw | Stable |  |
| `KB\DocxConverter` | class | call | Stable |  |
| `KB\PdfConversionException` | class | catch / throw | Stable |  |
| `KB\PdfConverter` | final class | call | Stable |  |
| `Knowledge\CredentialReferenceRenderer` | class | call | Stable |  |
| `Mcp\AgentDirectoryInterface` | interface | implement | Stable |  |
| `Mcp\IdentityLinker` | final class | call | Provisional | closure-or-logger union |
| `Mcp\McpConfig` | final class | call | Stable |  |
| `Mcp\McpDiagnostics` | final class | call | Stable |  |
| `Mcp\NotFoundException` | class | catch / throw | Stable |  |
| `Mcp\RedisMetadataCache` | class | call | Stable |  |
| `Mcp\TokenClaimsGuard` | final class | call | Stable |  |
| `Mcp\ToolPipeline` | final class | call | Provisional | closure-or-logger union (freeze review item 4) |
| `Mcp\UnlinkedIdentityStore` | final class | call | Provisional | closure-or-logger union |
| `Migration\CoreMigrations` | final class | call | Stable |  |
| `Migration\MigrationInProgressException` | final class | catch / throw | Stable |  |
| `Migration\MigrationInterface` | interface | implement | Stable |  |
| `Migration\MigrationRunner` | final class | call | Stable |  |
| `Panel\Contracts\PanelTicketGatewayInterface` | interface | implement | Stable |  |
| `Panel\Http\PanelApi` | final class | call | Stable |  |
| `Panel\Migration\PanelSchema` | final class | call | Stable |  |
| `Panel\PanelAdmin` | final class | call | Stable |  |
| `Panel\PanelAttachments` | final class | call | Stable |  |
| `Panel\PanelAuthenticator` | final class | call | Stable |  |
| `Panel\PanelEnrollment` | final class | call | Stable |  |
| `Panel\PanelLimits` | final class | call | Stable |  |
| `Panel\PanelModule` | final class | call | Stable |  |
| `Panel\PanelPrincipal` | final class | call | Stable |  |
| `Panel\PanelProtocol` | final class | call | Stable |  |
| `Panel\PanelService` | final class | call | Stable |  |
| `Panel\PanelSettings` | final class | call | Stable |  |
| `Panel\PanelText` | final class | call | Stable |  |
| `Redis\CronGuard` | final class | call | Stable |  |
| `Redis\Lock` | final class | call | Stable |  |
| `Redis\LockManager` | final class | call | Stable |  |
| `Redis\RateLimiter` | final class | call | Stable |  |
| `Redis\RedisAdmin` | final class | call | Stable |  |
| `Redis\RedisClientProviderInterface` | interface | implement | Stable |  |
| `Redis\RedisConnectionConfig` | final class | call | Provisional | ten positional parameters; use named arguments |
| `Reporting\ReportingCatalog` | final class | call | Stable |  |
| `Reporting\ReportingRole` | final class | call | Stable |  |
| `Reporting\ReportingSchema` | final class | call | Stable |  |
| `Reporting\ReportingView` | final class | call | Stable |  |
| `Retention\RetentionService` | final class | call | Stable |  |
| `Rmm\Admin\RmmAdmin` | final class | call | Stable |  |
| `Rmm\Alerting\AlertService` | final class | call | Stable |  |
| `Rmm\Alerting\AlertingActions` | final class | call | Stable |  |
| `Rmm\Alerting\AlertingEngine` | final class | call | Stable |  |
| `Rmm\Alerting\AlertingHousekeeping` | final class | call | Stable |  |
| `Rmm\Alerting\CheckEvalStore` | final class | call | Stable |  |
| `Rmm\Alerting\CheckPipeline` | final class | call | Stable |  |
| `Rmm\Alerting\DefaultThresholdResolver` | final class | call | Stable |  |
| `Rmm\Alerting\DependencyService` | final class | call | Stable |  |
| `Rmm\Alerting\EscalationService` | final class | call | Stable |  |
| `Rmm\Alerting\FlapDetector` | final class | call | Stable |  |
| `Rmm\Alerting\MaintenanceSchedule` | final class | call | Stable |  |
| `Rmm\Alerting\MaintenanceScheduleGate` | final class | call | Stable |  |
| `Rmm\Alerting\MaintenanceService` | final class | call | Stable |  |
| `Rmm\Alerting\ScopeMatcher` | final class | call | Stable |  |
| `Rmm\Alerting\StormControl` | final class | call | Stable |  |
| `Rmm\Alerting\ThresholdResolverInterface` | interface | implement | Stable |  |
| `Rmm\Alerting\Thresholds` | final class | call | Stable |  |
| `Rmm\Approvals\ApprovalService` | final class | call | Stable |  |
| `Rmm\Assets\DeviceTypeClassifier` | final class | call | Stable |  |
| `Rmm\Assets\DeviceTypes` | final class | call | Stable |  |
| `Rmm\Authz\RmmAbility` | final class | call | Stable |  |
| `Rmm\Authz\RmmAuthorizer` | final class | call | Stable |  |
| `Rmm\Authz\RmmPrincipal` | final class | call | Stable |  |
| `Rmm\AutoInstall\AutoInstallActions` | final class | call | Stable |  |
| `Rmm\AutoInstall\AutoInstallService` | final class | call | Stable |  |
| `Rmm\Binaries\BinaryInspector` | final class | call | Stable |  |
| `Rmm\Binaries\BinaryStore` | final class | call | Stable |  |
| `Rmm\Binaries\MsiInspector` | final class | call | Stable |  |
| `Rmm\Capacity\AutoscaleHint` | final class | call | Stable |  |
| `Rmm\Capacity\CapacityReport` | final class | call | Stable |  |
| `Rmm\Capacity\DataStoreRecommendation` | final class | call | Stable |  |
| `Rmm\Capacity\DbTokenBucketStore` | final class | call | Stable |  |
| `Rmm\Capacity\HerdControl` | final class | call | Stable |  |
| `Rmm\Capacity\IngestQueue` | final class | call | Stable |  |
| `Rmm\Capacity\LoadShedder` | final class | call | Stable |  |
| `Rmm\Capacity\RedisTokenBucketStore` | final class | call | Stable |  |
| `Rmm\Capacity\TokenBucketStoreInterface` | interface | implement | Stable |  |
| `Rmm\Change\ChangeActions` | final class | call | Stable |  |
| `Rmm\Change\ChangePresets` | final class | call | Stable |  |
| `Rmm\Change\ChangeProtocol` | final class | call | Stable |  |
| `Rmm\Change\ChangeRuleService` | final class | call | Stable |  |
| `Rmm\Change\ChangeService` | final class | call | Stable |  |
| `Rmm\Change\ChangeSettings` | final class | call | Stable |  |
| `Rmm\Change\ChangeWatchPolicy` | class | call | Stable |  |
| `Rmm\Checkin\CheckinService` | final class | call | Stable |  |
| `Rmm\Checks\CheckCatalog` | final class | call | Stable |  |
| `Rmm\Checks\CheckEvaluator` | final class | call | Stable |  |
| `Rmm\Checks\CheckReconciler` | final class | call | Stable |  |
| `Rmm\Checks\RmmChecks` | final class | call | Stable |  |
| `Rmm\Contracts\ChangeWatchPolicyInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\ExternalMetricStoreInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\PackageSourcePolicyInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\RmmAssetNamesInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\RmmAssetsInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\RmmAuditInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\RmmBridgeInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\RmmEscalationInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\RmmEventsInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\RmmMetricReaderInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\RmmMetricSinkInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\RmmModuleStateInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\RmmTenancyInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\ScheduleGateInterface` | interface | implement | Stable |  |
| `Rmm\Contracts\SecretBoxInterface` | interface | implement | Stable |  |
| `Rmm\Crypto\CanonicalJson` | final class | call | Stable |  |
| `Rmm\Crypto\Redactor` | final class | call | Stable |  |
| `Rmm\Crypto\Signer` | final class | call | Stable |  |
| `Rmm\Deploy\AllowedHostsPolicy` | final class | call | Stable |  |
| `Rmm\Deploy\ArgsTemplate` | final class | call | Stable |  |
| `Rmm\Deploy\DeployActions` | final class | call | Stable |  |
| `Rmm\Deploy\DeployApprovalService` | final class | call | Stable |  |
| `Rmm\Deploy\DeployExpectations` | final class | call | Stable |  |
| `Rmm\Deploy\DeployJobTypes` | final class | call | Stable |  |
| `Rmm\Deploy\DeployProtocol` | final class | call | Stable |  |
| `Rmm\Deploy\DeployReader` | final class | call | Stable |  |
| `Rmm\Deploy\DeployScheduler` | final class | call | Stable |  |
| `Rmm\Deploy\DeployService` | final class | call | Stable |  |
| `Rmm\Deploy\HostedFiles` | final class | call | Stable |  |
| `Rmm\Deploy\PackageCatalog` | final class | call | Stable |  |
| `Rmm\Deploy\PackageParams` | final class | call | Stable |  |
| `Rmm\Deploy\SoftwareTemplates` | final class | call | Stable |  |
| `Rmm\Deploy\SourceUrl` | final class | call | Stable |  |
| `Rmm\Device\DeviceRepository` | final class | call | Stable |  |
| `Rmm\Device\DeviceService` | final class | call | Stable |  |
| `Rmm\Device\DeviceState` | final class | call | Stable |  |
| `Rmm\Enrollment\AttemptLog` | final class | call | Stable |  |
| `Rmm\Enrollment\DeviceValidator` | final class | call | Stable |  |
| `Rmm\Enrollment\EnrollmentService` | final class | call | Stable |  |
| `Rmm\Fields\CustomFieldService` | final class | call | Stable |  |
| `Rmm\Fields\FieldPlaceholders` | final class | call | Stable |  |
| `Rmm\Http\AlertingApi` | final class | call | Stable |  |
| `Rmm\Http\ApiError` | final class | call | Stable |  |
| `Rmm\Http\AutoInstallApi` | final class | call | Stable |  |
| `Rmm\Http\ChangeApi` | final class | call | Stable |  |
| `Rmm\Http\DeployApi` | final class | call | Stable |  |
| `Rmm\Http\DeviceApi` | final class | call | Stable |  |
| `Rmm\Http\FileDownload` | final class | call | Stable |  |
| `Rmm\Http\HostedFileApi` | final class | call | Stable |  |
| `Rmm\Http\HypervisorApi` | final class | call | Stable |  |
| `Rmm\Http\PatchApi` | final class | call | Stable |  |
| `Rmm\Http\RemoteApi` | final class | call | Stable |  |
| `Rmm\Http\RmmFileBody` | final class | call | Stable |  |
| `Rmm\Http\RmmRequest` | final class | call | Stable |  |
| `Rmm\Http\RmmResponse` | final class | call | Stable |  |
| `Rmm\Http\SapiEmitter` | final class | call | Stable |  |
| `Rmm\Http\StarterApi` | final class | call | Stable |  |
| `Rmm\Http\TechnicianApi` | final class | call | Stable |  |
| `Rmm\Hypervisor\HypervisorActions` | final class | call | Stable |  |
| `Rmm\Hypervisor\HypervisorAlerts` | final class | call | Stable |  |
| `Rmm\Hypervisor\HypervisorLinker` | final class | call | Stable |  |
| `Rmm\Hypervisor\HypervisorProtocol` | final class | call | Stable |  |
| `Rmm\Hypervisor\HypervisorReader` | final class | call | Stable |  |
| `Rmm\Hypervisor\HypervisorService` | final class | call | Stable |  |
| `Rmm\Hypervisor\HypervisorSettings` | final class | call | Stable |  |
| `Rmm\Installer\InstallerDownload` | final class | call | Stable |  |
| `Rmm\Installer\InstallerFormat` | final class | call | Stable |  |
| `Rmm\Installer\InstallerService` | final class | call | Stable |  |
| `Rmm\Installer\InstallerStamp` | final class | call | Stable |  |
| `Rmm\Job\JobExtras` | final class | call | Stable |  |
| `Rmm\Job\JobService` | final class | call | Stable |  |
| `Rmm\Job\JobType` | final class | call | Stable |  |
| `Rmm\Job\JobTypeRegistry` | final class | call | Stable |  |
| `Rmm\Link\RmmLinker` | final class | call | Stable |  |
| `Rmm\Logs\LogActions` | final class | call | Stable |  |
| `Rmm\Logs\LogRuleService` | final class | call | Stable |  |
| `Rmm\Logs\LogService` | final class | call | Stable |  |
| `Rmm\Logs\SecurityLogPresets` | final class | call | Stable |  |
| `Rmm\Maintenance\Housekeeping` | final class | call | Stable |  |
| `Rmm\Mesh\MeshAfterApproval` | final class | call | Stable |  |
| `Rmm\Mesh\MeshAgentCatalog` | final class | call | Stable |  |
| `Rmm\Mesh\MeshAgentService` | final class | call | Stable |  |
| `Rmm\Mesh\MeshAgentSettings` | final class | call | Stable |  |
| `Rmm\Mesh\MeshCookie` | final class | call | Stable |  |
| `Rmm\Mesh\MeshNodeId` | final class | call | Stable |  |
| `Rmm\Mesh\MeshService` | final class | call | Stable |  |
| `Rmm\Metrics\CompositeMetricSink` | final class | call | Stable |  |
| `Rmm\Metrics\CurlHttpTransport` | final class | call | Stable |  |
| `Rmm\Metrics\ExternalMetricReader` | final class | call | Stable |  |
| `Rmm\Metrics\ExternalMetricSink` | final class | call | Stable |  |
| `Rmm\Metrics\ExternalStoreException` | final class | catch / throw | Stable |  |
| `Rmm\Metrics\HttpTransportInterface` | interface | implement | Stable |  |
| `Rmm\Metrics\MetricSteps` | final class | call | Stable |  |
| `Rmm\Metrics\VictoriaMetricsStore` | final class | call | Stable |  |
| `Rmm\Patch\PatchActions` | final class | call | Stable |  |
| `Rmm\Patch\PatchApprovalService` | final class | call | Stable |  |
| `Rmm\Patch\PatchComplianceService` | final class | call | Stable |  |
| `Rmm\Patch\PatchInstallService` | final class | call | Stable |  |
| `Rmm\Patch\PatchJobTypes` | final class | call | Stable |  |
| `Rmm\Patch\PatchPolicyResolver` | final class | call | Stable |  |
| `Rmm\Patch\PatchPolicySettings` | final class | call | Stable |  |
| `Rmm\Patch\PatchPolicyStore` | final class | call | Stable |  |
| `Rmm\Patch\PatchProtocol` | final class | call | Stable |  |
| `Rmm\Patch\PatchReader` | final class | call | Stable |  |
| `Rmm\Patch\PatchScanService` | final class | call | Stable |  |
| `Rmm\Patch\PatchScheduler` | final class | call | Stable |  |
| `Rmm\Policy\EffectiveConfig` | final class | call | Stable |  |
| `Rmm\Policy\EffectivePolicy` | final class | call | Stable |  |
| `Rmm\Policy\PolicyLayer` | final class | call | Stable |  |
| `Rmm\Policy\PolicyResolver` | final class | call | Stable |  |
| `Rmm\Policy\PolicySettings` | final class | call | Stable |  |
| `Rmm\Policy\PolicyStore` | final class | call | Stable |  |
| `Rmm\Policy\StarterPack\PolicyPack` | final class | call | Stable |  |
| `Rmm\Policy\StarterPack\PolicyPackInstaller` | final class | call | Stable |  |
| `Rmm\Presentation\DeviceHeader` | final class | call | Stable |  |
| `Rmm\Presentation\DeviceVolumes` | final class | call | Stable |  |
| `Rmm\Presentation\RemoteHeader` | final class | call | Stable |  |
| `Rmm\Read\AutomationReader` | final class | call | Stable |  |
| `Rmm\Read\RmmReadModel` | final class | call | Stable |  |
| `Rmm\Remote\JobRefused` | final class | call | Stable |  |
| `Rmm\Remote\PathPolicy` | final class | call | Stable |  |
| `Rmm\Remote\RemoteActions` | final class | call | Stable |  |
| `Rmm\Remote\RemoteJobTypes` | final class | call | Stable |  |
| `Rmm\Remote\RemoteProtocol` | final class | call | Stable |  |
| `Rmm\Remote\RemoteSettings` | final class | call | Stable |  |
| `Rmm\Remote\TransferService` | final class | call | Stable |  |
| `Rmm\RmmEvent` | final class | call | Stable |  |
| `Rmm\RmmModule` | final class | call | Stable |  |
| `Rmm\RmmProtocol` | final class | call | Stable |  |
| `Rmm\RmmState` | final class | call | Stable |  |
| `Rmm\RmmStateFile` | final class | call | Stable |  |
| `Rmm\Scripts\ApprovedRunExecutor` | final class | call | Stable |  |
| `Rmm\Scripts\BulkRunner` | final class | call | Stable |  |
| `Rmm\Scripts\CronSchedule` | final class | call | Stable |  |
| `Rmm\Scripts\OpenScheduleGate` | final class | call | Stable |  |
| `Rmm\Scripts\ParamSchema` | final class | call | Stable |  |
| `Rmm\Scripts\ScheduleRunner` | final class | call | Stable |  |
| `Rmm\Scripts\ScheduleService` | final class | call | Stable |  |
| `Rmm\Scripts\ScriptLanguage` | final class | call | Stable |  |
| `Rmm\Scripts\ScriptRunner` | final class | call | Stable |  |
| `Rmm\Scripts\ScriptService` | final class | call | Stable |  |
| `Rmm\Scripts\TargetResolver` | final class | call | Stable |  |
| `Rmm\Settings\ChecksValidator` | final class | call | Stable |  |
| `Rmm\Settings\RetentionClasses` | final class | call | Stable |  |
| `Rmm\Settings\RetentionFloorsInterface` | interface | implement | Stable |  |
| `Rmm\Settings\RmmSettings` | final class | call | Stable |  |
| `Rmm\Software\SoftwareHash` | final class | call | Stable |  |
| `Rmm\Software\SoftwareService` | final class | call | Stable |  |
| `Rmm\Software\SoftwareVersion` | final class | call | Stable |  |
| `Rmm\Starter\StarterInstaller` | final class | call | Stable |  |
| `Rmm\Starter\StarterLint` | final class | call | Stable |  |
| `Rmm\Starter\StarterPack` | final class | call | Stable |  |
| `Rmm\Storage\MetricRollup` | final class | call | Stable |  |
| `Rmm\Storage\PartitionMaintenance` | final class | call | Stable |  |
| `Rmm\Storage\StorageMaintenance` | final class | call | Stable |  |
| `Rmm\Storage\StorageState` | final class | call | Stable |  |
| `Rmm\Store\BackupHook` | final class | call | Stable |  |
| `Rmm\Store\ChangeCriteria` | final class | call | Stable |  |
| `Rmm\Store\ChangeEventStore` | interface | implement | Stable |  |
| `Rmm\Store\CheckHistoryStore` | interface | implement | Stable |  |
| `Rmm\Store\Cli\CliBootstrap` | final class | call | Stable |  |
| `Rmm\Store\ClientScopedChangeEventStore` | interface | implement | Stable |  |
| `Rmm\Store\ClientScopedLogStore` | interface | implement | Stable |  |
| `Rmm\Store\DataStoreInfo` | final class | call | Stable |  |
| `Rmm\Store\DataStoreSwitch` | final class | call | Stable |  |
| `Rmm\Store\DataStoreUnavailableException` | final class | catch / throw | Stable |  |
| `Rmm\Store\DeploymentHistoryStore` | interface | implement | Stable |  |
| `Rmm\Store\HotStoreUnavailableException` | final class | catch / throw | Stable |  |
| `Rmm\Store\HypervisorHistoryStore` | interface | implement | Stable |  |
| `Rmm\Store\InventoryAwareHypervisorHistoryStore` | interface | implement | Stable |  |
| `Rmm\Store\LogCriteria` | final class | call | Stable |  |
| `Rmm\Store\LogStore` | interface | implement | Stable |  |
| `Rmm\Store\MetricStore` | interface | implement | Stable |  |
| `Rmm\Store\Migration\MigrationException` | final class | catch / throw | Stable |  |
| `Rmm\Store\Migration\RmmStoreMigrator` | final class | call | Stable |  |
| `Rmm\Store\PatchHistoryStore` | interface | implement | Stable |  |
| `Rmm\Store\Postgres\CircuitBreakerConnection` | final class | call | Stable |  |
| `Rmm\Store\Postgres\Guard\HotStoreFaults` | final class | call | Stable |  |
| `Rmm\Store\Postgres\Pdo\PdoPostgresConnection` | final class | call | Stable |  |
| `Rmm\Store\Postgres\PostgresConfig` | final class | call | Stable |  |
| `Rmm\Store\Postgres\PostgresConnectionException` | final class | catch / throw | Stable |  |
| `Rmm\Store\Postgres\PostgresConnectionInterface` | interface | implement | Stable |  |
| `Rmm\Store\Postgres\PostgresException` | class | catch / throw | Stable |  |
| `Rmm\Store\Postgres\PostgresMigrator` | final class | call | Stable |  |
| `Rmm\Store\Postgres\PostgresQueryException` | final class | catch / throw | Stable |  |
| `Rmm\Store\Postgres\PostgresSerializationException` | final class | catch / throw | Stable |  |
| `Rmm\Store\Postgres\PostgresTimeoutException` | final class | catch / throw | Stable |  |
| `Rmm\Store\RmmDataStoreFactory` | final class | call | Stable |  |
| `Rmm\Store\RmmDataStoreKind` | final class | call | Stable |  |
| `Rmm\Store\RmmDataStores` | final class | call | Stable |  |
| `Rmm\Store\Sizes\MariaDbStoreSizes` | final class | call | Stable |  |
| `Rmm\Store\Sizes\PostgresStoreSizes` | final class | call | Stable |  |
| `Rmm\Store\SoftwareHistoryStore` | interface | implement | Stable |  |
| `Rmm\Store\StoreMaintenance` | interface | implement | Stable |  |
| `Rmm\Store\StoreSizes` | interface | implement | Stable |  |
| `Rmm\Store\Verify\StoreVerifier` | final class | call | Stable |  |
| `Rmm\Support\AgentOs` | final class | call | Stable |  |
| `Rmm\Support\AuditServiceRmmAudit` | final class | call | Stable |  |
| `Rmm\Support\ConfigSequence` | final class | call | Stable |  |
| `Rmm\Support\DailyBudget` | final class | call | Stable |  |
| `Rmm\Support\DatabaseMetricSink` | final class | call | Stable |  |
| `Rmm\Support\NullRmmAudit` | final class | call | Stable |  |
| `Rmm\Support\NullRmmEscalation` | final class | call | Stable |  |
| `Rmm\Support\NullRmmEvents` | final class | call | Stable |  |
| `Rmm\Support\NullRmmMetricSink` | final class | call | Stable |  |
| `Rmm\Support\RmmCache` | final class | call | Stable |  |
| `Rmm\Support\RmmEventPublisher` | final class | call | Stable |  |
| `Rmm\Support\Sql` | final class | call | Stable |  |
| `Rmm\Tags\GroupService` | final class | call | Stable |  |
| `Rmm\Tags\TagService` | final class | call | Stable |  |
| `Rmm\Technician\ActionResult` | final class | call | Stable |  |
| `Rmm\Technician\FieldActions` | final class | call | Stable |  |
| `Rmm\Technician\InventoryActions` | final class | call | Stable |  |
| `Rmm\Technician\PolicyActions` | final class | call | Stable |  |
| `Rmm\Technician\PolicyPackActions` | final class | call | Stable |  |
| `Rmm\Technician\ScriptActions` | final class | call | Stable |  |
| `Rmm\Technician\StarterActions` | final class | call | Stable |  |
| `Rmm\Technician\TechnicianActions` | final class | call | Stable |  |
| `Rmm\Update\UpdateService` | final class | call | Stable |  |
| `Security\BaselineChanges` | final class | call | Stable |  |
| `Security\BaselineViolation` | final class | call | Stable |  |
| `Security\SecurityBaseline` | final class | call | Stable |  |
| `Support\AllowAllPolicy` | final class | call | Provisional | ADR-003 |
| `Support\ArraySettings` | final class | call | Stable |  |
| `Support\DenyAllPolicy` | final class | call | Provisional | ADR-003 |
| `Support\ErrorLogLogger` | final class | call | Stable |  |
| `Support\LocalNetworks` | final class | call | Stable |  |
| `Support\NullRequestContext` | final class | call | Stable |  |
| `Support\SystemClock` | final class | call | Stable |  |
| `Testing\AccessPolicyConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\AgentDirectoryConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\AttestationProviderConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\BackupFactsProviderConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\ChangeWatchPolicyConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\ClockConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\DatabaseContractTestCase` | abstract class | call | Stable |  |
| `Testing\EnvelopeConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\ExternalMetricStoreConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\InMemoryRewrapSource` | class | call | Stable |  |
| `Testing\InMemoryRewrapStateStore` | final class | call | Stable |  |
| `Testing\PackageSourcePolicyConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\PanelTicketGatewayConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RedisClientProviderConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RequestContextConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RewrapSourceConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RmmAssetsConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RmmAuditConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RmmBridgeConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RmmDataStoreContractTestCase` | abstract class | call | Stable |  |
| `Testing\RmmEscalationConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RmmEventsConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RmmMetricReaderConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RmmMetricSinkConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RmmModuleStateConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\RmmTenancyConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\ScheduleGateConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\SecretBoxConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\SettingsConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\TicketProblemLinkConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\TokenBucketStoreConformanceTestCase` | abstract class | call | Stable |  |
| `Testing\WebhookSubscriptionsConformanceTestCase` | abstract class | call | Stable |  |
| `Ui\DateRange` | final class | call | Provisional | new in 0.20 |
| `Ui\IconCatalog` | final class | call | Provisional | new in 0.19 |
| `Webhooks\Authentication` | final class | call | Provisional | new in 0.21 |
| `Webhooks\Destination` | final class readonly | call | Provisional | preset catalogue added in 0.21 |
| `Webhooks\DestinationField` | final class readonly | call | Provisional | preset catalogue added in 0.21 |
| `Webhooks\Destinations` | final class | call | Provisional | preset catalogue added in 0.21 |
| `Webhooks\EventCatalog` | final class | call | Provisional | event list grows every release; ids are stable, the class shape is new in 0.21 |
| `Webhooks\EventDefinition` | final class readonly | call | Provisional | new in 0.21 |
| `Webhooks\EventSummary` | final class | call | Provisional | new in 0.21 |
| `Webhooks\FormattedPayload` | final class readonly | call | Provisional | new in 0.21 |
| `Webhooks\NetworkList` | final class | call | Stable |  |
| `Webhooks\PayloadFormatter` | final class | call | Provisional | new in 0.21 |
| `Webhooks\PayloadTemplate` | final class | call | Provisional | new in 0.21 |
| `Webhooks\UrlPolicy` | final class | call | Stable |  |
| `Webhooks\WebhookDispatcher` | class | call | Provisional | long positional constructor; options object proposed in the freeze review |
| `Webhooks\WebhookSubscription` | final class readonly | call | Stable |  |
| `Webhooks\WebhookSubscriptionLookupInterface` | interface | implement | Stable |  |
| `Webhooks\WebhookSubscriptionsInterface` | interface | implement | Stable |  |
| `Workflow\WorkflowService` | class | call | Stable |  |
<!-- types:end -->

## How a change is caught

1. `php scripts/api-surface-check.php` rebuilds the surface by reflection and compares it with `tests/api-surface.json`. **Any**
   difference fails, labelled `BREAKING` (removal or change) or `ADDITIVE` (addition). The author runs it with `--update`, reviews the
   JSON diff, and records it in `CHANGELOG.md`; a `BREAKING` line needs a major version.
2. CI runs it as the `api-surface` job (with a MariaDB service, so the table section is compared too) and `tests/Unit/ApiSurfaceGuardTest.php`
   proves the guard fails on a removed method, a changed signature, a new type, a new event id and a vanished migration.
3. The older `compatibility` job (Roave backward-compatibility-check against the last tag) stays advisory until 1.0: it understands
   PHP semantics the snapshot does not (variance, inherited members), the snapshot understands tables and vocabularies that Roave cannot.
   At 1.0 both are required.

What the guard cannot see: the **shape** of `array` parameters and returns (they are only `array` in the signature; the shapes live in
docblocks), the behaviour of a method with an unchanged signature, and the semantic meaning of a column. Those are covered by tests and
review, and by the open question below about array shapes.

## Open design questions (not decided here)

These need the owner. None blocks the tooling above.

1. **Non-final classes.** `AutomationRuleEvaluator`, `JobRunner`, `ProblemService`, `ChangeService`, `WorkflowService`, `DocxConverter`,
   `WebhookDispatcher`, `RedisMetadataCache`, `CredentialReferenceRenderer`, `DatabaseException` and the KB exceptions are not `final`,
   some on purpose (`JobRunner`, `RedisMetadataCache` are subclassed by editions to pin defaults). Inheritance then becomes API. Which
   stay extensible (document the extension points), which become `final` before 1.0?
2. **Array shapes.** About 70 public methods take or return plain `array`. Annotate with `array{...}` shapes before 1.0 so completion
   works and the guard can compare them, or accept that the shape is documented prose only? (Also in the freeze review, item 2.)
3. **Long positional constructors.** `RedisConnectionConfig` (10 parameters), `WebhookDispatcher` (8), `ToolPipeline` (7): freeze with
   "use named arguments", or introduce options objects (a break for positional callers)?
4. **Logger unions.** `\Closure|LoggerInterface|null` on three MCP classes: drop the closure at 1.0 (recommended in the freeze review)?
5. **Core owner decisions already open:** #45 (ITFlow shims), #46 (Packagist), #47 (webhook signature change), #48 (database support
   promise), #49 (test helpers package). In particular #49 decides where `Testing\DatabaseContractTestCase` and the conformance kit
   live, and whether they are inside the promise.
6. **Are the tables public for reading?** Proposed: yes, columns are Stable, and an edition may query `audit_events` etc. read-only;
   writes go through services. If instead the tables become private, `AuditReader` and friends must cover every edition query first.
   Adding a NOT NULL column without a default is breaking for an edition that inserts directly, so migrations must keep adding
   nullable or defaulted columns only.
7. **`MigrationRunner` lock name.** `rivet_core_migrations` is a server-global `GET_LOCK` name, so two databases on one server serialize
   their migrations (see [SECURITY-REVIEW-2.md](SECURITY-REVIEW-2.md)). Scoping it to the schema changes behaviour but not the
   signature: patch, or a documented minor?
8. **`Provisional` types** (table above): the list needs confirming, and each needs a promote-or-demote decision before `rc.1`.
9. **Webhook event ids as API.** Removing an id from `EventCatalog` is guarded as breaking. Is deprecating (still listed, flagged
   `deprecated`) the only allowed path, and how long does a deprecated id live?
10. **The 0.x constraint.** Editions pin `^0.N`; the guard reports changes per commit, not per tag. Should the release checklist attach
    the guard's diff against the last tag to every release note?
