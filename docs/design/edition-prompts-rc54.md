# Edition prompts for RivetCore 1.0.0-rc.54

Ready to paste, one block per chat. These cover what rc.53 and rc.54 added on top of `edition-prompts-rc47.md`. Do that file's R1-R4 / M1-M3 first if not yet merged. Beta before production; dump the database before any migration.

Longer feature prompts: `log-presets-edition-prompts.md` (log presets UI), `security-baseline-edition-prompts.md` (baseline), `compliance-edition-prompts.md`.

---

## RivetIT chat

### R5. Adopt rc.54 and apply the rc.53 hardening

```
Adopt RivetCore 1.0.0-rc.54 in RivetIT. Worktree, PR, do not deploy.

1. Pin rivet/rivet-core to ^1.0.0-rc.54, run the usual adoption steps (composer.lock, any new Core migrations mirrored in db.sql). Read vendor/rivet/rivet-core/CHANGELOG.md for rc.48-rc.54.
2. Webhooks: turn on requireHttps for webhook destinations (UrlPolicy / WebhookDispatcher); http:// only through a per-destination, audit-logged admin opt-in.
3. Backups: wire ArtifactCheckedBackupFactsProvider as the BackupFactsProvider so compliance reads backup age, success and encryption from the real artifact. Refuse unencrypted in-app backups unless an audit-logged override exists.
4. Data store form: a remote PostgreSQL host needs sslmode verify-full or verify-ca when the password is empty or reused. Weak transport (disable/allow/prefer) on a remote host is refused unless the form sends allow_insecure_transport => true, which must be an explicit admin checkbox with a warning. Local socket/loopback peer auth stays allowed. Show DataStoreSwitch's error text verbatim.
5. Retention: feed RetentionService::prune() with SecurityBaseline::auditRetentionDays().
6. Tests for each; report files changed, test results, any server step.
```

### R6. Log presets UI

Paste the "Prompt for the RivetIT chat" from `log-presets-edition-prompts.md`.

### R7. Ticket categories: SLA-exempt / non-billable

Paste the category prompt given earlier (per-category "don't count toward ticket timer" and non-billable flag, migration, UI, timer/SLA/billing honour it, tests).

---

## RivetMSP chat

### M4. Adopt rc.54 and apply the rc.53 hardening

Paste R5 above, with "RivetIT" replaced by "RivetMSP". If the staged catch-up M1 is not merged, finish it first.

### M5. Log presets UI plus per-client compliance row

Paste the "Prompt for the RivetMSP chat" from `log-presets-edition-prompts.md`.

### M6. Invoice/quote prefix hardening (one-liner)

```
In RivetMSP agent/post/invoice.php and quote.php (and their *_model.php), pass the prefix through an explicit mysqli_real_escape_string before it reaches the query. sanitizeInput already makes this safe in practice (verified false positive), so this is defence in depth only; add a test with a quote character in the prefix.
```

### M7. Secrets hygiene

Paste M2 from `edition-prompts-rc47.md` if not yet done.

---

## Core work still open (this chat)

Audit-log tamper evidence (hash chain, ERR-04), SBOM/release signing (needs a key), reporting phases 3-5, data store phases 4/5 and load test, Windows audit-policy fact/GPO follow-up (#88), off-site backup destination (your choice needed).
