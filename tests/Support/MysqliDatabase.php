<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Database\DatabaseException;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Database\ExecutionResult;

/**
 * Test-only mysqli implementation of DatabaseInterface. Production adapters
 * live in each edition (RivetIT, RivetMSP); keep behaviour identical to them.
 */
final class MysqliDatabase implements DatabaseInterface
{
    private int $depth = 0;

    public function __construct(private \mysqli $mysqli)
    {
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        [$rows] = $this->run($sql, $params);

        return $rows[0] ?? null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        [$rows] = $this->run($sql, $params);

        return $rows;
    }

    public function execute(string $sql, array $params = []): ExecutionResult
    {
        [, $result] = $this->run($sql, $params);

        return $result;
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->depth > 0) {
            // Nested call joins the outer transaction; no savepoints.
            return $callback();
        }

        $this->guard(fn () => $this->mysqli->begin_transaction());
        $this->depth = 1;
        try {
            $value = $callback();
            $this->guard(fn () => $this->mysqli->commit());

            return $value;
        } catch (\Throwable $e) {
            try {
                $this->mysqli->rollback();
            } catch (\Throwable) {
                // keep the original failure
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    /** @return array{0: list<array<string,mixed>>, 1: ExecutionResult} */
    private function run(string $sql, array $params): array
    {
        try {
            $stmt = $this->mysqli->prepare($sql);
            if ($stmt === false) {
                throw new DatabaseException('Prepare failed: ' . $this->mysqli->error, (int) $this->mysqli->errno);
            }
            try {
                if ($params !== []) {
                    $values = array_values($params);
                    $types = '';
                    foreach ($values as $i => $v) {
                        if (is_bool($v)) {
                            $values[$i] = (int) $v;
                            $types .= 'i';
                        } elseif (is_int($v)) {
                            $types .= 'i';
                        } elseif (is_float($v)) {
                            $types .= 'd';
                        } elseif ($v === null || is_string($v)) {
                            $types .= 's';
                        } else {
                            throw new \InvalidArgumentException('Unsupported parameter type: ' . get_debug_type($v));
                        }
                    }
                    $stmt->bind_param($types, ...$values);
                }
                if (!$stmt->execute()) {
                    throw new DatabaseException('Execute failed: ' . $stmt->error, (int) $stmt->errno);
                }
                $result = $stmt->get_result();
                $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
                $insertId = $stmt->insert_id > 0 ? (int) $stmt->insert_id : null;
                $affected = $result === false ? max(0, (int) $stmt->affected_rows) : count($rows);

                return [$rows, new ExecutionResult($affected, $insertId)];
            } finally {
                $stmt->close();
            }
        } catch (DatabaseException | \InvalidArgumentException $e) {
            throw $e;
        } catch (\mysqli_sql_exception $e) {
            throw new DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private function guard(callable $fn): void
    {
        try {
            if ($fn() === false) {
                throw new DatabaseException('Transaction control failed: ' . $this->mysqli->error);
            }
        } catch (\mysqli_sql_exception $e) {
            throw new DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
