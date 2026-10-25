<?php

declare(strict_types=1);

// A CliBootstrap file for the tests of the store command line tools: the scratch MariaDB, and the scratch PostgreSQL when RIVETCORE_TEST_CLI_PG=1.
use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Testing\InMemorySecretBox;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Tests\Support\ScratchDb;

if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
    throw new RuntimeException('scratch database required');
}
$m = ScratchDb::connect();

return [
    'database' => new MysqliDatabase($m),
    'clock' => new MutableClock(),
    'secretBox' => new InMemorySecretBox(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
    'postgres' => getenv('RIVETCORE_TEST_CLI_PG') === '1' ? static fn () => PdoPostgresConnection::fromConfig(PostgresTestDatabase::config(['breaker_open_s' => 0])) : null,
];
