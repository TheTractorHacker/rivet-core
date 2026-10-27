# RivetCore RMM module

The remote monitoring and management (RMM) module of RivetCore: the endpoint agent (Windows and Linux), the server side shared by
RivetIT and RivetMSP, and the roadmap to a complete RMM. Status: **Phase 0 in progress** (see the roadmap).

| Document | What it is |
|---|---|
| [FEATURES.md](FEATURES.md) | What the RMM does and contains, feature by feature, with the phase that delivers each one |
| [ASSET_PAGE_REDESIGN.md](ASSET_PAGE_REDESIGN.md) | How the asset (device) page and the fleet dashboard are redesigned: gauges, graphs, checks, alerts, inventory, jobs |
| [PROTOCOL.md](PROTOCOL.md) | The frozen wire protocol of the endpoint agent; constants, observed responses, examples and vectors are generated from the code and the recorded transcripts |
| [openapi-device.yaml](openapi-device.yaml) | OpenAPI 3.1 of the device endpoints and the technician REST API |
| [../modules/rmm.md](../modules/rmm.md) | The module page: what it owns, the contracts and abilities, key classes, runnable examples, failure modes |
| [REMOTE_AND_LOGS.md](REMOTE_AND_LOGS.md) | Operator guide for the remote tools (service and process managers, file transfer, Mesh terminal and files) and log collection: the switches and their risk texts, limits, what is built and what remains |
| [CHANGE_MONITOR.md](CHANGE_MONITOR.md) | Operator guide for change monitoring: registry, configuration file, local group and scheduled-task changes, the Security log presets, the signed configuration, privacy and redaction, rules and the timeline, scale numbers, the security review |
| [POSTGRES.md](POSTGRES.md) | The optional PostgreSQL (or TimescaleDB) store for the hot RMM tables: install on the same server, schema and migrator, connection, sizing next to MariaDB and PHP-FPM, partitions, backups, monitoring and what happens when it is down |
| [SCALING.md](SCALING.md) | How the RMM goes from 5,000 supported devices to 10,000 proven before it leaves beta: targets, load model, work items, validation gate |
| [mockups/asset-page.html](mockups/asset-page.html) | Interactive mockup of the redesigned asset page (self-contained HTML; download and open it) |
| [mockups/asset-page-app-style.html](mockups/asset-page-app-style.html) | The same mockup rendered with the real RivetIT CSS and shell markup (light and dark, red accent); self-contained, about 1.7 MB |
| [../design/endpoint-module-extraction.md](../design/endpoint-module-extraction.md) | Engineering design: what moves into Core, contracts, database, wire protocol, module switch, capacity, phases |
| [../architecture/ADR-010-endpoint-agent-module.md](../architecture/ADR-010-endpoint-agent-module.md) | Architecture decision record for the RMM module |
| [../../ROADMAP.md](../../ROADMAP.md) | Project roadmap with the RMM phases and issue links |

Machine-readable index: [manifest.json](manifest.json). Raw files: `https://raw.githubusercontent.com/TheTractorHacker/rivet-core/main/docs/rmm/<file>`.
Issues: <https://github.com/TheTractorHacker/rivet-core/issues?q=label%3Armm>.
