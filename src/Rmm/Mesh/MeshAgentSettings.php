<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * The install settings of the optional MeshCentral agent (one row of endpoint_agent_mesh_settings): the device group the devices join, the optional
 * pin of the Mesh server's certificate, whether an automatically found node id may replace one an administrator mapped by hand, and the optional
 * corrections to the agent-id and install-argument tables. The Mesh address and the on/off switch stay in the module's own settings
 * ({@see \RivetCore\Rmm\Settings\RmmSettings}: `mesh_enabled`, `mesh_url`).
 *
 * @api
 */
final class MeshAgentSettings
{
    public const GROUP_HELP_ERROR = 'The Mesh device group id must be the meshid from the MeshCentral download address (64 characters of letters, digits, @ and $), the whole download address pasted as it is, MeshCentral\'s mesh//... form, or the 96-character hex form.';

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly Sql $sql, private readonly ?RmmSettings $settings = null)
    {
    }

    /**
     * Warnings of the last {@see update()} (it saved anyway): today a pasted download address for a host other than the configured Mesh host.
     *
     * @return list<string>
     */
    public function lastWarnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        if ($this->cache === null) {
            $row = $this->sql->one('SELECT * FROM endpoint_agent_mesh_settings WHERE id = 1');
            if ($row === null) {
                $this->sql->run('INSERT IGNORE INTO endpoint_agent_mesh_settings (id, updated_at) VALUES (1, ?)', [$this->sql->utcNow()]);
                $row = $this->sql->one('SELECT * FROM endpoint_agent_mesh_settings WHERE id = 1') ?? [];
            }
            $this->cache = $row;
        }

        return $this->cache;
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    /**
     * `group_id` is the canonical hex; `group_id_url` the form MeshCentral prints in its download address (the verbatim one when the administrator gave it,
     * else derived from the hex), which is what the installer carries; empty when no group is set.
     *
     * `hide` is MeshCentral's `hide` bitmask for the desktop launch (default 31) and `hide_tools` the one for terminal and files (null = the same as `hide`).
     *
     * @return array{group_id:string,group_id_url:string,server_cert_pin:string,auto_map_overwrite:bool,agent_ids:array<string,int>,install_args:array<string,list<string>>,hide:int,hide_tools:int|null}
     */
    public function get(): array
    {
        $r = $this->row();
        $ids = MeshAgentCatalog::cleanAgentIds(self::decode($r['agent_ids_json'] ?? null)) ?? [];
        $args = MeshAgentCatalog::cleanInstallArgs(self::decode($r['install_args_json'] ?? null)) ?? [];

        $hex = (string) ($r['group_id'] ?? '');
        $url = $hex === '' ? '' : MeshAgentCatalog::wireGroupId($hex, (string) ($r['group_id_url'] ?? ''));

        return ['group_id' => $hex, 'group_id_url' => $url, 'server_cert_pin' => (string) ($r['server_cert_pin'] ?? ''), 'auto_map_overwrite' => (int) ($r['auto_map_overwrite'] ?? 0) === 1,
            'agent_ids' => $ids, 'install_args' => $args,
            'hide' => self::cleanHide($r['mesh_hide'] ?? null) ?? MeshCookie::HIDE_DEFAULT, 'hide_tools' => self::cleanHide($r['mesh_hide_tools'] ?? null)];
    }

    /**
     * The `hide` value for one launch view ('desktop', 'terminal', 'files'): 0 means "no hide parameter". A database without migration 0026 gives the default.
     */
    public function hideFor(string $view): int
    {
        $g = $this->get();

        return $view === 'desktop' ? $g['hide'] : ($g['hide_tools'] ?? $g['hide']);
    }

    /** True when migration 0026 is applied (the two columns exist), i.e. when a save of the hide settings can work. */
    public function hideAvailable(): bool
    {
        return array_key_exists('mesh_hide', $this->row());
    }

    /** A whole number 0..255 from an int or a plain digit string; null for anything else (null, '', 1.5, '12abc', 256, -1). */
    public static function cleanHide(mixed $v): ?int
    {
        if (is_string($v) && preg_match('/^[0-9]{1,3}$/', trim($v)) === 1) {
            $v = (int) trim($v);
        }

        return is_int($v) && $v >= 0 && $v <= MeshCookie::HIDE_MAX ? $v : null;
    }

    /**
     * The effective tables an installer carries (built-in plus corrections).
     *
     * @return array{agent_ids:array<string,int>,install_args:array<string,list<string>>}
     */
    public function effectiveTables(): array
    {
        $g = $this->get();

        return ['agent_ids' => MeshAgentCatalog::mergedAgentIds($g['agent_ids']), 'install_args' => MeshAgentCatalog::mergedInstallArgs($g['install_args'])];
    }

    /**
     * Validate and save. Only the keys present are changed. Unknown keys are an error.
     *
     * @param array<string,mixed> $in mesh_group_id, mesh_server_cert_pin, mesh_auto_map_overwrite, mesh_agent_ids, mesh_install_args, mesh_hide, mesh_hide_tools
     * @param string|null $meshUrl the Mesh address being saved together with these settings (default: the stored one), to check a pasted download address against
     * @return list<string> error messages; empty when saved
     */
    public function update(array $in, int $userId, ?string $meshUrl = null): array
    {
        $this->warnings = [];
        $known = ['mesh_group_id', 'mesh_server_cert_pin', 'mesh_auto_map_overwrite', 'mesh_agent_ids', 'mesh_install_args', 'mesh_hide', 'mesh_hide_tools'];
        $errors = [];
        foreach (array_keys($in) as $k) {
            if (!in_array($k, $known, true)) {
                $errors[] = 'Unknown setting ' . mb_substr((string) $k, 0, 40) . '.';
            }
        }
        $set = [];
        if (array_key_exists('mesh_group_id', $in)) {
            $raw = is_scalar($in['mesh_group_id']) ? trim((string) $in['mesh_group_id']) : null;
            if ($raw === '') {
                $set['group_id'] = '';
                $set['group_id_url'] = '';
            } elseif ($raw !== null && ($g = MeshAgentCatalog::parseGroup($raw, $meshUrl ?? ($this->settings === null ? null : (string) $this->settings->get()['mesh_url']))) !== null) {
                $set['group_id'] = $g['hex'];
                $set['group_id_url'] = $g['url'] ?? '';
                $this->warnings = array_merge($this->warnings, $g['warnings']);
            } else {
                $errors[] = self::GROUP_HELP_ERROR;
            }
        }
        if (array_key_exists('mesh_server_cert_pin', $in)) {
            $pin = is_scalar($in['mesh_server_cert_pin']) ? MeshAgentCatalog::normalizePin((string) $in['mesh_server_cert_pin']) : null;
            if ($pin === null) {
                $errors[] = 'The certificate pin must be the SHA-384 (96 hex characters) or SHA-256 (64) hash of the Mesh server certificate, or empty. Leave it empty when the server certificate is issued by a public CA such as Let\'s Encrypt (the system trust store verifies it); use a pin only for a private CA or a self-signed certificate.';
            } else {
                $set['server_cert_pin'] = $pin;
            }
        }
        if (array_key_exists('mesh_auto_map_overwrite', $in)) {
            if (!is_bool($in['mesh_auto_map_overwrite'])) {
                $errors[] = 'mesh_auto_map_overwrite must be true or false.';
            } else {
                $set['auto_map_overwrite'] = $in['mesh_auto_map_overwrite'] ? 1 : 0;
            }
        }
        if (array_key_exists('mesh_agent_ids', $in)) {
            $ids = MeshAgentCatalog::cleanAgentIds($in['mesh_agent_ids']);
            if ($ids === null) {
                $errors[] = 'mesh_agent_ids must be an object of "windows/amd64"-style keys (windows or linux) to agent ids between 1 and 255, at most ' . MeshAgentCatalog::MAX_AGENT_IDS . ' entries.';
            } else {
                $set['agent_ids_json'] = $ids === [] ? null : json_encode($ids, JSON_THROW_ON_ERROR);
            }
        }
        if (array_key_exists('mesh_install_args', $in)) {
            $args = MeshAgentCatalog::cleanInstallArgs($in['mesh_install_args']);
            if ($args === null) {
                $errors[] = 'mesh_install_args must be an object of windows/linux to a list of at most ' . MeshAgentCatalog::MAX_ARGS . ' simple arguments (letters, digits and = _ . : / @ -).';
            } else {
                $set['install_args_json'] = $args === [] ? null : json_encode($args, JSON_THROW_ON_ERROR);
            }
        }
        if (array_key_exists('mesh_hide', $in)) {
            $h = self::cleanHide($in['mesh_hide']);
            if ($h === null) {
                $errors[] = 'The Mesh interface hide value must be a whole number from 0 to 255 (31 = show only the remote screen, 0 = the full MeshCentral interface).';
            } else {
                $set['mesh_hide'] = $h;
            }
        }
        if (array_key_exists('mesh_hide_tools', $in)) {
            if ($in['mesh_hide_tools'] === null || $in['mesh_hide_tools'] === '') {
                $set['mesh_hide_tools'] = null;
            } elseif (($h = self::cleanHide($in['mesh_hide_tools'])) === null) {
                $errors[] = 'The Mesh terminal and files hide value must be empty (same as the desktop) or a whole number from 0 to 255.';
            } else {
                $set['mesh_hide_tools'] = $h;
            }
        }
        if ($errors !== [] || $set === []) {
            return $errors;
        }
        $existing = $this->row();   // also makes sure the row exists
        if (!array_key_exists('group_id_url', $existing)) {
            unset($set['group_id_url']);   // migration 0024 not applied yet: the URL form is derived from the hex
        }
        if (!$this->hideAvailable() && (isset($set['mesh_hide']) || array_key_exists('mesh_hide_tools', $set))) {
            return ['The Mesh interface settings need database migration 0026_rmm_mesh_hide; run the migrations first.'];
        }
        $cols = [];
        $vals = [];
        foreach ($set as $c => $v) {
            $cols[] = "`$c` = ?";
            $vals[] = $v;
        }
        $vals[] = $userId;
        $vals[] = $this->sql->utcNow();
        $this->sql->run('UPDATE endpoint_agent_mesh_settings SET ' . implode(', ', $cols) . ', updated_by = ?, updated_at = ? WHERE id = 1', $vals);
        $this->refresh();

        return [];
    }

    private static function decode(mixed $raw): mixed
    {
        return is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    }
}
