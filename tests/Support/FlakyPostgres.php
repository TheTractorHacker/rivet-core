<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Store\Postgres\PostgresConnectionException;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;

/** A PostgreSQL connection that can be "switched off" in the middle of a test. */
final class FlakyPostgres implements PostgresConnectionInterface
{
    public bool $down = false;
    public int $refused = 0;

    public function __construct(private readonly PostgresConnectionInterface $inner)
    {
    }

    private function up(): void
    {
        if ($this->down) {
            ++$this->refused;
            throw new PostgresConnectionException('the server closed the connection unexpectedly');
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $this->up();

        return $this->inner->query($sql, $params);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $this->up();

        return $this->inner->queryOne($sql, $params);
    }

    public function value(string $sql, array $params = []): mixed
    {
        $this->up();

        return $this->inner->value($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->up();

        return $this->inner->execute($sql, $params);
    }

    public function transaction(callable $callback): mixed
    {
        $this->up();

        return $this->inner->transaction($callback);
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function serverVersion(): string
    {
        $this->up();

        return $this->inner->serverVersion();
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
