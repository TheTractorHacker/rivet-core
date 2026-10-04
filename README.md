# rivet-core

Shared, edition-neutral PHP services used by both **RivetIT** (internal IT) and **RivetMSP** (managed service providers).
Free and open source under GPL-3.0. The code is free; hosting and setup are the paid service.

## Status

Planning. No code has moved here yet. See [ROADMAP.md](ROADMAP.md) and the open issues.

## Rules

1. A file moves here only if it has no client or department assumptions, or if those sit behind an interface.
2. Core owns its own migrations, tracked separately from either edition's database version.
3. Each edition pins a semver tag and never tracks `main`.
4. Every adopted module is off by default and enabled per instance.
5. The `ITFlow\` namespace is kept for compatibility with existing code.

## Pilot extraction order

1. Audit, Redis (lock, rate limit, health), Cron/JobRunner, Jobs queue, MCP pipeline
2. ITSM (Problem, Change), KB, Knowledge
3. Stays in each edition: UI shell, billing, client/department model, Training, Odoo
