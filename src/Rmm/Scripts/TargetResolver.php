<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Scripts;

use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Tags\GroupService;
use RivetCore\Rmm\Tags\TagService;

/**
 * Which devices a run or a schedule reaches. A target is `{type, id}` with type `device`, `tag`, `group`, `client`, `site`, `policy` (every device
 * a policy is assigned to) or `all`. Only live devices linked to an asset can receive a job, so only they are counted and returned; a caller
 * restricted to some clients passes the visible client ids and never sees (or reaches) a device outside them.
 *
 * @api
 */
final class TargetResolver
{
    public const TYPES = ['device', 'tag', 'group', 'client', 'site', 'policy', 'all'];

    public function __construct(private readonly Sql $sql, private readonly ?RmmTenancyInterface $tenancy = null)
    {
    }

    /**
     * @param mixed $in {type, id?}
     * @return array{0:?array{type:string,id:int},1:?string} [target, error]
     */
    public function validate(mixed $in): array
    {
        if (!is_array($in)) {
            return [null, 'A target is {"type": ..., "id": ...}.'];
        }
        $type = $in['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            return [null, 'target.type must be ' . implode(', ', self::TYPES) . '.'];
        }
        $idRaw = $in['id'] ?? 0;
        $id = is_int($idRaw) ? $idRaw : (is_string($idRaw) && ctype_digit($idRaw) ? (int) $idRaw : -1);
        if ($type === 'all') {
            return [['type' => 'all', 'id' => 0], null];
        }
        if ($id <= 0) {
            return [null, "target.id is required for a $type target."];
        }
        $exists = match ($type) {
            'device' => $this->sql->one('SELECT device_id FROM endpoint_agent_devices WHERE device_id = ?', [$id]) !== null,
            'tag' => $this->sql->one('SELECT tag_id FROM rmm_tags WHERE tag_id = ?', [$id]) !== null,
            'group' => $this->sql->one('SELECT group_id FROM rmm_groups WHERE group_id = ?', [$id]) !== null,
            'policy' => $this->sql->one('SELECT policy_id FROM rmm_policies WHERE policy_id = ?', [$id]) !== null,
            'client' => $this->tenancy === null || $this->tenancy->clientName($id) !== null,
            default => true,
        };

        return $exists ? [['type' => $type, 'id' => $id], null] : [null, "That $type does not exist."];
    }

    /**
     * @param list<int>|null $visibleClientIds null = every client
     * @param list<string> $platforms device operating systems the work can run on ([] = any)
     */
    public function count(string $type, int $id, ?array $visibleClientIds, array $platforms = []): int
    {
        [$where, $p] = $this->where($type, $id, $visibleClientIds, $platforms);

        return (int) $this->sql->val("SELECT COUNT(*) FROM endpoint_agent_devices d WHERE $where", $p);
    }

    /**
     * Devices in id order after a cursor.
     *
     * @param list<int>|null $visibleClientIds
     * @param list<string> $platforms
     * @param array{0:string,1:list<mixed>}|null $extra more conditions on alias d: [sql, params]
     * @return list<array<string,mixed>> endpoint_agent_devices rows
     */
    public function devices(string $type, int $id, ?array $visibleClientIds, array $platforms, int $afterDeviceId, int $limit, ?array $extra = null): array
    {
        [$where, $p] = $this->where($type, $id, $visibleClientIds, $platforms);
        if ($extra !== null) {
            $where .= ' AND (' . $extra[0] . ')';
            $p = [...$p, ...$extra[1]];
        }
        $limit = max(1, min(10000, $limit));

        return $this->sql->all("SELECT d.* FROM endpoint_agent_devices d WHERE $where AND d.device_id > ? ORDER BY d.device_id LIMIT $limit", [...$p, $afterDeviceId]);
    }

    /**
     * @param list<int>|null $visibleClientIds
     * @param list<string> $platforms
     * @return array{0:string,1:list<mixed>}
     */
    private function where(string $type, int $id, ?array $visibleClientIds, array $platforms): array
    {
        $w = ["d.revoked_at IS NULL", "d.retired_at IS NULL", "d.link_state = 'linked'", 'd.asset_id IS NOT NULL'];
        $p = [];
        switch ($type) {
            case 'device':
                $w[] = 'd.device_id = ?';
                $p[] = $id;
                break;
            case 'tag':
                $w[] = 'EXISTS (SELECT 1 FROM rmm_device_tags dt WHERE dt.device_id = d.device_id AND dt.tag_id = ?)';
                $p[] = $id;
                break;
            case 'group':
                $w[] = GroupService::membershipPredicate('d');
                array_push($p, $id, $id);
                break;
            case 'client':
                $w[] = 'd.client_id = ?';
                $p[] = $id;
                break;
            case 'site':
                $w[] = 'd.location_id = ?';
                $p[] = $id;
                break;
            case 'policy':
                [$sql, $pp] = $this->policyScope($id);
                $w[] = $sql;
                array_push($p, ...$pp);
                break;
            default:
                break;
        }
        if ($platforms !== []) {
            $w[] = 'd.os IN (' . implode(',', array_fill(0, count($platforms), '?')) . ')';
            array_push($p, ...$platforms);
        }
        [$vs, $vp] = TagService::scope($visibleClientIds, 'd');
        if ($vs !== '') {
            $w[] = ltrim(substr($vs, 4));   // " AND (...)" -> "(...)"
            array_push($p, ...$vp);
        }

        return [implode(' AND ', $w), $p];
    }

    /**
     * The devices a policy's assignments reach, as one SQL condition on alias d.
     *
     * @return array{0:string,1:list<mixed>}
     */
    private function policyScope(int $policyId): array
    {
        $or = [];
        $p = [];
        foreach ($this->sql->all('SELECT scope_type, scope_id FROM rmm_policy_assignments WHERE policy_id = ?', [$policyId]) as $a) {
            $sid = (int) $a['scope_id'];
            switch ($a['scope_type']) {
                case 'global':
                    return ['1 = 1', []];
                case 'client':
                    $or[] = 'd.client_id = ?';
                    $p[] = $sid;
                    break;
                case 'site':
                    $or[] = 'd.location_id = ?';
                    $p[] = $sid;
                    break;
                case 'device':
                    $or[] = 'd.device_id = ?';
                    $p[] = $sid;
                    break;
                case 'tag':
                    $or[] = 'EXISTS (SELECT 1 FROM rmm_device_tags dt WHERE dt.device_id = d.device_id AND dt.tag_id = ?)';
                    $p[] = $sid;
                    break;
                case 'group':
                    $or[] = GroupService::membershipPredicate('d');
                    array_push($p, $sid, $sid);
                    break;
            }
        }

        return $or === [] ? ['1 = 0', []] : ['(' . implode(' OR ', $or) . ')', $p];
    }
}
