# Parked ticket statuses (edition contract and prompts)

A **parked** status is a ticket status that keeps the ticket unresolved but out of the way: the technician has put it aside and will come back to it. It is not closed or resolved, so it keeps its history and can be reopened by changing the status. Think of the "unresolved, hidden from default views, not counted in ticket metrics" statuses other PSA tools offer.

RivetCore owns no ticket tables (the tickets and `ticket_statuses` tables live in each edition), so Core defines the rules below and the editions implement them. Both editions must behave the same.

## Contract

1. **Setting.** `ticket_statuses.ticket_status_parked` `tinyint(1) NOT NULL DEFAULT 0`, edited next to "Pauses the SLA clock" on the status form. Added by an edition database update (`ADD COLUMN IF NOT EXISTS`) and mirrored in `db.sql`. Code that reads the column must tolerate it not existing yet (same pattern as `ticket_status_pauses_sla`).
2. **Pre-ticked on upgrade.** Only the status named exactly `On Hold`. Do not tick the "Waiting on ..." statuses: those still need follow-up and an administrator can tick them. Never change a flag an administrator already set; the update runs once.
3. **Meaning.** A parked ticket is open (`ticket_resolved_at` and `ticket_closed_at` stay NULL; nothing is resolved or closed by parking). It is excluded from the **attention surfaces** and from the **open-ticket metrics**, and included everywhere a person looks it up on purpose.
4. **Hidden by default** (and counted out):
   - dashboards: every "open", "unassigned", "overdue", "due today", "on-site" and "my tickets" count and list;
   - the default ticket list ("Unresolved"), the board / kanban, and the stat cards on the ticket list;
   - report widgets and report pages that count open tickets, backlog or aging;
   - overdue / breach alerts and notifications that tell people to act (SLA breach warnings, "ticket overdue" emails, automation rules triggered by overdue).
5. **Still shown** (parked tickets are never hard to find):
   - search, global search, the client's ticket tab, the asset / contact / project ticket lists;
   - a status filter that selects the parked status, an "All" view, and a "Show parked" toggle on the ticket list and board (off by default, remembered per user is fine);
   - the ticket itself, its portal view, and everything that reads one ticket.
6. **Metrics that are not about "open".** Created, first response, resolved, closed, CSAT and time spent are unaffected by parking, and a ticket that is parked and later resolved counts as resolved on the day it is resolved. Moving a ticket out of a parked status puts it back in every count; no history is rewritten.
7. **SLA.** Parked does not pause the SLA. "Pauses the SLA clock" is a separate checkbox. Put a hint on the form: a parked ticket whose clock keeps running will still breach, so normally tick both. A breach on a parked ticket is **not** announced (item 4) but is still recorded and still counts in SLA-compliance reporting unless the status also pauses the SLA.
8. **Reporting feed (`rpt_tickets`).** `is_open` keeps its frozen meaning (not resolved and not closed), so existing consumers do not change. Append one trailing column `is_parked` (`1` when the ticket's status is parked, else `0`). Add it to the edition's frozen-column fixture, and note it in the report-author docs: "open and not parked" is `is_open = 1 AND is_parked = 0`. The starter Power BI model (reporting phase 5) will use that definition.
9. **Audit.** Changing the flag on a status is an audited settings change.

## Prompt for the RivetIT chat

```
Add "parked" ticket statuses to RivetIT per RivetCore docs/design/parked-ticket-statuses.md (read it first; it is in the rivet-core repo, and in vendor/rivet/rivet-core/docs/design/ once the pinned release includes it). Worktree, PR, do not deploy.

Goal: an administrator can mark a status (for example On Hold) as Parked. Tickets in it stay unresolved, but they disappear from the dashboard, the default ticket list and board, and the open-ticket metrics, until someone looks for them.

1. Schema: ticket_statuses.ticket_status_parked tinyint(1) NOT NULL DEFAULT 0 via a database-update step (ADD COLUMN IF NOT EXISTS, set to 1 only for the status named exactly 'On Hold', once) and db.sql. Mirror the way ticket_status_pauses_sla was added (admin/database_updates.php, includes/sla_functions.php helper that tolerates the column being absent). Bump the DB version the usual way.
2. Admin: a "Parked (hidden from dashboards and metrics)" checkbox on the add and edit status forms (admin/modals/ticket_status/*, admin/post/ticket_status.php), a "Parked" badge in the status list (admin/ticket_status.php), a hint that it does not pause the SLA (tick both normally), and an audit log row when it changes. Admin-only, CSRF as the other fields.
3. Add one helper (for example ticketParkedStatusIds($mysqli) with a per-request cache) and one SQL fragment builder, and use it everywhere. Do NOT sprinkle literal status names or ids (ids are per install). Grep for every place that counts or lists open tickets: "ticket_resolved_at IS NULL" has about 38 hits across api/v1/dashboard.php, api/v1/tickets.php, api/v1/clients.php, agent/dashboard.php and the other dashboards, agent/tickets.php (default view, stat cards), agent/ticket_kanban.php, agent/ticket_list.php, agent/client_overview.php, agent/rmm_dashboard.php, agent/reports/index.php, src/Reports/Widgets/WidgetRegistry.php, includes/ticket_filter_sets.php, includes/automation_functions.php, includes/sla_functions.php notifications, cron/cron.php (overdue/breach emails), mcp_server/ReadTools.php, Slack/webhook summaries. Decide per site using the contract: attention surfaces and open metrics exclude parked; lookups, search, client/asset/contact/project tabs, single-ticket views and "All" include them. Write the list of every site you changed AND every site you deliberately left alone, with the reason, in the PR.
4. Ticket list and board: add a "Show parked" toggle (off by default). Selecting the parked status in the Status filter shows those tickets without needing the toggle. Saved views keep working (they never contained parked tickets by accident; they now exclude them unless the toggle is set).
5. rpt_tickets (src/Reporting/EditionViews.php): keep is_open unchanged, append is_parked as the LAST column, update the frozen-column fixture and the deny-list/tenant tests. Parked data must not leak any new ticket text.
6. Tests: parked ticket absent from the dashboard counts and default list but present in search, the client tab and the single ticket; unparking restores it; flag absent (column not yet added) does not error; non-admin cannot change the flag; On Hold pre-ticked only on the first run and never overrides an admin's choice; rpt_tickets column order. Run the full suite on a scratch DB; live smoke test on beta only; report what you did not run.

Do not change the RMM device protocol or any file under vendor/.
```

## Prompt for the RivetMSP chat

```
Do the same in RivetMSP: add "parked" ticket statuses per RivetCore docs/design/parked-ticket-statuses.md. Read it, then do everything in the RivetIT prompt (schema, admin checkbox and badge, one helper and fragment, every open-ticket site, the Show parked toggle, rpt_tickets is_parked, tests), adapted to this edition:

- Check every table and column against THIS edition's db.sql; the ticket tables, the SLA model, the client / department portal and the billing code differ from RivetIT.
- MSP extras: client-facing portal and emailed reports must not list parked tickets as "open" or "overdue"; per-client and per-contract ticket counts, utilisation and billing-block reports exclude parked tickets from OPEN counts only and never from time or billing totals (parking must not change what is invoiced); recurring-ticket and SLA-breach notifications to clients are suppressed for parked tickets.
- If a client's contract SLA keeps running while a ticket is parked, show the hint from the contract on the status form.
- The live site is production: worktree and PR only, no deploy. Dump the database before any migration on beta first.
```
