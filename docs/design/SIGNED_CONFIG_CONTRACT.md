# Signed configuration contract (CORE-DS-16), server hand-off

Status: agent side implemented (endpoint-agent PR E). The PHP server side below is NOT done; until it is, the agent tolerates unsigned `config.logs` (older server) and nothing changes on the wire.

## What the server already does
`RmmSettings::signCheckList()` signs every delivered check (`Signer::checkMessage($item)`, key `signing_key`), for every type. So an agent can require a signature on every check today.

## What the server must add
1. **Announce the contract.** In every check-in and enrollment response that carries `config`, add `config.signed_config: 1` (integer, additive). The agent remembers the highest value it has ever seen (`State.SignedConfigV`) and from then on refuses unsigned checks and unsigned `config.logs`. Absent = old behaviour.
2. **Sign `config.logs`.** `RemoteActions::agentLogConfig()` result gets a `signature` member:
   `base64(ed25519(signing_key, "rivetit-logs-config-v1" + "\n" + canonical_json(config_without_signature)))`
   Canonical JSON is the existing job/check canonical form (`Signer::canonical`, same as `checkMessage`/change/hypervisor configs, which use the same pattern with their own domain line). The domain string is exactly `rivetit-logs-config-v1`.
3. **Optional device binding.** Add `device_id` (the device's id, integer or string) to each signed check item before signing, so a signed check cannot be replayed on another device. The agent already verifies it when present and accepts items without it. Adding the member changes the signed bytes, so do it together with the release that adds `signed_config`.
4. Tests: a check-in vector with `signed_config`, a signed logs config verified by the Go agent test vector (`acceptLogsConfig`), and the golden transcripts re-recorded for those cases only with entries in `expected-deltas.json`.

## Local switch
`config.json` `"require_signed_config": true` makes an agent refuse unsigned checks/logs even from a server that never announces `signed_config`.
