# Log presets: edition prompts (RivetCore 1.0.0-rc.54)

Ready-to-paste prompts for the RivetIT and RivetMSP chats. Core (rc.54) supplies the data; the editions draw the UI. Read first: `docs/rmm/REMOTE_AND_LOGS.md` section "Compliance baseline", `docs/rmm/CHANGE_MONITOR.md` section 6, and `docs/rmm/GPO_TEMPLATES_PLAN.md` (audit-policy subcategories are in its later, optional GPO package).

What Core guarantees (do not re-implement it in the edition):

- `RemoteSettings::summary()['log_presets']` (also the body of the remote settings endpoint) lists every preset with `recommended`, `selected`, `enabled`, `frameworks`, `audit_policy`. `log_presets_chosen` says whether an administrator ever saved a choice.
- `selected` is what to tick: the stored choice, or the compliance baseline while nobody has chosen. `enabled` is what agents are really told. Ticking the baseline on screen stores nothing.
- Nothing ever turns log collection on implicitly. Existing installs keep exactly what they saved.
- A preset cannot create events Windows does not write. The system does not read the audit policy of a device, so it can never show "audit policy is on".

## Prompt for the RivetIT chat

```
RivetCore 1.0.0-rc.54 adds a compliance baseline for the Windows security log presets. Update the RMM remote/log settings page (the one that edits logs_collect) in RivetIT:

1. Preset checklist. Render every entry of settings.log_presets as a checkbox row: title, description, and a "Recommended for compliance" badge when recommended is true. Under the badge show the frameworks list (HIPAA 164.312(b), PCI DSS 10.2, ...) as small text or a tooltip. Initial checked state = selected. Do not invent your own defaults.
2. Never-chosen banner. When log_presets_chosen is false show a one-line notice: "Nothing is saved yet. The recommended presets are ticked; they take effect when you save." Hide it once chosen.
3. First enable. When the admin turns the "Log collection" switch on and log_presets_chosen is false, send compliance_baseline_if_unset: true in the same request (with accept_logs_storage: true as today). Core stores the baseline only if no choice exists. Never send it when chosen, and never turn the switch on for the admin.
4. Save. Saving the checklist sends logs_collect: {presets: [ids of ticked rows]} (an empty list is a valid explicit choice: "none"). Keep the sources fields as they are; Core keeps stored sources when only presets are sent.
5. "Apply compliance baseline" button (admin only). Calls the apply action (RemoteActions::applyLogPresetBaseline / the matching route) and re-renders from the returned settings. It adds the baseline to what is already chosen and does not untick anything; it does not switch log collection on, so if the switch is off say so next to the result.
6. Audit-policy warning, always visible on this page (use log_presets_audit_note): "A preset only reads events Windows writes. If the audit policy subcategory is off on a device, that preset collects nothing from it." Under each row show audit_policy ("Needs: Audit User Account Management (Success)"). Link to the optional GPO / auditpol script that enables the subcategories. It does not exist in Core yet (see the last section and docs/rmm/GPO_TEMPLATES_PLAN.md): until it does, link to that plan and the list in audit_policy, and do not ship a button that pretends otherwise. Do not claim the policy is verified.
7. Compliance. If a custom profile contains the requirement log_presets_baseline, the compliance page shows the check security_log_presets (pass / fail). On fail print its detail lines verbatim: each ends "Enable preset X (needs Windows audit policy Y)". Do not hide the Caveat line on a pass.
8. Tests: the checklist is pre-ticked only while log_presets_chosen is false; an install with presets saved (even empty) shows exactly its saved state; first enable sends the flag once; the page never enables logs by itself; axe clean; no new JS dependency.

Do not change the device protocol, agent code, or any Core file. Pin RivetCore to the rc.54 release when it exists.
```

## Prompt for the RivetMSP chat

```
RivetCore 1.0.0-rc.54 adds a compliance baseline for the Windows security log presets. Do everything in the RivetIT prompt for the settings page (preset checklist with the compliance baseline pre-ticked from settings.log_presets[].selected, "Recommended for compliance" badge with the frameworks each preset supports, first enable sends compliance_baseline_if_unset: true, "Apply compliance baseline" button, the always-visible Windows audit-policy warning and per-row audit_policy text, link to the optional GPO / auditpol script), plus the MSP parts:

1. Per-client compliance gap. On each client's compliance view add a row "Security event log presets" from the security_log_presets check result: Pass / Fail with the detail lines, and the remediation text "Enable preset X (needs Windows audit policy Y)". The presets are one install-wide setting in Core, so show the same install-level result on every client and say so ("collected for all clients"); do not fake per-client numbers. If you want per-client coverage, count the client's Windows devices that are online and have the logs capability from data you already have, and label it as a count of devices, not as proof that Windows is auditing.
2. MSP onboarding: when a new client with Windows devices is created and log collection is on but log_presets_chosen is false, show a dismissible hint with the Apply button for admins. Never apply automatically.
3. Client-facing report: if a framework profile in use contains log_presets_baseline, include the check and its caveat line in the shared compliance report as Core renders it; do not reword the caveat.
4. Tests as in the RivetIT prompt, plus: a technician without RMM admin never sees or can post the apply button; the per-client view does not change a client's score except through the check Core returns.

Do not change the device protocol, agent code, or any Core file.
```

## GPO / audit policy (optional follow-up, not in rc.54)

The minimal route is an `auditpol /set /subcategory:"..." /success:enable [/failure:enable]` script for the subcategories listed by `SecurityLogPresets::auditPolicyNeeds()` (User Account Management, Security Group Management, Logon failure, Other Object Access Events, Security System Extension, Audit Policy Change). Offer it as a downloadable script or an RMM script-library entry the admin chooses to run; it is a change to the managed devices, so it needs the same approval path as any other script. The GPO backup package route is described, and deferred, in `docs/rmm/GPO_TEMPLATES_PLAN.md`.
