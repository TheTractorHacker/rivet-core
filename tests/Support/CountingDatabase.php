<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Database\ExecutionResult;

/** Counts the statements that reach the database (to prove what a code path does NOT touch it for). */
final class CountingDatabase implements DatabaseInterface
{
    public int $statements = 0;

    public function __construct(private readonly DatabaseInterface $inner)
    {
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        ++$this->statements;

        return $this->inner->fetchOne($sql, $params);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        ++$this->statements;

        return $this->inner->fetchAll($sql, $params);
    }

    public function execute(string $sql, array $params = []): ExecutionResult
    {
        ++$this->statements;

        return $this->inner->execute($sql, $params);
    }

    public function transaction(callable $callback): mixed
    {
        ++$this->statements;

        return $this->inner->transaction($callback);
    }
}
