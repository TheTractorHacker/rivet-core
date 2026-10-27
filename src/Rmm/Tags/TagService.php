<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Tags;

use RivetCore\Rmm\Support\Sql;

/**
 * Tags on devices (tables rmm_tags and rmm_device_tags): short free labels an administrator or a technician with the manage grant puts
 * on a device ("kiosk", "vip", "patch-ring-1"). Names are unique without regard to case. Tags are global to the module; which devices a
 * caller can see is decided by client scope where the devices are read, never here. Methods throw \InvalidArgumentException with a
 * message safe to show.
 *
 * @api
 */
final class TagService
{
    public const MAX_TAGS = 1000;
    public const MAX_PER_DEVICE = 50;
    public const NAME_MAX = 60;
    private const NAME_RE = '/^[\p{L}\p{N}][\p{L}\p{N} _.:\/+-]{0,59}$/u';
    private const COLOR_RE = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(private readonly Sql $sql)
    {
    }

    /** The stored form of a name (whitespace collapsed), or null when it is not an acceptable tag. */
    public static function cleanName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        $n = trim((string) preg_replace('/\s+/u', ' ', $name));

        return preg_match(self::NAME_RE, $n) === 1 ? $n : null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $tagId): ?array
    {
        $r = $this->sql->one('SELECT * FROM rmm_tags WHERE tag_id = ?', [$tagId]);

        return $r === null ? null : self::row($r);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        $r = $this->sql->one('SELECT * FROM rmm_tags WHERE name = ?', [$name]);

        return $r === null ? null : self::row($r);
    }

    /**
     * Create a tag. Creating one that exists (any case) returns it unchanged.
     *
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function create(string $name, string $color = '', string $description = '', int $userId = 0): array
    {
        $clean = self::cleanName($name);
        if ($clean === null) {
            throw new \InvalidArgumentException('A tag name is 1 to ' . self::NAME_MAX . ' letters, digits, spaces and . _ : / + -.');
        }
        if ($color !== '' && preg_match(self::COLOR_RE, $color) !== 1) {
            throw new \InvalidArgumentException('A tag color is empty or like #1a2b3c.');
        }
        $existing = $this->findByName($clean);
        if ($existing !== null) {
            return $existing;
        }
        if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_tags') >= self::MAX_TAGS) {
            throw new \InvalidArgumentException('There are already ' . self::MAX_TAGS . ' tags.');
        }
        $this->sql->run('INSERT IGNORE INTO rmm_tags (name, color, description, created_by, created_at) VALUES (?, ?, ?, ?, ?)',
            [$clean, strtolower($color), mb_substr($description, 0, 200), $userId, $this->sql->utcNow()]);

        return $this->findByName($clean) ?? throw new \InvalidArgumentException('The tag could not be created.');
    }

    /**
     * Change a tag's name, color or description. A name that another tag already has is refused.
     *
     * @param array{name?:string,color?:string,description?:string} $in
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function update(int $tagId, array $in): array
    {
        $cur = $this->find($tagId) ?? throw new \InvalidArgumentException('Tag not found.');
        $name = $cur['name'];
        if (isset($in['name'])) {
            $name = self::cleanName($in['name']) ?? throw new \InvalidArgumentException('A tag name is 1 to ' . self::NAME_MAX . ' letters, digits, spaces and . _ : / + -.');
            $other = $this->findByName($name);
            if ($other !== null && $other['tag_id'] !== $tagId) {
                throw new \InvalidArgumentException('Another tag already has that name.');
            }
        }
        $color = $in['color'] ?? $cur['color'];
        if ($color !== '' && preg_match(self::COLOR_RE, (string) $color) !== 1) {
            throw new \InvalidArgumentException('A tag color is empty or like #1a2b3c.');
        }
        $color = strtolower((string) $color);
        $description = mb_substr((string) ($in['description'] ?? $cur['description']), 0, 200);
        $this->sql->run('UPDATE rmm_tags SET name = ?, color = ?, description = ? WHERE tag_id = ?', [$name, $color, $description, $tagId]);

        return ['tag_id' => $tagId, 'name' => (string) $name, 'color' => $color, 'description' => $description];
    }

    /** Delete a tag and take it off every device and group. Returns false when it did not exist. */
    public function delete(int $tagId): bool
    {
        return $this->sql->transaction(function () use ($tagId): bool {
            $this->sql->run('DELETE FROM rmm_device_tags WHERE tag_id = ?', [$tagId]);
            $this->sql->run('DELETE FROM rmm_group_tags WHERE tag_id = ?', [$tagId]);

            return $this->sql->run('DELETE FROM rmm_tags WHERE tag_id = ?', [$tagId]) === 1;
        });
    }

    /**
     * SQL and parameters that restrict a device alias to the clients a caller may see (client 0 is always visible), or nothing for "all".
     *
     * @param list<int>|null $visibleClientIds
     * @return array{0:string,1:list<int>}
     */
    public static function scope(?array $visibleClientIds, string $alias = 'd'): array
    {
        if ($visibleClientIds === null) {
            return ['', []];
        }
        if ($visibleClientIds === []) {
            return [" AND $alias.client_id = 0", []];
        }

        return [" AND ($alias.client_id = 0 OR $alias.client_id IN (" . implode(',', array_fill(0, count($visibleClientIds), '?')) . '))', array_map('intval', $visibleClientIds)];
    }

    /**
     * Every tag with the number of devices that carry it (live devices only; with $visibleClientIds only those in the caller's clients).
     *
     * @param list<int>|null $visibleClientIds
     * @return list<array<string,mixed>>
     */
    public function all(?array $visibleClientIds = null): array
    {
        $out = [];
        [$scope, $params] = self::scope($visibleClientIds);
        foreach ($this->sql->all('SELECT t.*, (SELECT COUNT(*) FROM rmm_device_tags dt JOIN endpoint_agent_devices d ON d.device_id = dt.device_id
            WHERE dt.tag_id = t.tag_id AND d.retired_at IS NULL' . $scope . ') AS device_count FROM rmm_tags t ORDER BY t.name LIMIT ' . self::MAX_TAGS, $params) as $r) {
            $out[] = self::row($r) + ['device_count' => (int) $r['device_count']];
        }

        return $out;
    }

    /**
     * Put a tag on a device (idempotent). The tag may be given by id or created by name.
     *
     * @return array<string,mixed> the tag
     * @throws \InvalidArgumentException
     */
    public function assign(int $deviceId, int|string $tag, string $source = 'manual', int $userId = 0): array
    {
        $t = is_int($tag) ? ($this->find($tag) ?? throw new \InvalidArgumentException('Tag not found.')) : $this->create($tag, '', '', $userId);
        $has = (int) $this->sql->val('SELECT COUNT(*) FROM rmm_device_tags WHERE device_id = ?', [$deviceId]);
        $already = $this->sql->val('SELECT 1 FROM rmm_device_tags WHERE device_id = ? AND tag_id = ?', [$deviceId, $t['tag_id']]) !== null;
        if (!$already && $has >= self::MAX_PER_DEVICE) {
            throw new \InvalidArgumentException('A device can carry at most ' . self::MAX_PER_DEVICE . ' tags.');
        }
        $this->sql->run('INSERT IGNORE INTO rmm_device_tags (device_id, tag_id, source, created_by, created_at) VALUES (?, ?, ?, ?, ?)',
            [$deviceId, $t['tag_id'], $source === 'auto' ? 'auto' : 'manual', $userId, $this->sql->utcNow()]);

        return $t;
    }

    public function unassign(int $deviceId, int $tagId): bool
    {
        return $this->sql->run('DELETE FROM rmm_device_tags WHERE device_id = ? AND tag_id = ?', [$deviceId, $tagId]) === 1;
    }

    /**
     * @return list<array<string,mixed>> the tags of one device, by name
     */
    public function forDevice(int $deviceId): array
    {
        return $this->forDevices([$deviceId])[$deviceId] ?? [];
    }

    /**
     * The tags of many devices in one query.
     *
     * @param list<int> $deviceIds
     * @return array<int,list<array<string,mixed>>> device id => tags
     */
    public function forDevices(array $deviceIds): array
    {
        $out = [];
        if ($deviceIds === []) {
            return $out;
        }
        $rows = $this->sql->all('SELECT dt.device_id, dt.source, t.tag_id, t.name, t.color, t.description, t.created_by, t.created_at FROM rmm_device_tags dt
            JOIN rmm_tags t ON t.tag_id = dt.tag_id WHERE dt.device_id IN (' . implode(',', array_fill(0, count($deviceIds), '?')) . ') ORDER BY t.name', array_map('intval', $deviceIds));
        foreach ($rows as $r) {
            $out[(int) $r['device_id']][] = self::row($r) + ['source' => (string) $r['source']];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function row(array $r): array
    {
        return ['tag_id' => (int) $r['tag_id'], 'name' => (string) $r['name'], 'color' => (string) $r['color'], 'description' => (string) $r['description']];
    }
}
