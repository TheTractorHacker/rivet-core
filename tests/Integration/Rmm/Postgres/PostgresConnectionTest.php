<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Database\DatabaseException;
use RivetCore\Rmm\Store\Postgres\CircuitBreakerConnection;
use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionException;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresQueryException;
use RivetCore\Rmm\Store\Postgres\PostgresSerializationException;
use RivetCore\Rmm\Store\Postgres\PostgresTimeoutException;
use RivetCore\Tests\Support\PostgresTestDatabase;

/** The connection port against a real PostgreSQL: UTC session, prepared statements, error mapping, transactions, deadlock retry, lost sessions, the breaker. */
final class PostgresConnectionTest extends TestCase
{
    private PostgresConnectionInterface $db;

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $this->db = PostgresTestDatabase::connect();
        $this->db->execute('DROP TABLE IF EXISTS rc_conn_probe');
        $this->db->execute('CREATE TABLE rc_conn_probe (id integer PRIMARY KEY, v varchar(5), t timestamptz, j jsonb, d double precision, b boolean)');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
            PostgresTestDatabase::connect()->execute('DROP TABLE IF EXISTS rc_conn_probe');
        }
    }

    public function testTheSessionIsUtcAndTimesRoundTripAsUtcWallClock(): void
    {
        $this->assertSame('UTC', $this->db->value('SHOW TimeZone'));
        $this->db->execute('INSERT INTO rc_conn_probe (id, t) VALUES (?, ?)', [1, '2026-03-29 01:30:00']);   // the hour that does not exist in Europe/Berlin
        $this->assertSame('2026-03-29 01:30:00', $this->db->value("SELECT to_char(t AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM rc_conn_probe WHERE id = ?", [1]));
        $this->assertSame(1774747800, (int) $this->db->value('SELECT extract(epoch FROM t)::bigint FROM rc_conn_probe WHERE id = ?', [1]));
        // a session somebody else set to another zone does not leak in: a new connection is UTC again
        $this->db->execute("SET TIME ZONE 'America/New_York'");
        $this->db->close();
        $this->assertSame('UTC', $this->db->value('SHOW TimeZone'));
    }

    public function testValuesAreBoundNeverInterpolated(): void
    {
        $evil = "x'); DROP TABLE rc_conn_probe; --";
        $this->db->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [1, 'a?b']);
        $this->assertSame('a?b', $this->db->value('SELECT v FROM rc_conn_probe WHERE id = ?', [1]), 'a question mark inside a VALUE is data');
        $this->assertSame(0, count($this->db->query('SELECT * FROM rc_conn_probe WHERE v = ?', [$evil])));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM rc_conn_probe'));
        $this->expectException(PostgresQueryException::class);
        $this->db->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [2, $evil]);   // longer than varchar(5): refused, not executed
    }

    public function testScalarTypesComeBackAsPhpTypesAndNullsStayNull(): void
    {
        $this->db->execute('INSERT INTO rc_conn_probe (id, v, d, b, j) VALUES (?, ?, ?, ?, ?::jsonb)', [7, null, 1.5, true, '{"a": [1, 2], "b": null}']);
        $r = $this->db->queryOne('SELECT id, v, d, b, j::text AS j, id::bigint AS big FROM rc_conn_probe WHERE id = ?', [7]);
        $this->assertSame([7, null, 1.5, true, 7], [$r['id'], $r['v'], $r['d'], $r['b'], $r['big']]);
        $this->assertSame(['a' => [1, 2], 'b' => null], json_decode((string) $r['j'], true), 'jsonb round trip');
        $this->assertNull($this->db->queryOne('SELECT 1 FROM rc_conn_probe WHERE id = ?', [999]));
        $this->assertNull($this->db->value('SELECT v FROM rc_conn_probe WHERE id = ?', [999]));
        $this->assertSame(1, $this->db->execute('UPDATE rc_conn_probe SET v = ? WHERE id = ?', ['z', 7]));
        $this->assertSame(0, $this->db->execute('UPDATE rc_conn_probe SET v = ? WHERE id = ?', ['z', 8]));
        $this->assertCount(1, $this->db->query('SELECT * FROM rc_conn_probe'));
    }

    public function testErrorsAreMappedToTheRightClassWithoutLeakingValues(): void
    {
        $this->db->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [1, 'sec']);
        try {
            $this->db->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [1, 'dupe']);
            $this->fail('duplicate key');
        } catch (PostgresQueryException $e) {
            $this->assertSame('23505', $e->sqlState());
            $this->assertStringNotContainsString('dupe', $e->getMessage());
            $this->assertInstanceOf(DatabaseException::class, $e);
            $this->assertFalse($e->isRetryable());
        }
        try {
            $this->db->value('SELECT * FROM rc_no_such_table');
            $this->fail('missing table');
        } catch (PostgresQueryException $e) {
            $this->assertSame('42P01', $e->sqlState());
        }
        try {
            $this->db->value('SELECT ?::integer', ['not-a-number-xyz']);
            $this->fail('bad input');
        } catch (PostgresQueryException $e) {
            $this->assertStringNotContainsString('not-a-number-xyz', $e->getMessage(), 'a data exception does not echo the offending value');
        }
    }

    public function testStatementTimeoutIsMappedAndTheSessionStaysUsable(): void
    {
        $db = PostgresTestDatabase::connect(['statement_timeout_ms' => 200]);
        try {
            $db->value('SELECT pg_sleep(2)');
            $this->fail('timeout');
        } catch (PostgresTimeoutException $e) {
            $this->assertSame('57014', $e->sqlState());
        }
        $this->assertSame(1, (int) $db->value('SELECT 1'));
    }

    public function testTransactionsCommitRollBackAndNestAsSavepoints(): void
    {
        $this->assertSame('done', $this->db->transaction(function (): string {
            $this->assertTrue($this->db->inTransaction());
            $this->db->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [1, 'a']);

            return 'done';
        }));
        $this->assertFalse($this->db->inTransaction());
        try {
            $this->db->transaction(function (): void {
                $this->db->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [2, 'b']);
                throw new \RuntimeException('boom');
            });
            $this->fail('rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM rc_conn_probe'), 'the failed transaction left nothing');
        // an inner failure the caller catches does not poison the outer transaction
        $this->db->transaction(function (): void {
            $this->db->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [3, 'c']);
            try {
                $this->db->transaction(function (): void {
                    $this->db->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [4, 'd']);
                    $this->db->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [4, 'dup']);
                });
            } catch (PostgresQueryException) {
                // expected
            }
            $this->db->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [5, 'e']);
        });
        $this->assertSame([1, 3, 5], array_map(static fn (array $r): int => (int) $r['id'], $this->db->query('SELECT id FROM rc_conn_probe ORDER BY id')));
    }

    public function testADeadlockedTransactionIsRepeatedAndASecondDeadlockIsReportedAsRetryable(): void
    {
        $this->db->execute('INSERT INTO rc_conn_probe (id, v) VALUES (1, ?), (2, ?)', ['a', 'b']);
        $attempts = 0;
        $this->db->transaction(function () use (&$attempts): void {
            ++$attempts;
            $this->db->execute('UPDATE rc_conn_probe SET v = ? WHERE id = 1', ['x']);
            if ($attempts === 1) {
                $this->forceDeadlock();
            }
        });
        $this->reapChild();
        $this->assertSame(2, $attempts, 'the server aborted the first attempt (we waited first, so we are the victim) and the whole callback ran again');
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM rc_conn_probe'));
        $this->assertTrue((new PostgresSerializationException('x', '40P01'))->isRetryable());
    }

    /**
     * Close a lock cycle with a child process: it locks row 2, then asks for row 1 (held by this test's transaction); this test then asks for row 2. We started
     * waiting first, so the server's deadlock check fires on our session and aborts it.
     */
    private function forceDeadlock(): void
    {
        $cmd = [PHP_BINARY, '-r', 'require "' . dirname(__DIR__, 4) . '/vendor/autoload.php"; $c = \RivetCore\Tests\Support\PostgresTestDatabase::connect();
            $c->transaction(function () use ($c) { $c->execute("UPDATE rc_conn_probe SET v = \'o\' WHERE id = 2"); usleep(400000); $c->execute("UPDATE rc_conn_probe SET v = \'o\' WHERE id = 1"); });'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($p);
        usleep(150000);   // the child holds row 2 now
        $this->child = [$p, $pipes];
        $this->db->execute('UPDATE rc_conn_probe SET v = ? WHERE id = 2', ['y']);   // throws the deadlock; the child finishes once our transaction is rolled back
    }

    /** @var array{0:resource,1:array<int,resource>}|null */
    private ?array $child = null;

    private function reapChild(): void
    {
        if ($this->child !== null) {
            stream_get_contents($this->child[1][1]);
            proc_close($this->child[0]);
            $this->child = null;
        }
    }

    public function testALostConnectionIsAConnectionExceptionNothingIsCommittedAndTheNextCallReconnects(): void
    {
        $victim = PostgresTestDatabase::connect();
        $pid = (int) $victim->value('SELECT pg_backend_pid()');
        $this->assertGreaterThan(0, $pid);
        try {
            $victim->transaction(function () use ($victim, $pid): void {
                $victim->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [1, 'a']);
                $this->db->value('SELECT pg_terminate_backend(?)', [$pid]);   // the connection drops in the middle of the transaction
                usleep(150000);
                $victim->execute('INSERT INTO rc_conn_probe (id, v) VALUES (?, ?)', [2, 'b']);
            });
            $this->fail('connection lost');
        } catch (PostgresConnectionException $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
            $this->assertFalse($victim->inTransaction());
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM rc_conn_probe'), 'the server rolled the half transaction back');
        $this->assertSame(1, (int) $victim->value('SELECT 1'), 'the next call opened a new session');
        $this->assertNotSame($pid, (int) $victim->value('SELECT pg_backend_pid()'));
    }

    public function testAnUnreachableServerIsAConnectionExceptionWithoutThePassword(): void
    {
        $bad = new PdoPostgresConnection(PostgresTestDatabase::config(['port' => 1, 'password' => 'hunter2-not-a-real-password', 'connect_timeout' => 1]));
        try {
            $bad->value('SELECT 1');
            $this->fail('unreachable');
        } catch (PostgresConnectionException $e) {
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
            $this->assertStringNotContainsString('hunter2', $e->getTraceAsString(), 'the password is #[SensitiveParameter]');
        }
    }

    public function testDriverErrorsMapToTheDocumentedClasses(): void
    {
        $pdo = static function (string $state, string $text): \PDOException {
            $e = new \PDOException($text);
            $e->errorInfo = [$state, 7, $text];

            return $e;
        };
        $map = static fn (string $state, string $text) => \RivetCore\Rmm\Store\Postgres\Pdo\PdoErrorMapper::map($pdo($state, $text), 'pw');
        $this->assertInstanceOf(PostgresConnectionException::class, $map('28P01', 'FATAL:  password authentication failed for user "x"'), 'bad credentials: the store is unusable');
        $this->assertInstanceOf(PostgresConnectionException::class, $map('57P01', 'FATAL:  terminating connection due to administrator command'));
        $this->assertInstanceOf(PostgresConnectionException::class, $map('53300', 'FATAL:  sorry, too many clients already'));
        $this->assertInstanceOf(PostgresConnectionException::class, $map('08006', 'connection failure'));
        $this->assertInstanceOf(PostgresConnectionException::class, $map('HY000', 'SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly'));
        $this->assertInstanceOf(PostgresSerializationException::class, $map('40001', 'ERROR:  could not serialize access'));
        $this->assertInstanceOf(PostgresTimeoutException::class, $map('55P03', 'ERROR:  could not obtain lock on row'));
        $this->assertInstanceOf(PostgresQueryException::class, $map('42601', 'ERROR:  syntax error at or near "x"'));
        $this->assertStringNotContainsString('pw', $map('28P01', 'FATAL:  password authentication failed pw')->getMessage());
        $this->assertSame('23505', $map('23505', "ERROR:  duplicate key value violates unique constraint \"u\"\nDETAIL:  Key (a)=(secret-value) already exists.")->sqlState());
        $this->assertStringNotContainsString('secret-value', $map('23505', "ERROR:  duplicate key value violates unique constraint \"u\"\nDETAIL:  Key (a)=(secret-value) already exists.")->getMessage());
    }

    public function testTheBreakerOpensAfterAConnectionFailureAndClosesAgain(): void
    {
        $now = 1000.0;
        $dir = sys_get_temp_dir() . '/rc_breaker_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $bad = new PdoPostgresConnection(PostgresTestDatabase::config(['port' => 1, 'connect_timeout' => 1]));
        $b = new CircuitBreakerConnection($bad, 'test-' . bin2hex(random_bytes(4)), 15, $dir, function () use (&$now): float {
            return $now;
        });
        try {
            $b->value('SELECT 1');
            $this->fail('first attempt connects and fails');
        } catch (PostgresConnectionException) {
        }
        $this->assertGreaterThan(0.0, $b->openFor());
        $t = microtime(true);
        try {
            $b->value('SELECT 1');
            $this->fail('open');
        } catch (PostgresConnectionException $e) {
            $this->assertStringContainsString('not trying again', $e->getMessage());
        }
        $this->assertLessThan(0.05, microtime(true) - $t, 'an open breaker fails without touching the network');
        $this->assertCount(1, glob($dir . '/rivetcore-pg-breaker-*') ?: [], 'the state is shared through one small file');
        // another object for the same database (another worker) sees it too
        $this->assertGreaterThan(0.0, (new CircuitBreakerConnection($bad, $this->identityOf($b), 15, $dir, fn (): float => $now))->openFor());
        $now += 16.0;
        $this->assertSame(0.0, $b->openFor(), 'the window is over: the next call tries again');
        $b->reset();
        $this->assertSame([], glob($dir . '/rivetcore-pg-breaker-*') ?: []);
        @rmdir($dir);
    }

    public function testAStatementErrorDoesNotOpenTheBreaker(): void
    {
        $dir = sys_get_temp_dir() . '/rc_breaker_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $b = new CircuitBreakerConnection($this->db, 'ok-' . bin2hex(random_bytes(4)), 15, $dir);
        try {
            $b->value('SELECT * FROM rc_no_such_table');
        } catch (PostgresQueryException) {
        }
        $this->assertSame(0.0, $b->openFor());
        $this->assertSame(1, (int) $b->value('SELECT 1'));
        @rmdir($dir);
    }

    public function testConfigRefusesUnsafeInputAndHidesThePassword(): void
    {
        foreach ([['database' => "a;b", 'user' => 'u'], ['database' => 'd', 'user' => 'u', 'host' => 'h; sslmode=disable'], ['database' => 'd', 'user' => 'u', 'sslmode' => 'maybe'],
            ['database' => 'd', 'user' => 'u', 'schema' => 'Public"; DROP'], ['database' => 'd', 'user' => 'u', 'port' => 0], ['database' => 'd', 'user' => 'u', 'typo_key' => 1]] as $bad) {
            try {
                PostgresConfig::fromArray($bad);
                $this->fail('accepted: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $c = PostgresConfig::fromArray(['database' => 'rivet', 'user' => 'rivet', 'password' => 'pw-secret-123', 'host' => '/var/run/postgresql']);
        $this->assertStringNotContainsString('pw-secret-123', $c->dsn());
        $this->assertStringNotContainsString('pw-secret-123', print_r($c, true));
        $this->assertStringNotContainsString('pw-secret-123', var_export($c, true) . json_encode($c));
        $this->assertStringContainsString('host=/var/run/postgresql', $c->dsn());
        $this->expectException(\LogicException::class);
        serialize($c);
    }

    public function testSealedPasswordsGoThroughTheEditionsSecretBox(): void
    {
        $box = new \RivetCore\Testing\InMemorySecretBox(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $cfg = PostgresConfig::fromSealed(['database' => 'd', 'user' => 'u', 'password_sealed' => $box->encrypt('sealed-pw')], $box);
        $this->assertSame('sealed-pw', $cfg->password());
        $this->expectException(\InvalidArgumentException::class);
        PostgresConfig::fromSealed(['database' => 'd', 'user' => 'u', 'password_sealed' => 'garbage-not-a-box'], $box);
    }

    private function identityOf(CircuitBreakerConnection $b): string
    {
        return (new \ReflectionProperty($b, 'identity'))->getValue($b);
    }
}
