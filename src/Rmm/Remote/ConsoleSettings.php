<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Support\Sql;

/**
 * The Rivet Console settings (one row of `rmm_console_settings`, migration 0051):
 *  - `console_public_url`: the https origin the console redeems against and reports to. Empty (the default) refuses every console launch (409 `not_configured`).
 *  - `min_console_version`: `x.y.z`; a console reporting a lower version gets 426 `client_too_old`. Empty (the default) enforces nothing.
 *  - `console_log_file_paths`: default off. Only when on does Core store the file paths a console reports.
 *  - `console_cookie_once`: default on. Adds the random `once` claim to the cookie the redeem hands out; switch it off for multi-Mesh-server set-ups.
 *
 * Changes go through {@see RemoteActions::updateSettings()} (administrators, audited). Reading never throws: a missing table (migration 0051 not applied) reads as the defaults.
 *
 * @api
 */
final class ConsoleSettings
{
    public const KEYS = ['console_public_url', 'min_console_version', 'console_log_file_paths', 'console_cookie_once'];
    public const VERSION_RE = '/^(0|[1-9][0-9]{0,5})\.(0|[1-9][0-9]{0,5})\.(0|[1-9][0-9]{0,5})$/D';

    /** @var array{public_url:string,min_version:string,log_file_paths:bool,cookie_once:bool}|null */
    private ?array $cache = null;

    public function __construct(private readonly Sql $sql, private readonly bool $allowInsecureHttp = false)
    {
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    public function allowsInsecureHttp(): bool
    {
        return $this->allowInsecureHttp;
    }

    /** @return array{public_url:string,min_version:string,log_file_paths:bool,cookie_once:bool} */
    public function get(): array
    {
        if ($this->cache === null) {
            $out = ['public_url' => '', 'min_version' => '', 'log_file_paths' => false, 'cookie_once' => true];
            try {
                $r = $this->sql->one('SELECT public_url, min_version, log_file_paths, cookie_once FROM rmm_console_settings WHERE id = 1');
            } catch (\Throwable) {
                return $out;   // not cached: the table may appear a moment later
            }
            if ($r !== null) {
                $url = ConsoleLink::normalizeOrigin((string) $r['public_url'], $this->allowInsecureHttp);
                $out = ['public_url' => $url ?? '', 'min_version' => preg_match(self::VERSION_RE, (string) $r['min_version']) === 1 ? (string) $r['min_version'] : '',
                    'log_file_paths' => (int) $r['log_file_paths'] === 1, 'cookie_once' => (int) $r['cookie_once'] === 1];
            }
            $this->cache = $out;
        }

        return $this->cache;
    }

    /**
     * The settings as the settings page and API show them (the four wire names).
     *
     * @return array<string,mixed>
     */
    public function view(): array
    {
        $c = $this->get();

        return ['console_public_url' => $c['public_url'], 'min_console_version' => $c['min_version'], 'console_log_file_paths' => $c['log_file_paths'], 'console_cookie_once' => $c['cookie_once']];
    }

    /**
     * Validate the console keys present in `$in`; nothing is written. Unknown keys are not this class's business.
     *
     * @param array<string,mixed> $in
     * @return array{0:array<string,mixed>,1:list<string>} [normalised values by key, errors]
     */
    public function validate(array $in): array
    {
        $vals = [];
        $err = [];
        if (array_key_exists('console_public_url', $in)) {
            $v = $in['console_public_url'];
            if (!is_string($v)) {
                $err[] = 'console_public_url must be text.';
            } elseif (trim($v) === '') {
                $vals['console_public_url'] = '';
            } else {
                $o = ConsoleLink::normalizeOrigin($v, $this->allowInsecureHttp);
                if ($o === null) {
                    $err[] = 'console_public_url must be a bare https address such as https://rmm.example.com (no path, query, user name or fragment).';
                } else {
                    $vals['console_public_url'] = $o;
                }
            }
        }
        if (array_key_exists('min_console_version', $in)) {
            $v = $in['min_console_version'];
            if (!is_string($v)) {
                $err[] = 'min_console_version must be text.';
            } elseif (trim($v) === '') {
                $vals['min_console_version'] = '';
            } elseif (preg_match(self::VERSION_RE, trim($v)) !== 1) {
                $err[] = 'min_console_version must look like 1.2.3 (or be empty to enforce nothing).';
            } else {
                $vals['min_console_version'] = trim($v);
            }
        }
        foreach (['console_log_file_paths', 'console_cookie_once'] as $k) {
            if (array_key_exists($k, $in)) {
                if (!is_bool($in[$k])) {
                    $err[] = "$k must be true or false.";
                } else {
                    $vals[$k] = $in[$k];
                }
            }
        }

        return [$vals, $err];
    }

    /**
     * Store values from {@see validate()}.
     *
     * @param array<string,mixed> $vals
     */
    public function save(array $vals, int $userId): void
    {
        $now = $this->sql->utcNow();
        $this->sql->run("INSERT IGNORE INTO rmm_console_settings (id, public_url, min_version, log_file_paths, cookie_once, updated_by, updated_at) VALUES (1, '', '', 0, 1, 0, ?)", [$now]);
        $map = ['console_public_url' => 'public_url', 'min_console_version' => 'min_version', 'console_log_file_paths' => 'log_file_paths', 'console_cookie_once' => 'cookie_once'];
        $set = [];
        $params = [];
        foreach ($map as $k => $col) {
            if (array_key_exists($k, $vals)) {
                $set[] = "$col = ?";
                $params[] = is_bool($vals[$k]) ? ($vals[$k] ? 1 : 0) : (string) $vals[$k];
            }
        }
        if ($set !== []) {
            $this->sql->run('UPDATE rmm_console_settings SET ' . implode(', ', $set) . ', updated_by = ?, updated_at = ? WHERE id = 1', [...$params, $userId, $now]);
        }
        $this->refresh();
    }

    /** True when the reported version is lower than the minimum. A missing or malformed version counts as too old (only asked when a minimum is set). */
    public static function tooOld(string $minimum, ?string $reported): bool
    {
        if ($reported === null || preg_match('/^(\d{1,6}\.\d{1,6}\.\d{1,6})(?:[-+][A-Za-z0-9.+-]{0,30})?$/D', $reported, $m) !== 1 || preg_match(self::VERSION_RE, $minimum) !== 1) {
            return true;   // a pre-release suffix is ignored: 0.2.0-rc1 counts as 0.2.0
        }
        $reported = $m[1];

        return version_compare($reported, $minimum, '<');
    }
}
