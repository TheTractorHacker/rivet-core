# Compliance: GPO template builder (plan, 2026-10-18)

Status: **planned, on hold for the compliance work.** Nothing is built. Requested by the product owner: help departments and companies make Group Policy templates for Windows servers (and workstations), fully from the web UI, with no typing of registry paths or JSON.

## Decision: PowerShell is the primary export, a settings report is the second, a GPO backup package is optional

| Output | Verdict | Why |
|---|---|---|
| **PowerShell script** (`New-GPO`, `Set-GPRegistryValue`, `New-GPLink`, `-WhatIf`, idempotent) | **Primary.** | Readable and reviewable by an auditor before it runs; needs only the GroupPolicy module on a domain controller or a management server; supports a dry run; easy to version and diff; nothing binary to trust. |
| **Settings report** (HTML and CSV: every setting, value, why, and the control it satisfies) | **Always generated with the script.** | This is the compliance evidence: what the template enforces, mapped to CIS, NIST 800-171, HIPAA, PCI or the customer's own baseline. |
| **Local policy script** (same settings applied with registry writes and `secedit` for a non-domain server) | Second target of the same model. | Servers that are not domain joined, and lab testing. |
| **GPO backup package** (`manifest.xml`, `bkupInfo.xml`, `registry.pol`, `GptTmpl.inf`, for `Import-GPO`) | **Later, optional.** | It is the only route for security settings that `Set-GPRegistryValue` cannot write (password and lockout policy, user rights assignment, audit policy subcategories). It is also the most fragile format to generate and the hardest to review. Do it only after the script route is proven, and verify every package with `Import-GPO` on a real domain. |

## What the user sees (all GUI)

1. **Pick a base template**: CIS-style Server baseline, Member server, Domain controller, Workstation, Remote Desktop host, Hardened file server, "Start empty". Each is a ready list of settings with a plain-language reason.
2. **Choose where it applies**: company, department (OU name or a free text target), server role.
3. **Adjust with switches and dropdowns**, grouped by topic (Passwords and lockout, Remote Desktop, Windows Update, Defender and firewall, Audit logging, UAC, SMB and legacy protocols, Event log sizes, Removable media, Screen lock). A setting shows its default, the recommended value and the control it maps to; custom registry or ADMX-style entries live under Advanced with a guided picker, never a blank JSON box.
4. **Per-company and per-department overrides** of a base template, with a diff view ("this department differs from the company template in 3 settings").
5. **Review and export**: the PowerShell script, the HTML/CSV report, and a version number. Every export is audited and the template keeps its history.
6. **Compare**: paste or upload a `Get-GPOReport -ReportType Xml` file to compare an existing GPO against a template and see what is missing, extra or different (read-only; nothing is written to a domain from the server).

## Boundaries

* The server **never** writes to Active Directory. It generates artifacts that an administrator runs. No domain credentials are stored.
* Generated scripts are signed text with a header (template name, version, who exported it, SHA-256 of the settings), contain no secrets, and default to `-WhatIf` until the administrator removes it.
* Links with change monitoring: once the RMM change monitor (rc.22) exists, a template's registry-backed settings can be added as watch presets, so drift from the baseline is reported per device.
* Links with the RMM policy engine: a template is **not** an RMM policy. The RMM delivers checks and scripts to agents; GPO is the domain's own mechanism. The two are complementary and the doc for each says so.

## Build outline (when the compliance hold is lifted)

1. Settings catalog (data, not code): id, topic, registry path or security-template key, value type, default, recommended, risk note, control mappings, minimum Windows version. Reviewed like a dependency.
2. Template and override tables, versioning, audit rows; abilities `rmm.gpo.view` and `rmm.gpo.manage`.
3. Script and report generators (pure PHP, deterministic, golden-file tested); the script is syntax-checked with PowerShell (`pwsh` in CI) and every setting round-trips through `Get-GPRegistryValue` in a lab domain.
4. GUI: template picker, topic switches, override diff, export page, comparison upload.
5. Both editions adopt it; RivetMSP gets per-client templates, RivetIT per-department.

Size: L. Risk: correctness of the settings catalog and of the Windows behaviour, which cannot be proven on this Linux host; needs a lab domain.
