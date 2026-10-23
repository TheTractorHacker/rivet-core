# Shared design assets (`assets/ui`)

Decision (Blake, 2026-10-23): **RivetCore owns the common CSS/JS design layer. Each edition keeps only a thin override file. RivetMSP
ends up visually consistent with RivetIT.** RivetIT is the reference: the files here are RivetIT's `css/` files at commit `4a3545c57`
(release 26.10.68), copied byte for byte, except two that are cut in two (see "Split files").

Status: **additive, opt-in.** Nothing in Core's PHP changed. An edition that does not run the installer behaves exactly as before.

## Where it lives and why

`assets/ui/` at the package root: `css/`, `js/`, `MANIFEST.json`.

- It is **shipped** in the Composer dist (`.gitattributes` excludes `docs/`, `tests/` and most of `scripts/`, but not `assets/`), so an
  edition finds it at `vendor/rivet/rivet-core/assets/ui/` with no extra download.
- It is **not** under `src/`: that tree is PSR-4 PHP covered by PHPStan and the API-surface rules (ADR-004). Static files have no
  namespace, no API tag and no semver surface other than "the bytes".
- It is **not** a build step. The files are served as they are, like today; the installer is a copy plus a hash check.
- `scripts/install-ui-assets.php`, `scripts/check-ui-assets.php` and `scripts/ui-assets-lib.php` are the only scripts that ship (explicit
  `-export-ignore` lines in `.gitattributes`; CI proves it by running them from a `git archive`). They need nothing but PHP.

## What is shared and what is not

"Shared" = tokens, components, Tabler/Bootstrap shims, the bridge, motion, and the sheets of Core-owned pages (RMM, hypervisors,
build host, remote, logs, patches, software, scale, automation, metrics). "Edition" = training/LMS, KB, portal, billing, branding.

| File (RivetIT `css/`) | Bytes | Decision | In Core as | Why |
|---|---:|---|---|---|
| `itflow.shim-bs4.css` | 13,759 | shared, verbatim | `css/itflow.shim-bs4.css` | Bootstrap 4 markup shim; same for every edition |
| `itflow.shim-adminlte.css` | 18,769 | shared, verbatim | `css/itflow.shim-adminlte.css` | AdminLTE markup shim |
| `itflow_bs5_bridge.css` | 25,860 | shared, verbatim | `css/itflow_bs5_bridge.css` | already identical in both editions |
| `itflow.compat-color.css` | 10,062 | shared, verbatim | `css/itflow.compat-color.css` | `--color-*` to `--if-*` alias |
| `itflow.bind-tabler.css` | 12,154 | shared, verbatim | `css/itflow.bind-tabler.css` | `--if-*` to `--tblr-*` seam |
| `itflow_motion.css` | 40,659 | shared, verbatim | `css/itflow_motion.css` | already identical in both editions |
| `file_upload.css` | 2,081 | shared, verbatim | `css/file_upload.css` | generic file-input surface (header says "app, portal, installer") |
| `itflow_metrics.css` | 8,393 | shared, verbatim | `css/itflow_metrics.css` | `.ifm-*` Device Performance pane, a Core RMM page |
| `itflow_rmm.css` | 4,210 | shared, verbatim | `css/itflow_rmm.css` | RMM asset panel and fleet |
| `itflow_rmm_ui.css` | 20,409 | shared, verbatim | `css/itflow_rmm_ui.css` | `.rmmx-*` kit: alerts centre, starter policies, hypervisors, reports |
| `itflow_rmm_automation.css` | 4,429 | shared, verbatim | `css/itflow_rmm_automation.css` | policies, scripts, schedules, approvals, starter pack |
| `itflow_rmm_remote.css` | 1,942 | shared, verbatim | `css/itflow_rmm_remote.css` | Remote tab |
| `itflow_rmm_logs.css` | 2,162 | shared, verbatim | `css/itflow_rmm_logs.css` | already identical in both editions |
| `itflow_rmm_patches.css` | 1,130 | shared, verbatim | `css/itflow_rmm_patches.css` | patch compliance and approvals |
| `itflow_rmm_software.css` | 1,077 | shared, verbatim | `css/itflow_rmm_software.css` | software deployment |
| `itflow_rmm_scale.css` | 1,757 | shared, verbatim | `css/itflow_rmm_scale.css` | already identical in both editions |
| `itflow_custom.css` | 173,995 | **split** | `css/itflow_custom.css` (164,003) | 1,068 of 1,105 top-level rules are generic theme |
| | | | edition: `css/itflow_custom.edition.css` (9,992) | client-portal chrome (19 rules, original lines 121 to 338) and the KB article utility classes `.kb-*` the portal renders (18 rules) |
| `itflow_design.css` | 63,134 | **split** | `css/itflow_design.css` (61,220) | 287 of 288 top-level rules are the shared `.it-*` / `--if-*` layer |
| | | | edition: `css/itflow_design.edition.css` (1,914) | the company-logo chip on the portal navbar and login page (tenant branding) |
| `itflow_kb.css` | 16,822 | edition | stays in the edition | KB interactive blocks (`.ikb*`) |
| `itflow_portal.css` | 21,916 | edition | stays in the edition | client portal |
| `itflow_training*.css`, `training_verify.css` (16 files) | 419,127 total | edition | stays in the edition | training/LMS |
| `webhook_form.css`, `webhook_guides.css` | 15,872 / 17,098 | edition (**decision**) | stays in the edition | linked only from the webhook admin pages, which are edition code; see "Open decisions" |
| `frappe-gantt.css` | 3,186 | edition | stays in the edition | third-party library copy |
| RivetMSP only: `itflow_rmm_starter.css`, `ticket_view_filters.css`, `admin_update.css` | | not touched | stay in RivetMSP | no RivetIT counterpart to copy; see "Open decisions" |
| `js/chart_theme.js` | 6,125 | shared, verbatim | `js/chart_theme.js` | identical in both editions; reads the tokens below |

