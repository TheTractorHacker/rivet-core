<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

/** Loads the shared endpoint agent vector files (the same bytes the Go agent tests read). */
final class RmmVectors
{
    public static function dir(): string
    {
        return dirname(__DIR__, 3) . '/endpoint-agent/testdata/vectors';
    }

    /** @return array<string,mixed> */
    public static function load(string $file): array
    {
        $raw = file_get_contents(self::dir() . '/' . $file);
        if ($raw === false) {
            throw new \RuntimeException("missing vector file $file");
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("bad vector file $file");
        }

        return $data;
    }
}
