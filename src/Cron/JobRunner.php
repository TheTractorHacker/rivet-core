<?php

declare(strict_types=1);

namespace RivetCore\Cron;

/**
 * Starts an allowlisted cron script in the background and reports what happened, for the admin Cron Manager.
 * Nothing here takes a path or command from a request: the caller passes a script that already matched a
 * root-owned cron.d line (or a catalog entry), and arguments are only accepted in a strict --name[=value] form.
 * Scripts must live under <appRoot>/cron/ or <appRoot>/scripts/. Not final so an edition can pin its own default
 * state directory (keeping PID/log files where an earlier release left them).
 *
 * @api
 */
class JobRunner
{
    private string $stateDir;
    private bool $stateDirSafe;

    /**
     * Without $stateDir the default is a per-user directory in the system temp dir. Whatever directory is used must be a real
     * directory (not a symlink), owned by the current user and not writable by anyone else; if it is not, start() refuses to run.
     */
    public function __construct(private string $appRoot, ?string $stateDir = null)
    {
        $this->appRoot = realpath($appRoot) ?: rtrim($appRoot, '/');
        $this->stateDir = $stateDir ?? (sys_get_temp_dir() . '/rivetcore-jobs-' . self::uid());
        if (!file_exists($this->stateDir) && !is_link($this->stateDir)) @mkdir($this->stateDir, 0700, true);
        $this->stateDirSafe = self::isPrivateDir($this->stateDir);
    }

