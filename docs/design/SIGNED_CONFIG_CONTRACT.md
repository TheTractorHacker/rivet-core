# Signed configuration contract (CORE-DS-16), server hand-off

Status: agent side implemented (endpoint-agent PR E). The PHP server side below is NOT done; until it is, the agent tolerates unsigned `config.logs` (older server) and nothing changes on the wire.

## What the server already does
`RmmSettings::signCheckList()` signs every delivered check (`Signer::checkMessage($item)`, key `signing_key`), for every type. So an agent can require a signature on every check today.

## What the server must add
1. **Announce the contract.** In every check-in and enrollment response that carries `config`, add `config.signed_config: 1` (signed checks and signed logs) or `2` (logs also carry `device_id` and `seq`, item 3) (integer, additive). The agent remembers the highest value it has ever seen (`State.SignedConfigV`) and from then on refuses unsigned checks and unsigned `config.logs`. Absent = old behaviour.
2. **Sign `config.logs`.** `RemoteActions::agentLogConfig()` result gets a `signature` member:
   `base64(ed25519(signing_key, "rivetit-logs-config-v1" + "\n" + canonical_json(config_without_signature)))`
   Canonical JSON is the existing job/check canonical form (`Signer::canonical`, same as `checkMessage`/change/hypervisor configs, which use the same pattern with their own domain line). The domain string is exactly `rivetit-logs-config-v1`.
3. **Bind and version `config.logs` (contract version 2).** Inside the signed `config.logs` object add BOTH:
   * `device_id`: this device's id as a JSON **string** (`"7"`, not the integer 7), and
   * `seq`: a monotonic non-negative integer (for example the rule-set revision); it may repeat while the configuration is unchanged (the server sends it at every check-in) and must never decrease.
   Announce `config.signed_config: 2` once the server signs both. From then on (and whenever the device has `require_signed_config`) the agent REFUSES a signed logs configuration with a missing, null, empty, non-string or foreign `device_id`, a missing or non-integer `seq`, or a `seq` below the highest accepted (`State.LogsConfigSeq`), and refuses an unsigned one. Both members are covered by the signature, so they cannot be stripped. While the server announces only `signed_config: 1` (or nothing) the members are optional but, when present, are checked the same strict way.
4. **Optional device binding of checks.** Add `device_id` (the device's id, integer or string) to each signed check item before signing, so a signed check cannot be replayed on another device. The agent already verifies it when present and accepts items without it. Adding the member changes the signed bytes, so do it together with the release that adds `signed_config`.
5. Tests: a check-in vector with `signed_config`, a signed logs config verified by the Go agent test vector (`acceptLogsConfig`), and the golden transcripts re-recorded for those cases only with entries in `expected-deltas.json`.

## Local switch
`config.json` `"require_signed_config": true` makes an agent refuse unsigned checks/logs even from a server that never announces `signed_config`.

Learned requirement for checks (CORE-AGENT-4, red team 2026-11-21): once the server has delivered a non-empty check list (enrolment or check-in) in which every check carries a signature that verifies, the agent refuses unsigned checks from then on (`State.ChecksSignedSeen`, sticky, reset by a new enrolment), even if `signed_config` is never announced. Core already signs every check type (`RmmSettings::signCheckList`), so nothing changes on the wire. `config.logs` is not covered by this (older servers signed checks before they signed logs). `config.json` `"allow_unsigned_checks": true` turns the learned requirement off; it never overrides `require_signed_config` or an announced `signed_config`. The server default for NEW installs (`signed_config` 2) is the server's half of CORE-AGENT-4.
