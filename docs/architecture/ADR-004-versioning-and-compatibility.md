# ADR-004: Versioning and compatibility policy

Status: accepted (2026-10-06). Roadmap item 0.8.0 #10 / issue #19. Supersedes the "Release process and policies" paragraph in ROADMAP.md.

## Context

RivetCore is consumed by two editions (RivetIT, RivetMSP) that pin a Git tag through a Composer VCS repository. Until 1.0 the API
changed in minors (a `^0.x` constraint only accepts the same minor, so every 0.x release needed a deliberate bump in both editions). At
1.0 the editions need to be able to take a Core release without reading every line of it, and an outside developer needs to know what
they may build on. That requires a written rule for what counts as public, what a breaking change is, how long a deprecated API lives
and which platforms are supported.

## Decision

### Semantic versioning

RivetCore follows [semantic versioning 2.0.0](https://semver.org/) from `1.0.0`.

| Bump | Allowed content |
|---|---|
| Patch (`1.2.3` to `1.2.4`) | Bug fixes and security fixes with no change to the public API, to a table's columns or to a migration's meaning. |
| Minor (`1.2.x` to `1.3.0`) | Backward-compatible additions: new classes, new methods, new optional constructor arguments (always last, always with a default), new additive migrations (new tables, new nullable or defaulted columns, new indexes), new events, new webhook formats, deprecations. |
| Major (`1.x` to `2.0.0`) | Anything that can break a correct caller: removing or renaming a public type or method, changing a signature or a return shape, changing documented behaviour, removing a deprecated API, changing a table's existing columns or a migration's meaning, raising the minimum PHP or database version beyond what upstream still supports, changing a default that alters stored data or wire format. |

### What is public

- A type is public if its docblock carries `@api`. `@internal` types (migration classes, `Testing` support that is not part of the conformance kit
  contract, small helpers) are outside the promise and may change in any release. A type with neither tag is treated as `@internal`
  until tagged; the build that generates `docs/api-surface.md` (`scripts/api-surface.php`) is the list.
- The following are public even though they are not PHP types: the Core-owned tables and columns listed by each module page, the
  migration ids (`0001` to `00NN`) and the meaning of each, the webhook request format (body, header names, signature
  construction), the audit `event_type` and `action` naming convention, the `rivet_core_migrations` table, and the events in
  `Webhooks\EventCatalog` (an event id is never reused for something else).
- Interfaces that an edition implements (`DatabaseInterface`, `RequestContextInterface`, `SettingsInterface`, `ClockInterface`,
  `RedisClientProviderInterface`, the Webhooks, ITSM, MCP and Compliance contracts, `AccessPolicyInterface`) are stricter than
  classes: adding a method to one is a breaking change for implementers, so a new capability is added as a new optional companion
  interface (the `WebhookSubscriptionLookupInterface` pattern), never as a new method on an existing one, until 2.0.
- Array shapes that are documented in a docblock (`@return array{...}`) are part of the contract: keys may be added in a minor but
  never removed or retyped. Constructors with many parameters are called with named arguments; parameter names of `@api`
  constructors and methods are part of the contract for that reason.

### 0.x versus 1.x

| | 0.x (until 1.0.0) | 1.x |
|---|---|---|
| Breaking changes | Allowed in a minor, always listed in `CHANGELOG.md` and `UPGRADING.md` | Only in a major |
| Constraint | Editions bump every minor (`^0.21` accepts only 0.21.x) | Editions pin `^1.0` and accept every 1.x |
| Backward-compatibility check in CI | Advisory | Required on `main` and on every tag |
| Pre-releases | `-rc.N` tags are not an API promise | `-rc.N` precedes each major; a minor may ship an `-rc` on request |

### Deprecation policy

1. An API is deprecated in a **minor** release: a `@deprecated` docblock naming the replacement and the removal version, an entry in
   `CHANGELOG.md` under "Deprecated", and a row in `UPGRADING.md`. Core does not emit runtime deprecation notices by default (they would end up in the editions' error logs); the docblock, changelog and upgrade guide are the notice.
2. A deprecated API keeps working, unchanged, for **the rest of the current major**. It is removed only in the **next major**, and only
   if at least **one minor release** has shipped with the deprecation notice (so an edition always has one full minor cycle to react,
   in practice far more).
3. A deprecation never changes behaviour. A security fix that must change behaviour is a patch or minor with the change called
   out in the changelog, not a "deprecation".
4. Stored data and wire formats follow the same rule: the legacy webhook signature headers (ADR-007) and the `ITFlow\` shims in the
   editions (ADR-005) are deprecated in 1.0 and removed in 2.0.

### Backward-compatibility check in CI

- The `compatibility` job runs `roave/backward-compatibility-check` against the latest tag (`--from=<last v1.* tag> --to=HEAD`). It is
  installed inside that job only (it needs PHP 8.4 and would otherwise break installs on 8.2 and 8.3).
- It is advisory until `v1.0.0` is tagged. From the first commit after `v1.0.0` the job loses `continue-on-error: true` and is a
  required status check, so a pull request that breaks the `@api` surface cannot merge.
- A deliberate break is the only reason to waive it, and a waiver means the branch targets the next major.
- Beyond what the tool can see, `scripts/api-surface.php` regenerates `docs/api-surface.md`; a diff in that file during review is the
  human check for array-shape and constant changes.

### How editions pin

- Editions require `"rivet/rivet-core": "^1.0"` and commit `composer.lock`, so the version actually running is a deliberate update
  (`composer update rivet/rivet-core`) followed by the per-release checklist in
  [docs/EDITION_CHECKLIST.md](../EDITION_CHECKLIST.md). They never track a branch.
- A release candidate is pinned exactly (`1.0.0-rc.1`) with `minimum-stability` left at `stable` by using an inline alias or the
  explicit constraint; the soak (see the ROADMAP) runs on that exact pin.
- Rollback is a database backup plus the previous pin ([UPGRADING.md](../../UPGRADING.md#rollback)). Core migrations are forward-only.

### Supported platform matrix and support window

| Platform | Supported and tested in CI | Best effort |
|---|---|---|
| PHP | 8.2, 8.3, 8.4, 8.5 | any other 8.x not yet end of life |
| MariaDB | 10.11, 11 (the `mariadb:11` image) | other 10.x and 11.x |
| MySQL | 8.0, 8.4 | other 8.x |
| Redis | 7 (optional everywhere) | 6, 8 |
| PostgreSQL | not supported in 1.x (ADR-008) | none |

- Core supports every PHP version that still receives upstream security fixes. Dropping a PHP version is announced one minor in
  advance (in the changelog and the README) and happens in a minor release; because Composer enforces the `php` constraint, an edition
  on an older PHP simply stays on the last Core that supports it. It is not considered a breaking change under this policy, because
  it follows upstream support, and it is the only exception to "removals need a major".
- Dropping a database version follows the same rule, one minor ahead, and only when the vendor has ended support.
- **Support window:** the latest minor of the current major receives fixes. After a new major ships, the last minor of the previous
  major receives security fixes only for **12 months**. Before 1.0.0 only the latest 0.x tag is supported (unchanged from `SECURITY.md`).

## Consequences

- Editions can accept any `1.x` after reading the changelog and running the checklist; they no longer re-pin per release.
- The cost of being wrong is high after 1.0: a mistake in an `@api` signature can only be corrected additively until 2.0. That is why
  the freeze review (`docs/api-freeze-review.md`) precedes the release candidate.
- Contributors must tag every new public type `@api` or `@internal` in the same pull request; an untagged type is treated as `@internal`.
- Adding a method to an edition-implemented interface is off the table for all of 1.x; features that need it ship as companion interfaces.

## Reversal cost

Low before 1.0.0 (nothing has been promised; this ADR can be edited). After 1.0.0 it is a promise to downstream consumers: loosening it
(for example allowing breaking changes in minors) is a breaking change in itself and would need a new major plus a migration note for
both editions and any outside users. Tightening it (a longer deprecation window, a longer security-support window) is cheap and
backward compatible.
