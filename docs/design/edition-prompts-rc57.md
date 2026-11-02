# Edition prompts: RivetCore 1.0.0-rc.57 (audit log hash chain)

Core ships the chain (migration 0048, `AuditChainWriter`, `AuditChainVerifier`, `scripts/audit-verify.php`). Nothing is chained in an edition until the edition adopts it. Read `vendor/rivet/rivet-core/docs/security/AUDIT_CHAIN.md` and the "audit hash chain" section of `UPGRADING.md` first. Honest status: baseline control ERR-04 is **partial**, not enforced: a database administrator who rewrites the whole chain is only caught if the head hash is stored off the database.

## RivetIT chat

```
Adopt RivetCore 1.0.0-rc.57 and turn on the audit log hash chain. Worktree, one PR, beta first, dump the database before the migration, do not deploy to production.

1. Pin rivet/rivet-core ^1.0.0-rc.57, run Core migration 0048 in ONE DB step and mirror it in db.sql.
2. Wire AuditService with an AuditChainWriter (keyed mode: derive the key with AuditChainHasher::deriveKey from the existing key-encryption key, purpose KeyPurpose::AUDIT_CHAIN). Follow the 8-step edition section in UPGRADING.md. This edition already has its own earlier audit chain: decide, with a test, how the two coexist (do not break the existing sealed rows; Core reports them as legacy unchained rows) and say which one is authoritative.
3. Every place that inserts into the audit table with its own SQL must go through AuditService instead, or those rows show up as unchained after the chain starts and the verifier fails. grep for INSERT INTO audit and list them in the PR.
4. Health page: show the verifier result (ok / not intact / key problem / legacy rows N) and run scripts/audit-verify.php from the daily cron, alerting the admin on failure.
5. Store the exported head hash OFF the database (a root-owned file outside the web root, or the off-site backup destination once chosen) after each verify, and pass it back to verify() as the anchor. Without this the chain only detects partial tampering; say so on the page in plain words.
6. Tests: tamper with a row, delete a row, and prune through RetentionService; the verifier must report each correctly. Add the edition's EDITION_BASELINE.json entry for ERR-04 as partial.
Do not claim tamper-proof logging anywhere (see docs/security/CLAIMS.md); the approved wording is in AUDIT_CHAIN.md.
```

## RivetMSP chat

```
Do everything in the RivetIT prompt above for RivetMSP (pin rc.57, migration 0048 in db.sql, AuditChainWriter, route all audit inserts through AuditService, health page, daily verify, off-database head hash, tests, EDITION_BASELINE.json entry), then also:

Fix the Admin Update page (rivet-core issue #39). admin/post/update.php runs `git pull` as the web user against a tree that the CLI owns, which fails or leaves a mixed-ownership tree. Do what RivetIT's deploy/update.sh does: run the update as the owner of the files, take a backup first, run migrations, restart/reload the PHP-FPM unit (note the unit may be named php<full version>-fpm on our servers, for example php8.5.12-fpm), and report failures instead of swallowing them. Test it on beta only.
Worktree, one PR, dump the database before the migration, do not deploy to production.
```
