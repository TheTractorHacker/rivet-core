<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Pdo;

use RivetCore\Rmm\Store\Postgres\CircuitBreakerConnection;
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
    /** Statements kept per session: preparing is a round trip, executing is another, and PDO deallocates a statement it drops with a third. The hot paths repeat a handful of statements. */
    private const STATEMENT_CACHE = 64;

    private ?\PDO $pdo = null;
    private int $depth = 0;
    /** @var array<string,\PDOStatement> sql => prepared statement of the current session, oldest first */
    private array $statements = [];

    public function __construct(private readonly PostgresConfig $config)
    {
    }

    /**
     * The connection an edition should hand to the module: this adapter behind the shared {@see CircuitBreakerConnection}, so an outage costs one connect timeout per
     * window instead of one per check-in.
     */
    public static function fromConfig(PostgresConfig $config): PostgresConnectionInterface
    {
        return new CircuitBreakerConnection(new self($config), $config->identity(), $config->breakerOpenS);
    }

    public function query(string $sql, #[\SensitiveParameter] array $params = []): array
    {
        return $this->run($sql, $params, static fn (\PDOStatement $st): array => $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function queryOne(string $sql, #[\SensitiveParameter] array $params = []): ?array
    {
        $row = $this->run($sql, $params, static fn (\PDOStatement $st): mixed => $st->fetch(\PDO::FETCH_ASSOC));

        return is_array($row) ? $row : null;
    }

    public function value(string $sql, #[\SensitiveParameter] array $params = []): mixed
    {
        $row = $this->queryOne($sql, $params);

        return $row === null ? null : array_values($row)[0];
    }

    public function execute(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return max(0, (int) $this->run($sql, $params, static fn (\PDOStatement $st): int => $st->rowCount()));
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
        $this->statements = [];
        $this->depth = 0;
    }

    private function savepoint(callable $callback): mixed
    {
        $name = 'rc_sp_' . $this->depth;
        $this->exec('SAVEPOINT ' . $name);
        ++$this->depth;
        try {
            $result = $callback();
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
        --$this->depth;
        $this->exec('RELEASE SAVEPOINT ' . $name);

        return $result;
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
            $this->statements = [];
        }
    }

    /**
     * @template T
     * @param list<mixed> $params
     * @param \Closure(\PDOStatement): T $read what to take from the statement before it is reused
     * @return T
     */
    private function run(string $sql, #[\SensitiveParameter] array $params, \Closure $read): mixed
    {
        $bound = array_map(self::scalar(...), $params);
        $pdo = $this->pdo();
        for ($attempt = 1;; ++$attempt) {
            try {
                $st = $this->statements[$sql] ?? null;
                if ($st === null) {
                    $st = $pdo->prepare($sql);
                } else {
                    unset($this->statements[$sql]);   // re-inserted below as the newest
                }
                foreach ($bound as $i => $v) {
                    $st->bindValue($i + 1, $v, match (true) {
                        $v === null => \PDO::PARAM_NULL,
                        is_bool($v) => \PDO::PARAM_BOOL,
                        is_int($v) => \PDO::PARAM_INT,
                        default => \PDO::PARAM_STR,
                    });
                }
                $st->execute();
                $result = $read($st);
                $st->closeCursor();
                $this->statements[$sql] = $st;
                if (count($this->statements) > self::STATEMENT_CACHE) {
                    array_shift($this->statements);
                }

                return $result;
            } catch (\PDOException $e) {
                unset($this->statements[$sql]);   // a statement that failed is not trusted again
                $info = is_array($e->errorInfo) ? $e->errorInfo : [];
                if ($attempt === 1 && ($info[0] ?? '') === '0A000' && !$pdo->inTransaction()) {
                    continue;   // "cached plan must not change result type": the table was altered under a cached statement; prepare it again
                }
                $mapped = $this->failed($e);
                if ($attempt === 1 && $mapped->isRetryable() && $this->depth === 0) {
                    continue;   // a statement of its own that the server rolled back whole (deadlock, a partition attached under it): sending it again is safe
                }

                throw $mapped;
            }
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
        if (is_string($v)) {
            // PostgreSQL text cannot hold a NUL byte or invalid UTF-8, and the driver would cut a value at the NUL without a word. History is telemetry: one odd byte must
            // not cost a whole batch, so the byte is replaced (NUL by U+FFFD, an invalid sequence by "?") and the rest of the value is stored.
            if (!mb_check_encoding($v, 'UTF-8')) {
                $v = mb_scrub($v, 'UTF-8');
            }

            return str_contains($v, "\0") ? str_replace("\0", "\u{FFFD}", $v) : $v;
        }
        if ($v === null || is_bool($v) || is_int($v)) {
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
            $this->statements = [];
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
            throw $mapped instanceof PostgresConnectionException ? $mapped : new PostgresConnectionException('PostgreSQL session could not be opened: ' . $mapped->getMessage(), $mapped->sqlState());
        }

        return $this->pdo = $pdo;
    }
}
