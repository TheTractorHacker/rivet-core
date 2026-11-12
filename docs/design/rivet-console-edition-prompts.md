# Rivet Console: edition prompts (contract v1.2)

Ready to paste, one block per chat. They turn the agreed Rivet Console contract (v1.2, agreed between the Console lane and Core; the Console lane keeps the contract at `docs/design/rivet-console-contract.md` in its own repo) into edition work. **Do not start until Core reports the release that carries the redeem and events routes** (not built yet; field and setting names below are the agreed ones, Core confirms them at release). Beta before production, dump the database before any migration, never deploy from these prompts. The whole feature is optional and **off by default**: with `remote_console` off nothing changes for anyone, and stock MeshCentral keeps working exactly as today. RivetIT production go-live is January 1: nothing here may change behaviour for an install that has not switched the feature on.

What Core supplies (do not re-implement it): the launch descriptor, the redeem and events services, the audit rows, the settings and their validation, the webhook events, retention. What the editions own: the routes' web-server entries, the tables in `db.sql` and the updater, the settings page, the device page button, the documentation for operators.

## Common to RivetIT and RivetMSP

```
Adopt the Rivet Console support from RivetCore (release named by the Core session). Worktree, PR, do not deploy.

1. Pin rivet/rivet-core to that release; mirror Core's new migration(s) into db.sql and the database updater with this edition's own numbering (Core's ids are in vendor/rivet/rivet-core/UPGRADING.md and CHANGELOG.md). Read the new "Adopting Background Mode and the optional Rivet Console" section of UPGRADING.md first.

2. Mount the two console routes (the exact handler class names are in Core's docs/modules/rmm.md, "Rivet Console"):
   POST /api/v1/endpoint_console/redeem
   POST /api/v1/endpoint_console/events
   Both are PUBLIC: no session, no CSRF token (there is no cookie; the credential is the one-time code or the per-session report token), POST only (405 otherwise), JSON only (415 for another Content-Type), response header Cache-Control: no-store (Core's RmmResponse already sets it), NO CORS headers (the caller is a desktop app, not a browser). Rate limiting and the client address must come from the edition's trusted-proxy setting, never from a client-supplied header; test it behind the real reverse proxy on beta, because the limits are per IP.
   THE REQUEST BODY IS A CREDENTIAL. Check and prove that nothing in this edition captures it: PHP error/debug logging, the API router, any request-logging middleware, the WAF or web-server log format (grep nginx for $request_body / log_format and for any audit-of-requests feature), the edition's own API request log table, exception handlers and any "log failed requests" helper. Add a test that posts a distinctive code and asserts it appears in no log table, log file or audit row.
   Add these two routes to the edition's route manifest / managed nginx routes file the way other public API routes are listed, and note them in docs.

3. Settings page (the remote-tools settings, where remote_console already is). Add, administrators only, saved through RemoteActions::updateSettings() and audited by Core:
   - console_public_url: the public https origin of THIS Rivet site that technician PCs can reach (https only, no path, query or user info; Core validates and returns the error text, show it verbatim). If it is empty and remote_console is on, show a warning: "Console launches are refused until this is set." The link origin always comes from this setting, never from the request.
   - min_console_version: optional x.y.z; empty = not enforced. Older consoles get a "please update" refusal.
   - console_log_file_paths: off by default; explain the privacy effect ("file paths a technician opens or transfers are stored in the audit log; leave off unless your policy needs it").
   - console_cookie_once: on by default; turn it off only if MeshCentral runs as several servers (the single-use sign-in memory is not shared between them). Core supports one MeshCentral server per install for 1.0.
   Help text to show, plainly: the console is optional and needs the Rivet Console app on the technician's PC; stock MeshCentral launch remains available and turning the toggle off restores today's behaviour for everyone; Mesh accounts, device groups and rights are created by an operator in MeshCentral, Rivet does not create them (a technician needs an existing Mesh account matching the account template, contractors and portal users get none); changing the MeshCentral address means technicians approve the new address once in the console; a "notify" client policy is a banner shown to the technician, it does not notify the person at the device; an "approve" policy is currently a refusal. Direct mode (the console signing in to MeshCentral without a Rivet launch) is OFF by default and set per PC by an administrator through a Windows policy; real enforcement is on the MeshCentral side: give technicians' Mesh accounts no usable password so the only way in is a Rivet launch. Put this paragraph in the operator documentation too.

4. Device page / remote launch. When features.remote_console is on, send console: true with the launch (kind from the button: desktop, terminal, terminal-ps, files). Open the returned data.console.url by assigning window.location (not window.open) and offer data.console.fallback_url as "Open in browser instead". When console is absent or null, open url in a tab as before. Never offer Chat or Background in the console (neither is issued in v1). SHOW CORE'S DENIAL MESSAGE VERBATIM, as escaped plain text, for every refusal (not allowed for you, switch off, approval_required, not_configured, device offline, mapped node missing, rate limited). Do not replace it with a generic "failed". Device-visibility denials are deliberately generic: leave them so.

5. Log and audit views: show the new audit rows (Console Link Redeemed / Denied, Console Session Ended and the existing Remote Session rows) wherever the edition lists audit; they hold no secret by construction, but add a test that no code, report token or Mesh cookie can appear in any row, log or page. Webhook UI: list the new rmm.console.* events from the catalog.

6. Tests: switch off = launch result byte-identical to today; routes refuse GET and non-JSON; no CORS headers; the body-logging test from item 2; rate-limit uses the trusted-proxy address; settings validation errors shown; the denial message is rendered escaped and verbatim; the page never sends console: true while the switch is off; module-only logins and the Admin/Technician roles unchanged. Run the full suite on a scratch DB; live smoke only on beta; report what you did not run.

Do not change the RMM device protocol, any agent code, or any file under vendor/.
```

## RivetIT chat (adds to the common block)

```
RivetIT specifics: default policy mode is internal (no end-user prompt; the console shows nothing extra to the technician and every launch is still audited). Nothing in this change may alter a production install that has not switched remote_console on (go-live is January 1): verify on beta that an install with the switch off, after the migration, behaves identically (launch output, audit text, page markup). Keep the migration additive and idempotent. Document the new tables in db.sql.
```

## RivetMSP chat (adds to the common block)

```
RivetMSP specifics: the policy mode is per_client. On each client's remote policy (the existing Background Mode client policy screen) show allow / notify / approve with the honest wording above (notify = technician-side banner, approve = refused for now), and never describe notify as notifying the end user in the UI, reports or the client portal. Make sure tenant isolation holds for the new routes: a redeemed code is bound to its technician and device, and the report token only ever writes rows for its own session's client. Check every table and column against THIS edition's db.sql before mirroring Core's migration.
```

## Mobile

```
No Mobile feature is needed for the Rivet Console in v1 (the console is a Windows desktop app). Confirm only that the app never sends console: true, keeps using the stock url from the launch result if it has a remote button, and ignores the new console / unknown fields in the response (no strict parsing that would fail on them). Report if any screen assumes the launch result has exactly the old keys.
```
