<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

/** A throwaway directory for state-file tests (removed with {@see remove()}). */
final class TempDir
{
    public static function make(): string
    {
        $d = sys_get_temp_dir() . '/rmm_t9_' . bin2hex(random_bytes(5));
        mkdir($d, 0750);

        return $d;
    }

    public static function remove(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
