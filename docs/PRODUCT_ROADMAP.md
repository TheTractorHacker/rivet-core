# RivetIT + RivetMSP product roadmap (competitor comparison and feature ports)

> This is the product-level plan written before RivetCore existed. The roadmap for RivetCore itself, up to 1.0.0, is [ROADMAP.md](../ROADMAP.md).

Drafted 2026-10-04. **Draft status:** the port list (§2) comes from a directory diff of `agent/` and `api/v1/`
between the two trees. The competitor matrix (§3) comes from general product knowledge, not a fresh review of each
vendor, and the "have it?" column for RivetMSP is mostly inferred from file names. Treat every cell marked `?` as
needing a check before it becomes a ticket.

| | RivetIT | RivetMSP |
|---|---|---|
| Tree | `/var/www/mw-itflow.foleyit.com` (beta) | `/var/www/itflow.foleyit.com` (`Syncro-Beta` lineage) |
| Audience | One internal IT team, departments | MSPs billing real clients |
| DB version | 2.6.123 | 2.6.54 |
| `agent/` pages | 138 | 88 |
| Git lineage | Squashed import of upstream; no shared history | 7,000+ upstream commits |

Because there is no shared history, **every port is a patch, not a merge** (see `MSP_DESIGN_PORT_PLAN.md`). The
MSP is live and its cron sends client mail, so each port ships behind a settings toggle, default off.

---

## 1. Strategy

1. **Port what is edition-neutral** (ITSM, docs, platform plumbing). Skip what only makes sense for one internal
   company (LMS, org chart, department portal).
2. **Close competitor gaps in the MSP first**, since the MSP is where buyers compare you to Syncro, Halo and
   ConnectWise. RivetIT's competitors are ITSM tools and need a separate, shorter list.
3. **Gate by revenue risk.** Anything that touches invoicing or client email goes through a scratch-DB test
   before it reaches the MSP.
4. Migration numbers are per-tree. A ported feature gets a *new* MSP migration (2.6.55+), never RivetIT's number.

---

## 2. Port candidates: RivetIT to RivetMSP

Found in RivetIT `agent/`, absent from RivetMSP `agent/`.

### Port (high value for an MSP)

| Feature | RivetIT files | Why it matters to an MSP | Effort | Notes |
|---|---|---|---|---|
| Problem management | `problems`, `problem_details` | Link recurring tickets to one root cause; standard in Halo/ConnectWise | M | Needs ticket-link table; MSP tickets differ (billing fields) |
| Change management | `changes`, `change_details` | Approval trail for client changes, a selling point for managed contracts | M | Add client-facing approval via the portal |
| Service catalog | `service_catalog` | Maps to billable MSP service offerings and ticket templates | M | Connect to `products`/`services` so a catalog item can price |
| KB v2 | `kb_article_versions`, `kb_media`, `kb_embed`, `kb_progress`, attachments | Version history and review dates; client-visible articles | M | Mostly self-contained |
| Certificate and domain detail pages | `certificate_details`, `domain_details` | Expiry monitoring is core documentation (Hudu/IT Glue parity) | S | |
| Intune devices | `intune_devices` | Microsoft-shop MSPs; pairs with RMM | M | Per-client tenants, not one tenant: needs a new credential model |
| Workflow runs (lifecycle checklists) | `workflow_run`, workflow templates | Client onboarding/offboarding runbooks per client | M | Reframe "employee" as "client user" |
| Remote MCP + audit pipeline | `mcp_server/`, `src/Redis` | AI/agent access with audit and rate limits; differentiator | M | Off by default; read-only first |
| Redis cache, health endpoints, Cron Manager, Server status | `health/`, admin pages | Operability for a live multi-client box | S | `health/live.php` and `ready.php` are trivial wins |
| OpenID Connect portal SSO | `OPENID_CONNECT_PORTAL.md` | Client portal sign-in without passwords | M | MSP already has per-client portal logins; map to contacts |
| IT dashboard | `it_dashboard` | Needs a rework into an MSP ops dashboard (SLA, queue, utilization) | M | Check against MSP `dashboard` first |
| Design system (Tabler shell) | see `MSP_DESIGN_PORT_PLAN.md` | UI parity; MSP still on AdminLTE 4 | L | Already planned; blocked on clearing the 31 uncommitted MSP files |

### Port later or adapt heavily

| Feature | Reason to wait |
|---|---|
| Odoo integration and addon | Only if MSP customers use Odoo; QuickBooks/Xero is the more common MSP need |
| Mobile app features | Two Android repos already exist (`rivetmsp-android` is current). Ports follow the API, so API gaps come first |

### Do not port

| Feature | Reason |
|---|---|
| Training / LMS (`training_*`, about 35 pages) | **Decided: internal only.** No MSP billing tie-in |
| Org chart | Internal org structure; low MSP value |
| Department model, `department_sites` | RivetIT's rename of "client"; the MSP keeps clients |
| Module-only logins | Specific to RivetIT's portal model |

