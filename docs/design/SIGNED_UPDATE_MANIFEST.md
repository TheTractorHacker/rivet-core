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

## Key rollover
Check-in response gains an optional top-level `release_rollover`:
`{"kind":"release_key_rollover","new_key":"<b64 ed25519 public>","seq":<int>,"signature":"<b64>"}`, signature by a key the agent already trusts over `"rivetit-release-rollover-v1" + "\n" + {"kind":"release_key_rollover","new_key":"...","seq":N}` (canonical JSON, sorted keys). The agent applies it only if `seq` is above the highest applied, adds the key to `state.json` (`release_keys`) and keeps trusting the embedded keys. The server just stores and relays the message (e.g. in `rmm_settings`) while any agent might need it; it cannot create one.

## Local switch
`config.json` `"disable_update": true` stops self-update entirely (independent of `disable_jobs`, which deliberately does not).

## Migration
1. Ship agents with `RELEASE_KEYS` empty plus the server change: nothing changes.
2. Start signing releases offline and publishing `release_signature`.
3. Ship an agent build with `RELEASE_KEYS` set (that build still accepts the update that delivers it: it is validated by the previous build, which has no key). From then on enforcement is on for that build and every later one.
Rolling the key: sign a `release_rollover` with the current key, publish it, then start signing with the new key; agents that applied it trust both.

## Server tests to write
Manifest vectors (the Go test `release_test.go` fixes the byte format), a rollover vector, `UpdateService` serving per-platform signatures, a migration for the table, and golden transcripts re-recorded only for update-bearing cases with entries in `expected-deltas.json`.
