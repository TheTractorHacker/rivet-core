# Contributing

Thanks for helping. A few rules keep Core usable by both editions.

1. **Core is edition-neutral.** No `mysqli`, `$GLOBALS`, `$_SESSION`, `$_SERVER`, or edition function names in `src/`
   (CI greps for them). Talk to storage only through `DatabaseInterface`, to time through `ClockInterface`, and so on.
2. **Migrations are additive and idempotent** and touch only Core-owned tables. New migration = new file in
   `src/Migration`, registered in `CoreMigrations`, and the editions bump their own DB version to include it.
3. **Every module is off by default** in an edition until it switches it on; Redis is optional and everything fails open.
4. **Tests.** `composer install && vendor/bin/phpunit`. Integration tests need a scratch MariaDB/MySQL database
   (`RIVETCORE_TEST_DB_*`, the database name must be a throwaway) and optionally Redis (`RIVETCORE_TEST_REDIS_PORT`);
   they skip themselves when those are not set. Static analysis: `vendor/bin/phpstan analyse`.
5. **Changelog.** Add a line under a new version heading in `CHANGELOG.md`; the tag's GitHub Release is cut from it.
6. **Versioning.** Semantic versioning; in `0.x` a minor may break an API, and the changelog says so.

Open an issue before large changes. By contributing you agree your work is licensed GPL-3.0-only.
