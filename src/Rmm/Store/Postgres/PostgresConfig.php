<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

use RivetCore\Rmm\Contracts\SecretBoxInterface;

/**
 * Where and how to reach the PostgreSQL database of the RMM data store (phase 2 of #89). Immutable, validated on construction, and careful with the password:
 * it is not part of {@see dsn()}, not in `var_dump`/`print_r`, not in a stack trace (the constructor parameter is `#[\SensitiveParameter]`) and the object cannot
 * be serialized.
 *
 * Core never stores this. The EDITION supplies it from its own configuration (environment, a root-only file, or a sealed setting it decrypts) and passes it to
 * the module as the `postgres` option; {@see fromSealed()} is the helper for the sealed-setting case, using the same {@see SecretBoxInterface} Core uses for its
 * other secrets. Nothing here is written to the control database in plain text.
 *
 * @api
 */
final class PostgresConfig
{
    private readonly \Closure $secret;

    public const SSL_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    /**
     * @param string $host a host name, an IP address or, when it starts with `/`, the directory of the server's unix socket (the recommended same-server setup)
     * @param string $schema schema the tables live in (created by the migrator; must exist and be writable for the role)
     * @param int $connectTimeoutS seconds to wait for the server before the connection counts as down
     * @param int $statementTimeoutMs a statement running longer is cancelled (0 = no limit)
     * @param int $lockTimeoutMs a statement waiting longer for a lock is cancelled (0 = no limit)
     * @param int $transactionRetries how many times a deadlocked transaction is repeated
     * @param int $breakerOpenS seconds a worker stops trying after a connection failure, so an outage costs one connect timeout per worker per window and not one per check-in
     * @param int $socketTimeoutS seconds after which a connection whose peer stopped answering (a pulled cable, a firewall that drops instead of resetting) is given up: TCP keepalives
     *        and the user timeout, so a worker is not held for the kernel's quarter of an hour (0 = the operating system's defaults; irrelevant for a unix socket)
     */
    public function __construct(
        public readonly string $database,
        public readonly string $user,
        #[\SensitiveParameter] string $password = '',
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 5432,
        public readonly string $sslMode = 'prefer',
        public readonly ?string $sslRootCert = null,
        public readonly string $schema = 'public',
        public readonly int $connectTimeoutS = 3,
        public readonly int $statementTimeoutMs = 30000,
        public readonly int $lockTimeoutMs = 5000,
        public readonly string $applicationName = 'rivetcore-rmm',
        public readonly int $transactionRetries = 2,
        public readonly int $breakerOpenS = 15,
        public readonly int $socketTimeoutS = 30,
    ) {
        foreach (['database' => $database, 'user' => $user] as $field => $v) {
            if ($v === '' || preg_match('/^[A-Za-z0-9_.\-]{1,63}$/D', $v) !== 1) {
                throw new \InvalidArgumentException("Postgres $field must be 1 to 63 letters, digits, '_', '.' or '-'.");
            }
        }
        if ($host === '' || preg_match('~^[A-Za-z0-9_.:\-/\[\]]{1,255}$~D', $host) !== 1) {
            throw new \InvalidArgumentException('Postgres host must be a host name, an IP address or the absolute directory of a unix socket.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Postgres port must be 1 to 65535.');
        }
        if (!in_array($sslMode, self::SSL_MODES, true)) {
            throw new \InvalidArgumentException('Postgres sslmode must be one of: ' . implode(', ', self::SSL_MODES) . '.');
        }
        if ($sslRootCert !== null && preg_match('~^[A-Za-z0-9_.\-/]{1,255}$~D', $sslRootCert) !== 1) {
            throw new \InvalidArgumentException('Postgres sslrootcert must be a plain file path.');
        }
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new \InvalidArgumentException('Postgres schema must be a lower case identifier.');
        }
        if (preg_match('/^[A-Za-z0-9_.\-]{1,63}$/D', $applicationName) !== 1) {
            throw new \InvalidArgumentException('Postgres application name must be letters, digits, "_", "." or "-".');
        }
        if ($connectTimeoutS < 1 || $connectTimeoutS > 60 || $statementTimeoutMs < 0 || $lockTimeoutMs < 0 || $transactionRetries < 0 || $transactionRetries > 10 || $breakerOpenS < 0 || $breakerOpenS > 3600 || $socketTimeoutS < 0 || $socketTimeoutS > 3600) {
            throw new \InvalidArgumentException('Postgres timeouts out of range.');
        }
        if (str_contains($password, "\0")) {
            throw new \InvalidArgumentException('Postgres password must not contain a NUL byte.');
        }
        $this->secret = static fn (): string => $password;   // a closure, so var_export / print_r / json_encode / var_dump never show the value
    }

    /**
     * Build from the keys an edition keeps in its own config: database, user, password, host, port, sslmode, sslrootcert, schema, connect_timeout, statement_timeout_ms,
     * lock_timeout_ms, application_name. Unknown keys are refused so a typo cannot silently fall back to a default.
     *
     * @param array<string,mixed> $c
     */
    public static function fromArray(#[\SensitiveParameter] array $c): self
    {
        $known = ['database', 'user', 'password', 'host', 'port', 'sslmode', 'sslrootcert', 'schema', 'connect_timeout', 'statement_timeout_ms', 'lock_timeout_ms', 'application_name', 'transaction_retries', 'breaker_open_s', 'socket_timeout_s'];
        $unknown = array_diff(array_keys($c), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown Postgres setting(s): ' . implode(', ', array_map('strval', $unknown)) . '.');
        }
        foreach (['database', 'user'] as $required) {
            if (!isset($c[$required]) || !is_string($c[$required])) {
                throw new \InvalidArgumentException("Postgres setting '$required' is required.");
            }
        }
        $str = static fn (string $k, string $d): string => isset($c[$k]) && is_string($c[$k]) ? $c[$k] : $d;
        $int = static fn (string $k, int $d): int => isset($c[$k]) && is_numeric($c[$k]) ? (int) $c[$k] : $d;

        return new self((string) $c['database'], (string) $c['user'], $str('password', ''), $str('host', '127.0.0.1'), $int('port', 5432), $str('sslmode', 'prefer'),
            isset($c['sslrootcert']) && is_string($c['sslrootcert']) ? $c['sslrootcert'] : null, $str('schema', 'public'), $int('connect_timeout', 3), $int('statement_timeout_ms', 30000),
            $int('lock_timeout_ms', 5000), $str('application_name', 'rivetcore-rmm'), $int('transaction_retries', 2), $int('breaker_open_s', 15), $int('socket_timeout_s', 30));
    }

    /**
     * The same, where the edition keeps the password sealed (`password_sealed`) with its {@see SecretBoxInterface}. An unreadable value is an error, never an empty password.
     *
     * @param array<string,mixed> $c as {@see fromArray()} but with `password_sealed` instead of `password`
     */
    public static function fromSealed(#[\SensitiveParameter] array $c, SecretBoxInterface $box): self
    {
        $sealed = $c['password_sealed'] ?? '';
        unset($c['password_sealed']);
        if (is_string($sealed) && $sealed !== '') {
            $plain = $box->decrypt($sealed);
            if ($plain === '') {
                throw new \InvalidArgumentException('The sealed Postgres password cannot be read (damaged value or another key).');
            }
            $c['password'] = $plain;
        }

        return self::fromArray($c);
    }

    /** The libpq connection string without credentials: `pgsql:host=...;port=...;dbname=...`. */
    public function dsn(): string
    {
        $dsn = 'pgsql:host=' . $this->host . ';port=' . $this->port . ';dbname=' . $this->database . ';connect_timeout=' . $this->connectTimeoutS . ';sslmode=' . $this->sslMode
            . ';application_name=' . $this->applicationName;
        if ($this->socketTimeoutS > 0 && !str_starts_with($this->host, '/')) {
            $dsn .= ';keepalives=1;keepalives_idle=' . max(1, intdiv($this->socketTimeoutS, 2)) . ';keepalives_interval=5;keepalives_count=3;tcp_user_timeout=' . ($this->socketTimeoutS * 1000);
        }
        if ($this->sslRootCert !== null) {
            $dsn .= ';sslrootcert=' . $this->sslRootCert;
        }

        return $dsn;
    }

    /** @internal for the adapters in this library; do not log it */
    public function password(): string
    {
        return ($this->secret)();
    }

    /** A stable key for this server and role (no secret), used to share the circuit breaker between connections to the same database. */
    public function identity(): string
    {
        return $this->host . ':' . $this->port . '/' . $this->database . '@' . $this->user . '/' . $this->schema;
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['database' => $this->database, 'user' => $this->user, 'password' => $this->password() === '' ? '(none)' : '(hidden)', 'host' => $this->host, 'port' => $this->port,
            'sslMode' => $this->sslMode, 'schema' => $this->schema];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('A PostgresConfig holds a password and is not serializable.');
    }
}
