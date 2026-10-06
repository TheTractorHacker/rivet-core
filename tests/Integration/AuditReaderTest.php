<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditReader;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

final class AuditReaderTest extends TestCase
{
    private MysqliDatabase $db;
    private AuditReader $reader;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        $this->db->execute('DROP TABLE IF EXISTS audit_events');
        $this->db->execute('DROP TABLE IF EXISTS rivet_core_migrations');
        (new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock()))->run();
        $this->db->execute('TRUNCATE TABLE audit_events');
        $ins = fn (string $t, ?int $actor, ?string $et, ?string $eid, string $sum, ?string $meta, ?string $ip, string $at) => $this->db->execute(
            'INSERT INTO audit_events (event_type, actor_user_id, entity_type, entity_id, action, summary, metadata_json, ip_address, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$t, $actor, $et, $eid, 'x', $sum, $meta, $ip, $at]
        );
        $ins('auth.login', 1, 'user', '1', 'Signed in', null, '10.0.0.1', '2026-01-01 08:00:00');
        $ins('auth.failed', null, null, null, 'Bad password', '{"user":"bob"}', '203.0.113.9', '2026-01-02 09:00:00');
        $ins('settings.updated', 2, 'setting', 'smtp', '100% done_ok', '{broken', '10.0.0.2', '2026-01-03 23:59:59');
        $ins('settings', 2, 'setting', 'x', 'bare group', null, null, '2026-01-04 00:00:00');
        $ins('settingsfoo.bar', 3, null, null, 'not a settings event', null, null, '2026-01-05 12:00:00');
        $this->reader = new AuditReader($this->db);
    }

    public function testGroupPrefixDoesNotMatchLookalikes(): void
    {
        $p = $this->reader->page(['eventType' => 'settings']);
        self::assertSame(2, $p->total);
        self::assertSame(['auth.failed', 'auth.login'], array_column($this->reader->page(['eventType' => 'auth'])->rows, 'event_type'));
        self::assertSame(1, $this->reader->page(['eventType' => 'settings.updated'])->total);
    }

    public function testFiltersActorEntityAndDatesInclusive(): void
    {
        self::assertSame(2, $this->reader->page(['actorUserId' => 2])->total);
        self::assertSame(1, $this->reader->page(['entityType' => 'setting', 'entityId' => 'smtp'])->total);
        self::assertSame(2, $this->reader->page(['from' => '2026-01-02', 'to' => '2026-01-03'])->total, 'to is inclusive of 23:59:59');
        self::assertSame(1, $this->reader->page(['from' => '2026-01-03 23:59:59', 'to' => '2026-01-03 23:59:59'])->total);
    }

    public function testSearchEscapesWildcardsAndCoversColumns(): void
    {
        self::assertSame(1, $this->reader->page(['search' => '100%'])->total);
        self::assertSame(1, $this->reader->page(['search' => 'done_ok'])->total);
        self::assertSame(0, $this->reader->page(['search' => 'done_xx'])->total);
        self::assertSame(1, $this->reader->page(['search' => '%'])->total, 'a literal % only matches the row containing one');
        self::assertSame(1, $this->reader->page(['search' => '203.0.113.9'])->total);
        self::assertSame(1, $this->reader->page(['search' => 'bob'])->total);
        self::assertSame(1, $this->reader->page(['search' => 'smtp'])->total);
        self::assertSame(2, $this->reader->page(['search' => 'auth.'])->total);
    }

    public function testPaginationOrderAndMetadata(): void
    {
        $p = $this->reader->page([], 1, 2);
        self::assertSame(5, $p->total);
        self::assertSame(3, $p->pages);
        self::assertSame(['settingsfoo.bar', 'settings'], array_column($p->rows, 'event_type'));
        $last = $this->reader->page([], 99, 2);
        self::assertSame(3, $last->page);
        self::assertCount(1, $last->rows);
        $all = $this->reader->page([], 1, 50)->rows;
        $byType = array_column($all, null, 'event_type');
        self::assertSame(['user' => 'bob'], $byType['auth.failed']['metadata']);
        self::assertNull($byType['settings.updated']['metadata'], 'invalid JSON decodes to null');
        self::assertNull($byType['auth.login']['metadata']);
    }

    public function testGroupsActorsAndIterate(): void
    {
        self::assertSame(['auth' => 2, 'settings' => 2, 'settingsfoo' => 1], $this->reader->groups());
        self::assertSame([1, 2, 3], $this->reader->actors());
        $ids = [];
        foreach ($this->reader->iterate([], 100, 2) as $r) {
            $ids[] = (int) $r['audit_id'];
        }
        self::assertCount(5, $ids);
        $sorted = $ids;
        rsort($sorted);
        self::assertSame($sorted, $ids);
        self::assertCount(3, iterator_to_array($this->reader->iterate([], 3, 2), false));
        self::assertCount(2, iterator_to_array($this->reader->iterate(['actorUserId' => 2]), false));
    }
}
