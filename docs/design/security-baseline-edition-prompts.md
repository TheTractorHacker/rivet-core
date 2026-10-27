# Security baseline: edition prompts

Ready to paste into the RivetIT and RivetMSP chats. Source: `docs/security/BASELINE.md` and `docs/security/baseline.json` in rivet-core (the controls marked `edition-responsibility`, plus the `partial` ones that need the edition to wire a Core rule in). Core ships the rules in `RivetCore\Security\SecurityBaseline` and `RivetCore\Security\BaselineChanges`; the edition applies them.

Replace `<TAG>` with the RivetCore version that contains the baseline.

## Prompt 1: adopt the baseline check (both editions)

```
Adopt the RivetCore security baseline check in this edition. Do not release or deploy; work in a worktree and open a PR.

1. Pin RivetCore to <TAG>. Read vendor/rivet/rivet-core/docs/security/BASELINE.md and baseline.json.
2. Add docs/security/EDITION_BASELINE.json listing, for each Core control with status edition-responsibility or partial-needing-the-edition, an entry: core_control id, how this edition implements it (file/class), the test that proves it, status (enforced|partial|gap). Be honest: gap or partial unless a test proves it.
3. Add scripts/edition-baseline-check.php that fails when a referenced file or test does not exist or an "enforced" entry has no test (copy the rules from vendor/rivet/rivet-core/scripts/baseline-check.php; extra: it must also fail if a Core control id listed is not in the pinned Core baseline.json). Run it in CI as a job named "security baseline".
4. Do not claim any control publicly that is not enforced here; follow vendor/rivet/rivet-core/docs/security/CLAIMS.md exactly.
Report: counts by status and every gap.
```

## Prompt 2: implement the edition-responsibility controls (both editions)

```
Implement these RivetCore baseline controls in this edition, each with a test, then mark them enforced in EDITION_BASELINE.json. Use RivetCore\Security\SecurityBaseline and RivetCore\Security\BaselineChanges; do not copy the numbers.

AUTH-01  Password set/change: reject with SecurityBaseline::passwordLengthOk() (min 12, default 14). Accept at least 64 characters (SecurityBaseline::MIN_PASSWORD_MAX_ACCEPTED). No composition rules.
AUTH-02  Passwords stored with password_hash() (Argon2id if available, else bcrypt cost >= 12). Rehash on login when needed.
AUTH-03  (gap, optional) breached-password check (k-anonymity range lookup or an offline list).
AUTH-07  MFA (TOTP and/or WebAuthn). SecurityBaseline::mfaRequired($isAdmin, $clientSetting): administrators always, everyone else on by default; the per-client opt-out is changed only through BaselineChanges::change() so it is audit-logged.
AUTH-08  Recovery flows: single-use, expiring tokens; no default accounts; installer must make the operator choose the first admin password.
SESS-01  Session and CSRF cookies: build attributes with SecurityBaseline::cookieAttributes() and the name with hostCookieName(). Test that Set-Cookie carries Secure; HttpOnly; SameSite=Lax (or Strict) and __Host-.
SESS-02  Idle timeout from SecurityBaseline::effective('session_idle_minutes', $stored); absolute ceiling 480; logout invalidates server side; re-authenticate before changing credentials or MFA.
ACC-03  CSRF tokens on every cookie-authenticated state-changing request; test a request without one is refused.
ACC-04  Administrative pages behind MFA; no directory listing.
COMM-03  HTTPS only: redirect HTTP, send HSTS (max-age >= 1 year), TLS 1.2+ in the shipped nginx/apache snippets. Test the snippets.
COMM-04  Security headers on every page: Content-Security-Policy (no unsafe-inline for scripts if feasible), Referrer-Policy, X-Content-Type-Options nosniff, frame-ancestors/X-Frame-Options, Permissions-Policy; strict or no CORS. Test the headers.
DATA-02  Data export and deletion for personal data where the edition stores it.
DATA-03 / HARD-05  Backups: the in-app backup must be encrypted by default (AES-256-GCM via RivetCore\Crypto or age); refuse to write an unencrypted backup unless an explicit, audit-logged override exists. The Core Postgres script emits a plain dump; wrap it.
HARD-03  Installer creates a non-superuser database role limited to its own database; for PostgreSQL use the unix socket and peer authentication (see docs/rmm/POSTGRES.md); a separate read-only role for reporting.
ERR-01 / HARD-01  Audit logging always on: no setting or flag may disable AuditService calls; log login success/failure, MFA changes, permission changes, credential reveal, baseline setting changes.
ERR-03 / HARD-02  Every settings write for audit retention, password length, session timeout, MFA opt-out goes through BaselineChanges::change(); feed RetentionService::prune() with SecurityBaseline::auditRetentionDays($stored) so the 90 day floor holds.
COMM-01  Refuse (or loudly warn at install and in the health page) when SecurityBaseline::postgresTransportProblem($config) returns text.
COMM-03  Webhook destinations: refuse http:// destinations unless an administrator opts in per destination (audit-logged), since Core's UrlPolicy still accepts http (GAP-08).
FILE-02  (gap, optional) scan uploads with ClamAV when installed.

Rules: additive, no schema change to frozen RMM tables, no change to the device protocol. Each item: code + test + EDITION_BASELINE.json entry. Report status counts and gaps honestly.
```

## Prompt 3: trust and claims page (both editions)

```
Add a public "Security and trust" page (and an admin copy under Settings) to this edition. Content rules: use ONLY the wording in vendor/rivet/rivet-core/docs/security/CLAIMS.md "Approved wording"; never use any phrase in its "must NOT be used" table. The page shows:
- the approved claims verbatim, with the RivetCore version (<TAG>) and this edition's version;
- a link to the published control list (BASELINE.md at the tag) and the generated SOC 2 / ISO 27001 mapping (CONTROL_MAPPING.md);
- this edition's own status counts from EDITION_BASELINE.json, rendered at build time (not hand-typed);
- the known gaps list, the statement "Independent security review: none yet", and the disclosure contact (SECURITY.md);
- no badges, logos or seals that imply certification.
Add a test that fails if the page source contains any of: "SOC 2 compliant", "SOC 2 certified", "ISO 27001 certified", "HIPAA compliant", "HIPAA certified", "guaranteed secure", "unhackable", "military-grade", "bank-grade".
```
