<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;

/**
 * The small query helper every RMM service shares (replaces RivetIT's static Db class): row/rows/value/write/insert over a
 * DatabaseInterface, plus the clock-derived UTC strings the endpoint tables store. Every value goes through `?` placeholders.
 *
 * @api
 */
final class Sql
{
    public function __construct(private readonly DatabaseInterface $db, private readonly ClockInterface $clock)
    {
    }

    public function database(): DatabaseInterface
    {
        return $this->db;
    }

    public function clock(): ClockInterface
    {
        return $this->clock;
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        return $this->db->fetchOne($sql, $params);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * First column of the first row, or null.
     *
     * @param list<mixed> $params
     */
    public function val(string $sql, array $params = []): mixed
    {
        $r = $this->db->fetchOne($sql, $params);

        return $r === null ? null : array_values($r)[0];
    }

    /**
     * Runs a write; returns the affected rows.
     *
     * @param list<mixed> $params
     */
    public function run(string $sql, array $params = []): int
    {
        return max(0, $this->db->execute($sql, $params)->affectedRows);
    }

    /**
     * Runs an INSERT; returns the new AUTO_INCREMENT id.
     *
     * @param list<mixed> $params
     */
    public function insert(string $sql, array $params = []): int
    {
        return (int) $this->db->execute($sql, $params)->insertId;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback);
    }

    public function time(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /** The current UTC time as a database datetime. */
    public function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s', $this->time());
    }

    /** UTC database datetime of "now + $seconds" (negative for the past). */
    public function utcAt(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', $this->time() + $seconds);
    }

    /** Current time as RFC 3339 ("Z"). */
    public function isoNow(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $this->time());
    }

    /** Database (UTC) datetime to RFC 3339 ("Z"), or null. */
    public static function iso(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $ts = strtotime($utc . ' UTC');

        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** Database (UTC) datetime to a Unix timestamp (0 when unparsable). */
    public static function ts(?string $utc): int
    {
        if ($utc === null || $utc === '') {
            return 0;
        }
        $ts = strtotime($utc . ' UTC');

        return $ts === false ? 0 : $ts;
    }
}