### Port in the other direction (MSP to RivetIT)

The MSP has `custom` (agent page and API) that RivetIT lacks, and a few API resources (`expenses.php`,
`invoices.php`, `quotes.php`, `products.php` flat files). Decide whether RivetIT wants them; billing is off by
default there, so probably not.

### API parity gap

RivetIT's `api/v1` has `contracts`, `metrics`, `metrics_ingest`, `milestones`, `projects`, `tasks`, `ticket_views`,
`outtakes` and more that the MSP's API lacks. Anything ported to the web UI that the Android app needs also needs
the endpoint (see memory note on Android API gaps).

---

## 3. Competitor feature matrix (verified 2026-10-04)

Researched from vendor pages for **Syncro**, **HaloPSA/HaloITSM** and **Hudu / IT Glue**. ConnectWise, Autotask,
NinjaOne and SuperOps were dropped by decision. **C** = confirmed on a vendor page, **U** = unverified (no page found,
or only a third-party summary). The agents read marketing pages through a summarizing fetch tool and mostly did not
open support docs, so detail-level cells are thin. Syncro's roadmap page mixes shipped and planned items; re-check
dates there. RivetMSP column: Y have, P partial, N missing, **?** not yet inspected in code.

### 3.1 PSA: RivetMSP vs Syncro and Halo

| Capability | RivetMSP | Syncro | Halo | Priority |
|---|---|---|---|---|
| Ticketing, kanban, live updates, recurring tickets | Y | C | C | n/a |
| Ticket automations (routing, escalation) | ? | C "real-time ticket automations" | C workflows, parallel approvals, execution order | **High** |
| Formal SLA objects, business-hours clocks | P | U (only "SLA enforcement" via automations) | U for clocks; C for OLA breach notifications | **High**, a gap for both competitors too, so a chance to lead |
| Recurring invoices, auto-charge saved card | Y/P (`client_autopay`) | C, linked to contracts so they pause on contract end | C recurring and usage-based billing | Med: link recurring invoices to contracts |
| Draft invoice review before publishing | ? | C | U | Med |
| QuickBooks Online sync | ? | C, **one-way** Syncro to QBO; payments optionally back | C named, depth U | **High** |
| Xero sync | ? | C, one-way; customers, inventory, invoices, payments, POs | C named, depth U | **High** (you chose both; QBO first) |
| Pax8 / license billing | N | C Pax8; M365 license billing on Team plan | C Pax8 subscriptions, usage, daily sync | Med |
| Purchase orders, inventory | P | C POs and restocking | U | Med |
| Client portal, white-label | P | C; custom domain, SSO, chatbot listed on roadmap (U if shipped) | C branded portal, child tickets, log ticket from asset | **High** |
| Agent / portal SSO | ? | roadmap U | C Entra, SAML, Okta, ADFS | **High** |
| Dispatch: auto triage and tech matching | N | C but **beta** | C AI triage/routing | Med |
| Calendar, appointment booking widget | P | C | U | Med |
| Teams / Slack | ? | U | C both | Med |
| SMS | N | U | U | Low until verified |
| Mobile app | Y (Android) | C | C (limited; links out to web) | n/a |
| Technician utilization reports, Power BI | P | C | U | Med |
| Zapier / public API | Y API | C both | U | Low |

### 3.2 AI

| Capability | RivetMSP / RivetIT | Syncro | Halo |
|---|---|---|---|
| Natural-language ticket search | N | C, all plans | U |
| Sentiment analysis | N | C, Team plan | C |
| Triage, suggested replies | N | Guided resolution (Team) | C, included in base price |
| **MCP server** | Y (read-only, RivetIT, off by default) | C, **GA, creates/updates/escalates tickets**, in Claude Connector Directory | C: AI agents call MCP servers from workflows |
| Ticket summarization | N | U (roadmap) | U |

Both rivals have shipped AI. Syncro's MCP server is *write-capable*; RivetIT's is read-only. This is the
fastest-moving gap: port the MCP server and add write tools with approvals.

### 3.3 RMM-adjacent

RivetMSP integrates with external RMMs; neither Syncro's native RMM nor Halo's is the comparison. What Syncro
bundles that RivetMSP lacks: patch dashboard, M365 security baselines (Team), warranty tracking (Team), session
recording (Team). **Recommendation:** keep integrating, add patch/compliance and warranty *display* from the RMM,
do not build patching.

### 3.4 Documentation: RivetMSP vs Hudu and IT Glue