The accent and radius are not a file: each company's accent is an inline `<style>` the edition's header emits last (see Load order), so
branding stays in the edition by construction.

### Split files

`itflow_custom.css` and `itflow_design.css` were cut by **rule**, never by line: `scripts/lib/CssChunker.php` finds the top-level
rules (comments, strings, `url()` and nested `@media` are understood; a leading comment travels with its rule), and a rule goes to the
edition file when a selector matches the pattern in `scripts/ui-css-split.php` (`SPLIT`). A rule with some matching and some
non-matching selectors is an error, so nothing is ever half-moved. Result:

- `itflow_custom.css`: `.client-portal-nav`, `.portal-*` (19 rules, original lines 121 to 338) and `.kb-*` (9 rules, twice, because
  RivetIT's file contains its lines 1096 to 1852 a second time from line 1853; both copies move) go to `itflow_custom.edition.css`.
- `itflow_design.css`: the single rule `.client-portal-nav .navbar-brand img, .login-box-branding .brand-logo img` goes to
  `itflow_design.edition.css`.
- Deliberately **kept shared** although a name suggests otherwise: `.csat-*` and `.google-review-cta` (used by the agent-side ticket
  CSAT too, and `csat_rating.js` is identical in both editions), the event-picker, event-rules and webhook-flow styles (both editions
  have those pages), `.company-logo-*` (settings), and the accent-as-text rules (derived from `--color-accent`, no branding literal).

Proof (run on 2026-10-23, `php scripts/ui-css-split.php verify --src=<RivetIT css> --shared=assets/ui/css --edition=<overrides>`):

- 16 verbatim files: SHA-256 equal to RivetIT's, byte identical.
- `itflow_custom.css`: 1,105 rules = 1,068 shared + 37 edition; merging the two parts back in original order reproduces RivetIT's file
  byte for byte. `itflow_design.css`: 288 = 287 + 1, same result. Within each part the rules keep their original order.
- One thing does change: the 37 + 1 edition rules now come **after the rest of their parent file** instead of in the middle of it
  (the override is linked right after its parent, so their position relative to every other file is unchanged). Source order only
  matters between rules of equal specificity that set the same property on the same element, so `scripts/lib/CssCascade.php`
  checks every moved rule against every rule that used to come after it. First run reported three candidate pairs; two were
  `img` versus `::before` (different boxes), one was `.portal-preview-toast` versus `[class*="table-responsive-"]`, cleared by hand
  because `js/portal_preview_readonly.js` creates the toast with `className = 'portal-preview-toast'` only. That clearance is recorded
  in `REVIEWED` in the script. Final result: 0 order-sensitive pairs. The check is deliberately conservative (it ignores media
  conditions and ancestors), and it was exercised against a bad split (moving `.btn-primary` rules) to confirm it reports real conflicts.
- The remainders are not in Core. For RivetIT they are in `/home/sysadmin/worktrees/design-assets-handoff/rivetit-css/`
  (`itflow_custom.edition.css` sha256 `c89050c2...`, `itflow_design.edition.css` sha256 `f36c536c...`).

Known oddity, copied faithfully: RivetIT's `itflow_custom.css` contains the 757 lines from "Dashboard small-box refinements" (line 1096)
a second time from line 1853. The copies are not identical (for example the second leaves `!important` off the `.input-group-append`
radius rules, and it wins), so removing one is a behaviour decision, not a clean-up. It is left for a separate, reviewed change.

## Load order

The order is part of the contract: the shims and the seam files only work in this position (they have the same specificity as the
rules they retune and win on order alone). `assets/ui/MANIFEST.json` carries the same list as `load_order`; `UiAssetsTest` asserts the
constraints below against it.

Agent shell (RivetIT `includes/header.php`):

1. Vendor: `tabler.min.css`, then the plugin sheets (tom-select, tempus-dominus, simple-datatables, toastr, intl-tel-input).
2. `itflow.shim-bs4.css`, `itflow.shim-adminlte.css` (both must precede `itflow.bind-tabler.css`: shim-adminlte's `.small-box` rules
   are retuned there).
3. `itflow_bs5_bridge.css`
4. `itflow_custom.css` (declares `--color-*`), then **`itflow_custom.edition.css`**
5. `itflow_design.css` (declares `--if-*` and the `.it-*` layer), then **`itflow_design.edition.css`**
6. `file_upload.css`, `itflow_motion.css`
7. `itflow.compat-color.css` (**must follow both** 4 and 5, or it silently does nothing; it makes `--color-*` an alias of `--if-*`)
8. `itflow_metrics.css`
9. RMM: `itflow_rmm.css`, `itflow_rmm_ui.css`, `itflow_rmm_automation.css`, `itflow_rmm_remote.css`, `itflow_rmm_logs.css`,
   `itflow_rmm_patches.css`, `itflow_rmm_software.css`, `itflow_rmm_scale.css`
10. `itflow.bind-tabler.css` (maps `--if-*` / `--color-*` onto `--tblr-*`; **must follow the shims and the design layer, and precede
    the accent block**)
11. **Edition sheets** (RivetIT: `itflow_kb.css`, per-page extras such as the training sheets, `itflow_portal.css` in the portal)
12. Inline per-company `<style nonce>` with `--color-accent*`, `--bs-primary`, `--card-radius`: emitted last so a company accent wins

Client portal and guest shells load the subset 1 to 7 and 10 (no metrics, no RMM), then the edition sheets.

The two `*.edition.css` links are optional: an edition with nothing to override leaves them out (and a link to a missing file is
the only failure mode, so add the link only for a file that exists).

## Token contract

Three namespaces, one direction of dependency. An edition may *set* the first and must *read* the others, never redefine them.

| Namespace | Declared in | Role | Edition may |
|---|---|---|---|
| `--color-*` (`-accent`, `-accent-hover`, `-accent-soft`, `-accent-rgb`, `-surface`, `-surface-alt`, `-border`, `-border-soft`, `-text`, `-text-muted`), `--card-radius`, `--input-radius`, `--card-shadow` | `itflow_custom.css` (light on `:root`, dark on `body.dark-mode`) | Legacy palette, ~500 reads. **Accent family is the source of truth for branding**: the edition's inline `<style>` sets `--color-accent`, `-hover`, `-soft`, `-rgb` (light on `:root`, dark on `body.dark-mode`) | **Set** the accent family and `--card-radius`, nothing else |
| `--color-*` neutrals | `itflow.compat-color.css` | Aliases of `--if-*` (surface, border, text...). Retires with the last `var(--color-...)` read | Read only |
| `--if-*` (`-sans`, `-mono`, `-bg`, `-surface`, `-border`, `-border-strong`, `-ink`, `-muted`, `-radius`, `-radius-sm`, `-shadow`, `-shadow-md`, chrome tokens; `-primary`, `-primary-hover`, `-primary-soft`, `-primary-rgb`) | `itflow_design.css` (light `:root`, dark `:root[data-bs-theme="dark"]`) | The design language. `--if-primary*` are **aliases of `--color-accent*` declared on `body`**, so they follow whatever accent is cascaded there | Read; override a neutral only in `itflow_design.edition.css`, with the reason in a comment |
| `--tblr-*` (primary, link, body-bg/color, bg-surface(-secondary/-tertiary), bg-forms, secondary/muted, border-color, border-radius(-lg), navbar chrome) | `itflow.bind-tabler.css` | Exports the two namespaces above to Tabler so its components follow the theme | Never |

Rules that keep it working:

- The accent arrow is `--color-accent` to `--if-primary` to `--tblr-primary`. Do not alias `--color-accent` back to `--if-primary`:
  a cycle makes all three compute to the guaranteed-invalid value (explained in `itflow.compat-color.css`).
- Dark mode has two triggers kept in step by the header: `:root[data-bs-theme="dark"]` (design layer, Tabler) and `body.dark-mode`
  (legacy). The edition's header must set both, as RivetIT's does.
- New colour in a shared sheet: add an `--if-*` token, do not add a literal.
- Fonts: `itflow_design.css` and `itflow_custom.css` reference `/fonts/plex-sans-{400,500,600,700}.woff2` and
  `/fonts/plex-mono-{400,500}.woff2`. The installer does **not** ship fonts; the edition serves them from `/fonts/` (both editions
  already do).
- Plugins: the sheets assume Tabler 1.5 and the plugin sheets of step 1 are present at `/plugins/...`.

## Installing in an edition

```bash
composer update rivet/rivet-core        # pin the release that carries assets/ui
php vendor/rivet/rivet-core/scripts/install-ui-assets.php --css=css --js=js --dry-run   # what would change
php vendor/rivet/rivet-core/scripts/install-ui-assets.php --css=css --js=js
php vendor/rivet/rivet-core/scripts/check-ui-assets.php  --css=css --js=js              # must print OK
```

What the installer does: verifies Core's own files against `MANIFEST.json` (refuses if they disagree), copies every listed file into
`--css` / `--js` byte for byte through a temporary file and `rename` (no header is injected into the CSS, because that would break
the byte-for-byte proof), and writes `css/.rivet-ui-assets.json` recording the Core version and the SHA-256 of what it installed. The
"version header" is therefore that receipt plus the manifest. It touches nothing else; it never creates directories and never deletes.
`--dry-run` lists `new` / `update` / `same` per file.

Edition rules:

1. **Never edit a shared file in the edition.** The drift check fails on it. Change it in Core (and run
   `php scripts/build-ui-manifest.php`), or put the rule in the edition override.
2. Commit the installed copies (the app serves `css/` directly, no vendor lookup at request time), and run
   `check-ui-assets.php --css=css --js=js` in the edition's CI and release gate. It exits 1 on a modified or missing shared file
   and ignores the edition's own sheets.
3. On a Core bump, re-run the installer; the diff of `css/` in the edition is the visual change set to review.
4. The override files are the edition's: `css/itflow_custom.edition.css` and `css/itflow_design.edition.css`. Content rule: only what
   is specific to the edition (its portal, KB, training, branding). If a rule would be right for every edition, it belongs in Core.

## Drift check and CI

`scripts/check-ui-assets.php`:

- no arguments: Core against its own manifest. Fails on a file whose hash or size differs, a file on disk that the manifest does not
  list, a listed file that is missing, a stylesheet not named exactly once in `load_order`, or a `load_order` core entry with no file.
  CI job `ui-assets` runs this on every push, then extracts `git archive HEAD`, runs the **shipped** scripts from that copy, installs
  into an empty directory and checks the result. The manifest therefore cannot rot, and `.gitattributes` cannot silently drop a script.
- `--css=DIR [--js=DIR]`: an edition's copies against the manifest (add `--manifest=PATH` for another manifest).
- `--expect-version=X`: also fail unless `core_version` is `X`.

Maintainer routine:

```bash
php scripts/build-ui-manifest.php                              # after changing anything under assets/ui
php scripts/build-ui-manifest.php --core-version=1.0.0-rc.34  # at release time (the tag without v)
php scripts/check-ui-assets.php --expect-version=1.0.0-rc.34
```

`core_version` is `unreleased` until the release step sets it: run the last two commands before tagging (the Core release step). The
edition side is step 5 of [EDITION_CHECKLIST.md](../EDITION_CHECKLIST.md).

## RivetMSP migration checklist

RivetMSP's copies (commit `68d191973`) differ from RivetIT's in the files below. Replacing them changes what MSP shows. Measured with
the same rule parser (selectors compared by text, so a renamed class counts as removed plus added).

### 0. Before you start

- [ ] Pin the Core release that contains `assets/ui`; run `install-ui-assets.php --dry-run` and keep the list.
- [ ] Take "before" screenshots (light and dark) of the pages named below.
- [ ] Add `itflow_metrics.css`, `itflow_rmm_ui.css`, `itflow_rmm_patches.css`, `itflow_rmm_software.css` and `file_upload.css` links in
      `includes/header.php` in the load-order positions above (MSP does not load any of them today). MSP also links
      `itflow_rmm_starter.css`, which has no RivetIT counterpart (see section 5).
- [ ] Make sure MSP's header sets both dark-mode triggers and emits its accent block last (MSP's own `itflow_custom.css` declares the same default accent; confirm its header emits the per-company block last).

