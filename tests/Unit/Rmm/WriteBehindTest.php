<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Database\ExecutionResult;
use RivetCore\Rmm\Store\Postgres\Guard\HotStoreFaults;
use RivetCore\Rmm\Store\Postgres\Guard\HotStoreGuard;
use RivetCore\Rmm\Store\Postgres\Guard\WriteBehind;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Support\TrackedDatabase;
use RivetCore\Tests\Support\MutableClock;

/** Writes to a hot store in another database wait for the control transaction (no database needed: the transaction is a fake that commits or throws). */
final class WriteBehindTest extends TestCase
{
    private function db(): DatabaseInterface
    {
        return new class implements DatabaseInterface {
            public function fetchOne(string $sql, array $params = []): ?array
            {
                return null;
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                return [];
            }

            public function execute(string $sql, array $params = []): ExecutionResult
            {
                return new ExecutionResult(0);
            }

            public function transaction(callable $callback): mixed
            {
                return $callback();   // like the adapters: a nested call joins the outer transaction
            }
        };
    }

    public function testAWriteInsideATransactionRunsAfterItInOrderAndAWriteOutsideRunsAtOnce(): void
    {
        $behind = new WriteBehind();
        $db = new TrackedDatabase($this->db(), $behind);
        $guard = new HotStoreGuard(new HotStoreFaults(new Sql($db, new MutableClock())), null, $behind);
        $log = [];
        $guard->lossy('s', 'a', function () use (&$log): void {
            $log[] = 'outside';
        });
        $this->assertSame(['outside'], $log, 'no transaction: at once');
        $result = $db->transaction(function () use ($guard, &$log, $db): string {
            $guard->lossy('s', 'a', function () use (&$log): void {
                $log[] = 'first';
            });
            $db->transaction(function () use ($guard, &$log): void {   // a nested level does not flush
                $guard->lossy('s', 'a', function () use (&$log): void {
                    $log[] = 'second';
                });
            });
            $log[] = 'body done';

            return 'ok';
        });
        $this->assertSame('ok', $result, 'the transaction returns its own value');
        $this->assertSame(['outside', 'body done', 'first', 'second'], $log, 'held until the outermost transaction committed, then in order');
        $this->assertSame(0, $behind->pending());
    }

    public function testARolledBackTransactionDiscardsItsWrites(): void
    {
        $behind = new WriteBehind();
        $db = new TrackedDatabase($this->db(), $behind);
        $guard = new HotStoreGuard(new HotStoreFaults(new Sql($db, new MutableClock())), null, $behind);
        $ran = 0;
        try {
            $db->transaction(function () use ($guard, &$ran): void {
                $guard->lossy('s', 'a', function () use (&$ran): void {
                    ++$ran;
                });
                throw new \RuntimeException('rolled back');
            });
            $this->fail('rethrown');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, $ran);
        $this->assertSame(0, $behind->pending());
        // and the next transaction starts clean
        $db->transaction(function () use ($guard, &$ran): void {
            $guard->lossy('s', 'a', function () use (&$ran): void {
                ++$ran;
            });
        });
        $this->assertSame(1, $ran);
    }

    public function testAHeldWriteThatFailsAfterTheCommitIsCountedNotThrown(): void
    {
        $behind = new WriteBehind();
        $db = new TrackedDatabase($this->db(), $behind);
        $guard = new HotStoreGuard(new HotStoreFaults(new Sql($db, new MutableClock())), null, $behind);
        $other = 0;
        $db->transaction(function () use ($guard, &$other): void {
            $guard->lossy('s', 'a', static function (): void {
                throw new \RivetCore\Database\DatabaseException('the hot store is down');
            });
            $guard->lossy('s', 'b', function () use (&$other): void {
                ++$other;
            });
        });
        $this->assertSame(1, $other, 'a failing held write does not stop the ones behind it, and does not fail the caller');
    }

    public function testAStrictWriteIsNeverHeldBecauseItsCallerNeedsTheAnswer(): void
    {
        $behind = new WriteBehind();
        $db = new TrackedDatabase($this->db(), $behind);
        $guard = new HotStoreGuard(new HotStoreFaults(new Sql($db, new MutableClock())), null, $behind);
        $db->transaction(function () use ($guard): void {
            $this->assertSame(7, $guard->strict('s', 'a', static fn (): int => 7));
            $this->assertSame(0, $guard->maintenance('s', 'a', static fn (): int => 0, 0));
        });
        $this->assertSame(0, $behind->pending());
    }

    public function testARunawayLoopCannotHoldUnboundedMemory(): void
    {
        $behind = new WriteBehind();
        $db = new TrackedDatabase($this->db(), $behind);
        $guard = new HotStoreGuard(new HotStoreFaults(new Sql($db, new MutableClock())), null, $behind);
        $ran = 0;
        $db->transaction(function () use ($guard, &$ran, $behind): void {
            for ($i = 0; $i < WriteBehind::CAP + 5; ++$i) {
                $guard->lossy('s', 'a', function () use (&$ran): void {
                    ++$ran;
                });
            }
            $this->assertSame(WriteBehind::CAP, $behind->pending());
            $this->assertSame(5, $ran, 'beyond the cap a write runs at once');
        });
        $this->assertSame(WriteBehind::CAP + 5, $ran);
    }
}
