<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Background;

use PHPUnit\Framework\TestCase;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Database\ExecutionResult;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Rmm\Migration\BackgroundSchema;
use RivetCore\Rmm\Migration\Migration0050BackgroundMode;
use RivetCore\Rmm\Remote\DbBackgroundModeConfig;
use RivetCore\Rmm\Remote\DbBackgroundSessionStore;
use RivetCore\Rmm\Remote\RemoteProtocol;
use RivetCore\Rmm\Remote\RemoteSettings;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Tests\Support\FixedClock;

/** A1 (switch, risk text), A2 (migration 0050) and the SQL of the two database classes, against a recording database (no real database is needed or used). */
final class BackgroundStorageTest extends TestCase
{
    /** @return DatabaseInterface&object{calls:list<array{sql:string,params:array<mixed>}>,affected:int,row:?array<string,mixed>} */
    private function db(int $affected = 1, ?array $row = null): DatabaseInterface
    {
        return new class ($affected, $row) implements DatabaseInterface {
            /** @var list<array{sql:string,params:array<mixed>}> */
            public array $calls = [];

            /** @param array<string,mixed>|null $row */
            public function __construct(public int $affected, public ?array $row)
            {
            }

            public function fetchOne(string $sql, array $params = []): ?array
            {
                $this->calls[] = ['sql' => $sql, 'params' => $params];

                return $this->row;
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                $this->calls[] = ['sql' => $sql, 'params' => $params];

                return $this->row === null ? [] : [$this->row];
            }

            public function execute(string $sql, array $params = []): ExecutionResult
            {
                $this->calls[] = ['sql' => $sql, 'params' => $params];

                return new ExecutionResult($this->affected, 1);
            }

            public function transaction(callable $callback): mixed
            {
                return $callback();
            }
        };
    }

