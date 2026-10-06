# MCP (Model Context Protocol server pipeline)

`RivetCore\Mcp`: the edition-neutral parts of a remote, read-only MCP server: configuration, extra token checks, the common tool
path (rate limit, permission, scoped read, audit, envelope), identity linking and diagnostics. Core does not depend on the MCP SDK:
the edition owns the SDK glue and the tool bodies.

## What it owns

Table `mcp_unlinked_identities` (migration 0003): `mcp_unlinked_id`, `issuer`, `subject`, `display_name`, `email`, `attempts`,
`first_seen_at`, `last_seen_at`. A valid token whose identity is not linked to an agent yet is recorded here so an administrator
can link it from a list.

## You supply

An `AgentDirectoryInterface` over your user table (see [adapters](../adapters.md); the conformance kit checks it), the token
validation (signature, issuer, expiry), the permission callable for `ToolPipeline::run()`, and the tool bodies.

## Flags

Read through `SettingsInterface`: `mcp.enabled` (module switch, `1` = on), `mcp.issuer`, `mcp.audience`. Environment variables
`<PREFIX>ISSUER` and `<PREFIX>AUDIENCE` override the settings and `<PREFIX>ENABLED=0` is a hard off switch no setting can undo.
`mcp.issuer === null` means the schema does not have the setting yet (treated as not configured).

## Use it

<!-- run -->
```php
use RivetCore\Mcp\{McpConfig, TokenClaimsGuard};
use RivetCore\Support\ArraySettings;

$cfg = McpConfig::resolve(new ArraySettings(['mcp.enabled' => 1, 'mcp.issuer' => 'https://id.example.test', 'mcp.audience' => 'https://app.example.test/mcp']), fn (string $k) => false, 'APP_MCP_');
$ok = TokenClaimsGuard::acceptable('subject-1', ['mcp:read'], ['aud' => 'https://app.example.test/mcp', 'iat' => time(), 'exp' => time() + 600], 'https://app.example.test/mcp');
echo json_encode(['configured' => $cfg['configured'] ?? null, 'token_ok' => $ok]), "\n";
```

## How it fails

- `TokenClaimsGuard::acceptable()` returns `false` (never throws) unless the MCP audience is the token's only recipient, the required
  scope is present, and the lifetime is sane (issued at most 60 s in the future, expiry after issue, at most one hour).
- `ToolPipeline::run()` returns the standard `{success, request_id, data, errors}` envelope for every outcome: rate limited,
  permission denied, not found (`NotFoundException` from the tool body), internal error. An audit failure never turns a permitted
  read into an error. The rate limiter fails open when Redis is down.
- An identity that is not linked is recorded and denied; linking is always an explicit administrator action (`IdentityLinker::link()`
  runs in a transaction).
- Known issue noted in the changelog: the pending-identity table uses a case-insensitive collation for issuer and subject; OIDC
  subjects are case sensitive. See [SECURITY-REVIEW-2.md](../SECURITY-REVIEW-2.md).
