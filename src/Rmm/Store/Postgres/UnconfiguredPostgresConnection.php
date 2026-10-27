<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

/**
 * The connection of an installation whose `rmm_data_store` is `postgres` but whose edition passed no PostgreSQL connection: every call fails at once with a
 * {@see PostgresConnectionException} that says exactly that. It exists so such an installation behaves like one whose PostgreSQL is down (check-ins keep working, history
 * writes are dropped and counted, log and change batches get a retryable 503) and never silently writes the hot data to MariaDB, where it would be invisible to the
 * store the administrator chose.
 *
 * @internal
 */
final class UnconfiguredPostgresConnection implements PostgresConnectionInterface
{
    public function __construct(private readonly string $reason = 'The RMM data store is set to "postgres" but no PostgreSQL connection was configured (the edition passes one as the "postgres" module option; see docs/rmm/POSTGRES.md).')
    {
    }

    public function query(string $sql, array $params = []): array
    {
        throw $this->fail();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        throw $this->fail();
    }

    public function value(string $sql, array $params = []): mixed
    {
        throw $this->fail();
    }

    public function execute(string $sql, array $params = []): int
    {
        throw $this->fail();
    }

    public function transaction(callable $callback): mixed
    {
        throw $this->fail();
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function serverVersion(): string
    {
        throw $this->fail();
    }

    public function close(): void
    {
    }

    private function fail(): PostgresConnectionException
    {
        return new PostgresConnectionException($this->reason);
    }
}
