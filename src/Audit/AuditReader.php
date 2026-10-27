<?php

declare(strict_types=1);

namespace RivetCore\Audit;

use RivetCore\Database\DatabaseInterface;

/**
 * Read side of audit_events (AuditService is the write side). Everything uses prepared parameters; the only SQL
 * text interpolated is integers that have been clamped here.
 *
 * Filters (all optional, all AND-ed), passed as an array:
 *   eventType   string  exact event type, or a group prefix: 'settings' matches 'settings' and 'settings.*'
 *   actorUserId int     actor user id (> 0)
 *   entityType  string
 *   entityId    string|int
 *   from, to    string  inclusive. 'YYYY-MM-DD' (to = end of that day) or 'YYYY-MM-DD HH:MM:SS'; invalid values are ignored
 *   search      string  free text over summary, event_type, entity_id, ip_address and metadata_json (wildcards escaped)
 *
 * Rows carry no user names (the users table belongs to the edition); join them in the edition by actor_user_id.
 *
 * @api
 */
final class AuditReader
{
    public const MAX_PER_PAGE = 200;
    public const DEFAULT_PER_PAGE = 50;
    public const DEFAULT_EXPORT_CAP = 50000;
    private const CHUNK = 1000;

    public function __construct(private DatabaseInterface $database)
    {
    }

    /** @param array<string,mixed> $filters */
    public function page(array $filters = [], int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): AuditPage
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        [$where, $params] = $this->where($filters);
        $total = (int) ($this->database->fetchOne("SELECT COUNT(*) AS n FROM audit_events e WHERE $where", $params)['n'] ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;
        $rows = $this->database->fetchAll(
            "SELECT e.* FROM audit_events e WHERE $where ORDER BY e.audit_id DESC LIMIT $perPage OFFSET $offset",
            $params
        );

        return new AuditPage(array_map(self::decode(...), $rows), $total, $page, $pages, $perPage);
    }

    /**
     * Rows newest first, fetched in chunks (keyset on audit_id), at most $maxRows in total. For CSV export.
     *
     * @param array<string,mixed> $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function iterate(array $filters = [], int $maxRows = self::DEFAULT_EXPORT_CAP, int $chunkSize = self::CHUNK): \Generator
    {
        $maxRows = max(0, $maxRows);
        $chunkSize = max(1, min(self::CHUNK, $chunkSize));
        [$where, $params] = $this->where($filters);
        $yielded = 0;
        $before = null;
        while ($yielded < $maxRows) {
            $limit = min($chunkSize, $maxRows - $yielded);
            $sql = "SELECT e.* FROM audit_events e WHERE $where" . ($before !== null ? ' AND e.audit_id < ?' : '') . " ORDER BY e.audit_id DESC LIMIT $limit";
            $rows = $this->database->fetchAll($sql, $before !== null ? [...$params, $before] : $params);
            foreach ($rows as $row) {
                yield self::decode($row);
                $yielded++;
                $before = (int) $row['audit_id'];
            }
            if (count($rows) < $limit) {
                return;
            }
        }
    }

    /**
     * Event group (text before the first dot) => number of events, unfiltered.
     *
     * @return array<string,int>
     */
    public function groups(): array
    {
        $out = [];
        foreach ($this->database->fetchAll("SELECT SUBSTRING_INDEX(event_type, '.', 1) AS grp, COUNT(*) AS n FROM audit_events GROUP BY grp ORDER BY grp") as $r) {
            $out[(string) $r['grp']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Distinct non-null actor user ids, ascending.
     *
     * @return list<int>
     */
    public function actors(): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['actor_user_id'],
            $this->database->fetchAll('SELECT DISTINCT actor_user_id FROM audit_events WHERE actor_user_id IS NOT NULL ORDER BY actor_user_id')
        );
    }

    /** @return array<string,mixed>|null decoded metadata, or null when empty / invalid / not an object or list */
    public static function decodeMetadata(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        try {
            $v = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($v) ? $v : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function decode(array $row): array
    {
        $row['metadata'] = self::decodeMetadata($row['metadata_json'] ?? null);

        return $row;
    }

    /**
     * @param array<string,mixed> $f
     * @return array{0:string,1:list<string|int>}
     */
    private function where(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        $search = trim((string) ($f['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . self::escapeLike($search) . '%';
            $where[] = '(e.summary LIKE ? OR e.event_type LIKE ? OR e.entity_id LIKE ? OR e.ip_address LIKE ? OR e.metadata_json LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $type = trim((string) ($f['eventType'] ?? ''));
        if ($type !== '') {
            $where[] = '(e.event_type = ? OR e.event_type LIKE ?)';
            array_push($params, $type, self::escapeLike($type) . '.%');
        }
        $actor = (int) ($f['actorUserId'] ?? 0);
        if ($actor > 0) {
            $where[] = 'e.actor_user_id = ?';
            $params[] = $actor;
        }
        $entityType = trim((string) ($f['entityType'] ?? ''));
        if ($entityType !== '') {
            $where[] = 'e.entity_type = ?';
            $params[] = $entityType;
        }
        if (isset($f['entityId']) && (string) $f['entityId'] !== '') {
            $where[] = 'e.entity_id = ?';
            $params[] = (string) $f['entityId'];
        }
        $from = self::bound($f['from'] ?? null, '00:00:00');
        if ($from !== null) {
            $where[] = 'e.created_at >= ?';
            $params[] = $from;
        }
        $to = self::bound($f['to'] ?? null, '23:59:59');
        if ($to !== null) {
            $where[] = 'e.created_at <= ?';
            $params[] = $to;
        }

        return [implode(' AND ', $where), $params];
    }

    private static function bound(mixed $value, string $dayTime): ?string
    {
        $v = trim((string) ($value ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v . ' ' . $dayTime;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $v)) {
            return str_replace('T', ' ', $v);
        }

        return null;
    }

    private static function escapeLike(string $s): string
    {
        return addcslashes($s, '%_\\');
    }
}