### 1. Identical already (no visible change)

`itflow_motion.css`, `itflow_bs5_bridge.css`, `itflow_rmm_logs.css`, `itflow_rmm_scale.css`. `itflow.compat-color.css` and
`itflow.shim-adminlte.css` differ in one comment line (the product name).

### 2. Small shared files: what MSP loses or gains

| File | Visible effect on MSP | Pages/components |
|---|---|---|
| `itflow.shim-bs4.css` (39 lines) | MSP has 23 selectors RivetIT does not (light-theme contrast overrides): `.badge.text-bg-*`, `.badge.text-light/dark`, `html:not([data-bs-theme="dark"])` `.text-success/.danger/.warning`, `.form-text`, `.alert-*` and `.alert-danger .alert-link`, `.nav-pills .nav-link:not(.active)`, `.btn-success/.btn-info` focus and active. RivetIT carries its contrast work in the "Contrast pass" of `itflow_design.css`. Expect small colour changes on **badges, helper text, alerts and pill navigation in light mode**. | every list with status badges; settings nav |
| `itflow.bind-tabler.css` (76 lines) | MSP has 27 selectors RivetIT removed: link colours for plain `a`, `p a`, `.form-text a`, `.alert a`, `small a`, `.text-muted a`, `.card-text a`; `html:not(:has(.navbar-vertical))` rules for `.btn-primary`, `.btn-outline-primary` and `footer` text; dark `.ts-wrapper .ts-control` and `.page-link`. Expect **link colour and underline differences in running text, and buttons/footer on pages without the vertical sidebar** (login, MFA, guest pages, any top-nav page). | login, MFA, guest/pay pages, Tom Select in dark |
| `itflow_rmm.css` (63 lines) | MSP's copy embeds the `.ifm-*` tile/banner rules and `.it-stat-*` / `.it-empty-*` it needs because it has no metrics sheet. With Core's file those come from `itflow_metrics.css` and `itflow_design.css`. MSP-only and **lost**: `#rmm-panel .btn-xs` / `.btn-group-xs` (compact buttons in the asset RMM panel) and `.rmm-perf-canvas` (sized container for the Performance history charts, `height:200px`). Gains the Run-script dialog panes (`.rmm-run-pane`). | asset page RMM panel, Performance history, Run script dialog |
| `itflow_rmm_automation.css` (11 lines) | Gains the starter-pack page rules (`.rmm-starter-badge`, `#starter-*`). MSP's `itflow_rmm_starter.css` defines overlapping `.rmm-starter-*` rules; keep it until section 5 is decided. Loses nothing. | script library, starter pack |
| `itflow_rmm_remote.css` (4 lines) | MSP-only **lost**: `.rmm-mesh-agent` / `#mesh .alert > div` wrapping of MeshCentral node ids (they have no break opportunity and widen the page on a phone). | asset Remote/Mesh tab on phones |
| New to MSP | `itflow_rmm_ui.css` (`.rmmx-*` kit: alerts centre, starter policies, hypervisors, reports), `itflow_rmm_patches.css`, `itflow_rmm_software.css`, `itflow_metrics.css`, `file_upload.css` (every `input[type=file]`, including legacy `form-control-file`, becomes one themed control). Only pages whose markup uses those classes change. | patch compliance/approvals, software deployment, file inputs everywhere |

