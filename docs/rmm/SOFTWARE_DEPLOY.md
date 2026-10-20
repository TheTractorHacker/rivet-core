# RMM software deployment and reporting (Phase 5)

Install what you assigned, check that it is there, and show the difference. An administrator keeps a catalog of packages; a package is assigned to a scope (everyone, a client, a site, a group, a tag, one device); the agent reports what each machine has; the scheduler installs or removes the difference, only where it is allowed and never faster than the limits you set; a report shows deployed against expected by package, client, ring or device, and exports it as CSV.

Status: **built in Core (migration 0029; section 14 adds install-with-the-agent, migration 0033), not adopted by an edition yet**, behind the `software_deploy` sub-switch (off by default). Linux (apt, and dnf by parser and fixture) is proven end to end on a real systemd container with a real apt ([section 12](#12-what-is-proven-and-what-is-not)). Windows (MSI and EXE with a hash-pinned download) is **cross-compiled and unit-tested with a fake registry, a fake process runner and a local TLS server only: it has never been run on a real Windows machine.**

Related: [FEATURES.md](FEATURES.md) rows 10.1 to 10.3 and 12.x, [openapi-device.yaml](openapi-device.yaml) (`/endpoint_devices/software_*`, `/endpoint_devices/{id}/deployments*`), [PROTOCOL.md](PROTOCOL.md) section 3.6b, [PATCHING.md](PATCHING.md) (rings, windows and the pattern this follows), [CAPACITY.md](CAPACITY.md), [docs/modules/rmm.md](../modules/rmm.md), issue [#73](https://github.com/TheTractorHacker/rivet-core/issues/73).

## 1. The model in one page

```
administrator --> catalog:  package -> versions (immutable)  +  allowed download hosts
                                  |
        assignment (scope + install/uninstall [+ version])       two-person package: waits for a second user
                                  |
 DeployScheduler (housekeeping): which packages should this device have?   rmm_package_installs (one row per device and package)
                                  |
   software_detect --> what does it have?   (read only; per device about once a day)
                                  |
   missing?  --> dry run (always)  --> software_install  (only when software_live_install = 1)
                                  |
   the agent verifies by detection afterwards --> state compliant / failed --> report, CSV, events
```

* **Catalog.** `rmm_packages` (name, publisher, switched on, `two_person`) and `rmm_package_versions`. A version is one installable thing for one operating system and format: `apt` or `dnf` (a package name; the machine's own repositories supply the files), `msi` (a hash-pinned download plus its product code) or `exe` (a hash-pinned download, silent arguments, a detection rule). **A version can never be edited**; to change what is installed add a new version (this is what makes the hash a pin). A version can be retired: it is never installed again but can still be removed.
* **Assignment.** `action` `install` or `uninstall`, a scope (`global`, `client`, `site`, `group`, `tag`, `device`), and optionally one version (otherwise the newest version that is not retired and fits the device: its operating system and, on Linux, its package manager). For one device and one package the most specific scope wins (device over tag over group over site over client over global). Removing an assignment **uninstalls nothing**.
* **Ring.** A device's ring is the ring of its effective patch policy ([PATCHING.md](PATCHING.md) section 4). The ring's delay in days is added to the assignment's activation time: the pilot ring gets a package at once, `broad` a week later, `last` two weeks later. Until then the report shows the device as `scheduled`. A device without a ring waits for nothing.
* **Expected versus deployed.** One row per (device, package) in `rmm_package_installs`: the expectation (action, version, assignment, ring, since when), the last detection (installed, version, satisfied) and the last attempt (result, code, detail, attempts, failures).
* **Job types:** `software_detect` (read only) and `software_install` (install or uninstall; destructive: offered at most once, never re-sent when the acknowledgement is lost), each needing the capability the agent announced (`software:detect`, `software:install`). An agent from before Phase 5 announces neither, is never offered either job, and the golden replay of older agents is byte-identical.

## 2. Tables (migration `0029_rmm_software_deploy`, additive, idempotent, no rows inserted)

| Table | Holds |
|---|---|
| `rmm_software_hosts` | the host names installers may be downloaded from (exact names) |
| `rmm_packages` | the catalog: name, publisher, description, switched on, two-person |
| `rmm_package_versions` | one row per package, operating system, format and version: package name (apt, dnf), address, SHA-256, size, silent arguments, product code, detection rule, retired |
| `rmm_package_assignments` | package, optional version, scope, action, enabled, activated at, approval |
| `rmm_package_installs` | one row per device and package: expectation, last detection, last attempt |
| `rmm_package_history` | one row per job result: action, mode, result, code, detail, detected version, who, when |
| `rmm_package_jobs` | the ledger of detect and install jobs (what each was for, whether its result was applied; the idempotency key of a scheduled install) |
| `rmm_deploy_state` | per device bookkeeping: last evaluation, last detection request, last detection |

The tables are empty and cost nothing until the switch is on. With the switch on and no assignment, a housekeeping pass costs one indexed lookup. Retention: history for `software_deploy_history_days` (365), applied ledger rows for 30 days, installs rows nothing expects any more for 90 days.

## 3. The catalog and where installers may come from

**Package source pinning**, in layers. Each is checked when a version is saved **and again when a job is built**, so taking a host off the list stops deployments that were already assigned:

1. **Host list.** An administrator (`rmm.admin`, not limited to some clients) lists the host names an installer may be downloaded from (`POST endpoint_devices/software_hosts`). Exact names: `example.com` does not allow `cdn.example.com` or `example.com.evil.test`. An empty list allows nothing. The decision goes through `PackageSourcePolicyInterface` (`AllowedHostsPolicy` is Core's); an edition may replace it (`RmmModule::setPackageSourcePolicy()`) and must pass the conformance kit (section 10).
2. **The address itself** (`SourceUrl`, one anchored pattern, no `parse_url`): `https` only; a DNS host name with at least two labels whose last label is not numeric (so no IP address in any notation: dotted, decimal, hex, octal, IPv6; no `localhost`); no user information; only the default port; no fragment; no backslash, space or control character; at most 600 characters; and **no query string** (it would travel in every job record, and a token in it would be readable by anyone who can read jobs). The host is stored in lower case.
3. **The hash.** `sha256` is required, 64 lower-case hex digits, and `size_bytes` (1 to 1 GiB) is required. The agent downloads over https from exactly that host, reads at most `size` bytes, and runs the file only if its SHA-256 equals the pin (checked again just before it runs). A mismatch deletes everything and runs nothing (`hash_mismatch`).
4. **Linux has no address at all.** An apt or dnf version names a package; the machine's own configured repositories and their signature checks supply the files. The agent never passes `--allow-unauthenticated`, `--force-yes`, `--nogpgcheck`, a URL, a file path or a `.deb`/`.rpm`.

Other rules of a version: an `msi` needs its product code (how it is detected and removed) and is installed with fixed options (`/qn /norestart`); an `exe` needs a detection rule and `silent_args` of letters, digits, spaces and `/ \ - _ = . : , +` only (no quotes or shell characters, at most 12 words of 80 characters), is install only, and is started from an argument list; a Linux package name is `[a-z0-9][a-z0-9+._-]{0,99}` (never starts with a symbol, so it can never be an option) and always comes after `--`; a version to pin (`pin_version`) is a plain version string.

Reading the catalog needs `rmm.software.view`; a reader without `rmm.software.manage`, or limited to some clients, sees an installer's host but not its path.

## 4. How detection works

`software_detect` carries up to 25 rules in one JSON text (`rules`; a job parameter must be a flat string): `{key, kind, value, min}`.

| kind | Platform | Looks at |
|---|---|---|
| `package` | Linux | `dpkg-query` (apt) or `rpm -q` (dnf), by exact package name |
| `product_code` | Windows | the Uninstall registry keys (64 and 32 bit views) whose key name is the MSI product code; `DisplayVersion` |
| `uninstall_name` | Windows | the Uninstall entry whose `DisplayName` equals the value (case-insensitive); `DisplayVersion` |

`satisfied` is `installed` and the installed version is at least `min` (numeric dotted comparison; text after the numbers is ignored; an empty `min` means "present"). The server writes a result only to the packages it asked about: an agent cannot set the state of another package. A device is asked about every `software_detect_interval_s` seconds (default 24 h, 1 h to 7 days), spread by a stable hash of the device id, and on request (`POST .../deployments/detect`). Nothing is installed from a device that has never been detected.

## 5. Install, removal, dry run

* **Manual:** `POST endpoint_devices/{id}/deployments/run` `{package_id, action?, version_id?, mode?, confirm?, max_minutes?}`. `mode` is `dry_run` unless the body says `run`. A dry run needs `rmm.software.manage`; a real run needs `rmm.software.deploy` and `confirm: true`. The job is built from the catalog row only: **extra body fields (`url`, `args`, `sha256`, `pkg`, ...) are not parameters and are ignored.** One install job at a time per device.
* **What a dry run does.** Linux: the package manager's own simulation. Windows: detection and parameter checks only; nothing is downloaded (the result says `not_downloaded`).
* **What the agent does on a real run.** It detects first: installing what is already satisfied does nothing (`already_installed`), removing what is not there does nothing (`not_installed`). Linux: `apt-get -s` first, and **a simulation that would remove or replace another package refuses the job** (`would_remove_other`); then `apt-get install --no-install-recommends -- <package>` (a pinned version as `<package>=<version>`) or `remove` (never purge, never autoremove); `dnf`/`yum` the same way, and a removal that other installed packages depend on is refused. A held package manager lock is reported (`locked`) and never broken. Windows: download, verify, `msiexec /i` (or `/x {product code}`) with `/qn /norestart`, or run the exe with its arguments; exit codes 3010 and 1641 mean "done, restart needed" (`needs_reboot`), 1618 means another install is running (`locked`). Afterwards it detects again: if the package is not as asked the result is `verify_failed`. **It never restarts the machine.**
* **Results:** `would_install`, `would_uninstall`, `installed`, `uninstalled`, `already_installed`, `not_installed`, `needs_reboot` (good); `failed`, `hash_mismatch`, `download_failed`, `not_found`, `locked`, `verify_failed` (not good; the job is `failed`, the document is still the full result).

## 6. The scheduler and its safety defaults

A device is changed by the scheduler only when **all** hold: an enabled, activated assignment reaches it and its ring's delay has passed; the last detection says the package is missing (or, for a removal, present); no "leave this device alone" window suppresses it (the Phase 3 schedule gate); it is online and announced `software:install`; no install is already waiting on it; fewer than `software_max_installs` devices (25) and `software_max_installs_per_client` (5) are installing; load shedding is at level 0 or 1; and a package that failed is not retried before 12 hours, nor more than three times in a row.

* **The switch is off** (nothing is stored, scheduled or sent), and a pass with it off does nothing.
* **Live installs are off.** Until the limit `software_live_install` is 1 the scheduler queues **dry runs only** (one a day per device and package). Switch it on after a dry run has been seen on a pilot machine.
* **No assignment means nothing is installed.** Removing an assignment uninstalls nothing.
* **Two people.** A package with `two_person` needs a second user (`rmm.job.approve` and `rmm.software.deploy`) for every assignment (it is saved switched off and goes live when someone else approves; rejecting it deletes it) and for every real run a person starts (`202 pending_approval`, a Phase 2 approval of kind `software`; the job is queued only when the second person approves and is attributed to the person who asked). The requester can never decide their own request. A dry run never needs a second person.
* **Every value the agent receives is rebuilt from the catalog.** A technician cannot type an address, a hash, an argument or a package name into a job; the generic job endpoint refuses both job types.

## 7. Reports

* **Per device:** `GET endpoint_devices/{id}/deployments` (counts by state and one entry per package: expected action and version, state, installed version, ring, last result), `.../deployments/history`.
* **Fleet:** `GET endpoint_devices/software_report?group_by=package|client|ring|device` (filters `client_id`, `package_id`, `ring`, `state` for `device`, `limit`, `offset`). Package, client and ring give counts per state and the percentage compliant; `device` gives one row per device and package. `GET .../software_report/csv` is the same as `text/csv` (CRLF, RFC 4180, **a cell that starts with `=`, `+`, `-`, `@`, a tab or a CR gets a leading apostrophe**, because host names and package names come from devices and administrators). Only expectations count. A client-restricted viewer sees only their own clients and gets the same 404 for another client as for a missing one.
* **State of an expectation on a device** (worst first): `failed` (the last attempt failed and it is not fixed), `pending` (a job is waiting or running), `scheduled` (not due yet: the ring's delay), `unknown` (never detected), `missing` (an install is expected and it is not there), `present` (a removal is expected and it is still there), `compliant`. `RmmReadModel::deploymentStatus($device)` and `deploymentReport($visibleClientIds, $filters)` are the read models.
* **Reports are reproducible from stored data:** they read `rmm_package_installs`, which holds the last detection and the last attempt. There is no separate report store.

## 8. Events, abilities, routes

Events (through `RmmEventsInterface`): `rmm.deploy.started`, `rmm.deploy.finished`, `rmm.deploy.failed` (fields: job id, package name, action, mode, result; never an address, a hash or the job output). Audit rows for every catalog, host and assignment change, every run and every denial.

| Ability | Grants |
|---|---|
| `rmm.software.view` | read the catalog, assignments, device state, history, reports (also needs `rmm.device.view`) |
| `rmm.software.manage` | edit the catalog (packages, versions; fleet-wide, so also a scope that sees every client), ask for a detection, run a dry run |
| `rmm.software.deploy` | create and remove assignments (they change machines by themselves later), run a real install or removal, decide a software approval |

The allowed-host list needs `rmm.admin` and a scope that sees every client. Editions map the abilities (suggested: `view` like `device.view`, `manage` like `job.run_saved`, `deploy` like `job.reboot` or stronger). A restricted caller assigns only to their own clients and devices; an assignment to everyone, a site, a group or a tag needs an unrestricted caller. Routes: see [openapi-device.yaml](openapi-device.yaml).

## 9. Settings

Sub-switch `software_deploy`. Plain-language cost text for the administration page: *"Software deployment keeps a catalog of packages. Once you assign a package, every device in that scope is asked what it has about once a day (a short read-only check) and the answer is stored. Nothing is installed until you switch on live installs; until then you only see what would be installed."*

Limits (`limits_json`, all validated): `software_detect_interval_s` 86400 (3600 to 604800), `software_max_installs` 25 (1 to 10000), `software_max_installs_per_client` 5 (1 to 1000), `software_live_install` 0 (0 or 1), `software_deploy_history_days` 365 (30 to 3650), `software_batch` 100 (10 to 5000; devices per step of one pass, halved at shed level 1). Capacity: [CAPACITY.md](CAPACITY.md) section 20.

## 10. For editions: the package source policy kit

`PackageSourcePolicyInterface::allows(string $url, int $clientId): bool` is the one place an installer address is judged. `RivetCore\Testing\PackageSourcePolicyConformanceTestCase` checks any implementation: the configured address is allowed (also with a query, also in upper case); the same question gets the same answer; only https; no user information (five spellings); only the whole host (twelve lookalikes, including a suffix, a prefix, a trailing dot, `%2e` and Unicode full stops); no IP address in any notation (thirteen); only the default port; nothing a parser could read two ways (backslash, `#@`, `?@`, a second URL in the path); no whitespace or control character; garbage (empty, 5,000 characters, invalid UTF-8, full-width letters) refused and never thrown on; and the client id never loosens any of it. Core proves the kit with 18 deliberately broken policies (`tests/Conformance/KitMutationTest.php`): each must make the named test fail.

## 11. What is NOT done

* **winget and Chocolatey.** The issue lists them as catalog sources. Both resolve a package id through an online source at install time, which the agent would have to trust without a pin. This phase supports only what can be pinned: an MSI or EXE with a SHA-256, or the machine's own signed apt/dnf repositories. A winget/Chocolatey source could be added as a format with a pinned manifest; it is not built.
* **Scheduled reports, PDF export, client-facing reports** and the other report types of the issue (health, patch compliance, inventory, uptime as one report set). This phase delivers the deployment report (JSON and CSV); patch compliance and inventory have their own read models already. There is no `rmm_reports` table.
* **Updating a package by version.** An assignment with no pinned version installs "present"; moving a fleet from 2.4 to 2.5 means assigning the new version (detection with `min` then reports the old machines as missing). There is no "update everywhere automatically" flag.
* **EXE uninstall** (it would need to run an arbitrary uninstaller found on the machine), **MSI patches and transforms**, `.deb`/`.rpm` files by address, zypper, macOS (Phase 7), repository management (adding a source to a machine), installers signed-checked by Authenticode (the SHA-256 pin is the check), progress of a long install (the job protocol reports only the final result), bandwidth control, rollback.
* **Authenticode / publisher checks.** The pin proves "this is the file the administrator saw". It does not prove the administrator trusted the right file.
* **Windows has never been run.** See section 12.

## 12. What is proven and what is not

| | Proof |
|---|---|
| Server (PHP) | `DeployTest`, `DeploySchedulerTest`, `DeployContractTest` (hostile versions, immutability, a test per route with a client-restricted user, grants, two-person approval of runs and assignments, results, scheduler gates, rings, limits, retries, load shedding, drift, the report and the CSV); PHPStan level 6; the full suite |
| Contract | `agent_software_deploy_vectors.json`: accept/reject vectors of the job parameters, labelled by hand, checked against the PHP validators by the generator, replayed by the Go agent |
| Kit | `PackageSourcePolicyConformanceTestCase` with 18 mutants |
| Linux agent | Go tests in `internal/deploy` with an injected runner (exact argument lists, the no-unauthenticated invariant, refusals, locks, verification) and **`endpoint-agent/e2e/run_software_proof.sh`: the real agent under real systemd in `jrei/systemd-ubuntu:24.04` with real apt and a local repository, the real PHP server on a scratch database** (section 13) |
| Windows | **not run.** Parameter checks, the download (a local TLS server: good file, wrong hash, too many or too few bytes, a lying Content-Length, redirects to another host or to http, a slow server), the staging directory and its removal, the `msiexec` and exe argument lists, exit codes and detection through a fake registry are unit-tested on Linux. Treat the first real Windows install as a test: dry run first, one machine in the pilot ring, a small package |
| dnf, yum | argument lists and parsing by fixtures; never run on a real Rocky, Alma or Fedora host |

### 13. The Linux proof, step by step (`e2e/run_software_proof.sh`)

Run on 2026-10-17 with the agent built from this tree: **59 checks, `SOFTWARE PROOF PASS`**. The test host has no archive mirror inside the container: the Ubuntu sources are removed from apt and replaced by a `file:` repository built by `e2e/software_repo.sh` (`rivetit-dummy` 1.0 and 1.5, `rivetit-other`, `rivetit-base`, `rivetit-app` which depends on `rivetit-base`, and `rivetit-slow` whose postinst sleeps 100 seconds to hold the dpkg lock). Each step is a check that fails the run when untrue:

1. The agent enrolls against the PHP server and announces `software:detect`, `software:install`, `job:software_detect`, `job:software_install`.
2. The catalog refuses six hostile or malformed versions (an option-looking package name, a command in the name, a substitution, a download address on a package-manager version, a hash on one, an http address on an msi) with 422, accepts the real one (the detection rule is derived from the package name), and refuses to replace or edit a version.
3. A run is a dry run by default (`would_install`, nothing installed); a real run needs `confirm`; extra body fields (`url`, `pkg`) never reach the job; the real run installs **only the pinned package** (1.5) and every other installed package list is byte-identical; history and the server's view of the version are written; installing it again does nothing (`already_installed`, the dpkg log is untouched); the machine's boot id is unchanged and no reboot job exists; a removal removes it; removing it again does nothing (`not_installed`).
4. Safety: installing `rivetit-app` pulls in `rivetit-base`; removing `rivetit-base` would take `rivetit-app` with it and the **agent refuses** (`would_remove_other`, the job fails, both stay installed); a package in no repository is `not_found`; with another `apt-get` holding the dpkg lock the install **fails as `locked` instead of hanging**, the lock holder is neither killed nor loses its lock file, and after it finishes the retry succeeds.
5. Jobs **forged around the server** (rows written straight into the queue so the server signs them): six hostile install parameter sets (an option as the package name, a command in it, a download address and hash on an apt job, a command in the version, an msi on Linux, an unknown key) and a detection rule with an option-looking package name are refused by the **agent** (`invalid_params` or `unsupported_platform`); a valid forged detection answers per rule; nothing was written to the container.
6. **The scheduler.** After the package is assigned to the client, a housekeeping pass evaluates the device and asks it what it has; the answer is applied; the device is reported as not compliant; the pass queued exactly one **dry run and no real install** (live installs are off by default), and a second pass queues no second dry run the same day. After the limit `software_live_install` is set to 1 the next pass queues the real install, which runs on the device (dummy 1.5), attributed to the scheduler, not to a person. The report reads 1 expected, 1 compliant, 100 percent; the CSV has its header and the device row; a pass with nothing to do queues nothing.
7. **Drift.** The package is removed by hand, the next detection notices, and the scheduler puts it back. Removing the assignment uninstalls nothing.

Not covered by the proof: any Windows path, dnf, yum, a restart request by the installed package (the Linux reboot marker is covered by a unit test), the two-person approval over HTTP (covered by `DeployTest`), rings (covered by `DeploySchedulerTest`).

## 14. Install apps when the agent installs (1.0.0-rc.26, migration 0033)

Like other RMMs: the technician issues the installer and ticks the catalog apps (Huntress, Comet Backup, ...) that go on the new PC with the agent, and catalog packages can be marked "install automatically on new devices". Everything below is **additive** (the device protocol is untouched: no new job type, no new capability, no agent change) and **built on the `software_install` job of section 5**: the same pinned hash, allowed host or hosted file, sealed values, one install at a time per device, verification by detection. It is behind the same `software_deploy` switch (off by default). **Never run on a real PC; Windows vendor installers are UNVERIFIED.**

```
admin:   package -> auto-install scope + order + required    vendor parameters (sealed)    hosted installer files
technician (installer page): ticks apps ------------------------------+  (stored on the enrollment token; audited: who ticked what)
                                                                       v
device enrolls --> plan: one row per (device, app) [rmm_device_apps]   (new device: policy apps + ticked apps; re-enrolling: ticked apps only; INSERT IGNORE)
scheduler step --> next app by install_order --> software_install job (normal, signed) --> result --> status per app
```

### 14.1 Per package: scope, order, required

`PUT endpoint_devices/software_autoinstall/{package_id}` `{scope, os?, clients?, install_order?, required?}` (needs `rmm.software.deploy` and a scope that sees every client).

| Field | Meaning |
|---|---|
| `scope` | `off` (default), `all` (every new device), `clients` (chosen clients/departments, optionally one location each), `os` (`windows` or `linux`) |
| `install_order` | 1 to 9999, lower first (so the security agent installs before backup) |
| `required` | a required app that **failed** stops the later ones (they become `skipped` with the reason); a best-effort app that failed does not |

Only a **new** device (outcome `enrolled`) gets the policy apps; a re-enrollment or reinstall never does, so turning a policy on later does not reach machines that are already enrolled. A policy app on a package that needs a second person (`two_person`) still waits for that approval for each device.

### 14.2 Vendor parameters

An exe version's `silent_args` may be a TEMPLATE with named placeholders: `/ACCT_ID={{account_key}} /ORG_KEY={{org_key}} /S`. The template is the allow-list and is immutable like the rest of the version:

* a placeholder is `{{name}}` (lower-case letters, digits, underscore; at most 30 characters), at most 8, and must come **straight after `=` or `:`**, so it is always the value of a named option and can never be a token or an option of its own;
* with the placeholders replaced by a letter the template must pass the normal argument rules (section 3: no quotes, no shell characters, at most 12 words of 80 characters);
* a technician never types an argument, only a **value** for a name the template declares. A value is 1 to 128 characters of letters, digits and `. _ ~ : / = + , -` (no space, quote, brace, backslash or shell character), and the rendered text must again pass the argument rules; the agent checks it a third time.

Values are stored per package (`global`) or as an override for one `client` or one `location` (most specific wins), `PUT endpoint_devices/software_params/{package_id}` `{name, value, scope?, scope_id?}`, `DELETE` the same with `{name, scope?, scope_id?}`; `GET` says which names are declared and where each is set, **never a value**. They are sealed with the edition's `SecretBoxInterface`; no read, audit row, event or log ever contains one. When a job is built the values are unsealed, the arguments are rendered, validated, and passed through the job sidecar's secret slot (`extra.secret`, the mechanism the MeshCentral group id uses): the job row holds `[redacted:secret]` in `args`, the real text is put back only when the job is signed for the device, wiped as soon as the job has a final result, and scrubbed (also as base64, URL-encoded and JSON-escaped, and each value on its own) from any output the device reports. A missing value fails that app with the **names** that are missing (and the installer page refuses to tick it for that client).

**Who receives the values.** Only a device that is **linked** to an asset and online: a device that waits in `pending_approval` is sent nothing. Anyone who holds an enrollment token and a matching asset (serial or MAC) can enroll a device that then receives the vendor keys of the apps planned for it, as any endpoint of that customer would. With the setting `unmatched_policy` = `auto_create` a token alone is enough to get linked, so **do not combine `auto_create` with parameters you cannot afford to lose** (keep the token short-lived and the use count low).

### 14.3 Hosted installer files

For vendors whose download addresses are account-specific, upload the installer instead of allow-listing a host. `POST endpoint_devices/software_files` (raw body, header `X-Filename`; or `AutoInstallActions::uploadFile()` from an edition's upload handler), then add a version with `hosted_file_id` instead of `url`, `sha256` and `size_bytes` (the file gives its own). Rules: a Windows installer only (MZ or MSI header), at most 200 files, 1 KiB up to `hosted_max_bytes` (default 512 MiB, never above 1 GiB), stored under `<binary_dir>/hosted/hosted_<uuid>.bin` (random name, never from the upload; the directory is created 0750 with deny files and must be outside the web root and the in-app backup), never opened as an archive (so there is nothing to expand: a zip bomb is just a file of its size), checked against its size and SHA-256 every time it is served. A device gets it through **`GET /api/v1/agent_software/{job_id}/{secret}`** (new route, `RmmModule::hostedFileApi()`, an edition mounts it next to the other device routes): the secret is random (48 hex digits), exists for that one job, is sealed in the job like any vendor value, only its SHA-256 is stored, and works only while that job is queued or running for the file it was built for (24 h at most). The agent sends no credentials when it downloads, so the address is the credential; every refusal is the same 404. It needs a usable https service address (a DNS name, default port).

### 14.4 Technician ticks, explicit approval, the audit trail

`RmmAdmin::downloadInstaller()` and `deploymentCommands()` take a new last argument `array $appIds` (needs `rmm.software.deploy` for that client on top of `rmm.token.manage`). The ticked ids are stored on the enrollment token (`rmm_installer_apps`) and every device that token enrolls gets them. **Ticking is the explicit decision for that device**: a ticked app runs for real even while `software_live_install` is 0. A policy app without a tick follows the rule of every scheduled install: a **dry run** (shown as `skipped`, "dry run only") until `software_live_install` is 1. A `two_person` package still needs a second person for each device; the approval is requested in the name of the technician who ticked it (or the administrator who set the policy), and nobody approves their own. Audit rows: `Installer Apps Chosen` (who ticked which apps for which token), `Auto-install Planned` (device, source per app), `Software Auto-install Set`, `Software Parameter Set/Cleared` (name and scope, no value), the usual job rows. `GET endpoint_devices/software_autoinstall/selectable?client_id=&location_id=&os=` is the installer page's helper: apps that can be ticked, which are pre-ticked by policy, order, required, two-person and missing parameter names.

### 14.5 Per-device status

`GET endpoint_devices/{id}/autoinstall` (also `AutoInstallService::deviceApps()`): per app `status` `queued | installing | installed | failed | skipped`, `reason` (for example "waiting for a second person to approve (approval #7)", "dry run only", "not started: the required app \"Security\" failed", "needs_reboot"), `source` (`auto` or `chosen`), `selected_by`, `install_order`, `required`, `attempts`. A locked package manager or a failed download is retried (5 minutes apart, up to 3 attempts); a job that never ran is queued again; an app still waiting after 14 days is `skipped`. Rule: the planner queues **one app per device per scheduler pass**, and nothing at all when the switch is off, `jobs` is off, the device is not linked, offline, did not announce `software:install`, or the global limits (`software_max_installs`, per client) are reached.

### 14.6 Starter templates (data only, all UNVERIFIED)

`GET endpoint_devices/software_templates`; `POST software_templates/{key}` creates a package (or, with `package_id`, a version) from one, through the same checks as a version added by hand. A template carries no binary, no address, no hash and no key. It says what you must supply (version, an installer on an allowed host or an uploaded file, sha256 and size, the detection rule, the parameter values) and **marks everything unverified**.

| Key | Kind | Suggested arguments | Honest status |
|---|---|---|---|
| `huntress_agent` | exe | `/ACCT_ID={{account_key}} /ORG_KEY={{org_key}} /S` | the switch names are a placeholder to confirm with Huntress; never run |
| `comet_backup_windows` | exe | `/S /SERVER={{server_url}} /COMPANY={{company_id}}` | Comet branded installers differ by server; replace with your installer's documented arguments |
| `comet_backup_linux` | apt | none | a package from a repository already on the machine |
| `custom_exe`, `custom_msi` | exe, msi | `/S` / fixed `/qn /norestart` | generic: the vendor's own silent switches go in; an MSI cannot take properties |

Veeam Agent, ESET, Bitdefender, Sophos, SentinelOne and Datto are **not** listed: their silent switches could not be stated with certainty. Use `custom_exe` with the vendor's documentation.

### 14.7 Tables (migration `0033_rmm_auto_install`, eight new tables, nothing altered, nothing inserted)

`rmm_package_auto`, `rmm_package_auto_clients`, `rmm_package_params` (sealed values), `rmm_installer_apps`, `rmm_device_apps`, `rmm_hosted_files`, `rmm_version_hosted`, `rmm_hosted_grants`.

### 14.8 What is NOT done / not proven

* **Windows is still unproven**: the whole chain (download from a hosted address, an exe with sealed arguments, exit code 3010 = "installed, restart needed") is covered by PHP integration tests and the agent's existing fake-runner unit tests only. Exit code 1641 from an **exe** stays a failure (an installer that started a restart on its own is not treated as success); for an MSI it is "restart needed" as before.
* **MSI properties** (`ACCT_KEY=...`) cannot be passed: an MSI is installed with fixed options. Vendors that need them must be installed through their exe bootstrapper.
* **Linux** apps take no parameters.
* The apps are planned **when the device enrolls**; a device that enrolled while the switch was off, or before a policy existed, is not retrofitted (assign the package instead, section 1).
* Reinstall/re-enroll with a **new installer that ticks apps** plans the ticked apps that are not yet planned; an app already `installed` is not installed again (the agent also reports `already_installed`).
* No UI: the edition pages are described in UPGRADING.md ("Adopting install-with-the-agent").