| Capability | RivetMSP | Hudu | IT Glue | Priority |
|---|---|---|---|---|
| Structured asset layouts | P (custom_fields is a stub) | C; builder rebuilt 2026-08-31 as drag-and-drop with live preview | C "flexible assets" | **High** |
| Relationships / linking | P | C linked relationships (assets, creds, KB, processes) | C; AI creates relationships | **High** |
| Password vault, audit, OTP | Y; reveal audit is RivetIT | C, personal vault, passkeys | C | Med: port reveal audit |
| Password auto-rotation | N | U | C (AD and M365) | Med |
| KB version history | P | C | C with rollback | **High**: port KB v2 |
| KB review/expiry dates | RivetIT Y | U | U | Low-risk differentiator: port it |
| Internal + external KB, client portal | P | C; portal users free | C MyGlue | **High** |
| Domain/SSL expiry | Y | C | C | n/a |
| Warranty tracking | ? | U | U | Low |
| IPAM, racks | P | C | U | Med |
| Network diagrams | ? | U | U | Low |
| Auto-documentation/integrations | P | C 60+ | C 60-80+ | Med |
| Browser extension | N | C | C | Low |
| AI | N | C assistant in base plan | C AI-generated SOPs | Med |
| Pricing | n/a | $27/user/mo annual, portal users free | not published | Context for positioning |

### 3.5 Pricing context (for positioning, re-check before citing)

- Syncro: Core $129, Team $179 per tech per month annual (a search summary said $139/$189, conflicting).
- Halo: per agent only, all modules; only a UK price (GBP 66 per agent per month) was confirmed. USD sources conflict.
- Hudu: $27 per user per month annual; unlimited portal users.
- IT Glue: quote only.
- RivetMSP is self-hosted, so the pitch is ownership and no per-seat fee, not feature-for-feature parity.

### 3.6 RivetIT vs ITSM tools

Not researched this pass (Freshservice, Jira Service Management, HaloITSM). Halo's confirmed ITSM items to
benchmark against: parallel approval steps, Risk Score routing, OLA breach alerts, service catalogue with
recent/trending, AI agents with MCP access. CAB, change calendar and release management are U even at Halo.
RivetIT priorities remain the Master Plan phases 5, 13, 14, 15.

---

## 4. Proposed phases

Sequenced so each phase is shippable alone. Effort: S under 1 week, M 1-3 weeks, L over 3 weeks, solo developer.

### Phase 0: Preconditions (both trees)
- Clear the 31 uncommitted MSP files and take a DB dump plus git tag (per `MSP_DESIGN_PORT_PLAN.md` §2).
- Run the pending MSP migration (code declares 2.6.54; confirm live DB matches).
- **Decided: shared core library.** Extract edition-neutral code (Audit, Redis, MCP pipeline, ITSM services, Knowledge) into a shared PSR-4 package both trees consume. Edition-specific UI and billing stay in each tree. First step: inventory `src/` for code with no MSP/internal assumptions and agree how both trees load it (Composer path repo vs vendored copy).

### Phase 1: Quick MSP wins (S-M)
- `health/` endpoints, Cron Manager, Server status.
- Certificate and domain detail pages.
- Audit service + credential reveal audit.
- Redis cache/lock layer (off by default).

### Phase 2: ITSM core into MSP (M each)
- Problem management, then change management, then service catalog.
- KB v2 (versions, review dates, media).
- Each behind a `config_module_enable_*` toggle.

### Phase 3: Competitive must-haves (M-L)
1. SLA engine (clocks, business hours, breach notifications).
2. Automation rules engine (shared with RivetIT Phase 15).
3. Accounting sync, **QuickBooks Online first, then Xero**. Competitors sync one-way (app to accounting, payments optionally back), so that is an acceptable v1.
4. Client portal refresh + OIDC SSO.
5. Structured documentation layouts (Hudu/IT Glue parity).

### Phase 4: UI parity (L)
- Tabler shell and design system into MSP (existing plan).

### Phase 5: AI and integrations (M-L)
(AI moved up in urgency: Syncro ships a write-capable MCP server and Halo ships AI triage in the base price.)
- In-app ticket summarize/reply/triage using the Claude API.
- Remote MCP port with the read-only tool set; write tools later, with approvals.
- Teams/Slack notifications; SMS.
- M365 license and Pax8 sync.

### Phase 6: RivetIT-specific roadmap (continues in parallel)
- Master Plan phases 5, 13, 14, 15 (portal, lifecycle, reporting, automation).
- Live Entra/Intune and Odoo sync once credentials exist.

---

## 5. Decisions and open questions

Decided 2026-10-04: shared core library; benchmark Syncro, Halo, Hudu/IT Glue; QuickBooks Online and Xero (QBO first); LMS stays internal.

Still open:
1. Confirm the RivetMSP target is `/var/www/itflow.foleyit.com` (live) and that `itflow_msp` / `itflow-msp` are stale copies.
2. Inspect the `?` cells in §3 against the RivetMSP code (automation rules, accounting sync, SSO).
3. Where does the shared core live (Composer path repo, separate git repo, or vendored)?
4. Research RivetIT's ITSM competitors (Freshservice, JSM) if you want a RivetIT-side matrix.
