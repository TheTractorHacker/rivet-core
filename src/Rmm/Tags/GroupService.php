<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Tags;

use RivetCore\Rmm\Support\Sql;

/**
 * Device groups (tables rmm_groups, rmm_group_devices, rmm_group_tags). A group is a named set of devices: the devices added to it by
 * hand (static members) plus every device that carries one of the group's tags (tag membership). Rules beyond that (by software, by
 * OS, by site) are a later phase; a rule is expressed today by tagging. Groups are global to the module; client scope is applied where
 * devices are read. Methods throw \InvalidArgumentException with a message safe to show.
 *
 * @api
 */
final class GroupService
{
    public const MAX_GROUPS = 500;
    public const MAX_STATIC_MEMBERS = 5000;
    public const NAME_MAX = 100;

    public function __construct(private readonly Sql $sql)
    {
    }

    /**
     * SQL that is true when the device row aliased $deviceAlias belongs to the group the given placeholder ("?") names. The placeholder
     * must be bound TWICE, in order, to the group id.
     */
    public static function membershipPredicate(string $deviceAlias = 'd'): string
    {
        return "(EXISTS (SELECT 1 FROM rmm_group_devices gd WHERE gd.group_id = ? AND gd.device_id = $deviceAlias.device_id)"
            . " OR EXISTS (SELECT 1 FROM rmm_group_tags gt JOIN rmm_device_tags dt ON dt.tag_id = gt.tag_id WHERE gt.group_id = ? AND dt.device_id = $deviceAlias.device_id))";
    }

    /** @return array<string,mixed>|null */
    public function find(int $groupId): ?array
    {
        $r = $this->sql->one('SELECT * FROM rmm_groups WHERE group_id = ?', [$groupId]);

        return $r === null ? null : $this->row($r);
    }

