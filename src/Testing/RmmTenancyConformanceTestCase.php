<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;

/**
 * Conformance kit for {@see RmmTenancyInterface}. The edition tells the case how to create clients, locations and per-user
 * client restrictions in its own tables.
 *
 * Checks: clientName() returns the name of an existing client and null for an unknown one (this is the existence check);
 * locationInClient() is true only for a location of THAT client (not another client's, not an unknown one); visibleClientIds()
 * is null for a user without restriction rows, lists exactly the restricted clients otherwise (an empty restriction is [], not
 * null), never lists client 0 and returns ints; nothing throws for odd ids.
 *
 * @api
 */
abstract class RmmTenancyConformanceTestCase extends TestCase
{
    use UntypedValues;

    abstract protected function tenancy(): RmmTenancyInterface;

    /** Create a client with this name; return its id. */
    abstract protected function createClient(string $name): int;

    /** Create a location of the client; return its id. */
    abstract protected function createLocation(int $clientId): int;

    /**
     * Make $userId see only these clients (an empty list: none), or remove every restriction (null). The user need not exist
     * in the edition's users table unless it has a foreign key; override {@see self::newUserId()} then.
     *
     * @param list<int>|null $clientIds
     */
    abstract protected function restrictUser(int $userId, ?array $clientIds): void;

    protected function newUserId(): int
    {
        return random_int(1_000_000, 2_000_000_000);
    }

    public function testClientNameIsTheNameOrNullForUnknownClients(): void
    {
        $name = 'Conformance ' . bin2hex(random_bytes(4));
        $id = $this->createClient($name);
        $this->assertSame($name, $this->tenancy()->clientName($id));
        $this->assertNull($this->tenancy()->clientName(2_000_000_000));
        $this->assertNull($this->tenancy()->clientName(-5));
    }

    public function testLocationBelongsOnlyToItsOwnClient(): void
    {
        $a = $this->createClient('A ' . bin2hex(random_bytes(3)));
        $b = $this->createClient('B ' . bin2hex(random_bytes(3)));
        $loc = $this->createLocation($a);
        $this->assertTrue($this->tenancy()->locationInClient($loc, $a));
        $this->assertFalse($this->tenancy()->locationInClient($loc, $b));
        $this->assertFalse($this->tenancy()->locationInClient(2_000_000_000, $a));
        $this->assertFalse($this->tenancy()->locationInClient($loc, 2_000_000_000));
    }

    public function testUnrestrictedUserIsNull(): void
    {
        $u = $this->newUserId();
        $this->restrictUser($u, null);
        $this->assertNull($this->tenancy()->visibleClientIds($u));
    }

    public function testRestrictedUserSeesExactlyTheirClients(): void
    {
        $a = $this->createClient('A ' . bin2hex(random_bytes(3)));
        $b = $this->createClient('B ' . bin2hex(random_bytes(3)));
        $this->createClient('C ' . bin2hex(random_bytes(3)));
        $u = $this->newUserId();
        $this->restrictUser($u, [$a, $b]);
        $ids = $this->tenancy()->visibleClientIds($u);
        $this->assertIsArray($ids);
        $sorted = $ids;
        sort($sorted);
        $this->assertSame([min($a, $b), max($a, $b)], $sorted);
        foreach ($ids as $id) {
            $this->assertIsInt(self::untyped($id));
            $this->assertNotSame(0, $id);
        }
    }

    public function testRestrictionToNothingIsAnEmptyListNotNull(): void
    {
        $u = $this->newUserId();
        $this->restrictUser($u, []);
        $this->assertSame([], $this->tenancy()->visibleClientIds($u));
    }

    public function testRestrictionsAreIsolatedPerUser(): void
    {
        $a = $this->createClient('A ' . bin2hex(random_bytes(3)));
        $u1 = $this->newUserId();
        $u2 = $this->newUserId();
        $this->restrictUser($u1, [$a]);
        $this->restrictUser($u2, null);
        $this->assertSame([$a], $this->tenancy()->visibleClientIds($u1));
        $this->assertNull($this->tenancy()->visibleClientIds($u2));
    }

    public function testOddIdsNeverThrow(): void
    {
        foreach ([0, -1, PHP_INT_MAX, PHP_INT_MIN] as $id) {
            $this->tenancy()->clientName($id);
            $this->tenancy()->locationInClient($id, $id);
            $this->tenancy()->visibleClientIds($id);
        }
        $this->addToAssertionCount(1);
    }
}
