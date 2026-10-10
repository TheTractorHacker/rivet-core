# Platform readiness: gap analysis and plan (2026-10-09)

Scope: RivetIT (origin/beta 26.10.28), RivetMSP (26.10.8), RivetCore 1.0.0-rc.6, and a comparison with RivetDocs.
Method: read-only code audits of each requirement. Ratings come from reading source, not from running the products.
Companion docs: [security review](../security/review-2026-10.md), [RMM scale plan](../rmm/SCALING.md).

## Summary

| Requirement | State | Biggest gaps |
|---|---|---|
| Ticket intake: reliable email-to-ticket, replies, notifications | Partial | No auto-reply/loop protection; poison messages re-fetched forever; no Message-ID dedupe or In-Reply-To threading; no admin alerts when intake or the mail queue breaks; rows can stick in "sending"; weak quoted-text stripping; no attachment size cap; no parser tests |
| Documentation: relationships between devices, servers, apps, vendors, procedures | Partial | Links are hard-wired pairs; no generic link or "depends on"; no reverse/impact view; KB articles cannot link to assets; one vendor per asset/software; search skips software, networks, services |
| Security: MFA/SSO, access control, password protection, auditing | Partial | Backup zip carries the settings key beside the data; MSP stores secrets in plaintext without a settings key; vault is AES-128-CBC with a ~96-bit master key; 30-day minimum sessions and MFA-skipping remember-me; TOTP seeds plaintext, no recovery codes; no staff password policy; list page reveals vault secrets unaudited; audit trail thin and not tamper-evident |
| Recovery: automated backups and tested restoration | Partial | No automated restore test; no alert on failed/stale backup; no RTO/RPO; encrypted script backup has no offsite step and no timer installed; copies share the app's disk |
| Integrations: device inventory sync, minimal manual entry | Partial (6/10) | Vendor RMM syncs leave asset hardware fields empty; client matching needs exact names; no software inventory; no auto-retire; no AD/LDAP, NinjaOne/Datto/ConnectWise, or discovery; sync failures only logged |

## One platform or two (RivetDocs)

Keep two. RivetDocs is a separate TypeScript/Postgres product (AGPL-3.0, a Weavestream fork) with typed asset layouts,
generic relations with backlinks, IPAM and its own vault. The editions already store most documentation data; what is missing is the
relationship layer. Merging costs an XL and crosses an AGPL/GPL boundary (code cannot be copied either way). Build the relationship layer
in the editions (a `entity_links` table, a Relationships card, an impact view). Revisit RivetDocs as the documentation front-end only when it
has shared sign-on and a shared key standard.

## Key management standard (target)

One envelope for every encrypted value: `v3:<kid>:base64(nonce12 || tag16 || ciphertext)`, AES-256-GCM, record type and id as AAD. Old
formats (`ENC:`, `ENC2:`, `V2:`) stay readable and are re-wrapped lazily and by a CLI.

| Key | Protects | Lives in | Rotation | Backup |
|---|---|---|---|---|
| KEK (`settings_enc_key`, 32 random bytes) | Settings secrets, TOTP seeds, vault DEK wrap, derived keys (HKDF, one label per use) | Root-owned key file outside the web root, mode 0640 root:www-data | New `kid`, rewrap, retire after one backup cycle | Offline copy, never in the DB dump archive |
| Vault DEK (32 random bytes) | Credential fields | Wrapped by KEK and by each user's key (Argon2id or PBKDF2 600k+) | `vault-rewrap` with dry-run and audit | With the KEK |
| Instance signing key (Ed25519) | RMM jobs, update manifests | Sealed by KEK | Overlapping public keys, then retire | Settings backup |
| Backup passphrase (20+ random chars) | Backup archives | Root-only file | On staff change | Offline |

Rules: dump, KEK and passphrase never share a file or disk copy; every key has a `kid`, a created date and a rotation panel; setup generates
all keys with `random_bytes` and refuses weak values; the envelope and rewrap tool live in RivetCore so both editions share them.

## Plan

Sizes: S under a day, M a few days, L about a week or more. Order within a wave is by severity.

### Wave 1: close the worst exposures (all S or M)

