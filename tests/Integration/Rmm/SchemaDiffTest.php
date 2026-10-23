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
                foreach (['ca_pem', 'arch', 'binary_id', 'features_json', 'limits_json', 'shed_level', 'ingest_mode', 'max_devices', 'clock_skew_s', 'clock_skew_at', 'clock_offset_s', 'clock_skew_event_at', 'last_reject_reason', 'last_reject_at', 'detected_type', 'device_automation_disabled', 'os'] as $new) {
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
        // The six clock columns are added by migration 0031 (after the reference was frozen); ClockSkewTest covers them, so this comparison leaves them out. They are the LAST columns, so no ordinal moves.
        $columns = $db->fetchAll("SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in) AND NOT (TABLE_NAME = 'endpoint_agent_devices' AND COLUMN_NAME IN ('clock_skew_s','clock_skew_at','clock_offset_s','clock_skew_event_at','last_reject_reason','last_reject_at','detected_type')) ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $indexes = $db->fetchAll("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in) ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX");

        // rc.30 (migration 0037) adds `os` to the binaries and releases tables and to their unique keys; ReleaseOsTest covers it. Here the key is read back under its
        // baseline name without the column, so every comparison with the frozen reference keeps proving the rest of the schema.
        $columns = array_values(array_filter($columns, static fn (array $c): bool => !($c['COLUMN_NAME'] === 'os' && in_array($c['TABLE_NAME'], ['endpoint_agent_binaries', 'endpoint_agent_releases'], true))));
        $indexes = array_values(array_filter($indexes, static fn (array $i): bool => !($i['COLUMN_NAME'] === 'os' && in_array($i['TABLE_NAME'], ['endpoint_agent_binaries', 'endpoint_agent_releases'], true))));
        foreach ($indexes as &$i) {
            $i['INDEX_NAME'] = ['uniq_version_arch_os' => 'uniq_version_arch', 'uniq_version_ring_arch_os' => 'uniq_version_ring_arch'][$i['INDEX_NAME']] ?? $i['INDEX_NAME'];
        }
        unset($i);

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

    public function testMigration0019AddsOnlyNewTablesAndIsIdempotent(): void
    {
        $this->reference();
        $this->build('c', 'rivetit-2.6.146-final.sql', true);
        $this->seed('c');
        $before = $this->describe('c');
        $rows = $this->checksums('c');

        $applied = $this->runner('c')->run();
        $this->assertContains('0019_rmm_policies_and_scripts', $applied);
        $this->assertSame($before, $this->describe('c'), 'the ten original tables are not altered');
        $this->assertSame($rows, $this->checksums('c'));
        $this->assertCount(11, RmmSchema::phase2Tables());
        foreach (RmmSchema::phase2Tables() as $name => $_) {
            $this->assertSame(1, (int) $this->db('c')->fetchOne('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_COLLATION = ? AND ENGINE = ?', [$name, 'utf8mb4_general_ci', 'InnoDB'])['c'], $name);
            $this->assertSame(0, (int) $this->db('c')->fetchOne('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ?', [$name, 'utf8mb4_general_ci'])['c'], "$name columns");
        }
        // idempotent: a second run (and the migration called directly) changes nothing and keeps rows
        $this->db('c')->execute("INSERT INTO rmm_policies (name, body_json) VALUES ('baseline', '{}')");
        (new \RivetCore\Rmm\Migration\Migration0019PoliciesAndScripts())->up($this->db('c'));
        $this->assertSame([], $this->runner('c')->run());
        $this->assertSame(1, (int) $this->db('c')->fetchOne('SELECT COUNT(*) c FROM rmm_policies')['c']);
        $this->assertSame($before, $this->describe('c'));
    }

    public function testMigration0020AddsOnlyNewTablesAndIsIdempotent(): void
    {
        $this->reference();
        $this->build('d', 'rivetit-2.6.146-final.sql', true);
        $this->seed('d');
        $before = $this->describe('d');
        $rows = $this->checksums('d');

        $applied = $this->runner('d')->run();
        $this->assertContains('0020_rmm_alerting_maturity', $applied);
        $this->assertSame($before, $this->describe('d'), 'the ten original tables are not altered');
        $this->assertSame($rows, $this->checksums('d'));
        foreach (\RivetCore\Rmm\Migration\AlertingSchema::tables() as $name => $_) {
            $this->assertSame(1, (int) $this->db('d')->fetchOne('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_COLLATION = ? AND ENGINE = ?', [$name, 'utf8mb4_general_ci', 'InnoDB'])['c'], $name);
            $this->assertSame(0, (int) $this->db('d')->fetchOne('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ?', [$name, 'utf8mb4_general_ci'])['c'], "$name columns");
        }
        $this->assertCount(8, \RivetCore\Rmm\Migration\AlertingSchema::tables());
        // idempotent, and a row survives a second run
        $this->db('d')->execute("INSERT INTO rmm_alerting_settings (id, storm_client_max) VALUES (1, 7)");
        (new \RivetCore\Rmm\Migration\Migration0020AlertingMaturity())->up($this->db('d'));
        $this->assertSame([], $this->runner('d')->run());
        $this->assertSame(7, (int) $this->db('d')->fetchOne('SELECT storm_client_max c FROM rmm_alerting_settings')['c']);
        $this->assertSame($before, $this->describe('d'));
    }

    public function testMigration0021AddsOnlyNewTablesAndIsIdempotent(): void
    {
        $this->reference();
        $this->build('d', 'rivetit-2.6.146-final.sql', true);
        $this->seed('d');
        $before = $this->describe('d');
        $rows = $this->checksums('d');

        $applied = $this->runner('d')->run();
        $this->assertContains('0021_rmm_scale_storage', $applied);
        $this->assertSame($before, $this->describe('d'), 'the ten original tables are not altered');
        $this->assertSame($rows, $this->checksums('d'));
        foreach (\RivetCore\Rmm\Migration\ScaleSchema::tables() as $name => $_) {
            $this->assertSame(1, (int) $this->db('d')->fetchOne('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_COLLATION = ? AND ENGINE = ?', [$name, 'utf8mb4_general_ci', 'InnoDB'])['c'], $name);
        }
        $this->assertCount(5, \RivetCore\Rmm\Migration\ScaleSchema::tables());
        $this->db('d')->execute("INSERT INTO rmm_storage_state (name, detail) VALUES ('probe', 'kept')");
        (new \RivetCore\Rmm\Migration\Migration0021ScaleStorage())->up($this->db('d'));
        $this->assertSame([], $this->runner('d')->run());
        $this->assertSame('kept', $this->db('d')->fetchOne("SELECT detail c FROM rmm_storage_state WHERE name = 'probe'")['c']);
        $this->assertSame($before, $this->describe('d'));
    }

    public function testMigration0022AddsOnlyNewTablesAndIsIdempotent(): void
    {
        $this->reference();
        $this->build('d', 'rivetit-2.6.146-final.sql', true);
        $this->seed('d');
        $before = $this->describe('d');
        $rows = $this->checksums('d');

        $applied = $this->runner('d')->run();
        $this->assertContains('0022_rmm_remote_and_logs', $applied);
        $this->assertSame($before, $this->describe('d'), 'the ten original tables are not altered');
        $this->assertSame($rows, $this->checksums('d'));
        foreach (\RivetCore\Rmm\Migration\RemoteSchema::tables() as $name => $_) {
            $this->assertSame(1, (int) $this->db('d')->fetchOne('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_COLLATION = ? AND ENGINE = ?', [$name, 'utf8mb4_general_ci', 'InnoDB'])['c'], $name);
            $this->assertSame(0, (int) $this->db('d')->fetchOne('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ?', [$name, 'utf8mb4_general_ci'])['c'], "$name columns");
        }
        $this->assertCount(5, \RivetCore\Rmm\Migration\RemoteSchema::tables());
        // idempotent, and a row survives a second run
        $this->db('d')->execute("INSERT INTO endpoint_agent_remote_settings (id, transfer_allow_json) VALUES (1, '{}')");
        (new \RivetCore\Rmm\Migration\Migration0022RemoteAndLogs())->up($this->db('d'));
        $this->assertSame([], $this->runner('d')->run());
        $this->assertSame('{}', (string) $this->db('d')->fetchOne('SELECT transfer_allow_json c FROM endpoint_agent_remote_settings')['c']);
        $this->assertSame($before, $this->describe('d'));
    }

    public function testMigration0023AddsOnlyNewTablesAndIsIdempotent(): void
    {
        $this->reference();
        $this->build('d', 'rivetit-2.6.146-final.sql', true);
        $this->seed('d');
        $before = $this->describe('d');
        $rows = $this->checksums('d');

        $applied = $this->runner('d')->run();
        $this->assertContains('0023_rmm_mesh_agent', $applied);
        $this->assertSame($before, $this->describe('d'), 'the ten original tables are not altered');
        $this->assertSame($rows, $this->checksums('d'));
        foreach (\RivetCore\Rmm\Migration\MeshAgentSchema::tables() as $name => $_) {
            $this->assertSame(1, (int) $this->db('d')->fetchOne('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_COLLATION = ? AND ENGINE = ?', [$name, 'utf8mb4_general_ci', 'InnoDB'])['c'], $name);
            $this->assertSame(0, (int) $this->db('d')->fetchOne('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ?', [$name, 'utf8mb4_general_ci'])['c'], "$name columns");
        }
        $this->assertSame(['endpoint_agent_mesh_settings', 'endpoint_agent_mesh_agents', 'endpoint_agent_installer_options'], array_keys(\RivetCore\Rmm\Migration\MeshAgentSchema::tables()));
        $this->db('d')->execute("INSERT INTO endpoint_agent_mesh_settings (id, group_id) VALUES (1, 'abcd')");
        $this->db('d')->execute("INSERT INTO endpoint_agent_installer_options (token_id, install_mesh_agent, created_at) VALUES (5, 1, '2026-10-11 00:00:00')");
        (new \RivetCore\Rmm\Migration\Migration0023MeshAgent())->up($this->db('d'));
        $this->assertSame([], $this->runner('d')->run());
        $this->assertSame('abcd', (string) $this->db('d')->fetchOne('SELECT group_id c FROM endpoint_agent_mesh_settings')['c']);
        $this->assertSame(1, (int) $this->db('d')->fetchOne('SELECT install_mesh_agent c FROM endpoint_agent_installer_options WHERE token_id = 5')['c']);
        $this->assertSame($before, $this->describe('d'));
    }

    public function testMigration0029AddsOnlyNewTablesInsertsNoRowAndIsIdempotent(): void
    {
        $this->reference();
        $this->build('d', 'rivetit-2.6.146-final.sql', true);
        $this->seed('d');
        $before = $this->describe('d');
        $rows = $this->checksums('d');

        $applied = $this->runner('d')->run();
        $this->assertContains('0029_rmm_software_deploy', $applied);
        $this->assertSame($before, $this->describe('d'), 'the ten original tables are not altered');
        $this->assertSame($rows, $this->checksums('d'));
        $names = array_keys(\RivetCore\Rmm\Migration\DeploySchema::tables());
        $this->assertSame(['rmm_software_hosts', 'rmm_packages', 'rmm_package_versions', 'rmm_package_assignments', 'rmm_deploy_state', 'rmm_package_installs', 'rmm_package_history', 'rmm_package_jobs'], $names);
        foreach ($names as $name) {
            $this->assertSame(1, (int) $this->db('d')->fetchOne('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_COLLATION = ? AND ENGINE = ?', [$name, 'utf8mb4_general_ci', 'InnoDB'])['c'], $name);
            $this->assertSame(0, (int) $this->db('d')->fetchOne('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ?', [$name, 'utf8mb4_general_ci'])['c'], "$name columns");
            $this->assertSame(0, (int) $this->db('d')->fetchOne("SELECT COUNT(*) c FROM `$name`")['c'], "$name starts empty: the migration inserts nothing");
        }
        $this->db('d')->execute("INSERT INTO rmm_software_hosts (host, created_at) VALUES ('downloads.example.com', '2026-10-17 00:00:00')");
        $this->db('d')->execute("INSERT INTO rmm_packages (name, created_at, updated_at) VALUES ('Acme', '2026-10-17 00:00:00', '2026-10-17 00:00:00')");
        (new \RivetCore\Rmm\Migration\Migration0029SoftwareDeploy())->up($this->db('d'));
        $this->assertSame([], $this->runner('d')->run());
        $this->assertSame('downloads.example.com', (string) $this->db('d')->fetchOne('SELECT host c FROM rmm_software_hosts')['c']);
        $this->assertSame('Acme', (string) $this->db('d')->fetchOne('SELECT name c FROM rmm_packages')['c']);
        $this->assertSame($before, $this->describe('d'));
    }

    public function testMigration0024AddsOneColumnAndIsIdempotent(): void
    {
        $this->reference();
        $this->build('d', 'rivetit-2.6.146-final.sql', true);
        $this->seed('d');
        $this->runner('d')->run();
        $col = static fn (\RivetCore\Database\DatabaseInterface $db): ?array => $db->fetchOne("SELECT COLUMN_TYPE t, COLUMN_DEFAULT d, IS_NULLABLE n, COLLATION_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_mesh_settings' AND COLUMN_NAME = 'group_id_url'");
        $c = $col($this->db('d'));
        $this->assertSame(['varchar(64)', 'NO'], [$c['t'] ?? null, $c['n'] ?? null]);
        $this->assertSame('utf8mb4_general_ci', $c['c']);
        $this->db('d')->execute("INSERT INTO endpoint_agent_mesh_settings (id, group_id, group_id_url) VALUES (1, 'abcd', 'keep')");
        $before = $this->describe('d');
        (new \RivetCore\Rmm\Migration\Migration0024MeshGroupUrl())->up($this->db('d'));
        $this->assertSame([], $this->runner('d')->run());
        $this->assertSame($before, $this->describe('d'));
        $this->assertSame(['abcd', 'keep'], array_values(array_map('strval', array_intersect_key($this->db('d')->fetchOne('SELECT group_id, group_id_url FROM endpoint_agent_mesh_settings') ?? [], ['group_id' => 1, 'group_id_url' => 1]))));
        // a database that went through 0023 only (rc.12 to rc.14) gains the column and keeps its rows
        $this->db('d')->execute('ALTER TABLE endpoint_agent_mesh_settings DROP COLUMN group_id_url');
        (new \RivetCore\Rmm\Migration\Migration0024MeshGroupUrl())->up($this->db('d'));
        $this->assertSame('', (string) $this->db('d')->fetchOne('SELECT group_id_url c FROM endpoint_agent_mesh_settings')['c']);
        $this->assertSame('abcd', (string) $this->db('d')->fetchOne('SELECT group_id c FROM endpoint_agent_mesh_settings')['c']);
    }

    public function testMigration0026AddsTwoHideColumnsAndIsIdempotent(): void
    {
        $this->reference();
        $this->build('d', 'rivetit-2.6.146-final.sql', true);
        $this->seed('d');
        $this->runner('d')->run();
        $col = fn (string $c): ?array => $this->db('d')->fetchOne("SELECT COLUMN_TYPE t, COLUMN_DEFAULT d, IS_NULLABLE n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_mesh_settings' AND COLUMN_NAME = '$c'");
        $this->assertSame(['31', 'NO'], [$col('mesh_hide')['d'] ?? null, $col('mesh_hide')['n'] ?? null]);
        $this->assertSame('YES', $col('mesh_hide_tools')['n'] ?? null);
        $this->db('d')->execute("INSERT INTO endpoint_agent_mesh_settings (id, group_id, mesh_hide, mesh_hide_tools) VALUES (1, 'abcd', 7, 3)");
        $before = $this->describe('d');
        (new \RivetCore\Rmm\Migration\Migration0026MeshHide())->up($this->db('d'));
        $this->assertSame([], $this->runner('d')->run());
        $this->assertSame($before, $this->describe('d'));
        $this->assertSame([7, 3], array_map('intval', array_values($this->db('d')->fetchOne('SELECT mesh_hide, mesh_hide_tools FROM endpoint_agent_mesh_settings') ?? [])));
    }

    public function testMigration0027RewritesNodeIdsToTheCanonicalFormKeepsManualOnesAndIsIdempotent(): void
    {
        $this->reference();
        $this->build('d', 'rivetit-2.6.146-final.sql', true);
        $this->seed('d');
        $this->runner('d')->run();
        $db = $this->db('d');
        $db->execute('DELETE FROM endpoint_agent_mesh_nodes');
        $db->execute('DELETE FROM endpoint_agent_mesh_agents');
        $mk = static function (): array {
            $raw = random_bytes(48);

            return [bin2hex($raw), 'node//' . strtr(rtrim(base64_encode($raw), '='), '+/', '@$')];
        };
        [$hexA, $canA] = $mk();   // two devices, same node: device 2 manual, device 1 automatic: the manual one is kept
        [$hexB, $canB] = $mk();   // a lone hex row
        [$hexC, $canC] = $mk();   // two manual rows: both kept
        $legacy = 'node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01';
        $ins = fn (int $dev, string $node, string $src) => $db->execute("INSERT INTO endpoint_agent_mesh_nodes (device_id, mesh_node_id, source, updated_at, updated_by) VALUES ($dev, ?, '$src', NOW(), 0)", [$node]);
        $ins(1, 'node//' . $hexA, 'agent');
        $ins(2, 'node//' . strtoupper($hexA), 'manual');
        $ins(3, 'node//' . $hexB, 'agent');
        $ins(4, 'node//' . $hexC, 'manual');
        $ins(5, $canC, 'manual');
        $ins(6, $legacy, 'agent');
        $ins(7, 'node//' . str_repeat('!', 20) . 'zzzz', 'agent');   // unparseable: left alone
        foreach ([1, 3] as $dev) {
            $db->execute("INSERT INTO endpoint_agent_mesh_agents (device_id, installed, version, node_id, error, map_state, first_installed_at, reported_at, changed_at) VALUES ($dev, 1, '', ?, '', 'mapped', NOW(), NOW(), NOW())", ['node//' . ($dev === 1 ? $hexA : $hexB)]);
        }
        $m = new \RivetCore\Rmm\Migration\Migration0027MeshNodeIds();
        $m->up($db);
        $nodes = static fn (): array => array_column($db->fetchAll('SELECT device_id, mesh_node_id FROM endpoint_agent_mesh_nodes ORDER BY device_id'), 'mesh_node_id', 'device_id');
        $this->assertSame([2 => $canA, 3 => $canB, 4 => $canC, 5 => $canC, 6 => $legacy, 7 => 'node//' . str_repeat('!', 20) . 'zzzz'], $nodes(), 'device 1 (automatic) lost the node to the manual mapping of device 2');
        $one = $db->fetchOne('SELECT map_state, conflict_device_id, node_id FROM endpoint_agent_mesh_agents WHERE device_id = 1');
        $this->assertSame(['conflict', 2, $canA], [$one['map_state'], (int) $one['conflict_device_id'], $one['node_id']]);
        $this->assertSame($canB, $db->fetchOne('SELECT node_id FROM endpoint_agent_mesh_agents WHERE device_id = 3')['node_id']);
        $before = [$nodes(), $db->fetchAll('SELECT * FROM endpoint_agent_mesh_agents ORDER BY device_id')];
        $m->up($db);
        $this->assertSame($before, [$nodes(), $db->fetchAll('SELECT * FROM endpoint_agent_mesh_agents ORDER BY device_id')], 'a second run changes nothing');
        $this->assertSame([], $this->runner('d')->run());
    }

    public function testFreshCoreInstallHasEveryPhase1Table(): void
    {
        $this->runner('b')->run();
        foreach (array_keys(RmmSchema::phase1Tables()) as $name) {
            $this->assertSame(1, (int) $this->db('b')->fetchOne('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name])['c'], $name);
        }
        $this->assertCount(11, RmmSchema::phase1Tables());
        foreach (array_keys(RmmSchema::phase2Tables()) as $name) {
            $this->assertSame(1, (int) $this->db('b')->fetchOne('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name])['c'], $name);
        }
    }

    public function testEveryTableIsUtf8mb4GeneralCiExplicitly(): void
    {
        foreach (RmmSchema::tables() + RmmSchema::phase1Tables() + RmmSchema::phase2Tables() + \RivetCore\Rmm\Migration\AlertingSchema::tables() + \RivetCore\Rmm\Migration\ScaleSchema::tables() + \RivetCore\Rmm\Migration\RemoteSchema::tables() + \RivetCore\Rmm\Migration\MeshAgentSchema::tables() as $name => $ddl) {
            $this->assertStringContainsString('COLLATE=utf8mb4_general_ci', $ddl, $name);
            $this->assertStringContainsString('ENGINE=InnoDB', $ddl, $name);
        }
    }
}
