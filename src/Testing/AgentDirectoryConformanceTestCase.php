<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Mcp\AgentDirectoryInterface;

/**
 * Conformance kit for {@see AgentDirectoryInterface} (the MCP agent lookup). The edition seeds agents in its own user
 * store ({@see self::storeAgent()}); the case then drives the directory through the link/unlink life cycle.
 *
 * Checks: an active, unlinked agent is listed as linkable (with user_id, user_name, user_email); inactive agents are neither
 * linkable nor "active"; findActiveAgent() reports linked true/false and null for unknown ids; link() moves an agent from
 * linkable to linked, makes its (issuer, subject) pair taken and bumps linkedCount(); unlink() reverses all of that; the
 * (issuer, subject) pair is matched as a pair; link/unlink for an unknown user are harmless no-ops.
 *
 * It only reasons about the agents it seeded (counts are compared as deltas), so a scratch database with other users is fine.
 * It does not exercise row locking inside a transaction (findActiveAgent() "FOR UPDATE"); test that in the edition.
 *
 * @api
 */
abstract class AgentDirectoryConformanceTestCase extends TestCase
{
    use UntypedValues;

    /** @var list<int> */
    private array $agents = [];

    abstract protected function directory(): AgentDirectoryInterface;

    /** Create an agent in the edition's user store, without any linked identity, and return its user id. */
    abstract protected function storeAgent(string $name, string $email, bool $active): int;

    /** Remove an agent created by {@see self::storeAgent()}. */
    abstract protected function deleteAgent(int $userId): void;

    final protected function agent(bool $active = true): int
    {
        $n = bin2hex(random_bytes(4));

        return $this->agents[] = $this->storeAgent("Conformance Agent $n", "agent-$n@example.com", $active);
    }

    protected function tearDown(): void
    {
        foreach ($this->agents as $id) {
            try {
                $this->deleteAgent($id);
            } catch (\Throwable) {
                // best effort
            }
        }
        $this->agents = [];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function byUserId(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = $r;
        }

        return $out;
    }

    /** @return array{0:string,1:string} a fresh [issuer, subject] */
    private function identity(): array
    {
        return ['https://issuer.example.com/' . bin2hex(random_bytes(3)), 'sub-' . bin2hex(random_bytes(6))];
    }

    public function testAnActiveUnlinkedAgentIsLinkable(): void
    {
        $id = $this->agent();
        $rows = self::byUserId($this->directory()->linkableAgents());
        $this->assertArrayHasKey($id, $rows);
        $this->assertArrayHasKey('user_name', $rows[$id]);
        $this->assertArrayHasKey('user_email', $rows[$id]);
        $this->assertStringStartsWith('Conformance Agent ', (string) $rows[$id]['user_name']);
        $this->assertStringEndsWith('@example.com', (string) $rows[$id]['user_email']);
        $this->assertTrue(array_is_list(self::untyped($this->directory()->linkableAgents())));
    }

    public function testFindActiveAgentDescribesTheAgent(): void
    {
        $id = $this->agent();
        $found = $this->directory()->findActiveAgent($id);
        $this->assertIsArray($found);
        $this->assertArrayHasKey('linked', $found);
        $this->assertFalse($found['linked']);
    }

    public function testInactiveAgentsAreNotLinkableAndNotActive(): void
    {
        $id = $this->agent(false);
        $this->assertArrayNotHasKey($id, self::byUserId($this->directory()->linkableAgents()));
        $this->assertNull($this->directory()->findActiveAgent($id));
    }

    public function testUnknownUserIdIsNullNotAnError(): void
    {
        foreach ([0, -1, 2_000_000_000] as $id) {
            $this->assertNull($this->directory()->findActiveAgent($id));
        }
    }

