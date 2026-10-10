<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Rmm\Migration\Migration0014EndpointAgent;
use RivetCore\Rmm\Migration\Migration0015EndpointAgentConverge;
use RivetCore\Rmm\Migration\Migration0016ModuleSwitches;
use RivetCore\Rmm\Migration\RmmSchema;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;

/**
 * Proves Core's endpoint_agent_* migrations reproduce RivetIT's schema exactly:
 *   A reference : RivetIT's own DDL (db.sql, DB 2.6.146) plus the 0016 switch columns written out independently here
 *   B fresh     : Core's migrations on an empty schema
 *   C upgraded  : the RivetIT 2.6.145 DDL with rows in it, then Core's migrations
 *   D converged : an install already at 2.6.146 with rows in it, then Core's migrations
 * A == B == C == D for engine, collation, columns (type, nullability, default, extra, charset, collation, position) and indexes,
 * the rows survive, and a second run changes nothing.
 *
 * Needs a scratch server account that may create databases named "<RIVETCORE_TEST_DB_NAME>_a" .. "_d"; the name must contain
 * "scratch". Skipped without RIVETCORE_TEST_DB_*.
 */
final class SchemaDiffTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/rmm/schema';
    private const SUFFIXES = ['a', 'b', 'c', 'd'];

    /** The 0016 columns exactly as the design lists them, spelled independently of the migration. */
    private const SWITCH_COLUMNS = [
        'ALTER TABLE `endpoint_agent_settings` ADD COLUMN `features_json` text DEFAULT NULL',
        'ALTER TABLE `endpoint_agent_settings` ADD COLUMN `limits_json` text DEFAULT NULL',
        'ALTER TABLE `endpoint_agent_settings` ADD COLUMN `shed_level` tinyint(1) NOT NULL DEFAULT 0',
        "ALTER TABLE `endpoint_agent_settings` ADD COLUMN `ingest_mode` varchar(10) NOT NULL DEFAULT 'sync'",
        'ALTER TABLE `endpoint_agent_settings` ADD COLUMN `max_devices` int(11) NOT NULL DEFAULT 0',
    ];

    /** @var array<string,\mysqli> */
    private array $conn = [];
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = (string) getenv('RIVETCORE_TEST_DB_NAME');
        if ($this->base === '') {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        if (!str_contains($this->base, 'scratch')) {
            $this->markTestSkipped('Refusing to create databases next to a schema whose name does not contain "scratch".');
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $root = new \mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', '', (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 0));
        if ($root->connect_errno) {
            $this->markTestSkipped('Cannot connect: ' . $root->connect_error);
        }
        foreach (self::SUFFIXES as $s) {
            $name = $this->base . '_' . $s;
            $root->query("DROP DATABASE IF EXISTS `$name`");
            if (!$root->query("CREATE DATABASE `$name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
                $this->markTestSkipped("The scratch account may not create $name: " . $root->error);
            }
        }
        $root->close();
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        foreach (self::SUFFIXES as $s) {
            $c = new \mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $this->base . '_' . $s, (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 0));
            $c->set_charset('utf8mb4');
            $this->conn[$s] = $c;
        }
    }

    protected function tearDown(): void
    {
        if ($this->conn === []) {
            return;
        }
        $root = new \mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', '', (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 0));
        foreach (self::SUFFIXES as $s) {
            $this->conn[$s]->close();
            $root->query('DROP DATABASE IF EXISTS `' . $this->base . '_' . $s . '`');
        }
        $root->close();
    }

    private function db(string $s): MysqliDatabase
    {
        return new MysqliDatabase($this->conn[$s]);
    }

    private function runner(string $s, ?array $migrations = null): MigrationRunner
    {
        return new MigrationRunner($this->db($s), $migrations ?? CoreMigrations::all(), new FixedClock());
    }

    /** @return list<string> */
    private static function statements(string $file): array
    {
        $sql = (string) file_get_contents($file);
        $sql = (string) preg_replace('/^--.*$/m', '', $sql);

        return array_values(array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: []), static fn (string $s): bool => $s !== ''));
    }

    private function build(string $s, string $fixture, bool $withSwitchColumns): void
    {
        foreach (self::statements(self::FIXTURES . '/ddl-reference/' . $fixture) as $stmt) {
            $this->conn[$s]->query($stmt);
        }
        if ($withSwitchColumns) {
            foreach (self::SWITCH_COLUMNS as $alter) {
                $this->conn[$s]->query($alter);
            }
        }
    }

    /** Rows an install would already have: one of everything, with values a careless migration would lose. */
    private function seed(string $s): void
    {
        $db = $this->db($s);
        $db->execute("INSERT INTO endpoint_agent_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id");
        $db->execute("UPDATE endpoint_agent_settings SET enabled = 1, service_url = 'https://rmm.example.test', check_in_interval_s = 123, unmatched_policy = 'auto_create', signing_key_id = 'abcd1234abcd1234', signing_public_key = 'PUB', signing_private_key_enc = 'ENC' WHERE id = 1");
        $db->execute("INSERT INTO endpoint_agent_enrollment_tokens (token_selector, token_hash, client_id, expires_at, max_uses) VALUES ('0123456789ab', ?, 7, '2030-01-01 00:00:00', 3)", [str_repeat('a', 64)]);
        $db->execute("INSERT INTO endpoint_agent_devices (install_id, hostname, serial, client_id, token_hash, last_seq, inventory_json) VALUES ('123e4567-e89b-12d3-a456-426614174000', 'WS-1', 'SER-1', 7, ?, 41, '{\"cpu\":\"x\"}')", [str_repeat('b', 64)]);
        $db->execute("INSERT INTO endpoint_agent_checkins (device_id, seq) VALUES (1, 41)");
        $db->execute("INSERT INTO endpoint_agent_checks (device_id, check_key, status) VALUES (1, 'disk', 'ok')");
        $db->execute("INSERT INTO endpoint_agent_jobs (job_id, device_id, type, issued_at, expires_at, state) VALUES ('11111111-2222-4333-8444-555555555555', 1, 'powershell', '2026-01-01 00:00:00', '2026-01-02 00:00:00', 'succeeded')");
        $db->execute("INSERT INTO endpoint_agent_mesh_nodes (device_id, mesh_node_id) VALUES (1, 'node//x')");
        $db->execute("INSERT INTO endpoint_agent_releases (version, url, sha256, ring) VALUES ('0.1.0-beta.1', 'https://rmm.example.test/api/v1/agent_update', ?, 'stable')", [str_repeat('c', 64)]);
    }

    /** @return array<string,string> table => checksum of its rows */
    private function checksums(string $s): array
    {
        $out = [];
        foreach (array_keys(RmmSchema::tables()) as $t) {
            $has = $this->db($s)->fetchOne('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
            if ((int) ($has['c'] ?? 0) === 0) {
                continue;
            }
            $rows = $this->db($s)->fetchAll("SELECT * FROM `$t` ORDER BY 1, 2");
            foreach ($rows as &$r) {
                unset($r['updated_at']); // ON UPDATE current_timestamp()
                // columns the migrations add are not part of what existed before
                foreach (['ca_pem', 'arch', 'binary_id', 'features_json', 'limits_json', 'shed_level', 'ingest_mode', 'max_devices'] as $new) {
                    unset($r[$new]);
                }
            }
            unset($r);
            $out[$t] = count($rows) . ':' . md5((string) json_encode($rows));
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function describe(string $s): array
    {
        $db = $this->db($s);
        // Only the ten original tables: migration 0018 adds more (endpoint_agent_check_history among them), covered by the phase 1 tests below.
        $in = "'" . implode("','", array_keys(RmmSchema::tables())) . "'";
        $tables = $db->fetchAll("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in) ORDER BY TABLE_NAME");
        $columns = $db->fetchAll("SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in) ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $indexes = $db->fetchAll("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in) ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX");

        return ['tables' => $tables, 'columns' => $columns, 'indexes' => $indexes];
    }

    private function reference(): void
    {
        $this->build('a', 'rivetit-2.6.146-final.sql', true);
    }

    public function testTheReferenceFixtureHasTheTenTablesAndMatchesCoresDdl(): void
    {
        $stmts = self::statements(self::FIXTURES . '/ddl-reference/rivetit-2.6.146-final.sql');
        $this->assertCount(10, $stmts);
        $norm = static fn (string $s): string => rtrim(trim((string) preg_replace('/\s+/', ' ', str_replace('IF NOT EXISTS ', '', $s))), ';');
        $mine = array_map($norm, array_values(RmmSchema::tables()));
        $this->assertSame($mine, array_map($norm, $stmts), 'RmmSchema is a verbatim copy of the RivetIT DDL');
        $t1 = self::FIXTURES . '/ddl';
        if (is_dir($t1)) {
            foreach (RmmSchema::tables() as $name => $ddl) {
                if (is_file("$t1/$name.sql")) {
                    $this->assertSame($norm($ddl), $norm((string) preg_replace('/^--.*$/m', '', (string) file_get_contents("$t1/$name.sql"))), "baseline dump of $name");
                }
            }
        }
    }

    public function testFreshCoreInstallEqualsTheReference(): void
    {
        $this->reference();
        $applied = $this->runner('b')->run();
        $this->assertContains('0014_endpoint_agent_core', $applied);
        $this->assertContains('0015_endpoint_agent_converge', $applied);
        $this->assertContains('0016_rmm_module_switches', $applied);
        $this->assertSame($this->describe('a'), $this->describe('b'));
        $row = $this->db('b')->fetchOne('SELECT * FROM endpoint_agent_settings');
        $this->assertSame([1, 0, 0, 'sync', 0], [(int) $row['id'], (int) $row['enabled'], (int) $row['max_devices'], $row['ingest_mode'], (int) $row['shed_level']]);
        $this->assertSame(1, (int) $this->db('b')->fetchOne('SELECT COUNT(*) c FROM endpoint_agent_settings')['c']);
        $this->assertNull($row['features_json']);
        $this->assertNull($row['limits_json']);
    }

    public function testAnInstallThatStoppedAt2_6_145ConvergesAndKeepsItsData(): void
    {
        $this->reference();
        $this->build('c', 'rivetit-2.6.145.sql', false);
        $this->assertNotSame($this->describe('a'), $this->describe('c'), 'the 2.6.145 fixture really is different');
        $this->seed('c');
        $before = $this->checksums('c');

        $this->runner('c')->run();

        $this->assertSame($this->describe('a'), $this->describe('c'));
        $this->assertSame($before, array_intersect_key($this->checksums('c'), $before));
        $settings = $this->db('c')->fetchOne('SELECT * FROM endpoint_agent_settings');
        $this->assertSame([1, 'https://rmm.example.test', 123, 'auto_create', 'abcd1234abcd1234', 'ENC'], [(int) $settings['enabled'], $settings['service_url'], (int) $settings['check_in_interval_s'], $settings['unmatched_policy'], $settings['signing_key_id'], $settings['signing_private_key_enc']], 'the master switch and keys are never touched');
        $this->assertNull($settings['ca_pem']);
        $rel = $this->db('c')->fetchOne('SELECT arch, binary_id FROM endpoint_agent_releases');
        $this->assertSame('', $rel['arch']);
        $this->assertNull($rel['binary_id']);
        // the new key is (version, ring, arch): the same version and ring for another arch is now legal
        $this->db('c')->execute("INSERT INTO endpoint_agent_releases (version, url, sha256, ring, arch) VALUES ('0.1.0-beta.1', 'u', ?, 'stable', 'arm64')", [str_repeat('d', 64)]);
        $this->assertSame(0, (int) $this->db('c')->fetchOne("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'uniq_version_ring'")['c']);
    }

    public function testAnInstallAlreadyAt2_6_146IsLeftAloneExceptForTheSwitchColumns(): void
    {
        $this->reference();
        $this->build('d', 'rivetit-2.6.146-final.sql', false);
        $this->seed('d');
        $before = $this->checksums('d');

        $this->runner('d')->run();

        $this->assertSame($this->describe('a'), $this->describe('d'));
        $this->assertSame($before, $this->checksums('d'));
        $this->assertSame(1, (int) $this->db('d')->fetchOne('SELECT enabled FROM endpoint_agent_settings')['enabled'], 'an install that enabled the agent stays enabled');
    }

    public function testRunningAgainChangesNothing(): void
    {
        $this->build('c', 'rivetit-2.6.145.sql', false);
        $this->seed('c');
        $this->runner('c')->run();
        $schema = $this->describe('c');
        $rows = $this->checksums('c');

        $this->assertSame([], $this->runner('c')->run());
        foreach ([new Migration0014EndpointAgent(), new Migration0015EndpointAgentConverge(), new Migration0016ModuleSwitches()] as $m) {
            $m->up($this->db('c'));
        }
        $this->assertSame($schema, $this->describe('c'));
        $this->assertSame($rows, $this->checksums('c'));
        $applied = array_column(array_filter($this->runner('c')->status(), static fn (array $r): bool => in_array($r['id'], ['0014_endpoint_agent_core', '0015_endpoint_agent_converge', '0016_rmm_module_switches'], true)), 'applied_at');
        $this->assertCount(3, $applied);
        $this->assertNotContains(null, $applied);
    }

    public function testTheSettingsRowIsCreatedOnceAndNeverReplaced(): void
    {
        $this->runner('b')->run();
        $this->db('b')->execute("UPDATE endpoint_agent_settings SET enabled = 1 WHERE id = 1");
        (new Migration0014EndpointAgent())->up($this->db('b'));
        $this->assertSame(1, (int) $this->db('b')->fetchOne('SELECT COUNT(*) c FROM endpoint_agent_settings')['c']);
        $this->assertSame(1, (int) $this->db('b')->fetchOne('SELECT enabled FROM endpoint_agent_settings')['enabled']);
    }

    public function testMigration0018AddsOnlyNewTablesAndIsIdempotent(): void
    {
        $this->reference();
        $this->build('c', 'rivetit-2.6.146-final.sql', true);
        $this->seed('c');
        $before = $this->describe('c');
        $rows = $this->checksums('c');

        $applied = $this->runner('c')->run();
        $this->assertContains('0018_rmm_inventory_foundation', $applied);
        $this->assertSame($before, $this->describe('c'), 'the ten original tables are not altered');
        $this->assertSame($rows, $this->checksums('c'));
        foreach (RmmSchema::phase1Tables() as $name => $_) {
            $this->assertSame(1, (int) $this->db('c')->fetchOne('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_COLLATION = ? AND ENGINE = ?', [$name, 'utf8mb4_general_ci', 'InnoDB'])['c'], $name);
            $this->assertSame(0, (int) $this->db('c')->fetchOne('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ?', [$name, 'utf8mb4_general_ci'])['c'], "$name columns");
        }
        // idempotent, and a row survives a second run
        $this->db('c')->execute("INSERT INTO rmm_tags (name) VALUES ('kiosk')");
        (new \RivetCore\Rmm\Migration\Migration0018InventoryFoundation())->up($this->db('c'));
        $this->assertSame([], $this->runner('c')->run());
        $this->assertSame(1, (int) $this->db('c')->fetchOne('SELECT COUNT(*) c FROM rmm_tags')['c']);
        $this->assertSame($before, $this->describe('c'));
    }

    public function testFreshCoreInstallHasEveryPhase1Table(): void
    {
        $this->runner('b')->run();
        foreach (array_keys(RmmSchema::phase1Tables()) as $name) {
            $this->assertSame(1, (int) $this->db('b')->fetchOne('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name])['c'], $name);
        }
        $this->assertCount(11, RmmSchema::phase1Tables());
    }

    public function testEveryTableIsUtf8mb4GeneralCiExplicitly(): void
    {
        foreach (RmmSchema::tables() + RmmSchema::phase1Tables() as $name => $ddl) {
            $this->assertStringContainsString('COLLATE=utf8mb4_general_ci', $ddl, $name);
            $this->assertStringContainsString('ENGINE=InnoDB', $ddl, $name);
        }
    }
}
