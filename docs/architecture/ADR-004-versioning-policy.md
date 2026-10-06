# ADR-004: Versioning and backward-compatibility policy

Status: proposed (written for issue #19; numbered 004 because ADR-003 is the authorization contract). Needs the owner's sign-off, and
interacts with the open decisions #45 to #49.

## Context

RivetCore is consumed by two editions (RivetIT, RivetMSP) through Git tags (and, if decision #46 says yes, Packagist). In `0.x`
Composer's caret (`^0.21`) accepts only the same minor, so every minor is a deliberate upgrade and may break. After 1.0 the caret
accepts every `1.x`, so the promise has to be precise and mechanically checked.

## Decision (proposed)

1. **Semantic versioning from 1.0.** `MAJOR.MINOR.PATCH`.
   - **Major:** any change that can break a correct edition: removing or changing a public type, method signature, constant value,
     table, column (name, type, nullability), webhook event id or wire-format header; changing the meaning of a migration; raising
     the minimum PHP or a dependency's major version; dropping a database or PHP version from the supported matrix.
   - **Minor:** additions (types, methods with defaults, tables, nullable or defaulted columns, event ids, presets), deprecations,
     raising a dependency's minor, adding a PHP version to the matrix.
   - **Patch:** fixes that make behaviour match its documentation, security hardening that rejects input that was never valid, and
     documentation. A patch never adds public surface.
2. **What is public** is defined in [PUBLIC-API.md](../PUBLIC-API.md) and recorded in `tests/api-surface.json`. `@internal` is
   outside the promise. Stability levels are Stable and Provisional; the goal is none Provisional at `rc.1`.
3. **Enforcement.** `scripts/api-surface-check.php` runs in CI and fails on any difference from the snapshot, labelled BREAKING or
   ADDITIVE; the author updates the snapshot in the same pull request and records the change in the changelog. A BREAKING line in a
   non-major release is a failed review. The Roave check stays as a second opinion.
4. **Deprecation.** A deprecated API stays for at least two minor releases and is listed under "Deprecated" in the changelog with the
   replacement. It is marked `@deprecated` in code. Removal happens only in a major.
5. **Migrations** are forward-only, additive, idempotent and recorded by id in `rivet_core_migrations`; an id and its meaning never
   change, and a migration is never edited after release (fix forward with a new one). A rollback is the edition's database backup plus
   a version pin, not a down migration.
6. **Supported versions.** The latest minor of the current major, plus security fixes for the previous major's last minor for 12 months
   after a new major. PHP: every version still receiving upstream security fixes; dropping one is announced one minor ahead and is a
   major. Databases: per decision #48.
7. **Pre-1.0.** A minor may break; the changelog says so, the snapshot diff is attached to the release, and the edition's constraint
   is bumped explicitly (see [UPGRADING.md](../UPGRADING.md)).
8. **Release train.** Editions pin an exact tag (never a branch); a release is cut from `main` after the changelog, the CI matrix, the
   API-surface check and an edition regression run are green.

## Consequences

- Authors pay a small cost per change (update the snapshot, write the changelog line) and get a review artifact for free.
- The guard cannot see behaviour or the shape of `array` values; those stay with tests and review (open questions in PUBLIC-API.md).
- Fixing a long-standing defect that editions may have worked around (for example the global migration lock name) must be judged
  against rule 1: if it changes observable behaviour of a Stable method, it is a minor with a changelog note, not a silent patch.

## Alternatives considered

- Calendar versioning: rejected, it hides break/no-break from Composer.
- Roave only: rejected as the sole gate, it cannot see tables, event ids or migrations, and needs PHP 8.4 to run.
- Down migrations: rejected, DDL auto-commits on MySQL/MariaDB so they cannot be made safe.
