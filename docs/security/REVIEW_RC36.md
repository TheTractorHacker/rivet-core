# Security review of rc.31 to rc.36: findings and what rc.38 did about them

Status of every finding of the review below (reviewed `git diff v1.0.0-rc.30..v1.0.0-rc.36`, static reading only), as of `1.0.0-rc.38`. **fixed** = changed in Core in this release with a regression test (the important ones were mutation-checked: the enforcement removed, the test failed); **partly** = the Core-side part is fixed, the rest is named; **deferred** = nothing was changed; **edition** = lives in an edition (or needs one), nothing in Core; **no change** = informational. The review text follows unchanged after the table.

| # | Severity | Status | What rc.38 did | What is left |
|---|---|---|---|---|
| M1 | Medium | **fixed** (migration 0041) | Per device and UTC day: 500 chat posts and 50 MiB (text plus decoded attachments), `PanelLimits::$chatPostsPerDayMax` / `$chatBytesPerDayMax`, charged atomically before the gateway is called and refunded if the post fails; 429 `rate_limited` with `Retry-After` to the next UTC midnight; the first refusal of each day is audited once (`Panel Chat Limit Reached`). Table `rmm_daily_budget`. | Not done from the review's list: a per-ticket attachment count and a per-client ledger (the per-device day budget bounds the same abuse per device). **Edition:** run migration 0041. |
| M2 | Medium | **fixed** (no migration) | Ownership is the pair (device, contact): `authorizeTicket()`, `ownedIds()` and the replay of a `client_request_id` require the ledger row's `contact_id` to equal the device's current contact (null-safe). A re-enrolled PC sees none of the previous person's tickets or chat; nothing is deleted; re-enrolling for the original contact brings them back. | **Decision taken (Blake):** old tickets stay invisible rather than being reassigned. |
| M3 | Medium | **fixed** | `DeployScheduler` (evaluation, detection, install, uninstall), `AutoInstallService`, an approval of an app that automation planned, and the Mesh install after approval skip a switched-off device. Turning the switch on cancels queued, not started jobs of automated origin (schedule id, software source `scheduler`/`autoinstall`, patch source `scheduler`) with reason `automation_disabled`; a technician's own job and running jobs stay. Audit `Device Automation Disabled` / `Enabled` with the user on every real flip. | `DeployApprovalService::applyApproved()` for a technician's own approved run is deliberately NOT blocked. **Edition:** route the switch UI through `DeviceService::setAutomationDisabled($id, $bool, $userId)`. |
| L1 | Low | **fixed** | Quoted values are masked to the closing quote, space-separated directives are masked (PAM control words excepted), `psk` / `passw0rd` are secret names, `rvpd_`, `rvtp1.`, `rvte1.` are masked whole, in PHP (`Redactor`, `ChangeProtocol::harden`) and Go (`internal/change`, `internal/jobs`). New vector file `agent_change_monitor_rc38_vectors.json`, replayed by both. | Still best effort: a secret in free text with no marker stays visible. A line like `Token expired` at line start is masked after `Token` (accepted false positive). |
| L2 | Low | **fixed** | `alertText()` turns `[` `]` into `(` `)`, removes the `@` of mass mentions and of any `@name` that is not part of an address, and spells HTML entities `& lt;`. | A bare URL stays a URL. **Edition:** the webhook renderer must show these values as text. |
| L3 | Low | **fixed** | Audit rows plus events caused by one device's Mesh reports: 12 per UTC day (`Mesh Events Suppressed` once); the "already mapped to me" test now comes before the rival test, so a rival's report flags only the rival; the conflict event names the other device only within the same client. | The conflict **audit row** still names the other device's id (an administrator record, kept deliberately; existing test). |
| L4 | Low | **fixed** | The `move` branch needs an administrator-made asset link (`match_reason` `approved_by_<user>`); an automatic serial or MAC match gives `needs_approval`. | `setMeshNode()` (Approve) still has no check that another live device holds the node, and `mesh_node_id` has no unique key (see the F1 row below). **Edition:** make Approve go through Core. |
| L5 | Low | **partly** | Text types: formula-leading content, CSV formula/DDE cells and markup anywhere in the file are refused; Windows device names and a leading `-` are refused. PANEL_API.md states the serving requirements. | Active content in PNG, GIF and PDF is not scanned. **Edition:** store outside the web root, serve as an attachment with `nosniff`. |
| L6 | Low | **fixed** | 403 `client_invalid` when the tenancy no longer knows the client. | |
| L7 | Low | **deferred** | Nothing changed. | Document the gateway rule "one ticket per call, one transaction" (it is in the interface docs); the daily cap can still be overshot by the parallel requests `RATE_CREATE` allows. |
| L8 | Low | **fixed** | The guide fragment is rebuilt from an allow-list (tags, `class`, `title`, safe `href`/`src`, `colspan`/`rowspan`); `script`, `iframe`, `svg`, forms and the like are dropped. Documented as the fifth deviation from RivetIT's bytes; tests reverse it to RivetIT's SHA-256. | Inline `style`, `id` and `data-*` in a guide are dropped (a visible effect only if the guide used them). |
| L9 | Low | **fixed** | All third-party actions pinned to commit SHAs with the tag in a comment (including the release job); `govulncheck@v1.9.0`; `roave/backward-compatibility-check:8.23.0`. | Dependabot or a manual review is needed to move the pins. `golang:` is still unpinned by digest in the build host (F13 of RC30). |
| L10 | Low | **deferred / edition** | Nothing in Core (the values are stored as typed, as the RMM does for agent hostnames). | **Edition:** escape hostname, OS and app version wherever printed (checklist I5). |
| L11 | Low | **fixed** | The installer refuses a symlinked destination or receipt before writing, creates the temp file exclusively with a random name and replaces with `rename()`. | |
| I1 | Info | **no change** | | `server_time` stays on every answer. |
| I2 | Info | **fixed** | `ui_assets_valid_path()` ends with `\z`. | |
| I3 | Info | **fixed** | `promote-ui-assets.php` refuses a Core under `vendor/` and edition symlinks and replaces files by rename; `check-ui-assets.php` prints the receipt version with control characters replaced. | Make sure the web server denies dotfiles (the receipt `.rivet-ui-assets.json` is in the public CSS directory). |
| I4 to I7 | Info | **no change** | | ConfigSequence race, lossy output text, large-limit memory and existence information are as described. |

