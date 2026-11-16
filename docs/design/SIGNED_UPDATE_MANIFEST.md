# Signed update manifest with an offline release key (CORE-DS-26)

Status: agent side implemented (endpoint-agent PR E). Server side is a hand-off to the PHP side; the exact contract is below. Until the server sends release signatures an agent built **without** release keys behaves exactly as before.

## Why
The instance signature of an update (`Signer::signManifest`) covers only the lower-case SHA-256 text. Whoever can write the database, or holds the instance key, can offer any previously signed binary under any version, os, arch or `min_version`, and a leaked instance key cannot be rotated out remotely.

## Keys
* **Release key**: an Ed25519 key pair held offline by the release owner (never on the server). Generate with any Ed25519 tool; the public key is base64 of the 32 raw bytes.
* **Trust anchor**: the public key(s) compiled into the agent: `make ... RELEASE_KEYS=<b64>[,<b64>]` (ldflag `rivetit-agent/internal/update.EmbeddedReleaseKeys`). An agent with no embedded key and no rolled-over key skips the check (migration, see below).

## Signed message (what the release key signs)
`"rivetit-update-manifest-v1" + "\n" + canonical_json` where canonical JSON has exactly these keys, sorted, no whitespace, all strings:
`{"arch":"amd64","kind":"agent_update","min_version":"1.0.0","os":"linux","sha256":"<lowercase hex>","version":"1.2.3"}`
`min_version` is `""` when unset. Signature: Ed25519 detached, base64 (standard, padded). Go reference: `update.ManifestMessage` / `update.VerifyRelease`.

## Manifest fields the server must relay (additive)
`GET agent_update` / check-in `update` object gains: `os` (`windows`|`linux`), `arch` (`amd64`|`arm64`), `release_signature` (above). The existing `version`, `url`, `sha256`, `signature` (instance signature), `min_version` stay. The server stores `os`, `arch`, `release_signature` with each package version (`rmm_agent_packages`-style table, new Core migration) and the release owner uploads the signature together with the binary. `UpdateService` must serve the signature of the package it actually serves (per os/arch) and never invent one.

## Agent behaviour
With at least one trusted release key, `Manager.Apply` refuses, before downloading anything, a manifest that has no `release_signature`, whose `os`/`arch` differ from the running platform, or whose signature does not verify under any trusted key. The instance signature, host allow-list, sha256, size cap, probation and rollback are unchanged and still required.

## Key rollover and revocation
Check-in response gains an optional top-level `release_rollover`:
`{"kind":"release_key_rollover","new_key":"<b64 ed25519 public>","revoke":["<b64 public key>",...],"seq":<int>,"signature":"<b64>"}`
signed by a key the agent currently trusts over `"rivetit-release-rollover-v1" + "\n" + canonical JSON` of `{"kind":"release_key_rollover","new_key":"...","revoke":[sorted b64 keys],"seq":N}` (sorted keys, no whitespace; `revoke` is always present, `[]` when empty). Rules the agent enforces:
* `seq` must be EXACTLY the next one (highest applied + 1). A message with a huge `seq` is refused and cannot lock out later rollovers.
* The new key is added; every key in `revoke` (embedded or learned) is removed from the trusted set and can no longer sign updates or rollovers. A message cannot revoke its own `new_key`; at most 16 entries.
* State (`state.json`): `release_keys`, `release_revoked`, `release_rollover_seq`.
To retire a leaked key K1: sign `{new_key: K2, revoke: [K1], seq: N+1}` with a still-trusted key (K1 itself if K1 is the only one: do this before the attacker does). The server stores and relays the message and cannot create one.

## Local switch
`config.json` `"disable_update": true` stops self-update entirely (independent of `disable_jobs`, which deliberately does not).

## Migration
1. Ship agents with `RELEASE_KEYS` empty plus the server change: nothing changes.
2. Start signing releases offline and publishing `release_signature`.
3. Ship an agent build with `RELEASE_KEYS` set (that build still accepts the update that delivers it: it is validated by the previous build, which has no key). From then on enforcement is on for that build and every later one.
Rolling the key: sign a `release_rollover` with the current key, publish it, then start signing with the new key; keep the old key trusted until the fleet has applied it, then publish a second rollover that revokes it.

## Server tests to write
Manifest vectors (the Go test `release_test.go` fixes the byte format), a rollover vector, `UpdateService` serving per-platform signatures, a migration for the table, and golden transcripts re-recorded only for update-bearing cases with entries in `expected-deltas.json`.
