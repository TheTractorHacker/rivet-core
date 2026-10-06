<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Database\DatabaseException;
use RivetCore\Database\DatabaseInterface;

/**
 * Behavioural contract every DatabaseInterface implementation must satisfy.
 * RivetCore, RivetIT and RivetMSP each extend this against their own adapter
 * and a scratch MySQL/MariaDB database (never production data).
 *
 * @internal A test helper for edition test suites: it needs PHPUnit, which is a dev dependency, so it is not part of the
 *           semver/backward-compatibility promise (decision on a separate testing package: issue #49).
 */
abstract class DatabaseContractTestCase extends TestCase
{
    abstract protected function database(): DatabaseInterface;

    protected function setUp(): void
    {
        $db = $this->database();
        $db->execute('DROP TABLE IF EXISTS rc_contract');
        $db->execute('CREATE TABLE rc_contract (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NULL, n INT NULL, f DOUBLE NULL) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        $this->database()->execute('DROP TABLE IF EXISTS rc_contract');
    }

    public function testInsertReportsInsertIdAndAffectedRows(): void
    {
        $r = $this->database()->execute('INSERT INTO rc_contract (name, n) VALUES (?, ?)', ['a', 1]);
        $this->assertSame(1, $r->affectedRows);
        $this->assertSame(1, $r->insertId);
        $r2 = $this->database()->execute('INSERT INTO rc_contract (name, n) VALUES (?, ?)', ['b', 2]);
        $this->assertSame(2, $r2->insertId);
    }

    public function testFetchOneAndFetchAll(): void
    {
        $db = $this->database();
        $db->execute('INSERT INTO rc_contract (name, n) VALUES (?, ?), (?, ?)', ['a', 1, 'b', 2]);
        $one = $db->fetchOne('SELECT name, n FROM rc_contract WHERE name = ?', ['b']);
        $this->assertSame('b', $one['name']);
        $this->assertEquals(2, $one['n']);
        $this->assertNull($db->fetchOne('SELECT * FROM rc_contract WHERE name = ?', ['zzz']));
        $this->assertCount(2, $db->fetchAll('SELECT * FROM rc_contract ORDER BY id'));
        $this->assertSame([], $db->fetchAll('SELECT * FROM rc_contract WHERE n > ?', [99]));
    }

    public function testUpdateAndDeleteAffectedRows(): void
    {
        $db = $this->database();
        $db->execute('INSERT INTO rc_contract (name, n) VALUES (?, ?), (?, ?)', ['a', 1, 'b', 1]);
        $this->assertSame(2, $db->execute('UPDATE rc_contract SET n = ? WHERE n = ?', [5, 1])->affectedRows);
        $this->assertSame(1, $db->execute('DELETE FROM rc_contract WHERE name = ?', ['a'])->affectedRows);
        $this->assertNull($db->execute('DELETE FROM rc_contract WHERE name = ?', ['nope'])->insertId);
    }

    public function testParameterTypesNullBoolFloatAndLimit(): void
    {
        $db = $this->database();
        $db->execute('INSERT INTO rc_contract (name, n, f) VALUES (?, ?, ?)', [null, true, 1.5]);
        $row = $db->fetchOne('SELECT * FROM rc_contract');
        $this->assertNull($row['name']);
        $this->assertEquals(1, $row['n']);
        $this->assertEquals(1.5, $row['f']);
        $db->execute('INSERT INTO rc_contract (name) VALUES (?), (?), (?)', ['x', 'y', 'z']);
        $this->assertCount(2, $db->fetchAll('SELECT * FROM rc_contract ORDER BY id LIMIT ?', [2]));
    }

    public function testParametersAreNotInterpolated(): void
    {
        $db = $this->database();
        $evil = "x'); DROP TABLE rc_contract; --";
        $db->execute('INSERT INTO rc_contract (name) VALUES (?)', [$evil]);
        $this->assertSame($evil, $db->fetchOne('SELECT name FROM rc_contract')['name']);
    }

    public function testTransactionCommitsAndReturnsValue(): void
    {
        $db = $this->database();
        $value = $db->transaction(function () use ($db) {
            $db->execute('INSERT INTO rc_contract (name) VALUES (?)', ['t']);

            return 'ok';
        });
        $this->assertSame('ok', $value);
        $this->assertCount(1, $db->fetchAll('SELECT * FROM rc_contract'));
    }

    public function testTransactionRollsBackAndRethrows(): void
    {
        $db = $this->database();
        $message = null;
        try {
            $db->transaction(function () use ($db) {
                $db->execute('INSERT INTO rc_contract (name) VALUES (?)', ['t']);
                throw new \LogicException('boom');
            });
        } catch (\LogicException $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('boom', $message, 'exception not rethrown');
        $this->assertSame([], $db->fetchAll('SELECT * FROM rc_contract'));
    }

    public function testNestedTransactionJoinsOuter(): void
    {
        $db = $this->database();
        try {
            $db->transaction(function () use ($db) {
                $db->transaction(fn () => $db->execute('INSERT INTO rc_contract (name) VALUES (?)', ['inner']));
                throw new \RuntimeException('outer fails');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame([], $db->fetchAll('SELECT * FROM rc_contract'));
    }

    public function testErrorsBecomeDatabaseException(): void
    {
        $this->expectException(DatabaseException::class);
        $this->database()->fetchAll('SELECT * FROM rc_table_that_does_not_exist');
    }

    public function testConstraintViolationBecomesDatabaseException(): void
    {
        $db = $this->database();
        $db->execute('INSERT INTO rc_contract (id, name) VALUES (?, ?)', [1, 'a']);
        $this->expectException(DatabaseException::class);
        $db->execute('INSERT INTO rc_contract (id, name) VALUES (?, ?)', [1, 'dup']);
    }
}