**Also done in this release, not from the report:** fleet enrollment tokens are client-level, 72 h at most and audited per enrolment; the gateway conformance kit checks UTC time stamps (RivetIT's gateway has a +5 h bug, an edition fix).

---

# Security review of rc.31 to rc.36

Reviewed: `git diff v1.0.0-rc.30..v1.0.0-rc.36` on `origin/main` (fa5d262, which is rc.36 plus one CI test commit), 158 files. The review read the `src/Panel` code in full and the rc.31 fix commits critically. It also read the full path of every finding below (source, transformation, validation, authorization, sink). Read-only: nothing was run against a live database, an edition, or production.

## Scope notes and limits (read first)

- **Tools missing:** `go`, `semgrep`, `gitleaks`, `trivy` and `osv-scanner` are not installed here. The Go agent changes (rc.31) were reviewed by reading only. Nothing from the Go test suite was executed.
- **Executed:**
  - `php -l` on every changed PHP file: clean.
  - `composer audit --locked`: no advisories.
  - A regex scan of the diff for key, token and private-key shapes: no hits.
  - Isolated PHP probes (no database) of `ChangeProtocol::redact()`, `::alertText()`, `PanelAttachments::validate()` and `PanelText::output()`. Their results are marked **Confirmed** below.
- **Not executed:**
  - PHPUnit, in particular all `tests/Integration/Panel/*` tests. The worktree has no `vendor/` and no scratch database was created. No race (reservation takeover, enrollment, ConfigSequence) was reproduced. Race findings are code-path conclusions.
  - No HTTP request was made to any server.
- **Not in this repository:** the edition adapters (`PanelTicketGatewayInterface` implementations, the Panel routes, the Mesh Approve button, the guide endpoint that feeds `webhook_wizard.js`, escaping on output, `clientIp` and TLS decisions). Findings that depend on them say so.
- Confidence: Confirmed (executed), High (full path read, not executed), Possible (pattern, incomplete evidence).

## Summary

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 0 |
| Medium | 3 |
| Low | 11 |
| Informational | 7 |

No finding gives an unauthenticated caller access to tickets, chat, devices or code execution. The panel token isolation, enrollment single use, ticket scoping and attachment checks held up. The Medium findings are two availability or privacy problems in the new panel API and one kill switch that does not cover every scheduler. Three of the rc.31 "fixed" claims (F1, F2, F4) are only partly true; see the verification table.

---

## Findings

### M1. Medium (High confidence): panel chat has no cumulative volume limit, so any enrolled panel device can fill storage, CPU and the audit log

- **CWE:** CWE-770, CWE-400.
- **Where:** `src/Panel/PanelProtocol.php:47` (`RATE_CHAT = [20, 60]`), `src/Panel/PanelService.php:184-201` (`postChat()` has no daily or per-ticket budget; `createTicket()` has one at `:116-119`), `src/Panel/PanelLimits.php:29,69` (a post may carry about 14 MB: 10 MiB of attachments base64-encoded plus text), `src/Panel/Http/PanelApi.php:127-129`, audit row per post at `PanelService.php:198`.
- **Root cause:** the only brake on chat is 20 calls per minute per device. Each call may carry 3 attachments up to 10 MiB in total, and every call writes an audit row. Nothing counts bytes, messages per day, attachments per ticket, or total storage per device or client.
- **Preconditions:** one enrolled panel device. That is an end-user machine of a client, a lower-trust principal than an RMM agent. It also holds for a stolen device credential.
- **Exploit scenario:** the attacker POSTs `/panel_ticket_chat/<own ticket>` 20 times a minute with 10 MiB of valid PNG data. That is about 200 MB a minute, about 12 GB an hour, handed to the gateway to store. The same loop writes about 28,800 audit rows a day. Each request peaks at roughly 3 times the body in PHP memory (raw body, `json_decode`, `base64_decode`; about 45 MB for the default limits), so a few parallel devices stress FPM workers. Create is capped at 50 tickets a day, chat is not.
- **Fix:** keep a per-device and per-client byte and message ledger (a day window, like `ticketsPerDayMax`) and charge it before the gateway is called; cap attachments per ticket; use a lower `attachmentsTotalMax` for chat than for create; audit chat posts in aggregate or sample them.
- **Regression test:** post more than the daily byte budget and assert 429, with the gateway not called after the budget is spent.

### M2. Medium (High confidence): ticket ownership is keyed to the device, not the (device, contact) pair, so a machine re-enrolled for another person exposes the previous person's tickets

- **CWE:** CWE-639, CWE-284.
- **Where:** `src/Panel/PanelEnrollment.php:240-241` (re-enrollment updates `contact_id` on the same device row), `src/Panel/PanelService.php:285` (`authorizeTicket()` checks `device_id` and `client_id` only), `:304-306` (`ownedIds()` the same), `src/Panel/PanelService.php:300` (`inScope()` accepts `createdByThisDevice`). The ledger has a `contact_id` column (`src/Panel/Migration/PanelSchema.php:37`) that is never used for access.
- **Root cause:** a device's own tickets (rows in `panel_ticket_owner`) are readable and postable by whoever the device is bound to now. Re-enrollment with the same `install_id` and a token for another contact keeps the device and its ledger rows.
- **Preconditions:** an administrator re-enrolls the same install (same `install_id`) for a different contact of the same client, or from client-level to contact-level. This is the normal "PC handed to a new employee" workflow if the install id survives, which it does for a reinstall that keeps the app's data directory.
- **Exploit scenario:** employee A raised tickets from PC 7 ("my VPN password is ..." in the chat). PC 7 goes to employee B and is re-enrolled for B's contact. B's panel lists A's tickets (`mine: true`), reads the full public conversation and can post into them.
- **Fix:** in `authorizeTicket()`, `ownedIds()` and the listing, require the ledger row's `contact_id` to equal the principal's `contact_id` (null-safe). Alternatively, drop or reassign the device's ledger rows (or issue a new device row) when `contact_id` changes at re-enrollment.
- **Regression test:** device D enrolled for contact A creates a ticket; D re-enrolls for contact B; `GET` list and chat of that ticket answer empty or 404.

### M3. Medium (High confidence): `device_automation_disabled` is not enforced on every automation path, is not retroactive, and is not audited

- **CWE:** CWE-693 (protection mechanism failure), CWE-778.
- **Where:** enforced only at `src/Rmm/Scripts/TargetResolver.php:101` and `src/Rmm/Patch/PatchScheduler.php:146`. Not enforced at:
  - `src/Rmm/Deploy/DeployScheduler.php:87,110,152` (software detect, install and uninstall by assignment, `queueInstall()` at `:180`)
  - `src/Rmm/AutoInstall/AutoInstallService.php:427` (required and auto-install apps)
  - `src/Rmm/Deploy/DeployApprovalService.php:75`

  The flip itself, `DeviceService::setAutomationDisabled()` (`:179`), performs no audit and does not touch jobs that are already queued.
- **Root cause:** the switch was implemented as a filter in two places. The stated purpose is "so RivetCore's own automation never fights" another RMM or patch agent. The software deployment scheduler is part of that automation.
- **Exploit scenario (operational):** an administrator switches automation off for a device that a second RMM manages. The next `DeployScheduler` pass still queues a `software_install` or an uninstall for the packages assigned to its client, and the two tools fight. A technician with `device.manage` can also turn the switch off on any device in scope with no audit row, so patching and scheduled hardening scripts silently stop for it. Turning it on later does not recall jobs already in `queued`: scripts or patch installs queued before the flip still run when the device next polls.
- **Who can flip it:** `TechnicianActions::setAutomationDisabled()` (`:292`) requires `DEVICE_MANAGE` for the device's client, the same as `setRing()`. No agent, device-token or panel endpoint reaches it (checked: the only callers are `TechnicianActions` and `DeviceService`). Core exposes no HTTP route for it; editions that add one must apply the same check.
- **Fix:** add `d.device_automation_disabled = 0` to the three `DeployScheduler` queries and to `AutoInstallService`; when the switch goes on, call `jobs->cancelAllQueued($deviceId, 'automation_disabled')` for scheduler-sourced jobs; audit "Device Automation Disabled / Enabled" with the user.
- **Regression test:** `DeployScheduler` queues nothing for a flagged device; flipping the switch cancels queued scheduled jobs; an audit row exists.

---

### L1. Low (Confirmed): the rc.31 redaction (F2) is still bypassed by quoted values with spaces, space-separated directives, and the panel credentials

- **CWE:** CWE-532.
- **Where:** `src/Rmm/Change/ChangeProtocol.php:126` (`harden()`), `src/Rmm/Crypto/Redactor.php:40` (the 64-hex rule needs a `\b` before the hex, which `_` defeats).
- **Executed probes** (`ChangeProtocol::redact()`):
  - `pass="a b c"` becomes `pass="[REDACTED] b c"`, and `PASSWORD="quoted value"` becomes `PASSWORD="[REDACTED] value"`. The rest of a passphrase with spaces stays in the stored record, the alert and the webhook.
  - `DB_PASSWORD hunter2`, `token hunter2value`, `requirepass x`-style lines and `psk=hunter2hunter2` are unchanged. `Passw0rd=abc` is unchanged.
  - `rvpd_<64 hex>` (the new panel device credential) is unchanged, alone or in free text. `rvtp1.<12 hex>.<40 hex>` is masked only through the generic hex rule (`rvtp1.<sel>.[REDACTED]`). `Redactor` knows only `rvte1.`.
- **Impact:** a secret that an operator, installer or user writes in those shapes into a watched file or job output is stored and sent in clear. Presets keep this narrow, so Low. The panel token matters when the panel app and an RMM agent share a machine and the token reaches script output.
- **Fix:** mask a quoted value to its closing quote; add `rvpd_[0-9a-f]{64}` and `rvtp1\.` to `Redactor` and to the Go redactor with shared vectors; treat `\b(pass|token|secret|auth)\w*\s+\S+` at line start as a directive.

### L2. Low (Confirmed): alert text (F4) still lets Markdown links, images and mass mentions through

- **CWE:** CWE-79 (second order), CWE-116.
- **Where:** `src/Rmm/Change/ChangeProtocol.php:167` (`alertText()`), used at `ChangeRuleService.php:308` and `ChangeService.php:127`.
- **Executed probes:** `[Reset your password](https://evil.example/login)`, `![i](https://evil/p.png)` and `@everyone urgent` come out unchanged. `x&lt;script&gt;` keeps its entity form.
- **Scenario:** a standard user writes that text into a watched registry value or file line. The change event goes to a Teams, Discord or Markdown-rendering webhook as a clickable phishing link in a technician channel, or pings the whole channel.
- **Fix:** also neutralise `[`, `]`, `(`, `)`, `!`, `@`, `&`, `|` and `*` (or replace `](` and `@` runs); document that the webhook renderer must treat the values as text.

### L3. Low (High confidence): any enrolled device can flood the audit log and webhooks through Mesh reports, and can flag another device's mapped node as `conflict`

- **CWE:** CWE-779, CWE-770.
- **Where:** `src/Rmm/Mesh/MeshAgentService.php:154-195` (`report()`), `:246-252` (`decide()`: the rival check runs before the "already mapped to me" check), `:299-302` (conflict audit and `rmm.mesh.node_conflict` event), `:318-321` (needs-approval audit), `:184-189` (installed audit and event). A device may check in 40 times a minute (`RmmProtocol::RATE_CHECKIN`).
- **Root cause and scenario:**
  1. Each check-in that changes `node_id` (a fresh random 48-byte id each time) writes a "Mesh Node Needs Approval" audit row. Flipping `installed` true and false writes "Mesh Agent Installed" plus a webhook event every other check-in. That is up to 57,600 audit rows a day per device, with nothing bounding the count.
  2. A device that reports the node id of a legitimate, already mapped device X in another client sets X's next report to `conflict`, because the rival lookup (`:246`) finds the attacker's row before the `same` branch (`:251`). The mapping and `launch()` are not changed (`MeshService::launch()` ignores `map_state`), but X shows a conflict, an audit row naming the attacker's device id is written under X's client, and `rmm.mesh.node_conflict` carries `other_device_id` across clients. The attacker needs X's node id (visible to anyone in the Mesh group or in audit text).
- **Fix:** rate-limit state changes per device (at most one audit row and event per node change per hour, store the last audited change); move the "already mapped to me" check ahead of the rival check; do not name a device of another client in the audit text or the event.

### L4. Low (Possible): the Mesh `move` branch trusts an asset link that a device can obtain by reporting a serial or MAC

- **Where:** `src/Rmm/Mesh/MeshAgentService.php:242` (`sameMachine`), fed by `src/Rmm/Enrollment/EnrollmentService.php:418-442` (`matchAsset()` auto-links a new device to a single unowned in-scope asset matched by self-reported serial or MAC).
- **Scenario:** an asset's RMM device was retired. A rogue or compromised enrolled device of the same client enrolls reporting that asset's serial, is auto-linked to the asset, reports the retired device's node id, and gets it mapped with no approval (`Mesh Node Mapped ... automatically`). Impact stays inside one client: the technician's remote session on the attacker's device lands on the old machine's node. A real mislabel, not a cross-tenant break. F1's cross-tenant case is closed.
- **Fix:** require approval for `move` unless an administrator linked the asset by hand (`match_reason` starting `approved_by_`), or compare against a server-held fact such as the old device's last known hostname.

### L5. Low (High confidence): allowed attachment types still carry active content

- **CWE:** CWE-434, CWE-1236.
- **Where:** `src/Panel/PanelAttachments.php:361-368` (`plainText()`), `:348-359` (`magicOk()`).
- **Executed probes (all accepted):**
  - `.csv` with `=cmd|' /C calc'!A0,2` (CSV formula injection when a technician opens it in Excel).
  - `.txt` containing `<body onload=alert(1)>`, or `<script>` after the first 8 KiB (`substr($b, 0, 8192)`).
  - `.gif` content `GIF89a<script>...</script>`.
  - `.pdf` with an `/OpenAction /JavaScript` entry.
  - Names `CON.png` and `-rf.png`.
  
  Rejected as intended: `../../etc/passwd.png` becomes `passwd.png`, `x.php.png`, `x.png.php`, `x.png\0.php`, a shebang, `MZ`, a ZIP header, a wrong declared content type, and bidi in the extension.
- **Impact:** safe only if the edition stores the bytes outside the web root and serves them as attachment with `X-Content-Type-Options: nosniff`, never inline from the application origin. Core cannot enforce that. The CSV case needs no edition mistake.
- **Fix:** drop `csv` from the defaults or prefix cells that start with `= + - @` (tab or quote); scan the whole text file for markup, not 8 KiB; document the serving requirements in the gateway contract; refuse Windows device names and a leading `-`.

### L6. Low (High confidence): a client-level panel device keeps working after its client is archived or removed

- **Where:** `src/Panel/PanelAuthenticator.php:44-47` checks only the contact's validity (through the gateway), and only for contact-bound devices. `src/Panel/PanelService.php:263-266` allows a client-level device whenever the setting is on.
- **Scenario:** an offboarded client keeps a client-level device and the setting enabled; the device can still create tickets. `tenancy->clientName()` is available but is not consulted.
- **Fix:** in `authenticate()`, refuse with 403 `client_invalid` when `tenancy->clientName($clientId) === null` (or the edition's archived flag, through a port method).

### L7. Low (High confidence): ticket-create reservation edge cases (bounded and partly documented)

- **Where:** `src/Panel/PanelService.php:116-122` (daily cap read, then reserve, not atomic), `:226-236` (takeover after `RESERVATION_TTL_S`), `:250-258` (`complete()` fallback).
- **Facts checked:**
  - Two retries cannot both take the same expired claim (the conditional `UPDATE ... AND created_at = ?` has one winner).
  - A takeover of a call that is still running ends with both tickets recorded and an audit row ("Panel Ticket Duplicate").
  - A process that dies after the gateway created the ticket but before `complete()` leaves a pending claim; the retry after 300 s creates a second ticket. The first one is invisible to a client-level device (not in the ledger).
  - `uniq_ticket` makes `complete()` throw if a gateway ever returns an id that the ledger already holds. The ticket then exists with its claim pending (500, then a duplicate after the TTL).
  - The daily cap can be overshot by the number of parallel requests allowed by `RATE_CREATE` (10 a minute).
- **Fix:** do the daily count inside the reservation (count after insert and delete the claim when over); document the gateway rule "one ticket per call, one transaction". No change to the claim logic is needed.

### L8. Low (Possible): `webhook_wizard.js` inserts server HTML with only `script` and `noscript` removed

- **CWE:** CWE-79.
- **Where:** `assets/ui/js/webhook_wizard.js:446-449` (`loadGuide()`): `DOMParser` on `d.content`, `script, noscript` removed, then `document.importNode(...)` into the live page.
- **Root cause:** `DOMParser` documents do not run scripts, but event-handler attributes (`<img onerror>`, `<svg onload>`), `javascript:` links, `<iframe srcdoc>` and `<form action>` survive and become active when the nodes are imported into the page.
- **Exposure:** the guide endpoint is an edition file (`data-wz-guide`). If its HTML is static, this is a defense-in-depth gap. If it ever includes stored data (webhook names, network ranges), it is stored XSS on an admin page. The rest of the shipped JS builds DOM with `textContent` and `createElement`.
- **Fix:** build the guide from a whitelist (tags and attributes) or sanitise with a small allow-list walker; the simpler option is to ship the guide as text-only JSON blocks.

### L9. Low (High confidence): CI supply-chain items from F13 are still open and two new ones were added

- **Where:** `.github/workflows/ci.yml` (`dependency-audit` job): `go install golang.org/x/vuln/cmd/govulncheck@latest`; all third-party actions are pinned by tag (`shivammathur/setup-php@v2`, `actions/setup-go@v5`, `actions/checkout@v7`); `composer require --dev roave/backward-compatibility-check` is unpinned; `endpoint-agent.yml` still uses `softprops/action-gh-release@v2` in a write-permission job.
- **Mitigating:** the workflow-level `permissions: contents: read`, no secrets are passed to the new jobs, `pull_request` (not `pull_request_target`) is used, and no `${{ github.event.* }}` value is interpolated into a shell. No injection found.
- **Fix:** pin `govulncheck` to a version, pin actions to commit SHAs (at least the release job's), pin the BC check version.

### L10. Low (Possible): panel-supplied strings reach the RMM device list and audit log with markup intact

- **Where:** `src/Panel/PanelEnrollment.php:210-212` (hostname, os version, app version go through `PanelText::input()`, which strips controls and bidi but keeps `<`, `>` and quotes), stored in `endpoint_agent_devices` and echoed in `Panel Enrolled` (`:132`) and the token label.
- **Impact:** the RMM already treats agent-supplied hostnames the same way, so this is not a regression of Core. It is a new, lower-trust source: an end user, not a managed agent, now writes a string shown in technician UIs and audit pages. If any edition view forgets to escape it, it is stored XSS from an end-user machine.
- **Fix:** run `PanelText::output()`-style neutralisation on hostname and versions before storing, or state in the edition checklist that the device list and audit viewer must escape.

### L11. Low (High confidence): `install-ui-assets.php` is unsafe when run as root against a directory another user can write

- **Where:** `scripts/install-ui-assets.php:211-221`. The temp file name `$dest . '.tmp' . getmypid()` is predictable and opened with `copy()` (which follows a pre-planted symlink); the receipt is written with `file_put_contents("$css/.rivet-ui-assets.json")` (follows a symlink).
- **Impact:** a local user who can write the target directory plants a symlink and a root run overwrites a file with Core's known CSS or JSON content. `is_writable` is always true for root. A deploy that runs the installer as root into a web directory owned by a deploy user is the realistic case. No remote vector.
- **Fix:** open with `fopen($tmp, 'xb')` after `unlink`, refuse when `$dest` or the receipt is a symlink (`is_link`), and write the receipt with a temp-and-rename.

---

### Informational

- **I1. `server_time` is on every panel answer including unauthenticated ones** (`src/Panel/Http/PanelApi.php:140`): invalid_token, 429, 503, 426 and the enrollment 4xx. The RMM review noted that its device API shows the time only to an authenticated device. The time is not secret (HTTP `Date`), no timing or oracle follows. Low value, keep or limit to 2xx and authenticated errors.
- **I2. `ui_assets_valid_path()` uses `$`** (`scripts/ui-assets-lib.php:23`): a trailing newline passes (`css/a.css\n`). Not a traversal (`basename()` and the regex forbid `/` and `..`). Use `\z`.
- **I3. `promote-ui-assets.php`** copies an edition's file into Core's `assets/ui` without looking at content, follows symlinks in the edition directory (`--add`), and writes in place (`:114-119`). It is a maintainer tool and the diff is reviewed in git, but nothing stops it running from `vendor/`. A "refuse when the path contains `/vendor/`" guard is cheap. `check-ui-assets.php` prints the receipt's `core_version` unescaped to STDERR (`:292`); a hand-edited receipt can carry terminal escapes. The receipt `.rivet-ui-assets.json` is written into the edition's public CSS directory and discloses the Core version and file hashes; nginx normally denies dotfiles, make sure it does.
- **I4. ConfigSequence** (`src/Rmm/Support/ConfigSequence.php`) is monotonic under concurrency except for one race. A request that read the settings before an administrator's change but runs `seq()` after another request has stored the new body flips `body_hash` back and issues the old body with a higher seq. The next request with the new body bumps again, so it converges within one request. FPM re-reads settings per request, so the window is microseconds. `catch (\Throwable)` at `:56` returns the legacy `updated_at` seq on any database error; that can be lower than agents' stored seq (the agent then refuses, which is safe). Also: `agentConfig()` returns null when the watch list is empty, so an agent can never be told to stop watching.
- **I5. Output text is lossy**: `PanelText::output()` decodes entities then `strip_tags`, so a user message such as `if a<b and c>d` or `use <hostname>` is returned to the panel without the tagged part (confirmed: `a<b>c` becomes `ac`, `&lt;script&gt;x&lt;/script&gt;` becomes `x`). Not a security problem; the requester sees something different from what staff see.
- **I6. Large-limit configuration**: `PanelLimits::fromArray()` allows `attachmentsTotalMax` up to 50 MiB, so `postBodyMax()` reaches about 67 MB of base64 and `json_decode` plus decode needs several times that in memory. Defaults are fine; a deployment that raises the ceiling needs a matching `memory_limit`.
- **I7. Existence information**: enrollment answers `409 conflict` for an install id bound to an agent or another client and `403` for a revoked or retired panel device, only after a valid token secret. Ticket 404 versus 404 is identical for missing and out-of-scope tickets (one fewer query in the missing case, no practical timing signal).

---

## Verification of the rc.31 fixes (REVIEW_RC30.md)

| # | Claim | Verified | Notes |
|---|---|---|---|
| F1 | Device claim never maps a Mesh node | **Mostly closed** | The cross-tenant case is closed (`decide()` returns `needs_approval` or `conflict`; `claimFromInventory()` only moves). Residuals: L3 (rival check flags a mapped victim, audit and event spam), L4 (`move` trusts a self-reported asset link). `setMeshNode()` (the Approve action) still has no check that another live device already holds the node, and `endpoint_agent_mesh_nodes.mesh_node_id` has no unique key (`RmmSchema.php:204`), so `decide()` then `apply()` is a check-then-act race between two devices. Edition: make Approve go through a Core method that re-runs `decide()`. |
| F2 | Redaction extended | **Partly** | L1. |
| F3 | Keyed hash for redacted files | Closed (read only) | `files.go` now keys custom watches, files without kept lines, redacted files, large files and link targets. Plain SHA-256 stays only for preset files with kept lines and nothing redacted, where the text is already sent. Go not run. |
| F4 | `alertText()` | **Partly** | L2. |
| F5 | Upload gate before the body | Closed | `AutoInstallApi::upload()` calls `uploadGate()` first. Verified in code. |
| F6 | Client lists cut | Closed for the listed fields | `limitedTo()` and `setLimitedTo()` filter `auto.clients` and `params.set`; the catalog `software_files` listing remains shared by decision. |
| F7(b) | Hash once per version | Closed | Fingerprint of inode, size, mtime and ctime with a 2 s racy-window rule (`HostedFiles.php:222-258`). |
| F8 | install-linux.sh hash flag | As documented | Stamped installer is still trusted on TLS alone (deferred). |
| F9 | No `exec` | Closed | `run_and_clean()` removes the work directory. Not tested under SIGKILL. |
| F10 | Mesh agent must be root-owned | Closed (Linux, read only) | `trust_unix.go` Lstat-walks the file and every parent: no symlink, uid 0, no group or other write. No TOCTOU window an unprivileged user can use, as the chain is root-only. Windows relies on the Program Files ACL. |
| F11 | Monotonic counter | Closed with caveats | I4. Hypervisor agent persists the highest seq (`manager.go`, 0600 file); `Configure(nil)` keeps it. |
| F12 | Deferred | n/a | |
| F13 | Partial | **Still open** | L9. |
| F14 | psQuote and absolute paths | Closed for the listed places | `psQuote()` doubles the four typographic quotes and refuses C1/U+2028/U+2029; invalid UTF-8 refused. |
| F15 | Deny list and `.ssh` symlink | Closed for the listed names | Still a deny list. |

---

## Panel API: what was checked with no finding

- **Token kinds and isolation:** `rvpd_`+64 hex (panel) versus `[A-Za-z0-9]{64}` (RMM) cannot parse across families (`PanelProtocol::DEVICE_TOKEN_BEARER_RE`, `RmmProtocol::DEVICE_TOKEN_BEARER_RE`). All six `DeviceApi` handlers authenticate through `DeviceRepository::authenticate()`, which now checks `kind = 'agent'` (`:41`). `EnrollmentService`, `InstallerDownload` and the re-enrollment take-over (`:264`) reject `kind = 'panel'`. `PanelAuthenticator` requires `kind = 'panel'`. Both authenticators look up the SHA-256 and confirm with `hash_equals`. Rotate, allow-re-enroll, revoke, retire and transfer from the RMM side act on a panel row without making it an agent credential. A transferred client-level device cannot see tickets of its old client (the ledger is filtered by `client_id`).
- **Spoofing of client and contact:** client and contact come only from the credential (`PanelPrincipal`); no body or query field is read for them. A body field `client_id` or `contact_id` is ignored (also covered by `testCreateUsesTheClientAndContactOfTheCredentialAndIgnoresWhatTheCallerSends`).
- **Cross-client and cross-contact ticket and chat access:** `authorizeTicket()` checks the client of the ticket header and (ledger or contact match); the same 404 for missing and out of scope; the listing re-checks every gateway row (`inScope()` at `listTickets()`); internal notes are dropped by Core even if a lax gateway returns them. Ticket ids come from `/^[1-9]\d{0,17}$/`.
- **Enrollment:** one token yields one device. The use is a conditional `UPDATE ... use_count < max_uses AND revoked_at IS NULL AND expires_at > now` inside the same transaction as the device insert, so the second of two concurrent enrollments gets 403. A failed device insert rolls back the use. A wrong secret, an unknown selector and an RMM token give the same 401. The secret is always compared (`hash_equals` on a zero hash when the selector is unknown). Failures are rate-limited per address (10 failures or 60 attempts in 10 minutes) and each is audited. A revoked or retired device cannot re-enroll until an administrator allows it. An agent's install id, or another client's panel, is never taken over (409).
- **Revocation and expiry:** each request reads the row, so revoke takes effect at once. Expiry is 365 days and checked. A contact archived or moved yields 403 `contact_invalid`.
- **Rate limiting:** per device for read, create and chat; per address for failed authentication only (a valid device is never locked out by failures from its address).
- **Body, memory and size:** declared length, then a bounded read of `max + 1`, then JSON depth 16; base64 length is checked before decoding. Attachments are authenticated-only (enrollment body is 8 KiB).
- **Filenames and paths:** the stored name is `basename()` of the cleaned name, restricted to `[A-Za-z0-9._\-() \[\]]`, no dot-only or leading-dot names, 100 characters, extension required and on the allow-list, no dangerous extension in any dot-separated part, content type must match the extension, executable magic (MZ, ELF, Mach-O, shebang, OLE, ZIP) refused for every type, image and PDF magic required. Core never writes a file; the gateway stores the bytes.
- **Idempotency:** the unique `(device_id, request_id)` ledger row is the arbiter; a failed gateway call releases the claim; replay returns the stored id without calling the gateway; the same request id from two devices does not collide; takeover is atomic.
- **Migration 0040:** purely additive; every existing row keeps `kind = 'agent'`, `contact_id` NULL. Old agents authenticate as before. Panel devices are excluded from `max_devices` by `link_state <> 'panel'`, and no job, approval, remote or deploy action accepts a `panel` link state where `linked` is required. Panel devices have `last_checkin_at` NULL, so they raise no offline alerts.
- **Audit completeness:** token create and revoke, enroll, failed enroll, setting change, ticket create, duplicate, chat post, device revoke. Not audited: ticket reads and failed authentication of an existing token (rate-limited only).
- **Migration 0039 / kill switch authorization:** only `DEVICE_MANAGE` for the client can flip it; no agent, panel or API route in Core. The seven `TargetResolver` target types (`device`, `tag`, `group`, `client`, `site`, `policy`, `all`) share one `where()` and are covered, including the manual bulk run and schedule previews.
- **UI assets:** manifest keys are validated against `^(css|js)/[A-Za-z0-9][A-Za-z0-9._-]*\.(css|js)$` and written with `basename()`, so a manifest key or `--add` cannot reach outside the target directory; `scripts/lib/CssChunker.php`, `CssCascade.php` and `ui-css-split.php` are maintainer-only (not shipped; `.gitattributes` ships only the three files CI proves). The shipped CSS has no `@import` or external `url()`; the shipped JS has no `eval`, `document.write`, `insertAdjacentHTML`, `outerHTML` or server-data `innerHTML` (the only `innerHTML` uses are constants, an empty string, and the syntax highlighter, which escapes `& < >` first). `webhook_wizard.js` reads the CSRF token from the form's hidden input and sends it with every state-changing request; its redirect (`data-wz-switch`) rejects schemes, protocol-relative and cross-origin targets. Secret generation uses `crypto.getRandomValues`.
- **Dependency and secrets:** `composer audit --locked`: no advisories. The diff shows no key, token or private-key shapes.

## Suggested order of work

1. M1 (volume limits), M2 (contact-scoped ownership), M3 (kill-switch coverage, cancel and audit). All three are small, local changes with obvious tests.
2. L1 and L2 with shared vectors (PHP and Go), add the panel credential shapes.
3. L3 and L4 (Mesh state-change throttle, rival ordering, approval for `move`) and an Approve method in Core that re-runs `decide()`.
4. L5, L6, L8, L11, L9 hardening.
5. Run the Panel integration tests and a parallel-request race test against a scratch database; none were run in this review.
