<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Support\Sql;

/**
 * Vendor parameter VALUES of a package (an organisation key, an account key, a server address): the names a package may have are exactly the placeholders of its
 * version templates ({@see ArgsTemplate}); a value is stored for the whole package (`global`) or overrides it for one client (`client`) or one location
 * (`location`); the most specific scope wins. Values are SEALED with the edition's SecretBox ({@see SecretBoxInterface}); nothing in this class, the API or the audit
 * trail ever returns one: a reader only learns that a name is set, where, and when. A value is unsealed only when a job is built, and it reaches the job only
 * through the job sidecar's secret slot ({@see \RivetCore\Rmm\Job\JobExtras}).
 *
 * @api
 */
final class PackageParams
{
    public const SCOPES = ['global', 'client', 'location'];

    public function __construct(private readonly Sql $sql, private readonly SecretBoxInterface $box, private readonly PackageCatalog $catalog)
    {
    }

    /**
     * The names this package's versions declare.
     *
     * @return list<string>
     */
    public function declared(int $packageId): array
    {
        $names = [];
        foreach ($this->sql->all("SELECT silent_args FROM rmm_package_versions WHERE package_id = ? AND silent_args LIKE '%{{%'", [$packageId]) as $r) {
            foreach (ArgsTemplate::names((string) $r['silent_args']) as $n) {
                $names[$n] = true;
            }
        }
        $out = array_map('strval', array_keys($names));
        sort($out);

        return $out;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function set(int $packageId, string $scopeType, int $scopeId, mixed $name, mixed $value, int $userId): void
    {
        $this->check($packageId, $scopeType, $scopeId, $name);
        if (!ArgsTemplate::valueOk($value)) {
            throw new \InvalidArgumentException('A parameter value is 1 to 128 characters of letters, digits and . _ ~ : / = + , - (no spaces, quotes or other symbols).');
        }
        try {
            $enc = $this->box->encrypt((string) $value);
        } catch (\RuntimeException) {
            throw new \InvalidArgumentException('The value could not be sealed: the settings vault has no encryption key.');
        }
        $this->sql->run('INSERT INTO rmm_package_params (package_id, scope_type, scope_id, name, value_enc, set_by, set_at) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE value_enc = VALUES(value_enc), set_by = VALUES(set_by), set_at = VALUES(set_at)', [$packageId, $scopeType, $scopeId, $name, $enc, $userId, $this->sql->utcNow()]);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function clear(int $packageId, string $scopeType, int $scopeId, mixed $name): bool
    {
        $this->check($packageId, $scopeType, $scopeId, $name);

        return $this->sql->run('DELETE FROM rmm_package_params WHERE package_id = ? AND scope_type = ? AND scope_id = ? AND name = ?', [$packageId, $scopeType, $scopeId, $name]) > 0;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function check(int $packageId, string $scopeType, int $scopeId, mixed $name): void
    {
        if ($this->catalog->package($packageId) === null) {
            throw new \InvalidArgumentException('Package not found.');
        }
        if (!in_array($scopeType, self::SCOPES, true) || ($scopeType === 'global') !== ($scopeId === 0) || $scopeId < 0) {
            throw new \InvalidArgumentException('scope is global (no id), client or location (with its id).');
        }
        if (!is_string($name) || !in_array($name, $this->declared($packageId), true)) {
            throw new \InvalidArgumentException('That name is not a placeholder of any version of this package. Add a version whose arguments use {{name}} first.');
        }
    }

    /**
     * What is set, never the values.
     *
     * @return list<array{name:string,scope:string,scope_id:int,set_at:?string,set_by:int}>
     */
    public function status(int $packageId): array
    {
        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'scope' => (string) $r['scope_type'], 'scope_id' => (int) $r['scope_id'],
            'set_at' => Sql::iso((string) $r['set_at']), 'set_by' => (int) $r['set_by']],
            $this->sql->all('SELECT name, scope_type, scope_id, set_at, set_by FROM rmm_package_params WHERE package_id = ? ORDER BY name, FIELD(scope_type, \'global\', \'client\', \'location\'), scope_id', [$packageId]));
    }

    /**
     * Which of `$names` have a value for a device of this client and location (the most specific wins). Names only.
     *
     * @param list<string> $names
     * @return list<string> the names with NO value
     */
    public function missing(int $packageId, int $clientId, int $locationId, array $names): array
    {
        $have = array_keys($this->values($packageId, $clientId, $locationId, $names, true));

        return array_values(array_diff($names, $have));
    }

    /**
     * The unsealed values for one device, for building a job. Only the job builder calls this.
     *
     * @param list<string> $names
     * @return array<string,string>
     */
    public function resolve(int $packageId, int $clientId, int $locationId, array $names): array
    {
        return $this->values($packageId, $clientId, $locationId, $names, true);
    }

    /**
     * @param list<string> $names
     * @return array<string,string> name => value ('' when not unsealed)
     */
    private function values(int $packageId, int $clientId, int $locationId, array $names, bool $unseal): array
    {
        if ($names === []) {
            return [];
        }
        $rows = $this->sql->all('SELECT name, scope_type, scope_id, value_enc FROM rmm_package_params WHERE package_id = ? AND ((scope_type = \'global\') OR (scope_type = \'client\' AND scope_id = ?) OR (scope_type = \'location\' AND scope_id = ?))',
            [$packageId, $clientId, $locationId]);
        $rank = ['global' => 0, 'client' => 1, 'location' => 2];
        $best = [];
        foreach ($rows as $r) {
            $n = (string) $r['name'];
            if (!in_array($n, $names, true)) {
                continue;
            }
            if (!isset($best[$n]) || $rank[(string) $r['scope_type']] > $rank[(string) $best[$n]['scope_type']]) {
                $best[$n] = $r;
            }
        }
        $out = [];
        foreach ($best as $n => $r) {
            if (!$unseal) {
                $out[$n] = '';
                continue;
            }
            $v = $this->box->decrypt((string) $r['value_enc']);
            if (ArgsTemplate::valueOk($v)) {   // a value that cannot be unsealed (another key) or is not valid any more counts as missing
                $out[$n] = $v;
            }
        }

        return $out;
    }
}
