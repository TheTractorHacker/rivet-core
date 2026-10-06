<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Mcp\AgentDirectoryInterface;

/** Reference AgentDirectoryInterface over arrays. Identity comparison is byte-exact (case sensitive), as OIDC requires. */
class InMemoryAgentDirectory implements AgentDirectoryInterface
{
    /** @var array<int, array{name:string, email:string, active:bool, issuer:?string, subject:?string}> */
    protected array $agents = [];

    public function add(int $userId, string $name, string $email, bool $active = true): void
    {
        $this->agents[$userId] = ['name' => $name, 'email' => $email, 'active' => $active, 'issuer' => null, 'subject' => null];
    }

    public function linkableAgents(): array
    {
        $out = [];
        foreach ($this->agents as $id => $a) {
            if ($a['active'] && $a['issuer'] === null) {
                $out[] = ['user_id' => $id, 'user_name' => $a['name'], 'user_email' => $a['email']];
            }
        }

        return $out;
    }

    public function linkedAgents(): array
    {
        $out = [];
        foreach ($this->agents as $id => $a) {
            if ($a['issuer'] !== null) {
                $out[] = ['user_id' => $id, 'user_name' => $a['name'], 'user_email' => $a['email'], 'issuer' => $a['issuer'], 'subject' => $a['subject']];
            }
        }

        return $out;
    }

    public function linkedCount(): int
    {
        return count($this->linkedAgents());
    }

    public function findActiveAgent(int $userId): ?array
    {
        $a = $this->agents[$userId] ?? null;

        return $a !== null && $a['active'] ? ['linked' => $a['issuer'] !== null] : null;
    }

    public function identityTaken(string $issuer, string $subject): bool
    {
        foreach ($this->agents as $a) {
            if ($a['issuer'] === $issuer && $a['subject'] === $subject) {
                return true;
            }
        }

        return false;
    }

    public function link(int $userId, string $issuer, string $subject): void
    {
        $this->agents[$userId]['issuer'] = $issuer;
        $this->agents[$userId]['subject'] = $subject;
    }

    public function unlink(int $userId): void
    {
        if (isset($this->agents[$userId])) {
            $this->agents[$userId]['issuer'] = $this->agents[$userId]['subject'] = null;
        }
    }
}
