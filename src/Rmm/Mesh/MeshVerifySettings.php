<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

use RivetCore\Rmm\Support\Sql;

/**
 * The settings of the verified Mesh node mapping (1.0.0-rc.67), kept as one JSON document in `endpoint_agent_mesh_settings.verify_json` (migration 0052):
 *  - `mesh_verify_nodes`: OFF by default. When on, the housekeeping job asks MeshCentral itself which nodes exist and maps a device ONLY when every test of
 *    {@see MeshVerifyService} holds;
 *  - `mesh_service_account`: the MeshCentral account the read-only lookup signs in as (validated like the output of the account template: letters, digits, dot,
 *    underscore, hyphen);
 *  - `mesh_group_map`: client id => the MeshCentral device group (mesh) of that client. MeshCentral does not separate clients, so a client without an entry is never
 *    auto-mapped.
 *
 * @api
 */
final class MeshVerifySettings
{
    public const MAX_MAP = 500;
    public const MAX_ACCOUNT = 64;

    /** @var array<string,mixed>|null */
    private ?array $row = null;

    public function __construct(private readonly Sql $sql)
    {
    }

    public function refresh(): void
    {
        $this->row = null;
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        if ($this->row === null) {
            $r = $this->sql->one('SELECT * FROM endpoint_agent_mesh_settings WHERE id = 1');
            if ($r === null) {
                $this->sql->run('INSERT IGNORE INTO endpoint_agent_mesh_settings (id, updated_at) VALUES (1, ?)', [$this->sql->utcNow()]);
                $r = $this->sql->one('SELECT * FROM endpoint_agent_mesh_settings WHERE id = 1') ?? [];
            }
            $this->row = $r;
        }

        return $this->row;
    }

    /** True when migration 0052 is applied, i.e. when these settings can be saved. */
    public function available(): bool
    {
        return array_key_exists('verify_json', $this->row());
    }

    /**
     * @return array{enabled:bool,service_account:string,group_map:array<int,string>}
     */
    public function get(): array
    {
        $raw = $this->row()['verify_json'] ?? null;
        $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $j = is_array($j) ? $j : [];
        $map = [];
        foreach (is_array($j['group_map'] ?? null) ? $j['group_map'] : [] as $client => $hex) {
            if (is_int($client)) {   // a numeric string key is an int key in PHP
                $g = is_string($hex) ? self::groupHex($hex) : null;
                if ($g !== null && (int) $client > 0) {
                    $map[(int) $client] = $g;
                }
            }
        }
        $acct = is_string($j['service_account'] ?? null) ? $j['service_account'] : '';

        return ['enabled' => ($j['enabled'] ?? false) === true && self::validAccount($acct), 'service_account' => self::validAccount($acct) ? $acct : '', 'group_map' => $map];
    }

    /** The Mesh group (canonical hex) configured for a client, or null: no entry means "never auto-map". */
    public function groupFor(int $clientId): ?string
    {
        return $this->get()['group_map'][$clientId] ?? null;
    }

    /** A MeshCentral account name as the template output is validated: 1 to 64 of letters, digits, dot, underscore, hyphen, and nothing the sanitiser would remove. */
    public static function validAccount(string $a): bool
    {
        return $a !== '' && strlen($a) <= self::MAX_ACCOUNT && MeshCookie::accountName($a, 0, '') === $a;
    }

    /** Any spelling of a Mesh group id (`mesh//<id>`, `mesh/<domain>/<id>`, the 64-character URL form, a download address, hex) as canonical hex, or null. */
    public static function groupHex(string $in): ?string
    {
        $in = trim($in);
        if (preg_match('~^mesh/[A-Za-z0-9._-]*/(.+)$~D', $in, $m) === 1) {
            $in = 'mesh//' . $m[1];
        }
        $g = MeshAgentCatalog::parseGroup($in);

        return $g === null ? null : $g['hex'];
    }

    /**
     * Validate and save; only the keys present change. Nothing is saved when any value is invalid.
     *
     * @param array<string,mixed> $in mesh_verify_nodes (bool), mesh_service_account (string), mesh_group_map (object client id => group id)
     * @return list<string> errors; empty when saved
     */
    public function update(array $in, int $userId): array
    {
        if (!$this->available()) {
            return ['The verified Mesh mapping needs database migration 0052_rmm_mesh_verify; run the migrations first.'];
        }
        $errors = [];
        foreach (array_keys($in) as $k) {
            if (!in_array($k, ['mesh_verify_nodes', 'mesh_service_account', 'mesh_group_map'], true)) {
                $errors[] = 'Unknown setting ' . mb_substr((string) $k, 0, 40) . '.';
            }
        }
        $cur = $this->get();
        $enabled = $cur['enabled'];
        $acct = $cur['service_account'];
        $map = $cur['group_map'];
        if (array_key_exists('mesh_service_account', $in)) {
            if (!is_string($in['mesh_service_account']) || ($in['mesh_service_account'] !== '' && !self::validAccount($in['mesh_service_account']))) {
                $errors[] = 'The service account must be 1 to ' . self::MAX_ACCOUNT . ' characters: letters, digits, dot, underscore and hyphen.';
            } else {
                $acct = $in['mesh_service_account'];
            }
        }
        if (array_key_exists('mesh_group_map', $in)) {
            $m = $in['mesh_group_map'];
            if (!is_array($m) || count($m) > self::MAX_MAP) {
                $errors[] = 'mesh_group_map must be an object of client id to Mesh device group id, at most ' . self::MAX_MAP . ' entries.';
            } else {
                $map = [];
                foreach ($m as $client => $g) {
                    $hex = is_string($g) ? self::groupHex($g) : null;
                    if (!is_int($client) || $client < 1 || $client > 2000000000 || ($hex === null && $g !== '' && $g !== null)) {
                        $errors[] = 'Client ' . mb_substr((string) $client, 0, 20) . ': the Mesh device group id is not valid. ' . MeshAgentSettings::GROUP_HELP_ERROR;
                        continue;
                    }
                    if ($hex !== null) {
                        $map[(int) $client] = $hex;
                    }
                }
            }
        }
        if (array_key_exists('mesh_verify_nodes', $in)) {
            if (!is_bool($in['mesh_verify_nodes'])) {
                $errors[] = 'mesh_verify_nodes must be true or false.';
            } else {
                $enabled = $in['mesh_verify_nodes'];
            }
        }
        if ($enabled && $acct === '') {
            $errors[] = 'Set the MeshCentral service account before switching the verification on.';
        }
        if ($errors !== []) {
            return $errors;
        }
        ksort($map);
        $this->sql->run('UPDATE endpoint_agent_mesh_settings SET verify_json = ?, updated_by = ?, updated_at = ? WHERE id = 1',
            [json_encode(['enabled' => $enabled, 'service_account' => $acct, 'group_map' => (object) array_combine(array_map('strval', array_keys($map)), array_values($map))], JSON_THROW_ON_ERROR), $userId, $this->sql->utcNow()]);
        $this->refresh();

        return [];
    }
}