    private function sql(DatabaseInterface $db): Sql
    {
        return new Sql($db, new FixedClock(new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'))));
    }

    // ------------------------------------------------------------------ A1

    public function testTheSwitchExistsAndCanBeStoredButIsNotOnByDefault(): void
    {
        $this->assertContains('background_mode', RmmSettings::FEATURES);
        $this->assertSame('background_mode', RemoteProtocol::FEATURE_BACKGROUND_MODE);
        $this->assertNotContains('background_mode', RmmSettings::LEGACY_FEATURES, 'the legacy defaults (no features_json) never turn it on');
        [$json, $err] = RmmSettings::validateFeatures(['background_mode' => true]);
        $this->assertNull($err);
        $this->assertSame('{"background_mode":true}', $json);
        [, $err] = RmmSettings::validateFeatures(['background_mode' => 'yes']);
        $this->assertSame('Feature background_mode must be true or false.', $err);
        [, $err] = RmmSettings::validateFeatures(['background_modes' => true]);
        $this->assertNotNull($err, 'a misspelt switch is refused, not ignored');
    }

    public function testTheRiskTextSaysWhatTheFeatureDoesAndThatAuditStaysOn(): void
    {
        $t = RemoteSettings::BACKGROUND_MODE_RISK;
        $this->assertStringContainsString('without asking', $t);
        $this->assertStringContainsString('audit log', $t);
        $this->assertStringContainsString('cannot be switched off', $t);
    }

    // ------------------------------------------------------------------ A2

    public function testTheMigrationIsRegisteredWithAUniqueIdAndSkipsTheMissing0045(): void
    {
        $ids = array_map(static fn ($m): string => $m->id(), CoreMigrations::all());
        $this->assertSame(['0050_rmm_background_mode', '0051_rmm_console_sessions'], array_slice($ids, -2));
        $this->assertSame($ids, array_values(array_unique($ids)));
        $nums = array_map(static fn (string $id): int => (int) substr($id, 0, 4), $ids);
        $sorted = $nums;
        sort($sorted);
        $this->assertSame($sorted, $nums, 'ordered');
        $this->assertNotContains(45, $nums, '0045 was never shipped (the reporting views became 0047)');
        $this->assertNotContains(50, array_slice($nums, 0, -2));
    }

    public function testTheMigrationIsAdditiveAndIdempotent(): void
    {
        $db = $this->db();
        $m = new Migration0050BackgroundMode();
        $m->up($db);
        $first = $db->calls;
        $m->up($db);
        $this->assertSame($first, array_slice($db->calls, count($first)), 'a second run issues the same statements: nothing depends on state it created');
        $tables = [];
        foreach ($first as $c) {
            $sql = $c['sql'];
            $this->assertMatchesRegularExpression('/^(CREATE TABLE IF NOT EXISTS `rmm_[a-z_]+`|INSERT IGNORE INTO `rmm_background_settings`)/', $sql, 'only IF NOT EXISTS creates and one INSERT IGNORE');
            $this->assertDoesNotMatchRegularExpression('/\b(ALTER|DROP|UPDATE|DELETE|TRUNCATE)\b/i', $sql);
            $this->assertStringNotContainsString('rmm_remote_sessions', $sql, 'the edition-owned session log is not touched');
            if (preg_match('/CREATE TABLE IF NOT EXISTS `([a-z_]+)`/', $sql, $mm) === 1) {
                $tables[] = $mm[1];
            }
        }
        $this->assertSame(['rmm_background_settings', 'rmm_client_remote_policy', 'rmm_background_sessions', 'rmm_background_tokens'], $tables);
        $this->assertSame($tables, array_keys(BackgroundSchema::tables()));
        $this->assertSame($first[0]['sql'], BackgroundSchema::tables()['rmm_background_settings']);
    }

    public function testTheSchemaStoresNoTokenAndNoUrlColumn(): void
    {
        $ddl = implode("\n", BackgroundSchema::tables());
        $this->assertStringContainsString('`token_hash` char(64)', $ddl);
        $this->assertStringContainsString('PRIMARY KEY (`token_hash`)', $ddl);
        $this->assertDoesNotMatchRegularExpression('/`(token|url|secret|password|login_key|cookie)`/', $ddl);
        $this->assertStringContainsString("`policy_mode` varchar(12) NOT NULL DEFAULT ''", $ddl, 'unconfigured by default, which denies');
    }

    // ------------------------------------------------------------------ the config over SQL

    public function testConfigReadsAreStrictAndAbsentMeansNotConfigured(): void
    {
        $this->assertSame('', (new DbBackgroundModeConfig($this->sql($this->db(1, null))))->policyMode());
        $this->assertSame('', (new DbBackgroundModeConfig($this->sql($this->db(1, ['policy_mode' => 'Internal', 'internal_group' => ' ']))))->policyMode(), 'case matters');
        $c = new DbBackgroundModeConfig($this->sql($this->db(1, ['policy_mode' => 'per_client', 'internal_group' => ' Ops '])));
        $this->assertSame('per_client', $c->policyMode());
        $this->assertSame('Ops', $c->internalGroup());
        $this->assertNull((new DbBackgroundModeConfig($this->sql($this->db(1, null))))->clientPolicy(5));
        $this->assertNull((new DbBackgroundModeConfig($this->sql($this->db(1, ['background_policy' => 'everything']))))->clientPolicy(5), 'an unknown stored value reads as none');
        $this->assertSame('notify', (new DbBackgroundModeConfig($this->sql($this->db(1, ['background_policy' => 'notify']))))->clientPolicy(5));
    }

    public function testConfigSettersValidate(): void
    {
        $db = $this->db();
        $c = new DbBackgroundModeConfig($this->sql($db));
        $this->assertFalse($c->setPolicyMode('any', 1));
        $this->assertFalse($c->setPolicyMode('', 1));
        $this->assertFalse($c->setClientPolicy(0, 'allow', 1));
        $this->assertFalse($c->setClientPolicy(3, 'sometimes', 1));
        $this->assertFalse($c->setInternalGroup("a'; DROP TABLE x", 1));
        $this->assertFalse($c->setInternalGroup(str_repeat('a', 101), 1));
        $this->assertSame([], $db->calls, 'nothing is written for a refused value');
        $this->assertTrue($c->setPolicyMode('internal', 9));
        $this->assertTrue($c->setInternalGroup('Remote Admins', 9));
        $this->assertTrue($c->setInternalGroup('', 9));
        $this->assertTrue($c->setClientPolicy(3, 'notify', 9));
        $this->assertTrue($c->setClientPolicy(3, null, 9));
        $this->assertCount(5, $db->calls);
        $this->assertSame(['internal', 9, '2026-01-02 03:04:05'], $db->calls[0]['params']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE policy_mode', $db->calls[0]['sql']);
        $this->assertStringStartsWith('DELETE FROM rmm_client_remote_policy WHERE client_id = ?', $db->calls[4]['sql']);
    }

    // ------------------------------------------------------------------ the store over SQL

    public function testAnIssuedTokenIsStoredAsGivenWithAShortExpiry(): void
    {
        $db = $this->db();
        (new DbBackgroundSessionStore($this->sql($db)))->issueToken(str_repeat('b', 64), '0123456789abcdef', 4, 77, 2, 60);
        $insert = $db->calls[1];
        $this->assertStringStartsWith('INSERT INTO rmm_background_tokens', $insert['sql']);
        $this->assertSame([str_repeat('b', 64), '0123456789abcdef', 4, 77, 2, '2026-01-02 03:04:05', '2026-01-02 03:05:05'], $insert['params']);
        $this->assertStringStartsWith('DELETE FROM rmm_background_tokens WHERE expires_at < ?', $db->calls[0]['sql'], 'old rows are cleared when one is issued');
    }

    public function testConsumingIsOneConditionalUpdateAndOnlyTheWinnerSucceeds(): void
    {
        $win = $this->db(1);
        $this->assertTrue((new DbBackgroundSessionStore($this->sql($win)))->consumeToken('h'));
        $this->assertStringContainsString('WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', $win->calls[0]['sql']);
        $lose = $this->db(0);
        $this->assertFalse((new DbBackgroundSessionStore($this->sql($lose)))->consumeToken('h'), 'zero rows changed: used, expired or unknown');
    }

    public function testPeekClassifiesTheToken(): void
    {
        $now = '2026-01-02 03:04:05';
        $mk = fn (?array $row): DbBackgroundSessionStore => new DbBackgroundSessionStore($this->sql($this->db(1, $row)));
        $base = ['session_id' => 'abcdef0123456789', 'user_id' => '4', 'device_id' => '77', 'client_id' => '2'];
        $this->assertNull($mk(null)->peekToken('h'));
        $this->assertSame('ok', $mk($base + ['expires_at' => '2026-01-02 03:05:05', 'used_at' => null])?->peekToken('h')['state'] ?? null);
        $this->assertSame('expired', $mk($base + ['expires_at' => $now, 'used_at' => null])->peekToken('h')['state'] ?? null, 'expiry is exclusive of now');
        $this->assertSame('used', $mk($base + ['expires_at' => '2027-01-01 00:00:00', 'used_at' => $now])->peekToken('h')['state'] ?? null);
        $this->assertSame(4, $mk($base + ['expires_at' => $now, 'used_at' => null])->peekToken('h')['user_id'] ?? null);
    }

    public function testEndingASessionOnlyWorksOnce(): void
    {
        $first = $this->db(1);
        $this->assertTrue((new DbBackgroundSessionStore($this->sql($first)))->endSession('0123456789abcdef', 'user_closed'));
        $this->assertStringContainsString('WHERE session_id = ? AND ended_at IS NULL', $first->calls[0]['sql']);
        $this->assertFalse((new DbBackgroundSessionStore($this->sql($this->db(0))))->endSession('0123456789abcdef', 'user_closed'));
    }

    public function testStartAndFindRoundTripTheFields(): void
    {
        $db = $this->db();
        $s = new DbBackgroundSessionStore($this->sql($db));
        $s->startSession('0123456789abcdef', 77, 2, 500, 4, 'Tess', 'internal_admin', 'none');
        $this->assertSame(['0123456789abcdef', 77, 2, 500, 4, 'Tess', 'internal_admin', 'none', '2026-01-02 03:04:05'], $db->calls[0]['params']);
        $row = ['session_id' => '0123456789abcdef', 'device_id' => '77', 'client_id' => '2', 'asset_id' => '500', 'user_id' => '4', 'user_name' => 'Tess', 'policy' => 'internal_admin', 'notice' => 'none', 'ended_at' => null];
        $found = (new DbBackgroundSessionStore($this->sql($this->db(1, $row))))->findSession('0123456789abcdef');
        $this->assertSame(['session_id' => '0123456789abcdef', 'device_id' => 77, 'client_id' => 2, 'asset_id' => 500, 'user_id' => 4, 'user_name' => 'Tess', 'policy' => 'internal_admin', 'notice' => 'none', 'ended' => false], $found);
        $this->assertTrue((new DbBackgroundSessionStore($this->sql($this->db(1, ['ended_at' => '2026-01-02 03:04:05'] + $row))))->findSession('x')['ended'] ?? false);
        $this->assertNull((new DbBackgroundSessionStore($this->sql($this->db(1, null))))->findSession('x'));
    }
}
