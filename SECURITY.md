# Security policy

## Reporting a vulnerability

Please do **not** open a public issue for a security problem. Use GitHub's private vulnerability reporting
(Security tab > "Report a vulnerability") on this repository, or email the maintainer listed on the GitHub profile.

Include the affected version (tag), what you can do with it, and steps or a proof of concept. You will get an
acknowledgement within 5 working days and a fix or mitigation plan within 30 days for confirmed issues.

## Supported versions

| Version | Status |
|---|---|
| 1.0.x (current major, from 1.0.0) | Security fixes for the latest minor; the previous minor receives fixes for critical issues for 90 days after a new minor |
| 1.0.0-rc.N | Fixed by the next release candidate; not supported after 1.0.0 |
| 0.x | Only the final 0.x tag is fixed, and only until the editions have moved to 1.0 (target: 90 days after 1.0.0) |

After 1.1, the latest minor of the current major and the last minor of the previous major are supported. RivetIT and
RivetMSP pin a tag, so a fix is released as a new tag and the editions update their pin; the changelog states which fixes are
security fixes and their severity (Low/Medium/High as defined in the review reports).

## Threat model and reviews

* [docs/security/threat-model.md](docs/security/threat-model.md): assets, actors, trust boundaries, STRIDE tables per module,
  the deliberate fail-open decisions, and what Core does **not** protect.
* [docs/security/edition-checklist.md](docs/security/edition-checklist.md): what an edition must verify when it integrates
  Core (authorisation, tenancy, key storage, JWT validation, output encoding, process isolation).
* [docs/security/review-2026-10.md](docs/security/review-2026-10.md): the second security review before 1.0.0-rc.1, with
  every finding, its severity and status.

A report that depends on something Core documents as the edition's responsibility (for example "an unauthenticated caller can
call `JobRunner::start()`") is an edition issue; send it to the edition, and to us if the documentation is unclear.

## Scope

In scope: this library (`src/`, including the migration classes under `src/*/Migration/`), in particular
* the webhook `UrlPolicy` / `NetworkList` (SSRF), signature V1/V2, header and template handling, and the dispatcher's
  handling of untrusted receiver responses;
* the DOCX and PDF converters, which parse untrusted files (zip bombs, XXE, traversal, process execution);
* the Redis lock, rate-limit, admin and TLS configuration helpers;
* the MCP pipeline (`TokenClaimsGuard`, `ToolPipeline`, identity linking);
* audit redaction and the shared compliance report (what a portal user can see);
* the job queue, cron runner and migration runner.

Out of scope: the editions themselves (report those to RivetIT / RivetMSP), third-party dependencies (report upstream; we track
them with `composer audit` and Dependabot), poppler and libxml vulnerabilities as such (report upstream; we do want to hear
about a Core call pattern that makes one exploitable), and denial of service that needs an authenticated administrator
supplying hostile configuration within a documented limit.
