# ADR-009: Test helpers stay in the package

Status: accepted (2026-10-06). Decision #49 in the ROADMAP (which recommended splitting; this ADR reverses that recommendation).

## Context

`RivetCore\Testing\DatabaseContractTestCase` ships in `src/` and is used by both editions' test suites to prove that their
`DatabaseInterface` adapter behaves like the reference one. It extends PHPUnit, so the runtime package contains a class whose parent is
absent in production installs. The roadmap proposed moving it to a `rivet-core-testing` development package. The conformance kit
work extends the idea: more shared helpers (fake collaborators and per-contract test cases) that editions run in their own CI so
that a Core release cannot be tagged if an edition adapter would fail it.

## Decision

1. The helpers stay in the main package under `RivetCore\Testing`. They are **part of the public API** as the *adapter conformance
   kit*: tagged `@api`, covered by semver, documented in [docs/adapters.md](../adapters.md), and run by every edition's CI.
2. **PHPUnit is a dev dependency of Core and should be listed as a `suggest` entry** (`composer.json` has no `suggest` block today; adding one is a one-line change for the release owner), never a `require`: production installs do not load anything
   from `Testing\`; an edition that wants the kit installs `phpunit/phpunit` as its own dev dependency. The classes are only
   autoloaded when a test references them, so a production install carries unused files and no failures.
3. The kit must not call anything that needs a particular test framework version beyond the PHPUnit major that Core's own suite uses; a
   PHPUnit major bump follows ADR-004 (it is announced in a minor if it is only a `suggest` constraint change, otherwise a major).
4. **Revisit a separate package only if the kit grows beyond about 20 files** (today it is a handful), or if a second consumer
   outside the two editions needs a different PHPUnit major than Core's.
5. `.gitattributes` keeps `tests/` and `docs/` out of exported archives but `src/Testing/` ships, because it is source.

## Consequences

- One repository, one tag, one version to pin: an edition cannot run a kit that is out of step with the Core it tests.
- A few kilobytes of test code are installed in production vendor directories. Accepted.
- The BC check covers the kit, so a change to a conformance test is a visible API event: tightening a test is a minor only if every
  correct adapter still passes (otherwise it needs a major, or an opt-in flag).

## Reversal cost

Moderate. Splitting later means a new repository and package, a deprecation period in which the classes exist in both places (ADR-004
applies), and a constraint change in both editions' dev dependencies; no runtime code is affected. The trigger (about 20 files) is
chosen so the split happens before the cost grows.
