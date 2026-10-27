# RivetCore security baseline

RivetCore is built against a written, CI-enforced security baseline so that both editions inherit secure behaviour by default. The baseline has three layers, all driven from one control list.

| Layer | What | Where |
|---|---|---|
| 1. Build standard | OWASP ASVS 4.0.3 Level 2, self-assessed | this file (table below) |
| 2. Secure defaults | CIS-style hardening defaults with non-lowerable floors | controls `HARD-*`, `src/Security/SecurityBaseline.php` |
| 3. Mapping | SOC 2 Trust Services Criteria and ISO/IEC 27001:2022 Annex A | [CONTROL_MAPPING.md](CONTROL_MAPPING.md), generated |

**This is a self-assessment.** No certification exists and no third party has audited it. What may be said publicly is in [CLAIMS.md](CLAIMS.md).

## Source of truth

`docs/security/baseline.json` is the control list. Everything else is derived from it or checked against it:

* `php scripts/baseline-check.php` fails when a referenced file or test does not exist, an `enforced` control has no test, a SOC 2 or ISO id is unknown, a `GAP-nn` is undefined, or a generated document is stale. It runs in the CI job `security baseline` and in `tests/Unit/Security/BaselineCheckTest.php`.
* `php scripts/baseline-map.php` regenerates [CONTROL_MAPPING.md](CONTROL_MAPPING.md) and the table below. Never edit those by hand.

A control's `tests` entry is a test file, `file::method` (the method must exist), or `ci:job` (a job in `.github/workflows/ci.yml`). The check proves the proof exists; it does not judge whether the test is good. Review does that.

## Why ASVS 4.0.3

ASVS 5.0 renumbered and reorganised the requirements. 4.0.3 is the version whose requirement ids this project can reference reliably, so that is what the `asvs` fields use. Controls group related requirements; the list is not a line-by-line enumeration of every L2 requirement, and chapters Core has no surface for (sessions, pages) are covered by `not-applicable-to-Core` or `edition-responsibility` entries rather than silently skipped.

## Statuses

| Status | Meaning |
|---|---|
| enforced | Core enforces it in code or CI and a named test or CI job proves it. |
| partial | Part of it is enforced, or only when the edition wires it in. The note says what is missing. |
| not-applicable-to-Core | Core has no such surface. |
| edition-responsibility | The edition must implement it. Core supplies a tested helper or rule where it can. See [the edition prompts](../design/security-baseline-edition-prompts.md). |
| gap | Not done. |

## Secure-by-default settings (layer 2)

Defined in `RivetCore\Security\SecurityBaseline::SETTINGS`, changed only through `RivetCore\Security\BaselineChanges` (every accepted and every refused change is audit-logged).

| Setting | Default | Floor / ceiling | Adjustable |
|---|---|---|---|
| audit_enabled | on | locked | no |
| credential_encryption | on | locked | no |
| tls_only | on | locked | no |
| cookie_secure, cookie_httponly | on | locked | no |
| mfa_required_administrators | on | locked | no |
| mfa_required_users | on | none | yes, per client, audited |
| audit_retention_days | 365 | at least 90 | yes |
| password_min_length | 14 | at least 12 | yes |
| session_idle_minutes | 30 | at most 480 | yes |

"Locked" means the baseline rule has no off value. Core cannot reach into an edition's settings table, so each edition has to read these rules; that is why several controls are `partial` or `edition-responsibility`.

## Controls

<!-- BEGIN GENERATED CONTROLS (scripts/baseline-map.php) -->

**62 controls:** 30 enforced, 15 partial, 1 not-applicable-to-Core, 12 edition-responsibility, 4 gap.

