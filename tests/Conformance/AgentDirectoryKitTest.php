<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Mcp\AgentDirectoryInterface;
use RivetCore\Testing\AgentDirectoryConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\InMemoryAgentDirectory;

/** The kit against an in-memory user store (and the harness target for the agent-directory mutants). */
final class AgentDirectoryKitTest extends AgentDirectoryConformanceTestCase
{
    use Flaw;

    private ?InMemoryAgentDirectory $store = null;

    private function store(): InMemoryAgentDirectory
    {
        return $this->store ??= new InMemoryAgentDirectory(self::$flaw);
    }

    protected function directory(): AgentDirectoryInterface
    {
        return $this->store();
    }

    protected function storeAgent(string $name, string $email, bool $active): int
    {
        return $this->store()->store($name, $email, $active);
    }

    protected function deleteAgent(int $userId): void
    {
        $this->store()->delete($userId);
    }
}
