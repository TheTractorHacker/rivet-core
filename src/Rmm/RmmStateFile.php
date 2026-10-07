<?php

declare(strict_types=1);

namespace RivetCore\Rmm;

/**
 * The zero-database state file of the module switch: a small JSON document in a directory the edition provides
 * ({@see \RivetCore\Rmm\Contracts\RmmModuleStateInterface::stateDirectory()}) that mirrors the master switch, the sub-switches, the
 * shed level and the limits a gate needs, so that a request can be refused (or a cron block skipped) WITHOUT opening the database.
 *
 * FAIL-SAFE RULE. The file is a cache, the database is the truth. A missing, unreadable, empty, garbled, wrong-type or
 * wrong-version file is "unknown", NEVER "off": the reader returns null and every caller proceeds on the normal path (one primary
 * key SELECT on the settings row), which rewrites the file. Only a well-formed file of the current schema version that says
 * `enabled: false` can turn the module away. A stale file can delay a switch only until the writer runs again, and the writer runs
 * in the same request as every settings change.
 *
 * The file is data, never code: it is read with file_get_contents and json_decode and is never included, so a damaged or tampered
 * file cannot execute or print anything. The writer is atomic (temporary file in the same directory, then rename) and creates the
 * file with mode 0640. The standalone gate template (docs/rmm/templates/rmm_gate.php) re-implements the reader in a dozen lines
 * on purpose (it loads no Core class); tests keep the two in step.
 *
 * Schema v1: {"v":1,"enabled":bool,"edition":bool,"master":bool,"features":{name:bool},"shed":0..3,"shed_at":int,
 * "shed_retry":[min,max],"retry_after":int,"ingest_mode":"sync|queued","limits":{name:int},"written_at":int}
 *
 * @api
 */
final class RmmStateFile
{
    public const FILE_NAME = 'rmm_state.json';
    public const VERSION = 1;
    public const MODE = 0640;
    /** A shed level in the file counts for this long after `shed_at`; a stalled evaluator can never wedge the fleet in refusal. */
    public const SHED_TTL_S = 180;

    /** Where the file lives inside a state directory. */
    public static function path(string $dir): string
    {
        return rtrim($dir, '/\\') . '/' . self::FILE_NAME;
    }

    /**
     * The validated state, or null for "unknown" (missing, unreadable, garbled, stale version, wrong types).
     *
     * @return array{v:int,enabled:bool,edition:bool,master:bool,features:array<string,bool>,shed:int,shed_at:int,shed_retry:array{0:int,1:int},retry_after:int,ingest_mode:string,limits:array<string,int>,written_at:int}|null
     */
    public static function read(?string $dir): ?array
    {
        if ($dir === null || $dir === '') {
            return null;
        }
        $raw = @file_get_contents(self::path($dir), false, null, 0, 65536);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);

        return is_array($d) ? self::validate($d) : null;
    }

    /**
     * @param array<mixed> $d
     * @return array{v:int,enabled:bool,edition:bool,master:bool,features:array<string,bool>,shed:int,shed_at:int,shed_retry:array{0:int,1:int},retry_after:int,ingest_mode:string,limits:array<string,int>,written_at:int}|null
     */
    public static function validate(array $d): ?array
    {
        if (($d['v'] ?? null) !== self::VERSION) {
            return null;
        }
        foreach (['enabled', 'edition', 'master'] as $b) {
            if (!is_bool($d[$b] ?? null)) {
                return null;
            }
        }
        $features = $d['features'] ?? null;
        $limits = $d['limits'] ?? null;
        $retry = $d['shed_retry'] ?? null;
        if (!is_array($features) || !is_array($limits) || !is_array($retry) || count($retry) !== 2 || !is_int($retry[0] ?? null) || !is_int($retry[1] ?? null)) {
            return null;
        }
        foreach ($features as $k => $v) {
            if (!is_string($k) || !is_bool($v)) {
                return null;
            }
        }
        foreach ($limits as $k => $v) {
            if (!is_string($k) || !is_int($v)) {
                return null;
            }
        }
        $shed = $d['shed'] ?? null;
        $mode = $d['ingest_mode'] ?? null;
        if (!is_int($shed) || $shed < 0 || $shed > 3 || !is_int($d['shed_at'] ?? null) || !is_int($d['retry_after'] ?? null) || !is_int($d['written_at'] ?? null)
            || !in_array($mode, ['sync', 'queued'], true)) {
            return null;
        }

        /** @var array<string,bool> $features */
        /** @var array<string,int> $limits */
        return ['v' => self::VERSION, 'enabled' => $d['enabled'], 'edition' => $d['edition'], 'master' => $d['master'], 'features' => $features, 'shed' => $shed,
            'shed_at' => $d['shed_at'], 'shed_retry' => [$retry[0], $retry[1]], 'retry_after' => $d['retry_after'], 'ingest_mode' => $mode, 'limits' => $limits,
            'written_at' => $d['written_at']];
    }

    /**
     * Whether the module may run, from the file alone: false ONLY when a valid file says it is off. Unknown is true ("go and look in
     * the database"), so a cron entry guards its autoload with `if (!RmmStateFile::enabled($dir)) { skip }`.
     */
    public static function enabled(?string $dir): bool
    {
        $s = self::read($dir);

        return $s === null || $s['enabled'];
    }

    /**
     * The shed level a file asserts, 0 when the file is unknown or the level is older than {@see SHED_TTL_S}.
     *
     * @param array{shed:int,shed_at:int}|array<string,mixed>|null $state a validated state ({@see read()})
     */
    public static function shedLevel(?array $state, int $now): int
    {
        return $state === null || $now - $state['shed_at'] > self::SHED_TTL_S ? 0 : $state['shed'];
    }

    /**
     * Write the state atomically. Never throws: an unwritable directory returns false (the module then simply pays the one-SELECT cost).
     * The file is left alone (not even touched) when nothing but `written_at` would change, so frequent syncs are free.
     *
     * @param array<string,mixed> $state schema v1 without `v`/`written_at`
     */
    public static function write(?string $dir, array $state, int $now): bool
    {
        if ($dir === null || $dir === '') {
            return false;
        }
        $doc = ['v' => self::VERSION] + $state;
        $doc['written_at'] = $now;
        $current = self::read($dir);
        if ($current !== null) {
            $a = $current;
            $b = $doc;
            unset($a['written_at'], $b['written_at']);
            if ($a == $b && $now - $current['written_at'] < 3600) {
                return true;
            }
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $tmp = self::path($dir) . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (!is_string($json) || @file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, self::MODE);
        if (!@rename($tmp, self::path($dir))) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
