<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

/**
 * Wraps a {@see PostgresConnectionInterface} so an outage costs ONE connect timeout per window and not one per statement or per check-in.
 *
 * After a {@see PostgresConnectionException} the breaker is OPEN for `$openS` seconds: every call fails at once with the same exception class and does not touch
 * the network. The first call after the window tries again (half-open); success closes the breaker. The state is kept in a small file (default: the system temp
 * directory) so it is shared by the PHP-FPM workers and the cron jobs of the host, because one worker's knowledge is useless to the next request; when the file
 * cannot be written the breaker still works inside the process. The file holds a Unix time and nothing else (no credentials, no error text).
 *
 * Only connection failures open it: a rejected statement, a timeout or a deadlock does not mean the server is down.
 *
 * @api
 */
final class CircuitBreakerConnection implements PostgresConnectionInterface
{
    /** @var array<string,float> process-wide fallback: key => open until (Unix time) */
    private static array $local = [];

    private readonly string $file;
    private readonly \Closure $now;
    private float $checkedAt = -1.0;
    private float $cachedUntil = 0.0;

    /**
     * @param string $identity what the breaker guards ({@see PostgresConfig::identity()}); the file name is a hash of it
     * @param int $openS seconds to stay open after a connection failure (0 disables the breaker)
     * @param string|null $stateDir where the shared state file lives (null = the system temp directory)
     * @param (\Closure(): float)|null $now clock seam for tests
     */
    public function __construct(private readonly PostgresConnectionInterface $inner, private readonly string $identity, private readonly int $openS = 15, ?string $stateDir = null, ?\Closure $now = null)
    {
        $dir = $stateDir ?? sys_get_temp_dir();
        $this->file = rtrim($dir, '/') . '/rivetcore-pg-breaker-' . substr(hash('sha256', $identity), 0, 24);
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    public function query(string $sql, #[\SensitiveParameter] array $params = []): array
    {
        return $this->guard(fn (): array => $this->inner->query($sql, $params));
    }

    public function queryOne(string $sql, #[\SensitiveParameter] array $params = []): ?array
    {
        return $this->guard(fn (): ?array => $this->inner->queryOne($sql, $params));
    }

    public function value(string $sql, #[\SensitiveParameter] array $params = []): mixed
    {
        return $this->guard(fn (): mixed => $this->inner->value($sql, $params));
    }

    public function execute(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return $this->guard(fn (): int => $this->inner->execute($sql, $params));
    }

    public function transaction(callable $callback): mixed
    {
        return $this->guard(fn (): mixed => $this->inner->transaction($callback));
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function serverVersion(): string
    {
        return $this->guard(fn (): string => $this->inner->serverVersion());
    }

    public function close(): void
    {
        $this->inner->close();
    }

    /** Seconds the breaker stays open from now (0 = closed). */
    public function openFor(): float
    {
        return max(0.0, $this->openUntil() - ($this->now)());
    }

    /** Forget a recorded outage (an operator fixed the server and does not want to wait out the window). */
    public function reset(): void
    {
        $this->cachedUntil = 0.0;
        $this->checkedAt = -1.0;
        unset(self::$local[$this->identity]);
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

    /**
     * @template T
     * @param \Closure(): T $call
     * @return T
     */
    private function guard(\Closure $call): mixed
    {
        if ($this->openS > 0 && !$this->inner->inTransaction()) {
            $left = $this->openFor();
            if ($left > 0) {
                throw new PostgresConnectionException(sprintf('PostgreSQL is unavailable (the last connection attempt failed; not trying again for %d more seconds).', (int) ceil($left)));
            }
        }
        try {
            $result = $call();
        } catch (PostgresConnectionException $e) {
            $this->open();
            throw $e;
        }
        if ($this->openS > 0 && $this->cachedUntil > 0.0) {
            $this->reset();   // the half-open attempt worked
        }

        return $result;
    }

    /** The state file is only believed when it belongs to the user this process runs as (the temp directory is shared; another user could plant one). */
    private function ownedFile(): bool
    {
        if (!is_file($this->file)) {
            return false;
        }
        if (!function_exists('posix_geteuid')) {
            return true;
        }

        return @fileowner($this->file) === posix_geteuid();
    }

    private function open(): void
    {
        if ($this->openS <= 0) {
            return;
        }
        $until = ($this->now)() + $this->openS;
        self::$local[$this->identity] = $until;
        $this->cachedUntil = $until;
        $this->checkedAt = ($this->now)();
        $tmp = $this->file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, (string) $until, LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            if (!@rename($tmp, $this->file)) {
                @unlink($tmp);
            }
        }
    }

    private function openUntil(): float
    {
        $now = ($this->now)();
        if ($this->checkedAt >= 0.0 && $now - $this->checkedAt < 1.0) {
            return $this->cachedUntil;   // look at the shared file once a second, not once a statement
        }
        $until = self::$local[$this->identity] ?? 0.0;
        $raw = $this->ownedFile() ? @file_get_contents($this->file) : false;
        if (is_string($raw) && is_numeric($raw)) {
            // never trust a time further away than one window (a stray or planted value must not hold the breaker open), and spread the workers' retries over the last third
            // of the window so they do not all reconnect in the same second
            $until = max($until, min((float) $raw, $now + $this->openS) - ((int) getmypid() % max(1, intdiv($this->openS, 3) + 1)));
        }
        $this->checkedAt = $now;
        $this->cachedUntil = $until;

        return $until;
    }
}
