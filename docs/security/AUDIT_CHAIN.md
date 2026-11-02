# Audit log tamper evidence (hash chain)

Baseline control **ERR-04**, gap **GAP-02**, audit finding **INFO-08**. Status: **partial** (see "What it does not do").

## What it is

Every audit row written through `AuditService` when it is given an `AuditChainWriter` carries two extra values:

* `prev_hash`: the `row_hash` of the row before it (`AuditChainHasher::GENESIS`, 64 zeros, for the first one);
* `row_hash`: SHA-256 over `prev_hash`, a newline and a canonical JSON list of the row's fields (`audit_id`, `event_type`, `actor_user_id`, `entity_type`, `entity_id`, `action`, `summary`, `metadata_json`, `ip_address`, `user_agent`, `request_id`, `created_at`, each cast to a string, NULL kept as null).

Editing a row, deleting a row from the middle, inserting or reordering rows, or removing the newest rows all show up when `AuditChainVerifier::verify()` walks the chain.

**Keyed or plain.** Core has a crypto layer (`KeyPurpose`, one master KEK, HKDF per purpose), so the hash can be an HMAC-SHA-256: pass `AuditChainHasher::deriveKey($kek)` to both the writer and the verifier. Without a key the hash is plain SHA-256. Plain SHA-256 detects accidental and partial tampering (someone edits or deletes a few rows, a bad script, a restore from an old backup). **It does not stop a database administrator who rewrites the whole chain**, because anyone can recompute SHA-256. A key raises the bar to "database access and the key", but an administrator who also holds the key still wins. Only a head hash stored **outside the database** catches a consistent rewrite (below). The mode and a short tag of the key are stored with the chain; the verifier reports a wrong or missing key as `key_mismatch` / `key_unavailable`, not as tampering. There is no key rotation: changing the key means starting a new chain (the old rows become "unchained after the start").

The field encoding is the one RivetIT's own earlier edition-side chain used (src/Audit/AuditChain.php in RivetIT). Its key derivation and its mode records are different, so an edition that already has such a chain keeps it or starts the Core chain fresh; see UPGRADING.md. Not tested against RivetIT's real rows.

## Schema (migration `0048_audit_hash_chain`)

Additive and idempotent; no existing row is touched:

* `audit_events.prev_hash`, `audit_events.row_hash` (`char(64) NULL`), a no-op where an edition already has them;
* `audit_chain_head` (one row, `id = 1`): newest chained `audit_id`, its hash, hash mode, key tag;
* `audit_chain_checkpoint`: one row per retention prune that removed chained rows.

MariaDB and MySQL; the audit table is not in the PostgreSQL RMM store and nothing here touches it.

## Writing safely under concurrency

`AuditChainWriter::append()` runs in one transaction: `SELECT ... FROM audit_chain_head WHERE id = 1 FOR UPDATE`, `INSERT` the event, read it back (so the hash covers what the database stored, including `audit_id` and `created_at`), `UPDATE` the row with `prev_hash` and `row_hash`, `UPDATE` the head, commit.

Why a head row and not `GET_LOCK()`: the lock belongs to the transaction, so it is released by commit, rollback or a dropped connection (no leaked named lock, safe behind connection poolers), and the insert, the hash and the head move together or not at all. Writers take turns for the length of one short transaction. `tests/Integration/AuditChainConcurrencyTest.php` starts six PHP processes with their own connections writing 40 events each and asserts 240 chained rows, no two rows sharing a `prev_hash`, and a clean verify. (Removing the `FOR UPDATE` makes that test fail, which was checked by hand.)

Cost and caveat: audit writes are serialised. If an edition calls `AuditService::log()` inside a long outer transaction (its adapter's `transaction()` may join it), the head row stays locked until that transaction ends; log after the commit where you can.

The chain never costs an event: if it cannot be extended (migration not run, a different key than the chain was started with, a lock timeout) the row is inserted **unchained**, one line goes to `error_log`, and the verifier reports it as `unchained_rows`. A genuine database failure still propagates exactly as before.

## Retention and pruning

`RetentionService::prune()` deletes audit rows oldest first and then calls `AuditChainCheckpoints::record()`. The oldest row that is kept still holds, in its `prev_hash`, the hash of the last deleted row; that value is stored as a checkpoint "anchor". The verifier accepts an oldest-kept row whose `prev_hash` is the genesis value or a recorded anchor, and nothing else. Deleting old rows any other way (a manual `DELETE`, an edition's own pruning SQL) leaves a `prev_hash` no checkpoint accounts for, and is reported. If every row is pruned, the head row's hash is the anchor and the chain continues from it.

## Existing rows

The migration does not rewrite history. Rows before the first chained row are **legacy**: reported as `unchained legacy rows: N`, never hidden, never covered. Unchained rows that appear **after** the chain started are a failure (`unchained_rows`, with the first id): they were written outside the chain or while it was unavailable.

## Verifying

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
$verifier = new \RivetCore\Audit\AuditChainVerifier($database, $key /* or null */);
$result = $verifier->verify();      // AuditChainResult
$result->ok;                        // true for status ok or empty
$result->status;                    // ok | empty | broken | unchained_rows | key_unavailable | key_mismatch | not_installed
$result->firstBrokenId;             // audit_id of the first failing row
$result->chained;                   // rows verified
$result->legacyUnchained;           // rows from before the chain
$result->unchainedAfterStart;
```

`empty` means nothing has been chained yet. Do not show it as a green tick.

CLI (read-only, exit 0 ok, 1 not intact, 2 usage):

```
RIVETCORE_DB_NAME=itflow RIVETCORE_DB_USER=... RIVETCORE_DB_PASS=... \
  php vendor/rivet/rivet-core/scripts/audit-verify.php [--json] [--export-head] [--anchor=FILE|ID:HASH]
```

`RIVETCORE_AUDIT_CHAIN_KEY` takes the key as hex when the chain is keyed.

Compliance: `Compliance\Check\AuditChainIntactCheck` wraps the verifier (pass, warn when not started or not verifiable, fail when broken). It is in no built-in profile, so no install starts failing; an edition adds it to its catalog. The existing `integrity_chain` check of the `tamper_evidence` requirement still says "not available" and is unchanged.

## Anchoring the head off the database

`AuditChainVerifier::exportHead()` returns `['id', 'hash', 'mode', 'exported_at']` for the newest chained row. An edition should store it somewhere the database administrator cannot also write (a different host, a write-once log, a signed email, a ticket) on a schedule, and pass a stored one to `verify($anchor)` or `audit-verify.php --anchor=...`. The verifier then fails if that row is missing, has a different hash (the chain was rewritten), or lies beyond the newest row (the tail was cut). An anchor older than the oldest kept row is reported as `predates_kept_rows` and cannot be checked.

## What it does not do

* **Off by default.** An edition must pass an `AuditChainWriter` to `AuditService` and run the migration. Rows an edition inserts with its own SQL are not chained (they are reported as unchained after the start).
* **No protection against a database administrator** who rewrites rows, hashes, head and checkpoints consistently, unless the head is anchored off the database; with a key, unless they also have the key.
* Rows appended after the last anchor are not covered by it.
* The head and checkpoint tables are ordinary tables in the same database.
* It proves order and integrity, not that an event was complete or true: an action that was never audited leaves no row to protect.
* Rows removed in the middle of a retention run by a clock that went backwards (non-contiguous deletion) would be reported as a break.