    public function testLinkMovesTheAgentFromLinkableToLinked(): void
    {
        $dir = $this->directory();
        $id = $this->agent();
        [$issuer, $subject] = $this->identity();
        $before = $dir->linkedCount();
        $this->assertFalse($dir->identityTaken($issuer, $subject));

        $dir->link($id, $issuer, $subject);

        $this->assertSame($before + 1, $dir->linkedCount());
        $this->assertArrayNotHasKey($id, self::byUserId($dir->linkableAgents()), 'a linked agent is no longer linkable');
        $linked = self::byUserId($dir->linkedAgents());
        $this->assertArrayHasKey($id, $linked);
        foreach (['user_id', 'user_name', 'user_email'] as $key) {
            $this->assertArrayHasKey($key, $linked[$id], "linkedAgents() rows need '$key'");
        }
        $this->assertTrue($dir->findActiveAgent($id)['linked'] ?? null, 'findActiveAgent() must report linked = true');
        $this->assertTrue($dir->identityTaken($issuer, $subject));
    }

    public function testLinkedCountMatchesTheLinkedList(): void
    {
        $dir = $this->directory();
        $id = $this->agent();
        [$issuer, $subject] = $this->identity();
        $dir->link($id, $issuer, $subject);
        $this->assertSame(count($dir->linkedAgents()), $dir->linkedCount());
    }

    public function testIdentityIsMatchedAsAnIssuerSubjectPair(): void
    {
        $dir = $this->directory();
        $id = $this->agent();
        [$issuer, $subject] = $this->identity();
        $dir->link($id, $issuer, $subject);
        $this->assertTrue($dir->identityTaken($issuer, $subject));
        $this->assertFalse($dir->identityTaken($issuer . '/other', $subject), 'same subject at another issuer is a different identity');
        $this->assertFalse($dir->identityTaken($issuer, $subject . 'x'), 'another subject at the same issuer is a different identity');
        $this->assertFalse($dir->identityTaken($subject, $issuer), 'issuer and subject are not interchangeable');
    }

    public function testUnlinkReversesTheLink(): void
    {
        $dir = $this->directory();
        $id = $this->agent();
        [$issuer, $subject] = $this->identity();
        $before = $dir->linkedCount();
        $dir->link($id, $issuer, $subject);
        $dir->unlink($id);
        $this->assertSame($before, $dir->linkedCount());
        $this->assertFalse($dir->identityTaken($issuer, $subject));
        $this->assertFalse($dir->findActiveAgent($id)['linked'] ?? null);
        $this->assertArrayHasKey($id, self::byUserId($dir->linkableAgents()), 'an unlinked agent is linkable again');
        $this->assertArrayNotHasKey($id, self::byUserId($dir->linkedAgents()));
    }

    public function testLinkingOneAgentLeavesOthersAlone(): void
    {
        $dir = $this->directory();
        $a = $this->agent();
        $b = $this->agent();
        [$issuer, $subject] = $this->identity();
        $dir->link($a, $issuer, $subject);
        $this->assertFalse($dir->findActiveAgent($b)['linked'] ?? null);
        $this->assertArrayHasKey($b, self::byUserId($dir->linkableAgents()));
        $dir->unlink($b);
        $this->assertTrue($dir->identityTaken($issuer, $subject), 'unlinking another agent must not release this identity');
    }

    public function testUnlinkAndLinkForUnknownOrUnlinkedUsersAreHarmless(): void
    {
        $dir = $this->directory();
        $id = $this->agent();
        $dir->unlink($id);
        $dir->unlink(2_000_000_000);
        [$issuer, $subject] = $this->identity();
        $dir->link(2_000_000_000, $issuer, $subject);
        $this->assertFalse($dir->identityTaken($issuer, $subject), 'linking a user that does not exist must not record an identity');
    }

    public function testHostileIdentityStringsAreJustData(): void
    {
        $dir = $this->directory();
        $id = $this->agent();
        $evil = "x'; DROP TABLE users; -- \u{1F600}";
        $dir->link($id, $evil, $evil);
        $this->assertTrue($dir->identityTaken($evil, $evil));
        $this->assertFalse($dir->identityTaken('x', 'x'));
        $dir->unlink($id);
    }
}
