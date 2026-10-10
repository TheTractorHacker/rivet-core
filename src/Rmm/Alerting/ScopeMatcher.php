<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

use RivetCore\Rmm\Support\Sql;

/**
 * "Does this scope contain this device": the scope vocabulary shared by maintenance windows and escalation policies. A scope is a type
 * (all, client, site, group, tag, device) and an id. A site is the device's `location_id`; a group counts its tag-derived members. Group
 * and tag answers are remembered per instance, so a long-running worker asks the database once per device and scope.
 *
 * @api
 */
final class ScopeMatcher
{
    public const SCOPES = ['all', 'client', 'site', 'group', 'tag', 'device'];
    /** Most specific first: the order in which an escalation policy is chosen when several match. */
    public const SPECIFICITY = ['device' => 6, 'group' => 5, 'tag' => 4, 'site' => 3, 'client' => 2, 'all' => 1];

    /** @var array<string,bool> */
    private array $member = [];

    public function __construct(private readonly Sql $sql)
    {
    }

    public function forget(): void
    {
        $this->member = [];
    }

    /** @param array<string,mixed> $dev the device row (device_id, client_id, location_id) */
    public function matches(string $type, int $id, array $dev): bool
    {
        switch ($type) {
            case 'all':
                return true;
            case 'client':
                return (int) ($dev['client_id'] ?? 0) === $id;
            case 'site':
                return (int) ($dev['location_id'] ?? 0) === $id;
            case 'device':
                return (int) ($dev['device_id'] ?? 0) === $id;
            case 'tag':
                $k = 't' . $id . ':' . $dev['device_id'];

                return $this->member[$k] ??= $this->sql->one('SELECT 1 AS x FROM rmm_device_tags WHERE device_id = ? AND tag_id = ?', [$dev['device_id'], $id]) !== null;
            case 'group':
                $k = 'g' . $id . ':' . $dev['device_id'];

                return $this->member[$k] ??= $this->sql->one('SELECT 1 AS x FROM rmm_groups g WHERE g.group_id = ? AND (EXISTS (SELECT 1 FROM rmm_group_devices gd WHERE gd.group_id = g.group_id AND gd.device_id = ?)
                    OR EXISTS (SELECT 1 FROM rmm_group_tags gt JOIN rmm_device_tags dt ON dt.tag_id = gt.tag_id WHERE gt.group_id = g.group_id AND dt.device_id = ?))', [$id, $dev['device_id'], $dev['device_id']]) !== null;
        }

        return false;
    }

    /**
     * @throws \InvalidArgumentException when the scope names something that does not exist
     */
    public function assertExists(string $type, int $id): void
    {
        $table = ['group' => ['rmm_groups', 'group_id'], 'tag' => ['rmm_tags', 'tag_id'], 'device' => ['endpoint_agent_devices', 'device_id']][$type] ?? null;
        if ($table !== null && $this->sql->one("SELECT 1 AS x FROM `{$table[0]}` WHERE `{$table[1]}` = ?", [$id]) === null) {
            throw new \InvalidArgumentException("That $type does not exist.");
        }
    }
}