### 3. `itflow_design.css` (661 differing lines; RivetIT 288 rules, MSP 191)

- **Gained (139 selectors in RivetIT, none in MSP):** page header and card components, `.it-stat-*` cards and tints, status and
  priority pills (`.it-priority-*`), section grouping (Reports hub), list-page vertical rhythm and empty states, outline-button ink
  (amber/cyan contrast), inline-edit cells on the Assets list, the contact-card modal, skip link, wide tables scrolling inside their
  card below 1200px, the "Contrast pass" (helper text, tab links, tinted alerts, accent-filled buttons, dark-surface fixes), the
  Tabler-generation sidebar (`aside.navbar-vertical`: rail, count badges, folded flyouts, brand truncation), breadcrumbs, the CSAT
  and Google-review blocks, the ticket detail stacked sidebar cards and Account Security. This is the largest visible change: **page
  headers, dashboards/stat tiles, lists, sidebar and every card** if MSP's markup uses these classes (`includes/ui/` helpers).
- **Same selector, different declarations (13):** `:root` (RivetIT `--if-muted: #5d6f76`; MSP `#4b5d64`, commented as a WCAG fix:
  4.19:1 on `--if-bg` is below AA 4.5:1. See "Open decisions": the reference is lighter, so muted text gets lighter on MSP),
  `.btn-primary`, `.table-warning/-danger/-success/-info` and their dark variants (row tints), `.modal-content`, `.modal-header`,
  `.breadcrumb`.
