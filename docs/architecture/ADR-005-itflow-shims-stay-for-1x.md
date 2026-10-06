# ADR-005: `ITFlow\` compatibility shims stay for all of 1.x

Status: accepted (2026-10-06). Decision #45 in the ROADMAP.

## Context

Core was extracted from the ITFlow fork that RivetIT began as. When a service moved to `RivetCore\...`, each edition kept a thin class
under its old namespace (`ITFlow\...` in RivetIT; RivetMSP carries the same pattern under its own namespaces) that extends or
delegates to the Core class, so the roughly 35 audit call sites, the cron scripts, the MCP server and the pages that were written
against the old names keep working. The shims are edition code, not Core code; Core never refers to them (ADR-002).

The question for 1.0 is whether the editions should remove them. Removing them would force a rewrite of every call site and every
plugin or private script that an installation may have written against the old names, for no functional gain.

## Decision

1. The shims stay for the whole of the 1.x line. They are marked `@deprecated` in the editions (docblock naming the `RivetCore\`
   class that replaces them), and new code in the editions calls `RivetCore\` directly.
2. They are **removed in 2.0**, in the editions' next major, not in Core's: Core has nothing to remove. If an edition's 2.0 does not
   coincide with Core's, the edition's own changelog says so.
3. No behaviour is added to a shim. A shim is a pass-through; a fix goes into the Core class.
4. Each edition carries a test that fails if a shim stops matching its Core class (the adapter conformance kit, ADR-009, and the
   edition regression run already exercise them).

## Consequences

- No call-site churn at 1.0; the 30-day soak runs on the same code paths production already uses.
- Two names for the same class exist for a long time, so the documentation shows `RivetCore\` names only; `UPGRADING.md` lists the
  shim namespaces as deprecated.
- The edition test suites must keep a smoke test per shim until 2.0.

## Reversal cost

Low to remove earlier (a mechanical rename in the edition plus a major bump of the edition), moderate to reverse after removal
(plugins and private scripts break and have to be fixed by their authors). Because the shims live in the editions, this decision
does not constrain Core's own versioning.