| # | Item | Product | Size |
|---|---|---|---|
| 1 | Backups: require a passphrase for the in-app zip; stop writing `settings_enc_key` into the manifest (fingerprint only); `-iter 600000` for `backup.sh`, key file separate from the archive | IT, MSP | S |
| 2 | MSP settings cipher: port IT's AES-256-GCM `encryptSetting` (fail closed), generate the settings key in setup, re-wrap migration | MSP | M |
| 3 | Finish plaintext stragglers (Slack bot token, login key secret, whitelabel key, software keys, deferred SMTP/OAuth columns) | IT, MSP | S |
| 4 | TOTP seeds encrypted; 10 single-use recovery codes; MFA enforcement policy (global/role/department) | IT, MSP | M |
| 5 | Staff password policy (12+ chars, not the user's name/email), HIBP range check (fail open, SSRF-safe), Argon2id with rehash on login | IT, MSP | S |
| 6 | Sessions: 8 h idle default, absolute maximum, remember-me never skips MFA for admins, session list and revoke, strict cookies | IT, MSP | M |
| 7 | Intake: auto-reply/loop protection (inbound `Auto-Submitted`/`Precedence`/OOO, outbound headers, per-sender cap); poison-message quarantine; Message-ID dedupe; In-Reply-To/References threading | IT, MSP | M |
| 8 | Intake and mail queue health: last-polled/last-error per mailbox, queue counters, admin alerts on OAuth expiry, dead mailbox, exhausted retries; reaper for rows stuck in "sending"; exponential backoff | IT, MSP | S-M |
| 9 | Backup alerts: record status per run, alert on failure and when the newest backup is over 26 h old; alert on failed/stale sync (RMM, Intune, UniFi) | IT, MSP | S |
| 10 | Vault list page: mask by default, audit each reveal/copy, step-up re-auth, reveal rate limit | IT, MSP | M |

### Wave 2: recovery, relationships, inventory

| # | Item | Size |
|---|---|---|
| 11 | Nightly restore drill into a scratch DB with verification (checksums, schema version, row counts, ledger chain, sample secret decrypt), results in the UI and a compliance check; documented RTO/RPO | M |
| 12 | Offsite for `backup.sh` (rclone/S3/SFTP), systemd timer installed, runbook for the full recovery order | M |
| 13 | `entity_links` table, shared "Link item" modal, Relationships card on asset, software, vendor, document/KB; reverse "Referenced by / Impact" view; KB article to asset links | M |
| 14 | Search over software, networks, services and linked records; multiple vendors per asset/software with role; document review dates and expiry reminders | S-M |
| 15 | RMM/agent inventory writes asset hardware fields (model, CPU, RAM, IPs, OS) without overwriting human edits; per-integration client mapping table with a needs-mapping queue; auto-retire of stale assets (off by default) | M |
| 16 | Quoted-text stripping (plain-text and Outlook forms); attachment size caps and a ClamAV hook; global bounce/DSN detection; parser golden-file tests; DKIM/SPF/DMARC and OAuth setup runbook | M |
| 17 | Audit: events for role/permission changes, API-key lifecycle, setting changes, backup/export; hash chain with nightly verify; syslog/JSON sink | M |
| 18 | Entra user-to-asset assignment from Intune primary user | S |

### Wave 3: larger changes

| # | Item | Size |
|---|---|---|
| 19 | Vault v3 envelope (AES-256-GCM, 32-byte random master key, modern KDF) with resumable `vault-rewrap`, behind a flag; `kid` and rotation panel | L |
| 20 | Move the envelope, settings vault and rewrap into RivetCore (`SettingsVault`) so editions share one implementation | M-L |
| 21 | Software inventory from the agent (RMM Phase 1) and vendor RMM software pulls | L |
| 22 | SSO: JIT provisioning, IdP group to role mapping, tenant-wide enforce-SSO, then SCIM/SAML as demand requires | L |
| 23 | RMM signing-key rotation with overlapping public keys; shorter device-token lifetime or refresh | M |
| 24 | Dependency map view for services; typed per-client layouts (revive custom fields) | L |
| 25 | Connectors: AD/LDAP computers, NinjaOne, network discovery | L each, demand-driven |

## Out of scope for this plan

Production RivetIT (mwa) rollout, the 30-day Core soak, macOS agent, Authenticode signing certificate, RivetDocs integration beyond the existing read-only driver.
