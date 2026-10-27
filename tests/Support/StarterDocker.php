<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

/** Runs a throwaway container for the starter pack's script checks; every caller skips (or falls back) when docker or an image is not there. */
final class StarterDocker
{
    private static ?bool $up = null;
    /** @var array<string,bool> */
    private static array $images = [];

    public static function available(): bool
    {
        if (getenv('RIVETCORE_NO_DOCKER')) {
            return false;
        }

        return self::$up ??= self::exec(['docker', 'version', '--format', '{{.Server.Version}}'], 20)[0] === 0;
    }

    /** True when the image is local or could be pulled. */
    public static function image(string $image): bool
    {
        if (!self::available()) {
            return false;
        }

        return self::$images[$image] ??= self::exec(['docker', 'image', 'inspect', $image], 20)[0] === 0 || self::exec(['docker', 'pull', '--quiet', $image], 600)[0] === 0;
    }

    /**
     * @param list<string> $cmd
     * @return array{0:int,1:string} [exit code, stdout and stderr]
     */
    public static function exec(array $cmd, int $timeout = 120, ?string $stdin = null): array
    {
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($p)) {
            return [127, 'could not start ' . $cmd[0]];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $out = '';
        $end = time() + $timeout;
        while (true) {
            $chunk = stream_get_contents($pipes[1]);
            $out .= $chunk === false ? '' : $chunk;
            $st = proc_get_status($p);
            if (!$st['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                break;
            }
            if (time() > $end) {
                proc_terminate($p, 9);
                $out .= "\n[timed out after {$timeout}s]";
                fclose($pipes[1]);
                proc_close($p);

                return [124, $out];
            }
            usleep(50000);
        }
        fclose($pipes[1]);
        $code = proc_close($p);

        return [$st['exitcode'] >= 0 ? $st['exitcode'] : $code, $out];
    }

    public static function tempDir(string $what): string
    {
        $d = sys_get_temp_dir() . '/rivetcore_' . $what . '_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($d, 0755, true);

        return $d;
    }

    public static function rmTree(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($d);
    }
}
