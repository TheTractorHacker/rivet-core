<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use RivetCore\Mcp\AgentDirectoryInterface;

/**
 * Behaviour every AgentDirectoryInterface adapter must have (MCP identity linking). Implement the arrange hook over the
 * edition's user storage; directory() is called after it and must see what it wrote.
 */
abstract class AgentDirectoryConformanceTestCase extends TestCase
{
    /** Store an agent (staff user) with no linked identity. */
    abstract protected function givenAgent(int $userId, string $name, string $email, bool $active = true): void;

    abstract protected function directory(): AgentDirectoryInterface;

    public function testLinkableAgentsListsActiveUnlinkedAgentsWithTheDocumentedKeys(): void
    {
        $this->givenAgent(9101, 'Ada', 'ada@example.test');
        $this->givenAgent(9102, 'Gone', 'gone@example.test', false);
        $rows = $this->directory()->linkableAgents();
        $ids = array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
        $this->assertContains(9101, $ids);
        $this->assertNotContains(9102, $ids, 'an inactive agent must not be linkable');
        foreach ($rows as $r) {
            foreach (['user_id', 'user_name', 'user_email'] as $key) {
                $this->assertArrayHasKey($key, $r);
            }
        }
    }

    public function testFindActiveAgent(): void
    {
        $this->givenAgent(9103, 'Bob', 'bob@example.test');
        $this->givenAgent(9104, 'Gone', 'gone2@example.test', false);
        $d = $this->directory();
        $this->assertSame(false, $d->findActiveAgent(9103)['linked'] ?? null);
        $this->assertNull($d->findActiveAgent(9104), 'inactive agent');
        $this->assertNull($d->findActiveAgent(987654321), 'unknown agent');
    }

    public function testLinkAndUnlinkRoundTrip(): void
    {
        $this->givenAgent(9105, 'Cy', 'cy@example.test');
        $d = $this->directory();
        $before = $d->linkedCount();
        $this->assertFalse($d->identityTaken('https://issuer.example.test', 'sub-9105'));

        $d->link(9105, 'https://issuer.example.test', 'sub-9105');
        $this->assertTrue($d->identityTaken('https://issuer.example.test', 'sub-9105'));
        $this->assertSame($before + 1, $d->linkedCount());
        $this->assertTrue($d->findActiveAgent(9105)['linked'] ?? false);
        $this->assertNotContains(9105, array_map(static fn (array $r): int => (int) $r['user_id'], $d->linkableAgents()), 'a linked agent is no longer linkable');
        $this->assertContains(9105, array_map(static fn (array $r): int => (int) ($r['user_id'] ?? 0), $d->linkedAgents()));

        $d->unlink(9105);
        $this->assertFalse($d->identityTaken('https://issuer.example.test', 'sub-9105'));
        $this->assertSame($before, $d->linkedCount());
        $this->assertFalse($d->findActiveAgent(9105)['linked'] ?? true);
    }

    public function testIdentityIsCaseSensitive(): void
    {
        $this->givenAgent(9106, 'Di', 'di@example.test');
        $d = $this->directory();
        $d->link(9106, 'https://Issuer.example.test', 'Sub-9106');
        $this->assertFalse($d->identityTaken('https://issuer.example.test', 'sub-9106'), 'OIDC subjects are case sensitive: "Sub" and "sub" are different people');
        $d->unlink(9106);
    }
}
