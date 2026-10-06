# Security policy

## Reporting a vulnerability

Please do **not** open a public issue for a security problem. Use GitHub's private vulnerability reporting
(Security tab > "Report a vulnerability") on this repository, or email the maintainer listed on the GitHub profile.

Include the affected version (tag), what you can do with it, and steps or a proof of concept. You will get an
acknowledgement within 5 working days and a fix or mitigation plan within 30 days for confirmed issues.

## Supported versions

Only the latest `0.x` tag receives fixes until 1.0.0; after that the latest minor of the current major and the previous
major's last minor. RivetIT and RivetMSP pin a tag, so a fix is released as a new tag and the editions update their pin.

## Scope

In scope: this library (`src/`, including the migration classes under `src/*/Migration/`, the webhook `UrlPolicy` and signature V2, and the Redis TLS configuration), including the DOCX/PDF converters that parse untrusted files, the
webhook signing, the Redis lock and rate-limit helpers, and the MCP pipeline. Out of scope: the editions themselves
(report those to RivetIT / RivetMSP), and third-party dependencies (report upstream; we track them with `composer audit`
and Dependabot).