    /**
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function create(string $name, string $description = '', int $userId = 0): array
    {
        $n = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($n === '' || mb_strlen($n) > self::NAME_MAX || preg_match('/[\x00-\x1f\x7f]/', $n) === 1) {
            throw new \InvalidArgumentException('A group name is 1 to ' . self::NAME_MAX . ' characters.');
        }
        if ($this->sql->val('SELECT 1 FROM rmm_groups WHERE name = ?', [$n]) !== null) {
            throw new \InvalidArgumentException('A group with that name already exists.');
        }
        if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_groups') >= self::MAX_GROUPS) {
            throw new \InvalidArgumentException('There are already ' . self::MAX_GROUPS . ' groups.');
        }
        $id = $this->sql->insert('INSERT INTO rmm_groups (name, description, created_by, created_at) VALUES (?, ?, ?, ?)', [$n, mb_substr($description, 0, 200), $userId, $this->sql->utcNow()]);

        return $this->find($id) ?? throw new \InvalidArgumentException('The group could not be created.');
    }

    /**
     * @param array{name?:string,description?:string} $in
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function update(int $groupId, array $in): array
    {
        $cur = $this->find($groupId) ?? throw new \InvalidArgumentException('Group not found.');
        $name = $cur['name'];
        if (isset($in['name'])) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $in['name']));
            if ($name === '' || mb_strlen($name) > self::NAME_MAX || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                throw new \InvalidArgumentException('A group name is 1 to ' . self::NAME_MAX . ' characters.');
            }
            $clash = $this->sql->val('SELECT group_id FROM rmm_groups WHERE name = ? AND group_id <> ?', [$name, $groupId]);
            if ($clash !== null) {
                throw new \InvalidArgumentException('A group with that name already exists.');
            }
        }
        $description = mb_substr((string) ($in['description'] ?? $cur['description']), 0, 200);
        $this->sql->run('UPDATE rmm_groups SET name = ?, description = ? WHERE group_id = ?', [$name, $description, $groupId]);

        return ['group_id' => $groupId, 'name' => (string) $name, 'description' => $description];
    }

    public function delete(int $groupId): bool
    {
        return $this->sql->transaction(function () use ($groupId): bool {
            $this->sql->run('DELETE FROM rmm_group_devices WHERE group_id = ?', [$groupId]);
            $this->sql->run('DELETE FROM rmm_group_tags WHERE group_id = ?', [$groupId]);

            return $this->sql->run('DELETE FROM rmm_groups WHERE group_id = ?', [$groupId]) === 1;
        });
    }

    /**
     * Every group with its tags and the number of devices in it (static plus tagged, counted once; with $visibleClientIds only the
     * devices in the caller's clients).
     *
     * @param list<int>|null $visibleClientIds
     * @return list<array<string,mixed>>
     */
    public function all(?array $visibleClientIds = null): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT g.* FROM rmm_groups g ORDER BY g.name LIMIT ' . self::MAX_GROUPS) as $r) {
            $out[] = $this->row($r) + ['tags' => $this->tagsOf((int) $r['group_id']), 'device_count' => $this->memberCount((int) $r['group_id'], $visibleClientIds)];
        }

        return $out;
    }

    /** @return list<array{tag_id:int,name:string}> */
    public function tagsOf(int $groupId): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT t.tag_id, t.name FROM rmm_group_tags gt JOIN rmm_tags t ON t.tag_id = gt.tag_id WHERE gt.group_id = ? ORDER BY t.name', [$groupId]) as $r) {
            $out[] = ['tag_id' => (int) $r['tag_id'], 'name' => (string) $r['name']];
        }

        return $out;
    }

    /** @param list<int>|null $visibleClientIds */
    public function memberCount(int $groupId, ?array $visibleClientIds = null): int
    {
        [$scope, $params] = TagService::scope($visibleClientIds);

        return (int) $this->sql->val('SELECT COUNT(*) FROM endpoint_agent_devices d WHERE d.retired_at IS NULL AND ' . self::membershipPredicate('d') . $scope, array_merge([$groupId, $groupId], $params));
    }

    /**
     * Add devices by hand (idempotent).
     *
     * @param list<int> $deviceIds
     * @return int devices newly added
     * @throws \InvalidArgumentException
     */
    public function addDevices(int $groupId, array $deviceIds): int
    {
        $this->find($groupId) ?? throw new \InvalidArgumentException('Group not found.');
        $deviceIds = array_values(array_unique(array_map('intval', $deviceIds)));
        if ($deviceIds === []) {
            return 0;
        }
        if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_group_devices WHERE group_id = ?', [$groupId]) + count($deviceIds) > self::MAX_STATIC_MEMBERS) {
            throw new \InvalidArgumentException('A group holds at most ' . self::MAX_STATIC_MEMBERS . ' devices added by hand; use a tag for more.');
        }
        $added = 0;
        $now = $this->sql->utcNow();
        foreach (array_chunk($deviceIds, 250) as $chunk) {
            $params = [];
            foreach ($chunk as $id) {
                array_push($params, $groupId, $id, $now);
            }
            $added += $this->sql->run('INSERT IGNORE INTO rmm_group_devices (group_id, device_id, created_at) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?)')), $params);
        }

        return $added;
    }

    public function removeDevice(int $groupId, int $deviceId): bool
    {
        return $this->sql->run('DELETE FROM rmm_group_devices WHERE group_id = ? AND device_id = ?', [$groupId, $deviceId]) === 1;
    }

    /**
     * Replace the group's tags: a device carrying any of them is a member.
     *
     * @param list<int> $tagIds
     * @throws \InvalidArgumentException when a tag does not exist
     */
    public function setTags(int $groupId, array $tagIds): void
    {
        $this->find($groupId) ?? throw new \InvalidArgumentException('Group not found.');
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        if (count($tagIds) > 50) {
            throw new \InvalidArgumentException('A group can follow at most 50 tags.');
        }
        if ($tagIds !== []) {
            $n = (int) $this->sql->val('SELECT COUNT(*) FROM rmm_tags WHERE tag_id IN (' . implode(',', array_fill(0, count($tagIds), '?')) . ')', $tagIds);
            if ($n !== count($tagIds)) {
                throw new \InvalidArgumentException('Tag not found.');
            }
        }
        $this->sql->transaction(function () use ($groupId, $tagIds): void {
            $this->sql->run('DELETE FROM rmm_group_tags WHERE group_id = ?', [$groupId]);
            foreach ($tagIds as $t) {
                $this->sql->run('INSERT IGNORE INTO rmm_group_tags (group_id, tag_id) VALUES (?, ?)', [$groupId, $t]);
            }
        });
    }

    /**
     * The groups a device belongs to.
     *
     * @return list<array{group_id:int,name:string}>
     */
    public function forDevice(int $deviceId): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT g.group_id, g.name FROM rmm_groups g WHERE EXISTS (SELECT 1 FROM rmm_group_devices gd WHERE gd.group_id = g.group_id AND gd.device_id = ?)
            OR EXISTS (SELECT 1 FROM rmm_group_tags gt JOIN rmm_device_tags dt ON dt.tag_id = gt.tag_id WHERE gt.group_id = g.group_id AND dt.device_id = ?) ORDER BY g.name', [$deviceId, $deviceId]) as $r) {
            $out[] = ['group_id' => (int) $r['group_id'], 'name' => (string) $r['name']];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function row(array $r): array
    {
        return ['group_id' => (int) $r['group_id'], 'name' => (string) $r['name'], 'description' => (string) $r['description']];
    }
}
