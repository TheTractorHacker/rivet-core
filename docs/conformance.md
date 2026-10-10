# Adapter conformance kit

Core is only useful if every edition's adapters behave the way Core's docblocks promise. The conformance kit turns those
promises into abstract PHPUnit test cases (`RivetCore\Testing\*ConformanceTestCase`). An edition extends each case for
the adapters it has, implements a few factory methods, and runs the result in its own CI against a scratch MariaDB and a
throwaway Redis. Core's release process runs the same cases against both editions before a tag is cut, so a Core release
cannot ship with a contract an adapter would fail (issue #38).

PHPUnit stays a dev dependency: the kit classes extend `PHPUnit\Framework\TestCase` and can only be loaded where PHPUnit is
installed (Core's own checkout, or the checkout the edition bootstraps, see "Wiring"). Nothing in `src/` outside
`src/Testing/` refers to them.

## The cases

| Case | Interface | You implement | What it checks |
|---|---|---|---|
| `DatabaseContractTestCase` | `Database\DatabaseInterface` | `database()` | insert ids and affected rows, parameter types, no interpolation, transactions, error mapping (unchanged, older than the rest of the kit) |
| `RequestContextConformanceTestCase` | `Contracts\RequestContextInterface` | `context()` | no exception with no request at all (CLI/cron); every field null or sane (IP literal of at most 45 characters, request id 1-64 characters of `[A-Za-z0-9._:-]`, user agent without control characters); request id stable within a request, also across two contexts; a client-supplied `X-Request-ID` is never used |
| `SettingsConformanceTestCase` | `Contracts\SettingsInterface` | `settings()`, optionally `seededSettings()` | unknown key returns the caller's default unchanged (null, string, int, float, bool, array, no coercion); odd keys (empty, SQL, NUL byte, 5000 characters, unicode) never throw; stored values come back even when falsy (`'0'`, `''`); the default never leaks into a stored value; reads are repeatable |
| `ClockConformanceTestCase` | `Contracts\ClockInterface` | `clock()`, optionally `isRealTime()`, `expectedTimezone()` | immutable instant; never goes backwards over 200 calls; within 5 s of the system clock (catches a wall-clock string read in the wrong zone); valid timezone that round-trips |
| `RedisClientProviderConformanceTestCase` | `Redis\RedisClientProviderInterface` | `provider()`, `unreachableProvider()` | pointed at a closed port `client()` returns null, does not throw and returns fast (runs in its own PHP process, see below); with Redis up it returns a Predis client that pings, does set/get/del/exists, TTL, counters and `SETNX`; keys under different prefixes do not collide; a second `client()` call reaches the same database. Uses unique `rc_conf:` keys and deletes them: never flushes |
| `WebhookSubscriptionsConformanceTestCase` | `Webhooks\WebhookSubscriptionsInterface` + `WebhookSubscriptionLookupInterface` | `subscriptions()`, `storeSubscription()`, `deleteSubscription()`, optionally `supportsEventPatterns()` | enabled endpoints for an event with id, URL and decrypted secret; endpoints for other events excluded; disabled and deleted endpoints excluded; near-miss names (`ticket.creat`, `ticket.created_later`, `ticket.%`, `ticket._reated`, quotes, commas, NUL, 5000 characters) match nothing; `*` matches every event, `ticket.*` its group only (also for events the catalog does not list); patterns and plain ids can mix; `find()` returns an enabled endpoint consistent with `forEvent()` and null for unknown, disabled and deleted ids (skipped when the adapter has no `find()`) |
| `TicketProblemLinkConformanceTestCase` | `ITSM\TicketProblemLinkInterface` | `links()`, `createTicket()`, `linkedProblemId()`, optionally `deleteTicket()`, `newProblemId()` | link attaches; linking twice is harmless; linking to another problem replaces the link; unlink removes only the link to that problem; unlink of an unlinked ticket is harmless; other tickets are never touched; an unknown ticket id never throws and creates nothing |
| `AgentDirectoryConformanceTestCase` | `Mcp\AgentDirectoryInterface` | `directory()`, `storeAgent()`, `deleteAgent()` | active unlinked agents are linkable (with `user_id`, `user_name`, `user_email`); inactive agents are neither linkable nor found; unknown ids give null; link moves an agent to the linked list and `linkedCount()`, makes the pair taken and `findActiveAgent()['linked']` true; the identity is the (issuer, subject) pair; unlink reverses everything; link/unlink for unknown users are no-ops; hostile strings are plain data. Compares deltas, so a scratch DB with other users is fine. Does not exercise `FOR UPDATE` row locking |
| `AccessPolicyConformanceTestCase` | `Contracts\AccessPolicyInterface` | `policy()`, optionally `declaresDenyByDefault()`, `knownAllowed()`, `knownDenied()` | `can()` returns a bool and never throws for any user (null = system actor, negative, huge), ability (empty, 800 characters, unicode, SQL), subject type/id (unknown type, `'abc'`, `PHP_INT_MAX`, SQL) or context; deterministic; the edition's sample decisions hold, also through `AccessDenied::unless()`. When `declaresDenyByDefault()` is true: unknown abilities are denied for every user including the system actor, and no context value (`is_admin`, `role`, ...) can elevate them |
| `AttestationProviderConformanceTestCase` | `Compliance\AttestationProviderInterface` | `provider()`, `storeAttestation()`, `deleteAttestations()` | unreviewed item absent; entry has exactly `reviewed_on`, `next_due_on`, `reviewer_name`, `note` with `Y-m-d` dates and real nulls (not `''`); the review recorded last wins, also on the same date; items are independent |
| `RmmTenancyConformanceTestCase` | `Rmm\Contracts\RmmTenancyInterface` | `tenancy()`, `createClient()`, `createLocation()`, `restrictUser()`, optionally `newUserId()` | client name or null for unknown; a location belongs only to its own client; no restriction is `null`, restriction to nothing is `[]`, never client 0; per-user isolation; odd ids never throw |
| `RmmAssetsConformanceTestCase` | `Rmm\Contracts\RmmAssetsInterface` | `assets()`, `createAsset()`, `readAsset()` | serial exact, MAC ignoring case and `-`/`:` (one row per asset), hostname case-insensitive, archived assets excluded, limits and row shape, `find()`, `createForDevice()` (Server/Laptop, Active, "Windows <os>"), `fillBlanks()` never overwrites, `moveToClient()` moves only that asset |
| `RmmBridgeConformanceTestCase` | `Rmm\Contracts\RmmBridgeInterface` | `bridge()`, `createAsset()`, `readLink()`, `backdateStatusChange()`, `readAlert()`, `countAlerts()`, `createScript()`, `readRemoteSessions()` | integration idempotent per type; link upsert/move/remove; `applyHealth()` false without a link and moves the status-change time only on a change; `markOffline()` flips only online links of the given keys; alerts idempotent per (integration, key), resolve once, reassign only open alerts of the asset; only enabled PowerShell scripts; session log fields as given |
| `SecretBoxConformanceTestCase` | `Rmm\Contracts\SecretBoxInterface` | `box()` | round trip (short, unicode, binary, 10 KB), ciphertext hides the plaintext, `decrypt()` of garbage or a truncated value is `''` and never throws |
| `RmmMetricSinkConformanceTestCase` | `Rmm\Contracts\RmmMetricSinkInterface` | `sink()`, optionally `stored()` | never throws; when samples are observable: valid kept, out-of-range utilization dropped not clamped |
| `RmmMetricReaderConformanceTestCase` | `Rmm\Contracts\RmmMetricReaderInterface` (on the same object as the sink) | `store()` | newest reading wins whatever the arrival order; keys and instances separate; `peak()` min/max/mean of an hour-granular window that excludes earlier hours; `series()` oldest first, one point per hour; out-of-range values never appear |
| `RmmEventsConformanceTestCase` | `Rmm\Contracts\RmmEventsInterface` | `events()`, optionally `received()` | accepts every `RmmEvent` id with the documented payload; when observable: payloads arrive unchanged (null and false kept) and in order |
| `RmmAuditConformanceTestCase` | `Rmm\Contracts\RmmAuditInterface` | `audit()`, optionally `recorded()` | never throws for empty, long, unicode or hostile text; recorded values read back |
| `RmmModuleStateConformanceTestCase` | `Rmm\Contracts\RmmModuleStateInterface` | `state()` | `editionAllows()` is a repeatable bool; `stateDirectory()` is null or an existing writable absolute directory, stable |

`MigrationInterface` and `CheckInterface` are not covered: migrations are exercised by Core's own migration tests and checks
are edition catalogue entries.

## Wiring it in an edition

The edition's `tests/core/` already has a bootstrap that loads a Core checkout carrying PHPUnit
(`RIVETCORE_PHPUNIT_AUTOLOAD=/path/to/rivet-core/vendor/autoload.php`) and a scratch database
(`RIVETCORE_TEST_DB_{NAME,USER,PASS,HOST}`; never production). Add `RIVETCORE_TEST_REDIS_PORT` for a throwaway Redis. The
kit comes from that checkout, so check it out at the Core tag you are about to release (or the one you pin).

Each case is one small class. Seed through your own tables and use your own adapters:

```php
<?php
// tests/core/WebhookSubscriptionsConformanceTest.php (RivetMSP; RivetIT is identical with the ITFlow\Core\Adapter namespace)
declare(strict_types=1);

use RivetCore\Testing\WebhookSubscriptionsConformanceTestCase;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;
use RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter;
use RivetMSP\Core\Adapter\Webhooks\WebhooksTableSubscriptions;

final class WebhookSubscriptionsConformanceTest extends WebhookSubscriptionsConformanceTestCase
{
    private function db(): MysqliDatabaseAdapter
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $name = getenv('RIVETCORE_TEST_DB_NAME') ?: $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        $m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
        $m->set_charset('utf8mb4');

        return new MysqliDatabaseAdapter($m);
    }

    protected function subscriptions(): WebhookSubscriptionsInterface
    {
        return new WebhooksTableSubscriptions($this->db());
    }

    protected function storeSubscription(string $url, string $secret, array $events, bool $enabled): int
    {
        // seed the way the admin page does: secret encrypted with encryptSetting(), events as a comma list
        return (int) $this->db()->execute(
            'INSERT INTO webhooks (webhook_url, webhook_secret, webhook_events, webhook_enabled) VALUES (?, ?, ?, ?)',
            [$url, encryptSetting($secret), implode(', ', $events), $enabled ? 1 : 0]
        )->insertId;
    }

    protected function deleteSubscription(int $webhookId): void
    {
        $this->db()->execute('DELETE FROM webhooks WHERE webhook_id = ?', [$webhookId]);
    }

    // An edition whose admin page never stores "*" or "ticket.*" may opt out (RivetIT's FIND_IN_SET matching today):
    // protected function supportsEventPatterns(): bool { return false; }
}
```

The other cases follow the same shape. The ones both editions need:

```php
// Request context: nothing to seed. The default applyRequest()/clearRequest() write $_SERVER; override them if your
// adapter prefers other globals (RivetMSP reads $GLOBALS['session_ip'] first; clearRequest() already clears it).
final class RequestContextConformanceTest extends RequestContextConformanceTestCase
{
    protected function context(): RequestContextInterface { return new \RivetMSP\Core\Adapter\Http\ServerRequestContext(); }
}

// Ticket <-> problem link: the case needs to create a ticket and read the link back from your own table.
final class TicketProblemLinkConformanceTest extends TicketProblemLinkConformanceTestCase
{
    protected function links(): TicketProblemLinkInterface { return new TicketsProblemLink($this->db()); }
    protected function createTicket(): int { return (int) $this->db()->execute('INSERT INTO tickets (ticket_subject) VALUES (?)', ['conformance'])->insertId; }
    protected function linkedProblemId(int $ticketId): ?int
    {
        $r = $this->db()->fetchOne('SELECT ticket_problem_id FROM tickets WHERE ticket_id = ?', [$ticketId]);

        return $r === null || $r['ticket_problem_id'] === null ? null : (int) $r['ticket_problem_id'];
    }
    protected function deleteTicket(int $id): void { $this->db()->execute('DELETE FROM tickets WHERE ticket_id = ?', [$id]); }
}

// Redis: the SAME provider class, once pointed at the throwaway Redis, once at a port nothing listens on.
final class RedisProviderConformanceTest extends RedisClientProviderConformanceTestCase
{
    protected function provider(): RedisClientProviderInterface
    {
        putenv('RIVETMSP_REDIS_HOST=127.0.0.1');
        putenv('RIVETMSP_REDIS_PORT=' . getenv('RIVETCORE_TEST_REDIS_PORT'));

        return new GlobalRedisClientProvider();
    }
    protected function unreachableProvider(): RedisClientProviderInterface
    {
        putenv('RIVETMSP_REDIS_PORT=' . self::closedPort());

        return new GlobalRedisClientProvider();
    }
}
```

RivetIT additionally extends `AgentDirectoryConformanceTestCase` (`directory()` returns `UsersAgentDirectory`;
`storeAgent()` inserts a `users` row with `user_type = 1` and `user_status` 1 or 0; `deleteAgent()` deletes it) and RivetMSP
`SettingsConformanceTestCase` (`SettingsTableSettings` over the `settings` row; seed `config_core_audit_enabled = 0` and
`config_core_jobs_enabled = 1` in `seededSettings()` so a falsy stored value is exercised). An edition with a `ClockInterface`,
`AccessPolicyInterface` or `AttestationProviderInterface` adapter adds the matching case.

Things worth knowing:

* **The Redis "down" check runs in its own process** (`#[RunInSeparateProcess]`). Editions resolve host and port once and cache
  the connection or the failure in a static, so a dead and a live Redis cannot share a process. Configure the closed port
  inside `unreachableProvider()`, as above.
* **Webhook, ticket, agent and attestation cases create their own rows** (and delete them in `tearDown()`), and only reason about
  the ids they created, so other rows in the scratch database do not disturb them. Never run them against production.
* **Where your edition legitimately differs, override a hook, do not skip the file.** Hooks are `protected` methods with a
  safe default (`supportsEventPatterns()`, `declaresDenyByDefault()`, `isRealTime()`, `expectedTimezone()`, `redisAvailable()`,
  `newProblemId()`). A hook that turns a rule off is a visible, reviewable decision in the edition's repository.
* Each case that cannot do its job in the current environment calls `markTestSkipped()` with the reason; a skipped
  conformance test in CI means "not verified", so keep the CI job's services (below) up so nothing silently skips.

## Running it in CI

Proposed `.github/workflows/core-conformance.yml` for an edition (do not copy the `RIVETCORE_REF`: set it to the Core tag
under test; a Core release pipeline runs the same job with the release candidate):

```yaml
name: Core conformance

on:
  pull_request:
  workflow_dispatch:
    inputs:
      core_ref:
        description: 'rivet-core tag or branch to verify (default: the version pinned in composer.json)'
        required: false

permissions:
  contents: read

jobs:
  conformance:
    runs-on: ubuntu-latest

    services:
      mariadb:
        image: mariadb:latest
        ports: ['3306:3306']
        env:
          MARIADB_ROOT_PASSWORD: rootpw
          MARIADB_USER: user
          MARIADB_PASSWORD: password
          MARIADB_DATABASE: rivetcore_scratch_conf
        options: >-
          --health-cmd="healthcheck.sh --connect --innodb_initialized"
          --health-interval=10s --health-timeout=5s --health-retries=5
      redis:
        image: redis:7
        ports: ['6379:6379']
        options: >-
          --health-cmd="redis-cli ping" --health-interval=10s --health-timeout=5s --health-retries=5

    env:
      RIVETCORE_TEST_DB_HOST: 127.0.0.1
      RIVETCORE_TEST_DB_NAME: rivetcore_scratch_conf
      RIVETCORE_TEST_DB_USER: user
      RIVETCORE_TEST_DB_PASS: password
      RIVETCORE_TEST_REDIS_PORT: 6379
      RIVETMSP_REDIS_ENV_FILE: /nonexistent          # RivetIT: RIVETIT_REDIS_ENV_FILE
      RIVETCORE_PHPUNIT_AUTOLOAD: ${{ github.workspace }}/rivet-core/vendor/autoload.php

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mysqli, openssl, mbstring, gd, curl
          tools: composer:v2

      - name: Install the edition
        run: composer install --no-interaction --prefer-dist

      - name: Check out rivet-core (it carries PHPUnit and the kit)
        run: |
          REF="${{ github.event.inputs.core_ref }}"
          if [ -z "$REF" ]; then REF="$(composer show rivet/rivet-core --format=json | php -r 'echo json_decode(stream_get_contents(STDIN))->versions[0];')"; fi
          git clone --depth 1 --branch "$REF" https://github.com/TheTractorHacker/rivet-core.git rivet-core
          composer install --no-interaction --prefer-dist --working-dir=rivet-core

      - name: Load the edition schema into the scratch database
        run: mysql --host 127.0.0.1 -uroot -prootpw rivetcore_scratch_conf < db.sql

      - name: Run the conformance suite
        run: rivet-core/vendor/bin/phpunit -c tests/core/phpunit.xml --fail-on-skipped
```

`--fail-on-skipped` makes a silently unverified rule fail the job (the Redis and database cases skip when their services are
missing). Drop it only for a case you deliberately cannot run, and say why in the workflow file. The existing
`tests/core/phpunit.xml` already picks up every `*Test.php` in the directory, so the conformance classes need no extra
configuration. The edition's `db.sql` is the schema the seeds write to; a Core release candidate that changes a table an
adapter touches therefore shows up here.

Run locally the same way:

```bash
export RIVETCORE_PHPUNIT_AUTOLOAD=/path/to/rivet-core/vendor/autoload.php
export RIVETCORE_TEST_DB_NAME=myedition_scratch_conf RIVETCORE_TEST_DB_USER=... RIVETCORE_TEST_DB_PASS=...
redis-server --port 6388 --save "" --appendonly no &       # throwaway; kill it afterwards
export RIVETCORE_TEST_REDIS_PORT=6388
vendor/bin/phpunit -c tests/core/phpunit.xml
```

## Core proves the kit too

`tests/Conformance/` (suite `conformance` in `phpunit.xml.dist`) runs every case against

* **reference adapters** that are correct (Core's `SystemClock`, `FixedClock`, `ArraySettings`, `NullRequestContext`,
  `AllowAllPolicy`, `DenyAllPolicy`, the real `AttestationStore` on a scratch DB, plus in-memory webhook, ticket, agent,
  attestation and policy stores and a probing Redis provider in `tests/Conformance/Reference/`): all rules must pass;
* **mutants**: the same reference adapters with one named flaw each (`includes_disabled`, `prefix_match`, `no_patterns`,
  `trust_client_id`, `throws_when_down`, `falsy_to_default`, `system_bypass`, `first_wins`, ...). `KitMutationTest` runs the
  case against the mutant through `Harness` and asserts that the test named for that rule fails. A rule that no mutant can
  break is not really being checked.

`Harness::run($caseClass, $flaw)` calls `setUp()`, each test method (expanding data providers) and `tearDown()` directly and
returns which tests passed, failed or skipped. It deliberately does not use `TestCase::runBare()`, which would report the
inner failure to the running PHPUnit session and fail the outer test as well. The reference case classes use the `Flaw`
trait (`public static ?string $flaw`) so the harness can switch the flaw on.

To add a rule to a case: write the test in the case, add a flaw to the matching reference adapter that violates it, add a row to
`KitMutationTest::mutants()` naming the test that must fail, and run `vendor/bin/phpunit --testsuite conformance`
(set `RIVETCORE_TEST_DB_*` and `RIVETCORE_TEST_REDIS_PORT` to include the database and Redis cases).

## Versioning

The kit lives in Core, so it is versioned with Core.

* **Adding a case or a rule is a minor release** (0.N.0 -> 0.N+1.0). A new rule that an adapter written to the older docblocks
  could fail must ship **opt-in**: a protected `supportsXxx()` hook that returns `false` by default (or a new case an edition
  has to extend before it runs). The release notes name the hook, and the edition flips it on when its adapter conforms.
* **Making an opt-in rule the default, or tightening what an existing rule accepts, is a major release.** Until then a Core
  release cannot break an edition that conformed to the previous release.
* **Fixing a test that was wrong** (it demanded something the interface docblock never said) is a patch release, and the
  docblock is the reference: a rule must be traceable to the interface docblock that states it. Where a contract was
  under-specified the docblock is tightened first (the release that introduced the kit tightened `RequestContextInterface`, `SettingsInterface`,
  `ClockInterface`, `TicketProblemLinkInterface`, `WebhookSubscriptionsInterface`/`LookupInterface`, `RedisClientProviderInterface`,
  `AgentDirectoryInterface`, `AccessPolicyInterface` and `AttestationProviderInterface`).
* Hooks already shipped keep their name and default. Factory methods (`abstract`) are only added in a major release, because
  adding one breaks every subclass.
* The classes are `@api` for their public/protected surface (hook names, factory signatures, test method names edition
  suites may filter on). The exact assertions inside a test are not: they get stricter as Core learns what editions get wrong.
  PHPUnit itself is a dev dependency; the kit targets PHPUnit ^11.5 (attributes, not annotations).

## Release gate

Before tagging Core: run the edition conformance workflow with `core_ref` set to the release candidate in both RivetIT and
RivetMSP. A failure is either an adapter bug (fix the edition) or a wrongly strict rule (fix or opt-out the rule in Core
first); never tag over a red conformance run.
