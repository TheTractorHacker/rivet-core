# ADR-002: What stays in the editions, and why

Status: accepted (2026-10-04)

The extraction rule is "abstract only what is actually shared". These were inspected and deliberately **not** moved.

## Authorization (`Security\AuthorizationService`) - stays

It is a 47-line wrapper over two functions the edition owns (`lookupUserPermission()` and `enforceClientAccess()`).
There is no permission-evaluation logic in it to share, and no module in Core needs to ask "may this user do X?"
beyond a callable the edition passes in (`ToolPipeline::run(..., $allow, ...)`). Adding an `AuthorizationInterface`
with no consumer would be an abstraction for its own sake. Revisit when a Core module genuinely needs it.

## Metrics (`Metrics\*`, 12 files, ~4,000 lines) - stays

It is bound to RivetIT's RMM integrations and device tables (`assets`, integration rows, the Tactical provider), uses the
connection directly in 8 of 12 files, and RivetMSP already has its own RMM layer (`rmm_*`) with a different shape. Moving it
would mean designing a device/RMM contract that two editions do not yet agree on. Revisit once both editions need the same
metrics pipeline.

## KB media, URL rewriting and HTML import (`KB\MediaToken`, `MediaUrlRewriter`, `HtmlImporter`, `InteractiveBlocks`) - stay

They depend on the edition's signing key (`config_kb_media_key`, `decryptSetting`), its public URL scheme, its principal/client
scoping, HTMLPurifier definitions and its `kb_*` tables. The pure document converters (`DocxConverter`, `PdfConverter`) did move.

## Training - stays (out of scope)

Internal-only, its own `Core\Db`/`Ctx` concepts. Not touched.

## Directory, Assets, Ops - stay (not in the plan)

`Directory` (people import) and `Assets` (assignment history) are tied to the department/contact model; `Ops\ServerStatus` is a
RivetIT admin page helper.

## Always edition-owned data

`users`, `clients`/`departments`, `contacts`, `tickets` (including `tickets.ticket_problem_id`), `settings`, `webhooks`, `assets`, and every
table not listed in `CoreMigrations`. Core reaches them only through contracts (`AgentDirectoryInterface`, `TicketProblemLinkInterface`,
`WebhookSubscriptionsInterface`, `RequestContextInterface`, `SettingsInterface`, `RedisClientProviderInterface`).
