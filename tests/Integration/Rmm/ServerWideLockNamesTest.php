<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Storage\PartitionMaintenance;
use RivetCore\Rmm\Support\RmmCache;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * CORE-08 (SR-11 class): GET_LOCK() names are server-wide. A lock another database holds under the bare name must not stop this
 * database's partition maintenance, job claim or cache refresh, and ours must be released under the scoped name.
 */
final class ServerWideLockNamesTest extends RmmTestCase
{
    public function testPartitionLockIsScopedToTheDatabase(): void
    {
        $other = \RivetCore\Tests\Support\ScratchDb::connect();
        $this->assertNotNull($other);
        $other->query("SELECT GET_LOCK('" . PartitionMaintenance::LOCK . "', 0)");   // what another schema used to hold under the shared name
        try {
            $pm = new PartitionMaintenance(new Sql($this->h->db, new MutableClock()));
            $lock = new \ReflectionMethod($pm, 'lock');
            $this->assertTrue($lock->invoke($pm), 'the bare-name lock of another database must not block this one');
            $unlock = new \ReflectionMethod($pm, 'unlock');
            $unlock->invoke($pm);
            $scoped = PartitionMaintenance::LOCK . ':' . md5((string) $this->h->one('SELECT DATABASE()'));
            $this->assertSame('1', (string) $this->h->one("SELECT IS_FREE_LOCK('$scoped')"), 'released under the scoped name');
        } finally {
            $other->query("SELECT RELEASE_LOCK('" . PartitionMaintenance::LOCK . "')");
            $other->close();
        }
    }

    public function testJobClaimWaitsOnlyForItsOwnDatabasesLock(): void
    {
        $bare = \RivetCore\Jobs\JobClaimer::LOCK_PREFIX . '*';
        $scoped = $bare . ':' . md5((string) $this->h->one('SELECT DATABASE()'));
        $claimer = new \RivetCore\Jobs\JobClaimer($this->h->db, false, \RivetCore\Jobs\JobClaimer::SKIP_LOCKED);
        foreach ([[$bare, true], [$scoped, false]] as [$held, $mustNotWait]) {
            $other = \RivetCore\Tests\Support\ScratchDb::connect();
            $this->assertNotNull($other);
            $other->query("SELECT GET_LOCK('$held', 0)");
            $t = microtime(true);
            $claimer->claim(5, null, 3);
            $took = microtime(true) - $t;
            $other->query("SELECT RELEASE_LOCK('$held')");
            $other->close();
            if ($mustNotWait) {
                $this->assertLessThan(1.0, $took, 'a lock held under the bare name by another database must not make this one wait');
            } else {
                $this->assertGreaterThan(1.5, $took, 'the scoped name is the one the claimer really takes');
            }
        }
    }
}
