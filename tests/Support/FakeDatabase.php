<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Database\ExecutionResult;

/** Records every call; returns canned rows. For unit tests of Core services. */
final class FakeDatabase implements DatabaseInterface
{
    /** @var list<array{sql:string,params:array}> */
    public array $calls = [];
    /** @var list<array<string,mixed>> */
    public array $rows = [];
    public ?\Throwable $failWith = null;

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->record($sql, $params);

        return $this->rows[0] ?? null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->record($sql, $params);

        return $this->rows;
    }

    public function execute(string $sql, array $params = []): ExecutionResult
    {
        $this->record($sql, $params);

        return new ExecutionResult(1, 1);
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }

    private function record(string $sql, array $params): void
    {
        if ($this->failWith) {
            throw $this->failWith;
        }
        $this->calls[] = ['sql' => $sql, 'params' => $params];
    }
}
