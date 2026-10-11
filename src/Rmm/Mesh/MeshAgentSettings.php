<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

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
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Sql $sql)
    {
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
     * @return array{group_id:string,server_cert_pin:string,auto_map_overwrite:bool,agent_ids:array<string,int>,install_args:array<string,list<string>>}
     */
    public function get(): array
    {
        $r = $this->row();
        $ids = MeshAgentCatalog::cleanAgentIds(self::decode($r['agent_ids_json'] ?? null)) ?? [];
        $args = MeshAgentCatalog::cleanInstallArgs(self::decode($r['install_args_json'] ?? null)) ?? [];

        return ['group_id' => (string) ($r['group_id'] ?? ''), 'server_cert_pin' => (string) ($r['server_cert_pin'] ?? ''), 'auto_map_overwrite' => (int) ($r['auto_map_overwrite'] ?? 0) === 1,
            'agent_ids' => $ids, 'install_args' => $args];
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
     * @param array<string,mixed> $in mesh_group_id, mesh_server_cert_pin, mesh_auto_map_overwrite, mesh_agent_ids, mesh_install_args
     * @return list<string> error messages; empty when saved
     */
    public function update(array $in, int $userId): array
    {
        $known = ['mesh_group_id', 'mesh_server_cert_pin', 'mesh_auto_map_overwrite', 'mesh_agent_ids', 'mesh_install_args'];
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
            } elseif ($raw !== null && ($g = MeshAgentCatalog::normalizeGroupId($raw)) !== null) {
                $set['group_id'] = $g;
            } else {
                $errors[] = 'The Mesh device group id must be the hex id from the MeshCentral installer address (meshid=...), or MeshCentral\'s mesh//... form.';
            }
        }
        if (array_key_exists('mesh_server_cert_pin', $in)) {
            $pin = is_scalar($in['mesh_server_cert_pin']) ? MeshAgentCatalog::normalizePin((string) $in['mesh_server_cert_pin']) : null;
            if ($pin === null) {
                $errors[] = 'The certificate pin must be the SHA-384 (96 hex characters) or SHA-256 (64) hash of the Mesh server certificate, or empty to use the system trust store.';
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
        if ($errors !== [] || $set === []) {
            return $errors;
        }
        $this->row();   // make sure the row exists
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
