<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Mcp\AgentDirectoryInterface;
use RivetCore\Mcp\IdentityLinker;
use RivetCore\Mcp\Migration\Migration0003McpUnlinkedIdentities;
use RivetCore\Mcp\RedisMetadataCache;
use RivetCore\Mcp\UnlinkedIdentityStore;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;
use RivetCore\Tests\Support\TestRedis;

final class McpIdentityTest extends TestCase
{
    private MysqliDatabase $db;
    private UnlinkedIdentityStore $store;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        $this->db->execute('DROP TABLE IF EXISTS mcp_unlinked_identities');
        (new Migration0003McpUnlinkedIdentities())->up($this->db);
        $this->store = new UnlinkedIdentityStore($this->db, static function (string $m): void {
        });
    }

    private function dir(array $agents = [1 => null, 2 => 'taken-sub'], bool $failLink = false): AgentDirectoryInterface
    {
        return new class($agents, $failLink) implements AgentDirectoryInterface {
            public array $linked = [];

            public function __construct(private array $agents, private bool $failLink)
            {
            }

            public function linkableAgents(): array
            {
                return [];
            }

            public function linkedAgents(): array
            {
                return [];
            }

            public function linkedCount(): int
            {
                return count($this->linked);
            }

            public function findActiveAgent(int $userId): ?array
            {
                return array_key_exists($userId, $this->agents) ? ['linked' => $this->agents[$userId] !== null] : null;
            }

            public function identityTaken(string $issuer, string $subject): bool
            {
                return $subject === 'taken-sub';
            }

            public function link(int $userId, string $issuer, string $subject): void
            {
                if ($this->failLink) {
                    throw new \RuntimeException('directory write failed');
                }
                $this->linked[$userId] = [$issuer, $subject];
            }

            public function unlink(int $userId): void
            {
                unset($this->linked[$userId]);
            }
        };
    }

    public function testRecordCountsRepeatsAndKeepsNewestDetails(): void
    {
        $this->store->record('https://idp', 'sub-1', ['email' => "a@x.test\x00", 'name' => 'Ann']);
        $this->store->record('https://idp', 'sub-1', ['email' => 'a@x.test']);
        $this->store->record('https://idp', 'sub-2', []);
        $rows = $this->store->pending();
        $this->assertCount(2, $rows);
        $one = array_values(array_filter($rows, fn ($r) => $r['subject'] === 'sub-1'))[0];
        $this->assertEquals(2, $one['attempts']);
        $this->assertSame('a@x.test', $one['email']);
        $this->assertSame('Ann', $one['display_name'], 'a later sighting without a name keeps the earlier one');
    }

    public function testRecordIsCappedAndNeverThrows(): void
    {
        for ($i = 0; $i < UnlinkedIdentityStore::MAX_PENDING; $i++) {
            $this->db->execute('INSERT INTO mcp_unlinked_identities (issuer, subject) VALUES (?, ?)', ['i', "s$i"]);
        }
        $this->store->record('i', 'one-too-many', []);
        $this->assertSame(UnlinkedIdentityStore::MAX_PENDING, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM mcp_unlinked_identities')['c']);
        $this->store->record('i', 's0', []);
        $this->assertEquals(2, $this->db->fetchOne("SELECT attempts FROM mcp_unlinked_identities WHERE subject='s0'")['attempts'], 'known identities still count at the cap');
        $this->db->execute('DROP TABLE mcp_unlinked_identities');
        $this->store->record('i', 'x', []); // table gone: must not throw
        $this->addToAssertionCount(1);
    }

    public function testPendingPrunesOldEntries(): void
    {
        $this->store->record('i', 'fresh', []);
        $this->db->execute("INSERT INTO mcp_unlinked_identities (issuer, subject, last_seen_at) VALUES ('i', 'stale', NOW() - INTERVAL 40 DAY)");
        $subjects = array_column($this->store->pending(), 'subject');
        $this->assertSame(['fresh'], $subjects);
    }

    public function testLinkSuccessRemovesPending(): void
    {
        $this->store->record('https://idp', 'sub-9', []);
        $id = (int) $this->store->pending()[0]['mcp_unlinked_id'];
        $dir = $this->dir();
        $this->assertSame([true, 'Linked.'], (new IdentityLinker($this->db, $this->store, $dir))->link($id, 1));
        $this->assertSame(['https://idp', 'sub-9'], $dir->linked[1]);
        $this->assertSame([], $this->store->pending());
    }

    public function testLinkRefusals(): void
    {
        $this->store->record('https://idp', 'sub-9', []);
        $id = (int) $this->store->pending()[0]['mcp_unlinked_id'];
        $linker = new IdentityLinker($this->db, $this->store, $this->dir());
        $this->assertSame([false, 'Choose an active agent.'], $linker->link($id, 99));
        $this->assertSame([false, 'That agent is already linked. Unlink them first.'], $linker->link($id, 2));
        $this->assertSame([false, 'That sign-in is no longer waiting. Ask the person to try again.'], $linker->link(424242, 1));
        $this->store->record('https://idp', 'taken-sub', []);
        $takenId = (int) $this->db->fetchOne("SELECT mcp_unlinked_id id FROM mcp_unlinked_identities WHERE subject='taken-sub'")['id'];
        $this->assertSame([false, 'This identity is already linked to another account.'], $linker->link($takenId, 1));
        $this->assertCount(2, $this->store->pending(), 'refusals change nothing');
    }

    public function testLinkRollsBackWhenTheDirectoryFails(): void
    {
        $this->store->record('https://idp', 'sub-9', []);
        $id = (int) $this->store->pending()[0]['mcp_unlinked_id'];
        $linker = new IdentityLinker($this->db, $this->store, $this->dir([1 => null], true), static function (string $m): void {
        });
        $this->assertSame([false, 'Could not link. Nothing was changed.'], $linker->link($id, 1));
        $this->assertCount(1, $this->store->pending());
    }

    public function testDismiss(): void
    {
        $this->store->record('i', 's', []);
        $this->store->dismiss((int) $this->store->pending()[0]['mcp_unlinked_id']);
        $this->assertSame([], $this->store->pending());
    }

    public function testMetadataCache(): void
    {
        if (!TestRedis::available()) {
            $this->markTestSkipped('throwaway Redis required');
        }
        $r = new TestRedis();
        $r->client()->flushdb();
        $c = new RedisMetadataCache($r);
        $this->assertNull($c->get('k'));
        $this->assertTrue($c->set('k', ['a' => 1], 60));
        $this->assertSame(['a' => 1], $c->get('k'));
        $this->assertSame(1, (int) $r->client()->exists('mcp_metadata:' . hash('sha256', 'k')));
        $this->assertTrue($c->delete('k'));
        $this->assertFalse($c->has('k'));
        $this->assertFalse($c->clear(), 'never clears the shared database');
        $down = new RedisMetadataCache(new TestRedis(null, true));
        $this->assertSame('dflt', $down->get('k', 'dflt'));
        $this->assertFalse($down->set('k', 1));
    }
}