- **Lost (MSP-only, 50 selectors):** `.ticket-stat-box*` (6; the clickable status tiles on the ticket list) and the KB reader
  styles `.kb-chapter*`, `.kb-article-card*`, `.kb-reader-*`, `.kb-prose*`, `.kb-empty` (44). RivetIT keeps no equivalent in
  `itflow_design.css` (its KB lives in `itflow_kb.css` for interactive blocks only). **Carry these into MSP's own
  `itflow_design.edition.css`** or MSP's ticket-list tiles and KB reader pages lose their styling.

### 4. `itflow_custom.css` (2,277 differing lines; RivetIT 656 selectors, MSP 374)

- **Same selector, different declarations (127):** the accent-as-text family (`a`, `a:hover`, `.text-primary`, `.btn-link`,
  `.btn-outline-primary`, `.page-link`, `.nav-pills .nav-link.active`, `.dropdown-item.active`, `.list-group-item.active`,
  `.nav-tabs .nav-link.active`), `.small-box` / `.small-box-footer` (dashboard stat tiles), `.dropdown-menu`, `.modal-*`,
  `.card-header.bg-dark`, `.table-hover` row accent, input-group radii, the `select2` Bootstrap 4 skin, the ticket "alga" theme
  headers. Expect **link and active-state colours, dashboard tiles and ticket detail** to change.
