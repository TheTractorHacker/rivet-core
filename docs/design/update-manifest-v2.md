# Signed update manifest, server side (CORE-DS-26)

Status: implemented in RivetCore (server half). The agent half is the endpoint agent's.

The check-in response carries `update: {version, url, sha256, signature, min_version}` where `signature` is Ed25519 over the bare lowercase SHA-256 text. Version, platform and minimum version are not
covered. For an agent that announces the capability **`update:signed`** the object also carries:

| Member | Meaning |
|---|---|
| `os` | `windows` or `linux`: the platform the binary is for |
| `arch` | `amd64` or `arm64` |
| `manifest_signature` | Ed25519 (the key the agent pinned at enrolment), base64, over the message below |

Message: the line `rivetit-update-manifest-v1`, a line feed, then the canonical JSON (the same canonicalisation as job signatures) of
`{"kind":"agent_update","version":V,"os":O,"arch":A,"sha256":H,"min_version":M}` with `sha256` lowercase.

An agent that verifies `manifest_signature` cannot be told to install a signed binary under another version, platform or minimum version. An agent that does not announce `update:signed` gets the response it
always got (byte-identical). Not in this change, and left to a design decision of the key owner: an offline release key and a signed key rollover (`signing_key_id` changes are still only logged by the agent).
