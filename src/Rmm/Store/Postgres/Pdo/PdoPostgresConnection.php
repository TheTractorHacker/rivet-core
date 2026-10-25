<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Pdo;

use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionException;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresException;
use RivetCore\Rmm\Store\Postgres\PostgresQueryException;
use RivetCore\Rmm\Store\Postgres\PostgresSerializationException;

/**
 * {@see PostgresConnectionInterface} on `pdo_pgsql`: the only class in Core that touches PDO (the CI rule "Core must not reference PDO outside
 * `src/Rmm/Store/Postgres/Pdo`" keeps it that way). One lazily opened session per instance; true server-side prepared statements (no emulation); UTC session; statement,
 * lock and idle-in-transaction timeouts; every failure mapped by {@see PdoErrorMapper}; the session is dropped after a connection failure so the next call reconnects.
 *
 * Not supported: connection poolers in TRANSACTION mode (the session settings above are per session). Use a direct connection, a unix socket, or pooling in SESSION mode.
 *
 * @api
 */
final class PdoPostgresConnection implements PostgresConnectionInterface
{
    private ?\PDO $pdo = null;
    private int $depth = 0;

    public function __construct(private readonly PostgresConfig $config)
    {
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->queryOne($sql, $params);

        return $row === null ? null : array_values($row)[0];
    }

    public function execute(string $sql, array $params = []): int
    {
        return max(0, $this->run($sql, $params)->rowCount());
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->depth > 0) {
            return $this->savepoint($callback);
        }
        $attempts = $this->config->transactionRetries + 1;
        for ($attempt = 1;; ++$attempt) {
            $pdo = $this->pdo();
            try {
                $pdo->beginTransaction();
            } catch (\PDOException $e) {
                throw $this->failed($e);
            }
            $this->depth = 1;
            try {
                $result = $callback();
                $pdo->commit();
                $this->depth = 0;

                return $result;
            } catch (\Throwable $e) {
                $this->depth = 0;
                $this->rollback();
                $e = $e instanceof \PDOException ? $this->failed($e) : $e;
                if ($e instanceof PostgresSerializationException && $attempt < $attempts) {
                    usleep(random_int(5000, 25000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    public function serverVersion(): string
    {
        return (string) $this->value('SHOW server_version');
    }

    public function close(): void
    {
        $this->pdo = null;
        $this->depth = 0;
    }

    private function savepoint(callable $callback): mixed
    {
        $name = 'rc_sp_' . $this->depth;
        $this->exec('SAVEPOINT ' . $name);
        ++$this->depth;
        try {
            $result = $callback();
            --$this->depth;
            $this->exec('RELEASE SAVEPOINT ' . $name);

            return $result;
        } catch (\Throwable $e) {
            --$this->depth;
            try {
                if ($this->pdo !== null) {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $name);
                }
            } catch (\Throwable) {
                // the session is gone or the transaction is aborted: the outer transaction rolls back
            }
            throw $e instanceof \PDOException ? $this->failed($e) : $e;
        }
    }

    private function rollback(): void
    {
        if ($this->pdo === null) {
            return;
        }
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (\Throwable) {
            $this->pdo = null;   // the session cannot even roll back: drop it, the server rolls the transaction back when it notices
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function run(string $sql, array $params): \PDOStatement
    {
        $pdo = $this->pdo();
        try {
            $st = $pdo->prepare($sql);
            foreach (array_values($params) as $i => $v) {
                $st->bindValue($i + 1, self::scalar($v), match (true) {
                    $v === null => \PDO::PARAM_NULL,
                    is_bool($v) => \PDO::PARAM_BOOL,
                    is_int($v) => \PDO::PARAM_INT,
                    default => \PDO::PARAM_STR,
                });
            }
            $st->execute();

            return $st;
        } catch (\PDOException $e) {
            throw $this->failed($e);
        }
    }

    private function exec(string $sql): void
    {
        $pdo = $this->pdo();
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            throw $this->failed($e);
        }
    }

    private static function scalar(mixed $v): string|int|bool|null
    {
        if ($v === null || is_bool($v) || is_int($v) || is_string($v)) {
            return $v;
        }
        if (is_float($v)) {
            return is_nan($v) ? 'NaN' : (is_infinite($v) ? ($v > 0 ? 'Infinity' : '-Infinity') : (string) $v);
        }
        throw new PostgresQueryException('A query parameter must be a string, int, float, bool or null.');
    }

    private function failed(\PDOException $e): PostgresException
    {
        $mapped = PdoErrorMapper::map($e, $this->config->password());
        if ($mapped instanceof PostgresConnectionException) {
            $this->pdo = null;   // reconnect on the next call
            $this->depth = 0;
        }

        return $mapped;
    }

    private function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (!extension_loaded('pdo_pgsql')) {
            throw new PostgresConnectionException('The PHP extension pdo_pgsql is not installed, so the PostgreSQL data store cannot connect.');
        }
        try {
            $pdo = new \PDO($this->config->dsn(), $this->config->user, $this->config->password(), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => $this->config->connectTimeoutS,
                \PDO::ATTR_PERSISTENT => false,
            ]);
            $pdo->exec("SET TIME ZONE 'UTC'; SET client_min_messages TO warning; SET statement_timeout = " . $this->config->statementTimeoutMs . '; SET lock_timeout = ' . $this->config->lockTimeoutMs
                . '; SET idle_in_transaction_session_timeout = 60000; SET search_path TO "' . $this->config->schema . '"');
        } catch (\PDOException $e) {
            $mapped = PdoErrorMapper::map($e, $this->config->password());
            // a session that cannot be set up is as good as no session, whatever the server called it
            throw $mapped instanceof PostgresConnectionException ? $mapped : new PostgresConnectionException('PostgreSQL session could not be opened: ' . $mapped->getMessage(), $mapped->sqlState(), $e);
        }

        return $this->pdo = $pdo;
    }
}