- **Gained (452 selectors):** event-picker, event-rules, webhook flow, calendar, list-ui (sortable headers, filter chips, sticky
  header), the 24-point "Design review fixes", the Tabler-era dropdown, split-button, TinyMCE and input-group fixes, the MDM
  mapping picker.
- **Lost (185 selectors in MSP's file that RivetIT's file does not contain). This is the biggest risk.** They are the styles of
  five widgets whose class names RivetIT has since **renamed** (BEM `icon-picker__button`, rebuilt event-rules builder, and so on)
  together with their JavaScript:

  | Widget | MSP-only selectors | RivetIT JS that goes with the new CSS (lines differing from MSP's) |
  |---|---:|---|
  | Icon picker (`.icon-picker-*`) | 20 | `js/icon_picker.js` (412) |
  | Date range picker (`.date-range-picker`, `.drp-*`) | 26 | `js/date_range_picker.js` (577) |
  | Event picker (`.event-picker .ep-*`) | 22 | `js/event_picker.js` (762) |
  | Event rules builder (`.er-*`) | 83 | `js/event_rules.js` (1,495) |
  | Webhook wizard and guides (`.wh-*`) | 32 | `js/webhook_form.js` (968), `js/webhook_guides.js` (447) |
  | Other | 2 | (`.text-dark` remap, `.filter-toolbar > .filter-row-nowrap`) |

  **Installing Core's `itflow_custom.css` in MSP without also taking those RivetIT scripts leaves these five widgets unstyled** (the
  old class names no longer match any rule). They must move together. Core does not yet ship that JavaScript (see "Open decisions").

