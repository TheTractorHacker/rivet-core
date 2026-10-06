<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Mcp\AgentDirectoryInterface;

/**
 * A users "table" in memory, or one with a named flaw: inactive_linkable, inactive_found, link_ignores_issuer,
 * unlink_keeps_identity, linked_still_linkable, count_wrong, find_throws_unknown, link_unknown_creates, find_linked_false.
 */
final class InMemoryAgentDirectory implements AgentDirectoryInterface
{
    /** @var array<int,array{name:string,email:string,active:bool,issuer:?string,subject:?string}> */
    private array $users = [];
    /** @var list<array{string,string}> identities that survive an unlink (flaw) */
    private array $ghosts = [];
    private int $next = 10;

    public function __construct(private ?string $flaw = null)
    {
    }

    public function store(string $name, string $email, bool $active): int
    {
        $this->users[$this->next] = ['name' => $name, 'email' => $email, 'active' => $active, 'issuer' => null, 'subject' => null];

        return $this->next++;
    }

    public function delete(int $id): void
    {
        unset($this->users[$id]);
    }

    public function linkableAgents(): array
    {
        $out = [];
        foreach ($this->users as $id => $u) {
            $linked = $u['subject'] !== null;
            if (($u['active'] || $this->flaw === 'inactive_linkable') && (!$linked || $this->flaw === 'linked_still_linkable')) {
                $out[] = ['user_id' => $id, 'user_name' => $u['name'], 'user_email' => $u['email']];
            }
        }

        return $out;
    }

    public function linkedAgents(): array
    {
        $out = [];
        foreach ($this->users as $id => $u) {
            if ($u['subject'] !== null) {
                $out[] = ['user_id' => $id, 'user_name' => $u['name'], 'user_email' => $u['email'], 'user_oidc_issuer' => $u['issuer'], 'user_oidc_subject' => $u['subject']];
            }
        }

        return $out;
    }

    public function linkedCount(): int
    {
        return $this->flaw === 'count_wrong' ? count($this->users) : count($this->linkedAgents());
    }

    public function findActiveAgent(int $userId): ?array
    {
        $u = $this->users[$userId] ?? null;
        if ($u === null) {
            if ($this->flaw === 'find_throws_unknown') {
                throw new \RuntimeException('No such agent');
            }

            return null;
        }
        if (!$u['active'] && $this->flaw !== 'inactive_found') {
            return null;
        }

        return ['linked' => $this->flaw === 'find_linked_false' ? false : $u['subject'] !== null];
    }

    public function identityTaken(string $issuer, string $subject): bool
    {
        foreach ($this->ghosts as [$i, $s]) {
            if ($i === $issuer && $s === $subject) {
                return true;
            }
        }
        foreach ($this->users as $u) {
            if ($u['subject'] === $subject && ($this->flaw === 'link_ignores_issuer' || $u['issuer'] === $issuer)) {
                return true;
            }
        }

        return false;
    }

    public function link(int $userId, string $issuer, string $subject): void
    {
        if (!isset($this->users[$userId])) {
            if ($this->flaw === 'link_unknown_creates') {
                $this->users[$userId] = ['name' => 'ghost', 'email' => 'ghost@example.com', 'active' => true, 'issuer' => $issuer, 'subject' => $subject];
            }

            return;
        }
        $this->users[$userId]['issuer'] = $issuer;
        $this->users[$userId]['subject'] = $subject;
    }

    public function unlink(int $userId): void
    {
        if (!isset($this->users[$userId])) {
            return;
        }
        if ($this->flaw === 'unlink_keeps_identity' && $this->users[$userId]['subject'] !== null) {
            $this->ghosts[] = [(string) $this->users[$userId]['issuer'], $this->users[$userId]['subject']];
        }
        $this->users[$userId]['issuer'] = null;
        $this->users[$userId]['subject'] = null;
    }
}
