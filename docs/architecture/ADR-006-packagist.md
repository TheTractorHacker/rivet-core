# ADR-006: Packagist: not yet; editions keep VCS repositories; revisit at 1.1

Status: accepted (2026-10-06). Decision #46 in the ROADMAP (which recommended publishing after the first release candidate; this ADR
replaces that recommendation).

## Context

RivetCore is consumed through a Composer VCS repository entry pointing at the GitHub repository; every edition's `composer.json` has the
`repositories` block and `"no-api": true`. Packagist would let any outside developer run `composer require rivet/rivet-core`
without that block and would add download statistics and security-advisory matching.

Publishing on Packagist has costs that are not reversible in practice:

- **A name is a public commitment.** `rivet/rivet-core` becomes a package other people depend on. Packagist can mark a package
  abandoned or delete it, but versions that were installed and locked elsewhere are not recalled.
- **Tags become immutable in effect.** Packagist caches tag contents; moving or re-cutting a tag after it was fetched produces
  mismatched checksums for everyone who locked it. During the 1.0 release candidate a tag may still need to be re-cut.
- **The only consumers today are the two editions, both maintained by one person.** VCS repositories already give them exact tags and
  lock files. Nothing about the editions needs Packagist.
- **Outside users would arrive with 1.0 expectations** (support, issue response times) before the soak has shown the release is
  stable.

## Decision

1. Do not publish to Packagist for `1.0.0` or the release candidates. Editions keep their VCS repository entries.
2. Revisit when `1.1.0` is released and the 1.0 line has run in both productions without a re-cut tag and without an open P1 or P2 bug.
3. The package metadata is kept Packagist-ready in the meantime (valid `composer.json` name, license, `homepage`, `support`; `composer
   validate` is part of CI), so publishing later is a settings change, not a repository change.

### Exact steps to publish later

1. Confirm the vendor name: `rivet` must be free on Packagist (the vendor is claimed by the first account that submits a package
   under it). Create the Packagist account with the maintainer's address and enable two-factor authentication.
2. In the repository, confirm `composer validate --strict` passes and that `composer.json` has no `version` key (Packagist reads tags),
   `name` is `rivet/rivet-core`, `license` is `GPL-3.0-only`, and `.gitattributes` keeps tests and docs out of installs.
3. Check that no tag that will be published will ever be moved: tags are annotated `vMAJOR.MINOR.PATCH`; from now on a mistake is fixed by
   a new patch tag, never by moving a tag.
4. Submit the repository URL at <https://packagist.org/packages/submit>. Packagist reads the existing tags.
5. Enable automatic updates: install the Packagist GitHub App on the repository (preferred over a manually added webhook, so a
   token is not stored in the repository settings), or add the webhook shown on the package page.
6. Check the package page: license, PHP requirement, `suggest` and `require` lists, the README rendering and that the `docs/` and
   `tests/` directories are absent from a `composer require` install (`.gitattributes` `export-ignore`).
7. In a clean temporary directory, without any `repositories` block, run `composer require rivet/rivet-core:^1.0` on PHP 8.2 and 8.5 and
   run the quickstart.
8. Update the README and `docs/quickstart.md` install instructions to the plain `composer require`; keep the VCS repository
   instructions as a "from source" note.
9. In each edition, remove the `repositories` entry in a normal change, run `composer update rivet/rivet-core --no-install`, check that
   the lock file records the same commit reference as before (so nothing runs differently), run the edition checklist.
10. Register the package for security advisories: add the repository to GitHub's security advisories feature (a published GitHub
    advisory is picked up by `composer audit`), and note in `SECURITY.md` how a fix will be announced.
11. Announce in `CHANGELOG.md`.

## Consequences

- External contributors and users install from a VCS repository, which is a documented but less convenient route.
- No dependency on a third-party registry for the editions' builds, and no name squatting risk is resolved yet: someone else could
  register `rivet/rivet-core` on Packagist first. That is accepted; the vendor name is distinctive, and the publishing steps above
  include checking it.

## Reversal cost

Low until the first publish. After publishing, un-publishing is not clean: Packagist keeps the package page, installed locks continue to
resolve, and abandoned-marking is the polite exit. Delaying the decision costs nothing.