### 5. Files that stay MSP's

`itflow_rmm_starter.css` (starter pack: Core-owned page, but RivetIT has folded it into `itflow_rmm_automation.css`; once MSP takes
that file, delete the MSP copy), `ticket_view_filters.css` (MSP ticket-view filters; no RivetIT counterpart), `admin_update.css`,
`webhook_form.css` / `webhook_guides.css` (diverged by 342 / 456 lines from RivetIT's; they follow the webhook JS decision),
`frappe-gantt.css` (2 lines).

### 6. Verification after the swap

- [ ] `php vendor/rivet/rivet-core/scripts/check-ui-assets.php --css=css --js=js` prints OK.
- [ ] Side-by-side screenshots, light and dark, desktop and 390px: dashboard, ticket list, ticket detail, asset page (RMM panel,
      Remote, Performance), client list, Reports hub, an admin settings page, webhook add/edit, event rules, login and MFA.
- [ ] Console has no 404 for a stylesheet; no `--tblr-*` resolves to `initial` (inspect `<html>` computed style).
- [ ] Keyboard focus ring visible on buttons, nav and calendar links (the contrast pass relies on the shared files).

## Open decisions (Blake)

1. **JavaScript scope.** Only `js/chart_theme.js` is shared today (identical in both editions). The widgets named in section 4 need
   their RivetIT scripts alongside the CSS. Options: extend `assets/ui/js/` with `icon_picker.js`, `date_range_picker.js`,
   `event_picker.js`, `event_rules.js`, `webhook_form.js`, `webhook_guides.js`, `rivet_dialog.js` and the `rmm_*.js` page scripts
   (RivetIT is ahead on all of them; they differ from MSP's by 4 to 1,495 lines and some carry edition endpoints), or make MSP take
   them as a one-off copy. This is the prerequisite for MSP adopting `itflow_custom.css`.
2. **Webhook stylesheets** (`webhook_form.css`, `webhook_guides.css`): kept in the edition because they are linked only from edition
   admin pages. If webhook admin UI becomes Core's, they move with the JS.
3. **MSP-only sheets** (`itflow_rmm_starter.css`, `ticket_view_filters.css`, `admin_update.css`): no RivetIT source to copy, so
   untouched. Starter pack is a Core page and RivetIT has folded it into `itflow_rmm_automation.css`; MSP's copy can then be removed.
4. **`--if-muted`**: MSP darkened it to `#4b5d64` for WCAG AA; the RivetIT reference still has `#5d6f76`. The shared file is
   verbatim, so MSP will get the lighter value. A one-line fix in Core (a reviewed change to the reference) would give both editions
   the accessible value.
5. **One override file or two.** Edition rules are cut from two files that load at different positions, so there are two override
   files (`itflow_custom.edition.css`, `itflow_design.edition.css`). A single `itflow_edition.css` loaded after `itflow_design.css`
   is possible but moves portal rules past the whole design layer, which the cascade check would have to be re-run for.
6. **Portal in RivetMSP.** MSP's `itflow_custom.css` has a `.client-portal-nav` rule; the richer RivetIT portal chrome is now in RivetIT's
   override. Whether MSP adopts it (copying RivetIT's override) or keeps a smaller one is a product call.
7. **Near-duplicate block in `itflow_custom.css`** (see above): decide which copy is intended, then remove the other in a follow-up.
