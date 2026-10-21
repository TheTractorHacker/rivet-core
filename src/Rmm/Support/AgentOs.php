<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

/**
 * The operating system an agent binary, a release and a device belong to (1.0.0-rc.30, migration 0037): `windows` (a PE executable) or `linux` (an ELF executable).
 * Pure helpers, plus the one check the code needs to run before and after the migration: whether the `os` columns exist yet.
 *
 * @api
 */
final class AgentOs
{
    public const WINDOWS = 'windows';
    public const LINUX = 'linux';
    public const ALL = [self::WINDOWS, self::LINUX];

    /** @var \WeakMap<Sql,array{0:bool,1:int}>|null */
    private static ?\WeakMap $columns = null;

    /** 'windows' or 'linux' for a value that is one of them in any case, otherwise null (never guess). */
    public static function normalize(mixed $os): ?string
    {
        if (!is_string($os)) {
            return null;
        }
        $os = strtolower(trim($os));

        return in_array($os, self::ALL, true) ? $os : null;
    }

    /**
     * The OS a device must be served for: its stored `os` (authoritative), else the one it sent with the request, else windows (what every device was before).
     *
     * @param array<string,mixed> $device an endpoint_agent_devices row
     * @param mixed $requested the optional `os` the agent sent (runtime.GOOS)
     */
    public static function forDevice(array $device, mixed $requested = null): string
    {
        return self::normalize($device['os'] ?? null) ?? self::normalize($requested) ?? self::WINDOWS;
    }

    /** True when the first bytes of the file are the executable magic of this OS (PE `MZ`, ELF `7f 45 4c 46`). False for an unreadable file. */
    public static function magicMatches(string $path, string $os): bool
    {
        $fh = is_file($path) ? @fopen($path, 'rb') : false;
        if ($fh === false) {
            return false;
        }
        $head = (string) fread($fh, 4);
        fclose($fh);

        return $os === self::LINUX ? $head === "\x7fELF" : str_starts_with($head, 'MZ');
    }

    /**
     * Whether migration 0037 has run (both tables have the column). A positive answer is kept for the life of the connection; a negative one is re-checked after 30
     * seconds, so a live migration is picked up without a restart and a database that has not run it costs one cheap query per half minute.
     */
    public static function supported(Sql $sql): bool
    {
        self::$columns ??= new \WeakMap();
        $hit = self::$columns[$sql] ?? null;
        if ($hit !== null && ($hit[0] || time() - $hit[1] < 30)) {
            return $hit[0];
        }
        $row = $sql->one("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'os' AND TABLE_NAME IN ('endpoint_agent_binaries', 'endpoint_agent_releases')");
        $ok = (int) ($row['c'] ?? 0) === 2;
        self::$columns[$sql] = [$ok, time()];

        return $ok;
    }

    /** Forget what {@see supported()} learned (tests that run the migration on a live connection). */
    public static function forget(): void
    {
        self::$columns = null;
    }

    /**
     * The OS of a binary or release row; rows from a database without the column are Windows (the only kind that was ever stored).
     *
     * @param array<string,mixed> $row
     */
    public static function ofRow(array $row): string
    {
        return self::normalize($row['os'] ?? null) ?? self::WINDOWS;
    }
}
