<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Database\ExecutionResult;
use RivetCore\Rmm\Store\Postgres\Guard\WriteBehind;

/**
 * The control database the module was given, with one addition: it tells a {@see WriteBehind} when a transaction opens and when its outermost level ends, so history
 * writes to a hot store in ANOTHER database wait for the commit. Every call is passed through unchanged; with the hot store in the same database nothing is ever queued.
 *
 * @internal
 */
final class TrackedDatabase implements DatabaseInterface
{
    public function __construct(private readonly DatabaseInterface $inner, private readonly WriteBehind $behind)
    {
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->inner->fetchOne($sql, $params);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->inner->fetchAll($sql, $params);
    }

    public function execute(string $sql, array $params = []): ExecutionResult
    {
        return $this->inner->execute($sql, $params);
    }

    public function transaction(callable $callback): mixed
    {
        $this->behind->enter();
        try {
            $result = $this->inner->transaction($callback);
        } catch (\Throwable $e) {
            $this->behind->leave(false);

            throw $e;
        }
        $this->behind->leave(true);

        return $result;
    }
}
