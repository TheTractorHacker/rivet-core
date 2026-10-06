<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Mcp\AgentDirectoryInterface;
use RivetCore\Tests\Conformance\Reference\InMemoryAgentDirectory;

final class InMemoryAgentDirectoryConformanceTest extends AgentDirectoryConformanceTestCase
{
    private InMemoryAgentDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = new InMemoryAgentDirectory();
    }

    protected function givenAgent(int $userId, string $name, string $email, bool $active = true): void
    {
        $this->dir->add($userId, $name, $email, $active);
    }

    protected function directory(): AgentDirectoryInterface
    {
        return $this->dir;
    }
}
