<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Mcp\McpDiagnostics;

/**
 * CORE-API-9 (red team 2026-11-21, rejected as a finding; the judge asked for the contract to be written down): McpDiagnostics fetches https://$baseHost/... from the
 * server without UrlPolicy (the self-check must reach the server's own public name, which may resolve to a private address), so $baseHost must come from configuration,
 * never from the request. The contract stays in the docblocks an edition reads.
 */
final class McpDiagnosticsHostContractTest extends TestCase
{
    public function testTheBaseHostContractIsDocumented(): void
    {
        $run = (string) (new \ReflectionMethod(McpDiagnostics::class, 'run'))->getDocComment();
        $this->assertStringContainsString('FROM CONFIGURATION', $run);
        $this->assertStringContainsString('never from the request', $run);
        $this->assertStringContainsString('X-Forwarded-Host', $run);
        $route = (string) (new \ReflectionMethod(McpDiagnostics::class, 'routeCheck'))->getDocComment();
        $this->assertStringContainsString('WITHOUT UrlPolicy', $route);
        $this->assertStringContainsString('never a value from the request', $route);
    }
}