### Architecture

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| ARCH-01 | Threat model | 1.1.2, 1.1.3 | partial | docs/security/threat-model.md (assets, trust boundaries, STRIDE per module). No automated check that it is current. **Note:** Reviewed in the 2026-10 audit; freshness is a manual duty. | none |
| ARCH-02 | Documented security review and remediation | 1.1.1, 1.14.6 | enforced | docs/security/CORE_SECURITY_AUDIT.md and CORE_REMEDIATION.md list each finding with its fix commit and a regression test that fails without the fix. **Note:** Open items are listed as gaps below (GAP-03, GAP-04, GAP-05). | `tests/Unit/SecurityFixes202610Test.php`<br>`tests/Unit/SecurityReview202610Test.php`<br>`tests/Integration/Rmm/TenancyBoundaryAuditTest.php` |
| ARCH-03 | Core has no direct database or request-global coupling | 1.4.1, 1.5.1 | enforced | CI step 'Core must not reference mysqli or request globals' and the PDO-containment step fail the build on a violation. | `ci:test` |
| ARCH-04 | Public API compatibility is checked | 1.14.1 | enforced | docs/api-surface.md is regenerated from the code and a unit test fails when it drifts; a BC check guards public signatures. | `tests/Unit/ApiSurfaceTest.php` |
### Authentication

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| AUTH-01 | Minimum password length 12, long passwords accepted | 2.1.1, 2.1.2, 2.1.9 | edition-responsibility | Core has no user accounts. SecurityBaseline::passwordLengthOk() and the locked floor (MIN_PASSWORD_LENGTH = 12) give editions one tested rule to call. **Note:** The edition must call it on every password set. | `tests/Unit/Security/SecurityBaselineTest.php::testPasswordLengthCountsCharactersNotBytes`<br>`tests/Unit/Security/SecurityBaselineTest.php::testValidateRejectsBelowFloorAboveCeilingAndWrongTypes` |
| AUTH-02 | Password storage uses a slow salted hash | 2.4.1, 2.4.2, 2.4.5 | edition-responsibility | Core stores no user passwords. Device and panel credentials are 256-bit random tokens stored as SHA-256 hashes (see AUTH-04). **Note:** Editions use password_hash() in their own user store. | none |
| AUTH-03 | Breached-password check | 2.1.7 | gap | Nothing in Core or its helper does this. **Note:** Edition feature; see GAP-06. | none |
| AUTH-04 | Service and device credentials are random, hashed at rest, compared in constant time | 2.6.1, 2.9.1, 2.10.1, 2.10.3, 6.3.1 | enforced | DeviceRepository and PanelAuthenticator look a bearer token up by its SHA-256 and compare with hash_equals; enrollment issues the credential once and stores only its hash. | `tests/Integration/Rmm/EnrollmentTest.php::testNewEnrollmentIssuesACredentialAndStoresOnlyItsHash`<br>`tests/Integration/Panel/PanelAuthTest.php::testEveryPanelEndpointRequiresACredential` |
| AUTH-05 | Credential kinds cannot be swapped; revocation is immediate | 2.10.2, 3.3.1 | enforced | Device kind is checked at authentication; revocation clears the credential. | `tests/Integration/Panel/PanelAuthTest.php::testAPanelTokenNeverWorksOnAnAgentEndpointAndAnAgentTokenNeverOnAPanelEndpoint`<br>`tests/Integration/Panel/PanelAuthTest.php::testARevokedDeviceIsRefusedImmediatelyByEveryRevocationRoute` |
| AUTH-06 | Anti-automation on enrollment and MCP | 2.2.1, 11.1.4 | partial | Enrollment attempts are recorded and locked per selector; MCP calls are rate limited through the Redis limiter; Redis fails open by design. **Note:** INFO-04: no per-IP throttle on failed device credentials (256-bit tokens make guessing infeasible). Interactive-login throttling is the edition's. | `tests/Integration/Rmm/EnrollmentTest.php::testInvalidTokensAndDevicesAreRefusedAndRecorded`<br>`tests/Unit/McpTest.php::testPipelineRateLimit` |
| AUTH-07 | Multi-factor authentication available and required for administrators | 2.8.1, 2.8.2, 2.8.3, 4.3.1 | edition-responsibility | Core has no interactive login. SecurityBaseline::mfaRequired() fixes the rule (always for administrators, on by default for others, opt-out per client audit-logged through BaselineChanges). **Note:** The edition must implement TOTP/WebAuthn and call the rule. | `tests/Unit/Security/SecurityBaselineTest.php::testMfaIsAlwaysRequiredForAdministratorsAndOnByDefaultForEveryoneElse` |
| AUTH-08 | Recovery and out-of-band flows | 2.5.1, 2.5.2, 2.5.4, 2.5.6, 2.7.1, 2.7.2, 2.7.3 | edition-responsibility | No recovery or out-of-band flow exists in Core. | none |
| AUTH-09 | No default credentials or secrets in the repository | 2.10.4, 14.1.3 | enforced | scripts/secret-scan.php scans the tree and history with an allowlist that needs a written reason per fixture; CI runs it plus gitleaks. No default account or default password ships in Core. | `tests/Unit/SecretScanTest.php::testTheWholeTrackedTreeIsCleanAgainstTheAllowlist`<br>`tests/Unit/SecretScanTest.php::testAllowlistEntriesMustJustifyThemselves`<br>`ci:secret-scan` |
### Session

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| SESS-01 | Session cookies are Secure, HttpOnly, SameSite, __Host- | 3.4.1, 3.4.2, 3.4.3, 3.4.4 | edition-responsibility | Core sets no cookies. SecurityBaseline::cookieAttributes() always returns Secure and HttpOnly true and never SameSite=None; hostCookieName() gives the prefix. **Note:** Locked settings cookie_secure and cookie_httponly. | `tests/Unit/Security/SecurityBaselineTest.php::testCookieAttributesAreAlwaysSecureHttpOnlyAndNeverSameSiteNone` |
| SESS-02 | Idle timeout default 30 minutes, ceiling 8 hours | 3.3.2, 3.3.4, 3.7.1 | edition-responsibility | SecurityBaseline defines session_idle_minutes (default 30, max 480); the edition applies it. | `tests/Unit/Security/SecurityBaselineTest.php::testValidateRejectsBelowFloorAboveCeilingAndWrongTypes` |
| SESS-03 | Session identifiers, token-based sessions | 3.1.1, 3.2.1, 3.2.2, 3.2.3, 3.5.1, 3.5.2, 3.5.3 | not-applicable-to-Core | Core has no sessions. **Note:** Edition concern. | none |
### Access control

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| ACC-01 | Per-ability and per-client authorization on every technician action | 4.1.1, 4.1.2, 4.1.3, 4.1.5, 4.2.1, 1.4.1, 13.1.4 | enforced | RmmAuthorizer checks ability and client for every technician action; out-of-scope and missing objects return the same 404. | `tests/Integration/Rmm/RoleMatrixTest.php::testRoleMatrix`<br>`tests/Integration/Rmm/RoleMatrixTest.php::testMissingAndOutOfScopeDevicesAreTheSame404`<br>`tests/Unit/Rmm/AuthorizerTest.php` |
| ACC-02 | Tenant boundary (client isolation) | 4.2.1, 4.1.3, 1.4.5 | enforced | CORE-02, CORE-03 and CORE-11 fixes: client visibility and fleet-wide guards on shared objects. **Note:** CORE-05 (enrollment-token holder can take over a device in the same client) is an open design decision, see GAP-03. | `tests/Integration/Rmm/TenancyBoundaryAuditTest.php::testLimitedTechnicianCannotLinkAPendingDeviceToAnAssetOfAnotherClient`<br>`tests/Integration/Rmm/TenancyBoundaryAuditTest.php::testLimitedTechnicianCannotChangeSharedTagsGroupsOrScripts`<br>`tests/Conformance/RmmTenancyKitTest.php` |
| ACC-03 | CSRF protection | 4.2.2, 13.2.3 | edition-responsibility | Core endpoints authenticate with bearer tokens, not cookies, so ambient-credential CSRF does not apply to them. Cookie-authenticated pages are the edition's. | none |
| ACC-04 | Administrative interfaces are protected | 4.3.1, 4.3.2, 4.3.3 | edition-responsibility | Core's RMM admin actions are gated by abilities (ACC-01); the login in front of them is the edition's. | `tests/Integration/Rmm/AdminTest.php` |
### Input validation

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| VAL-01 | Strict positive validation of agent-supplied identifiers | 5.1.3, 5.1.4, 5.1.1 | enforced | Exact-match helpers at agent-controlled call sites (CORE-10), schema validation of device payloads and check parameters. **Note:** The same newline class exists in ~60 other patterns that are not agent-controlled (CORE-10 residual). | `tests/Unit/Rmm/TrailingNewlineIdentifiersTest.php::testDeviceValidationRefusesNewlineSuffixedIdentifiers`<br>`tests/Unit/Rmm/DeviceValidatorTest.php`<br>`tests/Unit/Rmm/ChecksValidatorTest.php` |
| VAL-02 | Parameterized queries | 5.3.4, 5.3.5 | partial | Stores use bound parameters; identifiers in dynamic SQL are fixed in code (RetentionService). Enforcement is by review and the audit, not by an automated rule. **Note:** No static rule forbids concatenated SQL; see GAP-07. | `tests/Integration/RetentionTest.php` |
| VAL-03 | SSRF: outbound URLs are vetted and the resolved address is pinned | 5.2.6, 12.6.1 | enforced | UrlPolicy::vet() resolves, refuses private ranges and odd host spellings, and the dispatcher pins the vetted address. **Note:** A host that resolves to a public address stays allowed by design. Plain http destinations are accepted by default and refused when the dispatcher is built with requireHttps (GAP-08). | `tests/Unit/UrlPolicyTest.php`<br>`tests/Unit/UrlPolicyHostSpellingTest.php`<br>`tests/Unit/WebhookHardeningTest.php::testVettedTargetIsPassedToTransportForPinning` |
| VAL-04 | Output encoding and no dynamic code execution | 5.3.1, 5.3.3, 5.2.4, 5.2.5, 5.3.6, 5.3.8, 5.5.1, 5.5.3 | partial | Core renders no HTML pages of its own except converter output (escaped in DocxConverter) and the shared UI assets. JSON is encoded with fixed flags. No static rule forbids eval, unserialize or shell calls. **Note:** A grep rule in CI against eval/unserialize/exec in src is cheap; see GAP-07. | `tests/Unit/Converters/DocxConverterTest.php` |
| VAL-05 | Log and header injection | 7.3.1, 5.1.3 | enforced | Audit values are clamped and stored as bound data; the HTTP transport rejects control characters in headers (INFO-01). | `tests/Unit/AuditServiceTest.php::testLongSummaryAndUserAgentAreTruncatedToColumnWidth`<br>`tests/Unit/Rmm/Metrics/CurlHttpTransportTest.php` |
### Cryptography

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| CRYPT-01 | Authenticated encryption with proven primitives | 6.2.1, 6.2.2, 6.2.3, 6.2.5, 6.2.6, 6.2.7, 6.2.8 | enforced | Envelope: AES-256-GCM through OpenSSL, 96-bit random nonce per call, key id in the envelope, caller AAD bound to the context; fuzzing never opens a damaged value. | `tests/Unit/Crypto/EnvelopeTest.php::testNistGcmTestCase16ForTheUnderlyingPrimitive`<br>`tests/Unit/Crypto/EnvelopeTest.php::testNonceIsFreshPerCallAndInjectionIsTheOnlyWayToFixIt`<br>`tests/Unit/Crypto/EnvelopeTest.php::testTagBitFlipsAndAadChangesAreEachRefused`<br>`tests/Unit/Crypto/EnvelopeTest.php::testFuzzRandomTruncationsAndBitFlipsNeverOpenAndOnlyThrowCryptoExceptions` |
| CRYPT-02 | Key management: random keys, key ids, rotation, rewrap | 6.4.1, 6.4.2, 6.3.1, 6.3.2, 6.2.4 | enforced | KeyGenerator uses random_bytes; KeyRing carries several keys; Rewrapper re-seals data under the active key, resumably. **Note:** Key storage and backup of the key file is the edition's and the operator's (GAP-09: no HSM/KMS integration). | `tests/Unit/Crypto/KeysTest.php`<br>`tests/Unit/Crypto/RewrapperTest.php::testRewrapsEverythingAndTheSecondRunChangesNothing`<br>`tests/Unit/Crypto/RewrapperTest.php::testInterruptedRunResumesFromTheSavedCursor`<br>`tests/Unit/Crypto/RotationPlanTest.php` |
| CRYPT-03 | Stored credentials are sealed; sensitive objects do not leak through dumps | 6.1.1, 6.1.2, 6.1.3, 8.3.4, 2.10.4, 7.1.1 | enforced | PostgresConfig, RedisConnectionConfig, SecretString and the VictoriaMetrics store hide secrets from var_dump, (array), serialize and json_encode; sealed passwords are opened only when connecting. **Note:** var_export of a Redis config still shows the password (CORE-07 residual). Encrypting the edition's own vault rows is the edition's. | `tests/Unit/SecretsInDumpsTest.php::testPostgresPasswordIsHiddenFromEveryDumpIncludingArrayCasts`<br>`tests/Unit/SecretsInDumpsTest.php::testSecretStringHidesAndRefusesSerialisation`<br>`tests/Unit/RedisConnectionSecurityTest.php::testPasswordDoesNotLeaveThroughJsonOrSerialize` |
| CRYPT-04 | Signed jobs and update manifests | 10.3.1, 10.3.2, 13.2.6 | enforced | Signer signs job, check and manifest payloads; known-answer vectors are checked in PHP and Go. **Note:** INFO-07: no domain-separation prefix on job signatures (frozen protocol, mitigated by field-set validation). | `tests/Unit/Rmm/SignerTest.php::testAllFourJobVectors`<br>`tests/Unit/Rmm/CanonicalJsonTest.php` |
### Errors and logging

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| ERR-01 | Audit trail records security events and is always on | 7.1.3, 7.2.1, 7.2.2, 7.1.4 | partial | AuditService writes actor, entity, action, ip, user agent and request id; the audit_enabled baseline setting is locked on. Core cannot force an edition to call it, and the RMM module logs through AuditServiceRmmAudit. **Note:** A database failure propagates from AuditService (the caller decides); MCP reads continue if the audit write fails by design. | `tests/Unit/AuditServiceTest.php::testInsertsAllColumnsWithRequestContext`<br>`tests/Integration/MigrationAndAuditTest.php::testAuditWritesARealRow`<br>`tests/Unit/Security/SecurityBaselineTest.php::testLockedSettingsCannotBeTurnedOffAndAreAlwaysOnWhateverIsStored`<br>`tests/Conformance/RmmAuditKitTest.php` |
| ERR-02 | Audit metadata and job output are redacted | 7.1.1, 7.1.2, 8.3.4 | enforced | AuditService redacts secret-named keys before fan-out and size-caps metadata; Redactor masks job and log output and fails closed on regex errors (CORE-06). **Note:** The Go agent's own redaction lacks the newer name rules (CORE-18, GAP-05); the server redacts again before storage. | `tests/Unit/Rmm/RedactorTest.php::testSecretsAreRemoved`<br>`tests/Unit/Rmm/RedactorHardeningTest.php`<br>`tests/Unit/Rmm/ChangeRedactionTest.php` |
| ERR-03 | Audit retention at least 90 days; changes are audited | 7.3.3, 7.3.4, 8.3.8 | enforced | SecurityBaseline::auditRetentionDays() (default 365, floor 90) and BaselineChanges (audits accepted and refused changes) enforce the floor at the settings layer, and RetentionService::prune()/plan() clamp the audit horizon to SecurityBaseline::MIN_AUDIT_RETENTION_DAYS themselves, so a stored 7 days still keeps 90 even if the edition does not call auditRetentionDays(). **Note:** An edition that prunes audit_events with its own SQL instead of RetentionService is not covered; a horizon below 1 still means keep everything. | `tests/Unit/Security/SecurityBaselineTest.php::testAuditRetentionNeverBelowNinetyDays`<br>`tests/Unit/Security/SecurityBaselineTest.php::testEveryChangeIsAuditLoggedAndARefusalIsLoggedToo`<br>`tests/Integration/Compliance/RetentionEnforcementTest.php`<br>`tests/Unit/Retention/RetentionFloorTest.php`<br>`tests/Integration/RetentionTest.php::testTheAuditFloorHoldsInTheServiceItself` |
| ERR-04 | Audit log tamper evidence | 7.3.3, 7.3.2 | gap | Audit rows are plain table rows; there is no hash chain or sealing (INFO-08). **Note:** GAP-02. | none |
| ERR-05 | Errors fail safe and say little | 7.4.1, 7.4.2, 7.4.3, 6.2.1, 4.1.5 | partial | ApiError returns coded errors; decryption and validation failures are closed; Redis rate limit fails open on purpose (documented, threat-model section 5). **Note:** No global last-resort handler in a library; the edition owns it. | `tests/Unit/Rmm/HttpObjectsTest.php`<br>`tests/Unit/Crypto/EnvelopeTest.php::testEmptyRingAndNoActiveKey` |
| ERR-06 | Clock synchronisation and skew tolerance | 7.3.4 | partial | Database clock is used for retention cutoffs; device check-ins reject skew over 900 s and report it. **Note:** NTP on servers is operational. | `tests/Integration/Rmm/ClockSkewTest.php::testWindowIsNineHundredSecondsAndOldAgentsAreUnchanged` |
### Data protection

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| DATA-01 | Sensitive responses are not cached | 8.1.1, 8.1.2, 14.4.2, 14.4.4 | enforced | RmmResponse JSON sets Cache-Control: no-store; file downloads set nosniff and no-store; panel attachments are served as attachments. | `tests/Unit/Rmm/HttpObjectsTest.php`<br>`tests/Unit/Panel/PanelAttachmentsTest.php` |
| DATA-02 | Data classification and privacy | 8.3.1, 8.3.2, 8.3.3, 8.3.5, 8.3.6 | edition-responsibility | Core does not hold a customer personal-data model beyond device and technician identifiers; export and deletion are the edition's. | none |
| DATA-03 | Backups of the RMM data store are encrypted | 8.3.7, 6.1.1 | partial | scripts/rmm-postgres-backup.sh writes a dump to stdout so the caller encrypts it (documented `/ age`); the script itself does not encrypt and defaults sslmode to prefer. BackupEncryptionInspector detects from file content whether a backup artifact is age/OpenPGP/openssl/RivetCore-envelope encrypted, and ArtifactCheckedBackupFactsProvider feeds that into the BackupFacts, so the backup_encrypted compliance check fails on a plaintext file. **Note:** GAP-11: the detection is opt-in. The edition has to give the decorator the paths of its newest backup; nothing stops the script from producing a plain dump. The edition's own backup (zip or encrypted script) is separate. | `tests/Unit/Rmm/BackupHookTest.php`<br>`tests/Unit/Compliance/BackupEncryptionInspectorTest.php` |
| DATA-04 | Restore testing evidence | 8.3.8 | enforced | RestoreTestStore records restore tests and compliance checks consume them. **Note:** Evidence is only as good as the edition feeding it. | `tests/Integration/Compliance/RestoreTestEvidenceTest.php`<br>`tests/Unit/Compliance/BackupChecksTest.php` |
### Communications

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| COMM-01 | PostgreSQL: unix socket with peer authentication, or verified TLS | 9.2.2, 9.1.1, 9.1.2, 9.1.3, 9.2.1, 1.9.1 | partial | PostgresConfig accepts a socket directory as host (no password needed with peer auth); SecurityBaseline::postgresTransportProblem() reports any remote host below verify-ca. DataStoreSwitch (the supported way to choose PostgreSQL) now REFUSES, on save and test, a remote host with sslmode disable, allow or prefer unless the input carries the explicit, audit-logged allow_insecure_transport acknowledgement, and a new remote connection defaults to verify-full. Stored connections still load. **Note:** Still partial: the PostgresConfig constructor default stays prefer (a public contract), PostgresConfig::fromArray() does not refuse (an edition that builds the config itself is not covered), sslmode require is accepted with a warning, and an already stored weak connection keeps running until it is saved or tested again (GAP-01). | `tests/Unit/Security/SecurityBaselineTest.php::testPostgresTransportRule`<br>`tests/Unit/Rmm/BackupHookTest.php`<br>`tests/Integration/Rmm/Postgres/PostgresConnectionTest.php`<br>`tests/Unit/Rmm/PostgresWeakTransportTest.php`<br>`tests/Integration/Rmm/Migration/DataStoreSwitchTest.php::testARemoteHostWithWeakSslmodeIsRefusedUnlessAcknowledged`<br>`tests/Integration/Rmm/Migration/DataStoreSwitchTest.php::testTheDefaultSslmodeForANewRemoteConnectionIsVerifyFull` |
| COMM-02 | Redis authentication and TLS support | 9.2.2, 9.2.3 | partial | RedisConnectionConfig supports password, ACL user and TLS with a timeout; no refusal of an unauthenticated plaintext remote Redis. | `tests/Integration/RedisAuthTlsTest.php`<br>`tests/Integration/RedisAuthTest.php`<br>`tests/Unit/RedisConnectionSecurityTest.php::testAHungRedisIsGivenUpOnWithinTheTimeout` |
| COMM-03 | TLS for inbound traffic and outbound webhooks | 9.1.1, 9.1.2, 9.1.3, 14.4.5 | edition-responsibility | TLS terminates in the web server of the edition. WebhookDispatcher can refuse plain http:// destinations (appended constructor parameter requireHttps, per-destination options['allow_http'] flag, UrlPolicy::destinationProblem() for the save form); the default is off because UrlPolicy has always accepted http. The tls_only baseline setting is locked on as the stated rule. **Note:** GAP-08: refusing plain http is opt-in per dispatcher (requireHttps); an edition that does not turn it on still delivers to http destinations. | `tests/Unit/UrlPolicyTest.php`<br>`tests/Unit/Webhooks/HttpsOnlyDeliveryTest.php` |
| COMM-04 | Security response headers, CSP, CORS | 14.4.1, 14.4.3, 14.4.6, 14.4.7, 14.5.1, 14.5.2, 14.5.3, 14.5.4 | edition-responsibility | Core's JSON and download responses set Content-Type, no-store and nosniff. Page-level headers belong to the edition's web layer. | `tests/Unit/Rmm/HttpObjectsTest.php` |
### Malicious code and supply chain

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| MAL-01 | Dependencies audited and pinned | 10.2.1, 14.2.1, 14.2.4, 14.2.5, 1.14.4 | enforced | composer.lock is committed; CI runs composer audit and govulncheck; Dependabot covers composer, actions and Go; CI actions are pinned by commit SHA. **Note:** No SBOM is published (GAP-12). | `ci:dependency-audit`<br>`ci:static` |
| MAL-02 | Release integrity | 10.3.1, 10.3.2, 14.1.1 | partial | Releases carry SHA256SUMS. There is no signature or provenance attestation (INFO-09). **Note:** GAP-12. | none |
| MAL-03 | Static analysis and secret scanning on every change | 10.1.1, 14.2.2 | enforced | PHPStan, lint, our secret scanner (tree and history) and gitleaks run in CI. | `ci:static`<br>`ci:secret-scan` |
### Business logic

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| BIZ-01 | Enrollment and job flows enforce order and limits | 11.1.1, 11.1.2, 11.1.4, 11.1.5, 11.1.6 | partial | Enrollment, approval and job states are checked server side; daily budgets and load shedding cap abuse. CORE-05 (reinstall rule) is a documented design weakness. **Note:** GAP-03. | `tests/Integration/Rmm/DailyBudgetTest.php`<br>`tests/Integration/Rmm/ApprovalsTest.php`<br>`tests/Integration/Rmm/LoadShedderTest.php` |
### Files

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| FILE-01 | Upload size, type and name checks | 12.1.1, 12.1.2, 12.2.1, 12.3.1, 12.3.2, 12.3.3, 12.3.5, 12.5.1, 12.5.2 | enforced | PanelAttachments checks format by content, size and count; reserved names refused; served as attachment with nosniff. Converters cap image and document size. **Note:** INFO-03: crafted OLE upload can inflate memory (admin only, accepted). | `tests/Unit/Panel/PanelAttachmentsTest.php::testRefuses`<br>`tests/Unit/Panel/PanelAttachmentsTest.php::testCountAndSizeLimits`<br>`tests/Unit/Converters/ConverterImageLimitsTest.php` |
| FILE-02 | Antivirus scanning of uploads | 12.4.2 | gap | No scanner is called. **Note:** GAP-13. | none |
### API

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| API-01 | Typed JSON API with content-type and method checks | 13.1.1, 13.1.3, 13.1.5, 13.2.1, 13.2.2, 13.2.5 | enforced | OpenAPI document for the device API is tested against the routes; requests are parsed into typed objects. **Note:** Frozen device protocol; not changed by this baseline. | `tests/Unit/Rmm/OpenApiDocTest.php`<br>`tests/Integration/Rmm/DeviceHeaderTest.php` |
| API-02 | MCP tokens: issuer, audience, scope, lifetime | 3.5.3, 13.1.4, 2.9.1 | enforced | TokenClaimsGuard requires a dedicated audience, the scope, and a lifetime of at most one hour. | `tests/Unit/McpTest.php::testTokenClaimsGuard`<br>`tests/Unit/McpTest.php::testIssuerAndAudienceValidation` |
### Configuration

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| CFG-01 | Hardened, reviewable build and CI | 14.1.1, 14.1.3, 14.1.5, 14.2.3 | enforced | Workflows run with contents: read, third-party actions pinned by commit, tag-derived input validated (CORE-17), minimal docker and build-host scripts reject symlinked output (CORE-16). | `ci:test` |
| CFG-02 | Security baseline is itself checked | 14.1.2, 1.1.1 | enforced | scripts/baseline-check.php runs in the 'security baseline' CI job and as a unit test. **Note:** It proves the references exist, not that the tests are good. | `tests/Unit/Security/BaselineCheckTest.php`<br>`ci:security-baseline` |
| CFG-03 | Vulnerability disclosure policy | 1.14.6 | enforced | SECURITY.md gives private reporting, acknowledgement in 5 working days, supported versions. | `tests/Unit/Security/BaselineCheckTest.php::testSecurityPolicyExists` |
### Secure defaults

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| HARD-01 | Locked-on controls cannot be switched off | 1.14.3, 14.1.3 | partial | SecurityBaseline::SETTINGS marks them locked: validate() refuses false and effective() ignores a stored false. They are rules an edition reads; Core cannot reach into an edition's settings table. | `tests/Unit/Security/SecurityBaselineTest.php::testLockedSettingsCannotBeTurnedOffAndAreAlwaysOnWhateverIsStored`<br>`tests/Unit/Security/SecurityBaselineTest.php::testDefaultsAreTheSecureValues` |
| HARD-02 | Floors are frozen numbers | 14.1.3 | enforced | Constants pinned by test; BaselineChanges audits every accepted and refused change. **Note:** Applies to callers that go through BaselineChanges; an edition that writes its settings table directly bypasses it. | `tests/Unit/Security/SecurityBaselineTest.php::testFloorsAreFrozenNumbers`<br>`tests/Unit/Security/SecurityBaselineTest.php::testEveryChangeIsAuditLoggedAndARefusalIsLoggedToo` |
| HARD-03 | Least-privilege database accounts | 1.4.1, 2.10.2, 14.1.3 | partial | docs/rmm/POSTGRES.md prescribes NOSUPERUSER roles and peer authentication; a separate read-only reporting role reads views only (tested). Core does not create or inspect the production role. **Note:** Edition installers set up the role (see edition prompts). | `tests/Integration/Rmm/Postgres/ReportingPostgresRoleTest.php::testTheRoleReadsTheViewsAndNothingElse`<br>`tests/Integration/Reporting/ReportingRoleTest.php` |
| HARD-04 | No default passwords; credentials generated at first use | 2.10.4, 14.1.3 | enforced | Core ships no accounts; the signing key and enrollment secrets are minted at enable time; secret scanner blocks committed credentials (AUTH-09). | `tests/Integration/Rmm/EnrollmentTest.php::testEnablingMintsTheSigningKeyOnceAndTheIntegrationOnce`<br>`tests/Unit/SecretScanTest.php::testSrcAndDocsCarryNoSecretShape` |
| HARD-05 | Encrypted backups by default | 8.3.7 | edition-responsibility | See DATA-03; the Core script emits a plain dump for the caller to encrypt. Core supplies a content-based encryption detector and a BackupFacts decorator an edition can use to report and flag an unencrypted backup. **Note:** Edition backup tooling must encrypt and must wire the detector. | `tests/Unit/Compliance/BackupEncryptionInspectorTest.php` |
| HARD-06 | Module and remote-tool features are off until enabled | 14.2.2, 14.1.3 | enforced | The RMM module, enrollment and remote tools refuse until an administrator enables them. | `tests/Integration/Rmm/EnrollmentTest.php::testDisabledServiceRefusesEnrollmentAndIsOffByDefault`<br>`tests/Integration/Rmm/ModuleOffFastPathTest.php` |
### Endpoint agent

| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |
|---|---|---|---|---|---|
| AGT-01 | Agent state directory permissions | 14.1.3, 6.4.1 | gap | Linux is checked; Windows ownership and reparse points are not (CORE-13, not testable here). **Note:** GAP-04. | none |
| AGT-02 | Agent job and log redaction | 7.1.1 | partial | Go redaction exists; newer name rules are server side only (CORE-18). **Note:** GAP-05. | `tests/Unit/Rmm/RedactorTest.php` |

### Known gaps

| Id | Gap | Detail |
|---|---|---|
| GAP-01 | PostgresConfig default sslmode is prefer | The PostgresConfig constructor default stays prefer (public contract). DataStoreSwitch now refuses weak remote sslmodes on save/test and defaults new remote connections to verify-full; config built directly with fromArray() and already stored connections are not refused. |
| GAP-02 | No audit log tamper evidence | No hash chain or sealing (INFO-08). |
| GAP-03 | CORE-05 enrollment-token reinstall takeover | Frozen device protocol rule; decision recorded as open. |
| GAP-04 | CORE-13 Windows agent state directory | Needs a Windows test environment. |
| GAP-05 | CORE-18 Go agent redaction rules | Server-side redaction covers storage. |
| GAP-06 | No breached-password check | Edition feature. |
| GAP-07 | No static rule against concatenated SQL, eval, unserialize or shell calls | Cheap to add to CI later. |
| GAP-08 | Plain http webhook destinations are accepted | UrlPolicy still allows http by default. WebhookDispatcher refuses it when built with requireHttps unless the destination sets allow_http; the default is off for compatibility. |
| GAP-09 | No KMS/HSM integration for the key file | File-based key ring only. |
| GAP-11 | The backup script does not itself guarantee an encrypted backup | The script emits a plain dump to stdout. Core can now detect a plaintext artifact (BackupEncryptionInspector) but only when the edition wires it in. |
| GAP-12 | No SBOM, release signature or provenance attestation | Releases carry SHA256SUMS only. |
| GAP-13 | No antivirus scan of uploads | Content-type and size checks only. |

<!-- END GENERATED CONTROLS -->

## Changing the baseline

Edit `baseline.json`, run `php scripts/baseline-map.php`, run `php scripts/baseline-check.php`. Never raise a control's status without a test; lower it when a regression is found. Anything not enforced stays `partial` or `gap`.
