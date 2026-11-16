# Signed installer trailer with a build-time trust anchor (CORE-DS-20)

Status: agent side implemented (endpoint-agent PR E). The server (stamper) side is a hand-off to the PHP side; the contract is below.

## Problem
The stamped installer trailer (server URL, CA, enrollment token, Mesh settings) is protected only by an unkeyed SHA-256 in its footer. Anyone can append a new payload and footer to a genuine (and later Authenticode-signed) installer, and setup trusts the server URL and CA in it.

## Format (additive, backward compatible)
The file layout and the 52-byte footer do not change. The signature is an extra member **inside the payload JSON**: `"signature": "<base64>"`. Older agents ignore unknown members (`embed/vectors_test.go`), so a signed installer still works with every existing agent, and an unsigned installer still works with every agent that has no trust anchor.

Signed message: `"rivetit-installer-payload-v1" + "\n" + canonical_json(payload without "signature")`, Ed25519 detached, base64 standard padded. Canonical JSON is the same canonical form used for jobs and checks (sorted keys, no whitespace, integers only; `Signer::canonical` in PHP, `jobs.Canonical` in Go). Key: the instance signing key (the one `Signer::sign` already uses for jobs/checks).

## Trust anchor
The agent is built per instance (build host). Pass the instance signing PUBLIC key at build time: `make dist TRUST_KEYS=<base64 32-byte key>[,<key>]` (ldflag `rivetit-agent/internal/embed.EmbeddedTrustKeys`). With a key compiled in, `embed.Parse` returns `ErrUnsigned` unless the payload carries a valid signature by one of the keys, so a re-stamped genuine installer cannot enroll the device elsewhere. Without a key (all existing builds, the default) nothing changes.

## Server hand-off
`InstallerStamper` (the code that builds the payload and appends footer): after building the payload object and before the 16 KiB size check, sign it as above and add `signature`. Keep the payload under `MaxPayload` (16384) including the 88-character signature. Test with the vector produced by the Go test `embed/signed_test.go` (`SignPayload`). Expose the instance signing public key to the build host so it can set `TRUST_KEYS`.

## Migration
1. Ship the server change (signs every payload). Old and new agents keep working.
2. Rebuild agents with `TRUST_KEYS` set (build host config). From that build on, unsigned or foreign-signed installers are refused with: "installer configuration is not signed by this server's key; download a new installer".
3. Installers stamped before step 1 and run by a step-2 agent need a re-download (expiry is at most days anyway).
Key rotation of the instance signing key changes the stamper key: rebuild agents with the new key (`TRUST_KEYS` accepts a list, so old and new can overlap).

## MSI note
The `.msi` payload is read through the same `embed.Read`, so it is covered. Reading it under an exclusive handle is done by CORE-DS-48 (`--payload-from` is pinned).

## Dev tool
`rivetit-agent stamp --sign-seed <base64 seed>` (devtools build) signs a test installer.