    private static function uid(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    /** A real (non-symlink) directory owned by this user that neither group nor others can write to. */
    private static function isPrivateDir(string $dir): bool
    {
        $st = @lstat($dir);
        if ($st === false || is_link($dir) || ($st['mode'] & 0170000) !== 0040000) return false;
        return $st['uid'] === self::uid() && ($st['mode'] & 0022) === 0;
    }

    /** The "/var/log/x.log" a cron line appends to, if it is a plain file under /var/log. */
    public static function logPathFromCommand(string $command): ?string
    {
        return preg_match('~>>\s*(/var/log/[A-Za-z0-9._-]+)~', $command, $m) ? $m[1] : null;
    }

    /**
     * Arguments between the script and any redirect, only if every one is a plain --option[=value].
     *
     * @return list<string>|null
     */
    public static function argumentsFromCommand(string $command, string $script): ?array
    {
        $rest = trim(explode($script, $command, 2)[1] ?? '');
        $rest = trim(preg_replace('~\s*(?:>>?|2>&1|&>).*$~', '', $rest) ?? '');
        if ($rest === '') return [];
        $args = preg_split('/\s+/', $rest);
        foreach ($args as $a) {
            if (!preg_match('/^--[a-z][a-z-]*(=[A-Za-z0-9_.,-]+)?\z/D', $a)) return null;
        }
        return $args;
    }

    public static function phpBinaryFromCommand(string $command): string
    {
        return preg_match('~^(/usr/bin/php[0-9.]*)\s~', $command, $m) ? $m[1] : '/usr/bin/php';
    }

    /** @return array{log:string, pid:string, key:string} */
    private function files(string $key): array
    {
        $key = preg_replace('/[^A-Za-z0-9_-]/', '', $key);
        return ['log' => "{$this->stateDir}/$key.log", 'pid' => "{$this->stateDir}/$key.pid", 'key' => $key];
    }

    /**
     * Last lines of a log file with control characters removed.
     *
     * @return array{lines:list<string>, mtime:?int}
     */
    public static function tail(?string $path, int $lines = 8, int $bytes = 8192): array
    {
        if (!$path || !is_file($path) || !is_readable($path)) return ['lines' => [], 'mtime' => null];
        $size = filesize($path);
        $fh = fopen($path, 'rb');
        if (!$fh) return ['lines' => [], 'mtime' => null];
        if ($size > $bytes) fseek($fh, -$bytes, SEEK_END);
        $chunk = (string) stream_get_contents($fh);
        fclose($fh);
        $clean = array_values(array_filter(array_map(
            static fn($l) => mb_substr(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', rtrim($l)) ?? '', 0, 300),
            explode("\n", $chunk)
        ), static fn($l) => trim($l) !== ''));
        return ['lines' => array_slice($clean, -$lines), 'mtime' => filemtime($path) ?: null];
    }

    /** @return array{running:bool, pid:?int, started_at:?int, finished_at:?int, exit:?int, lines:list<string>} */
    public function state(string $key, string $scriptPath): array
    {
        $f = $this->files($key);
        $pid = is_file($f['pid']) ? (int) trim((string) file_get_contents($f['pid'])) : null;
        $running = false;
        if ($pid) {
            $cmd = @file_get_contents("/proc/$pid/cmdline");
            $running = is_string($cmd) && str_contains($cmd, $scriptPath);
        }
        $t = self::tail($f['log'], 12);
        $exit = null;
        foreach (array_reverse($t['lines']) as $line) {
            if (preg_match('/^\[exit (\d+)\]$/', $line, $m)) { $exit = (int) $m[1]; break; }
        }
        return [
            'running' => $running,
            'pid' => $pid,
            'started_at' => is_file($f['pid']) ? (filemtime($f['pid']) ?: null) : null,
            'finished_at' => (!$running && $exit !== null) ? $t['mtime'] : null,
            'exit' => $exit,
            'lines' => $t['lines'],
        ];
    }

    /**
     * @param list<string> $args
     * @return array{ok:bool, message:string}
     */
    public function start(string $key, string $script, array $args = [], string $php = '/usr/bin/php'): array
    {
        $script = realpath($script) ?: '';
        $allowed = str_starts_with($script, $this->appRoot . '/cron/') || str_starts_with($script, $this->appRoot . '/scripts/');
        if ($script === '' || !$allowed || !is_file($script)) {
            return ['ok' => false, 'message' => 'That job script was not found.'];
        }
        if (!preg_match('~^/usr/bin/php[0-9.]*\z~D', $php) && $php !== PHP_BINARY) return ['ok' => false, 'message' => 'Unexpected PHP binary.'];
        foreach ($args as $a) {
            if (!preg_match('/^--[a-z][a-z-]*(=[A-Za-z0-9_.,-]+)?\z/D', $a)) return ['ok' => false, 'message' => 'Unexpected argument.'];
        }
        if ($this->state($key, $script)['running']) return ['ok' => false, 'message' => 'This job is already running.'];

        if (!$this->stateDirSafe) return ['ok' => false, 'message' => 'The job state directory is not private; refusing to start.'];
        $f = $this->files($key);
        // Remove (never follow) whatever sits at the pid/log paths, then create the log exclusively so a planted symlink cannot be written through.
        @unlink($f['pid']);
        @unlink($f['log']);
        $fh = @fopen($f['log'], 'x');
        if ($fh === false) return ['ok' => false, 'message' => 'Could not create the job log.'];
        fclose($fh);
        @chmod($f['log'], 0600);
        $inner = 'echo $$ > ' . escapeshellarg($f['pid']) . '; cd ' . escapeshellarg(dirname($script)) . ' && '
            . escapeshellarg($php) . ' ' . escapeshellarg($script) . ($args ? ' ' . implode(' ', array_map('escapeshellarg', $args)) : '')
            . ' > ' . escapeshellarg($f['log']) . ' 2>&1; rc=$?; printf ' . escapeshellarg('\n[exit %s]\n') . ' "$rc" >> ' . escapeshellarg($f['log']);
        exec('nohup sh -c ' . escapeshellarg($inner) . ' > /dev/null 2>&1 &');
        for ($i = 0; $i < 20 && !is_file($f['pid']); $i++) usleep(50000);
        return is_file($f['pid']) ? ['ok' => true, 'message' => 'Started.'] : ['ok' => false, 'message' => 'The job did not start.'];
    }
}
