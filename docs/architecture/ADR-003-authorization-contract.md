# ADR-003: Authorization and tenant contract

Status: accepted (0.17.0). Issue #24.

## Context

Core started as read-mostly building blocks. Three modules now *act* on behalf of someone: Automation runs actions
(create a ticket, call a webhook, notify), ITSM and Workflow change records, and MCP serves an agent. Each edition has its
own permission model (RivetIT roles per module, RivetMSP roles plus per-client access), which Core must never import
(ADR-002). Without a contract, every Core feature that acts would either skip authorization or hard-code an edition rule.

## Decision

1. Core ships one small interface, `RivetCore\Contracts\AccessPolicyInterface`:

   ```php
   public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool;
   ```

   `ability` is a dotted verb the module documents (for example `itsm.problem.close`, `workflow.run.start`,
   `automation.rule.execute`, `mcp.tool.call`). `subjectType`/`subjectId` identify the record (so an MSP edition can check
   client access); `context` carries anything else (the client id, the acting agent). `userId` is null for system actors.
2. The interface is **opt-in per service**: a module constructor takes an optional policy. With none injected behaviour is
   unchanged (editions still authorize at their own call sites today), so this is additive and non-breaking.
3. Core ships `RivetCore\Support\DenyAllPolicy` and `AllowAllPolicy` (explicitly named, for tests and for editions that
   authorize elsewhere). There is no default policy: an edition that wants Core to enforce must pass one.
4. A denied check throws `RivetCore\Contracts\AccessDenied` (carries ability and subject) so callers cannot ignore it by
   forgetting to test a boolean. `can()` stays boolean for UI decisions.
5. Tenant scoping is a context concern, not Core data: Core tables carry no tenant column. An edition that is multi-tenant
   scopes through the policy (and through its own queries) using `subjectId`/`context`.

## Consequences

- Modules adopt the policy one at a time as they gain acting features; the first adopters are Automation execution and MCP tool calls.
- Editions implement one adapter class each (`can()` over their existing permission helpers).
- Nothing in 0.17 changes behaviour for existing callers.
