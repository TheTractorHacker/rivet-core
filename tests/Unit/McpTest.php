<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditService;
use RivetCore\Mcp\AgentDirectoryInterface;
use RivetCore\Mcp\McpConfig;
use RivetCore\Mcp\McpDiagnostics;
use RivetCore\Mcp\NotFoundException;
use RivetCore\Mcp\TokenClaimsGuard;
use RivetCore\Mcp\ToolPipeline;
use RivetCore\Redis\RateLimiter;
use RivetCore\Support\ArraySettings;
use RivetCore\Support\NullRequestContext;
use RivetCore\Tests\Support\FakeDatabase;
use RivetCore\Tests\Support\TestRedis;

final class McpTest extends TestCase
{
    private function env(array $vars): callable
    {
        return static fn (string $k) => $vars[$k] ?? false;
    }

    public function testConfigFromSettings(): void
    {
        $c = McpConfig::resolve(new ArraySettings(['mcp.issuer' => 'https://idp.example/realm', 'mcp.audience' => 'client-1', 'mcp.enabled' => 1]), $this->env([]), 'X_MCP_');
        $this->assertTrue($c['enabled']);
        $this->assertTrue($c['configured']);
        $this->assertTrue($c['schema_ready']);
        $this->assertFalse($c['issuer_from_env']);
    }

    public function testEnvironmentWinsAndKillSwitchBeatsSettings(): void
    {
        $s = new ArraySettings(['mcp.issuer' => 'https://a.example', 'mcp.audience' => 'aud', 'mcp.enabled' => 1]);
        $c = McpConfig::resolve($s, $this->env(['X_MCP_ISSUER' => 'https://env.example', 'X_MCP_ENABLED' => '0']), 'X_MCP_');
        $this->assertSame('https://env.example', $c['issuer']);
        $this->assertTrue($c['issuer_from_env']);
        $this->assertTrue($c['killed']);
        $this->assertFalse($c['enabled']);
        $this->assertTrue($c['module_on']);
    }

    public function testOlderSchemaIsNotReadyAndNotConfigured(): void
    {
        $c = McpConfig::resolve(new ArraySettings([]), $this->env([]), 'X_MCP_');
        $this->assertFalse($c['schema_ready']);
        $this->assertFalse($c['configured']);
        $this->assertFalse($c['enabled']);
    }

    public function testIssuerAndAudienceValidation(): void
    {
        $this->assertTrue(McpConfig::issuerValid('https://idp.example/realm/'));
        foreach (['http://idp.example', 'https://u:p@idp.example', 'https://idp.example?x=1', 'https://idp.example#f', 'nonsense', ''] as $bad) {
            $this->assertFalse(McpConfig::issuerValid($bad), $bad);
        }
        $this->assertTrue(McpConfig::audienceValid('abc123'));
        $this->assertFalse(McpConfig::audienceValid('has space'));
        $this->assertFalse(McpConfig::audienceValid(''));
    }

    public function testTokenClaimsGuard(): void
    {
        $now = 1_800_000_000;
        $ok = ['aud' => 'a', 'iat' => $now, 'exp' => $now + 600];
        $this->assertTrue(TokenClaimsGuard::acceptable('sub', ['mcp:read'], $ok, 'a', 'mcp:read', $now));
        $this->assertTrue(TokenClaimsGuard::acceptable('sub', ['mcp:read'], ['aud' => ['a']] + $ok, 'a', 'mcp:read', $now));
        $this->assertFalse(TokenClaimsGuard::acceptable('sub', ['mcp:read'], ['aud' => ['a', 'other']] + $ok, 'a', 'mcp:read', $now), 'extra audience');
        $this->assertFalse(TokenClaimsGuard::acceptable('sub', ['other'], $ok, 'a', 'mcp:read', $now), 'scope');
        $this->assertFalse(TokenClaimsGuard::acceptable('', ['mcp:read'], $ok, 'a', 'mcp:read', $now), 'empty subject');
        $this->assertFalse(TokenClaimsGuard::acceptable(str_repeat('s', 256), ['mcp:read'], $ok, 'a', 'mcp:read', $now), 'long subject');
        $this->assertFalse(TokenClaimsGuard::acceptable('sub', ['mcp:read'], ['iat' => $now + 120] + $ok, 'a', 'mcp:read', $now), 'issued in future');
        $this->assertFalse(TokenClaimsGuard::acceptable('sub', ['mcp:read'], ['exp' => $now] + $ok, 'a', 'mcp:read', $now), 'exp not after iat');
        $this->assertFalse(TokenClaimsGuard::acceptable('sub', ['mcp:read'], ['exp' => $now + 7200] + $ok, 'a', 'mcp:read', $now), 'too long');
        $this->assertFalse(TokenClaimsGuard::acceptable('sub', ['mcp:read'], ['iat' => '1'] + $ok, 'a', 'mcp:read', $now), 'non-int iat');
    }

    private function pipeline(FakeDatabase $db, bool $redis = false, int $limit = 60): ToolPipeline
    {
        return new ToolPipeline(
            new RateLimiter($redis ? new TestRedis() : new TestRedis(null, true), 'p:'),
            new AuditService($db, new NullRequestContext()),
            new NullRequestContext(),
            $limit,
            60,
            'mcp',
            static function (string $m): void {
            }
        );
    }

    public function testPipelineSuccessEnvelopeAndAudit(): void
    {
        $db = new FakeDatabase();
        $r = $this->pipeline($db)->run(7, 'rivetit_tickets', ['q' => str_repeat('x', 300)], fn () => true, fn () => [['id' => 1], ['id' => 2]]);
        $this->assertTrue($r['success']);
        $this->assertCount(2, $r['data']);
        $this->assertSame([], $r['errors']);
        $this->assertStringStartsWith('req_', $r['request_id']);
        $p = $db->calls[0]['params'];
        $this->assertSame('mcp.tool_call', $p[0]);
        $this->assertSame(7, $p[1]);
        $this->assertSame('MCP rivetit_tickets: ok', $p[5]);
        $meta = json_decode($p[6], true);
        $this->assertSame(2, $meta['rows']);
        $this->assertSame(100, strlen($meta['args']['q']), 'long args are clipped in the audit row');
    }

    public function testPipelineDeniedNotFoundAndErrors(): void
    {
        $db = new FakeDatabase();
        $pl = $this->pipeline($db);
        $this->assertSame('PERMISSION_DENIED', $pl->run(null, 't', [], fn () => true, fn () => 1)['errors'][0]['code']);
        $this->assertCount(0, $db->calls, 'no audit row for an unauthorized caller');
        $d = $pl->run(5, 't', [], fn () => false, fn () => $this->fail('body must not run'), 'RivetIT role');
        $this->assertSame('Your RivetIT role does not allow this.', $d['errors'][0]['message']);
        $this->assertSame('MCP t: denied', $db->calls[0]['params'][5]);
        $this->assertSame('NOT_FOUND', $pl->run(5, 't', [], fn () => true, fn () => throw new NotFoundException())['errors'][0]['code']);
        $e = $pl->run(5, 't', [], fn () => true, fn () => throw new \RuntimeException('secret db detail'));
        $this->assertSame('INTERNAL_ERROR', $e['errors'][0]['code']);
        $this->assertStringNotContainsString('secret', json_encode($e));
    }

    public function testPipelineAuditFailureNeverBreaksTheRead(): void
    {
        $db = new FakeDatabase();
        $db->failWith = new \RuntimeException('audit table gone');
        $r = $this->pipeline($db)->run(5, 't', [], fn () => true, fn () => ['ok' => 1]);
        $this->assertTrue($r['success']);
    }

    public function testPipelineRateLimit(): void
    {
        if (!TestRedis::available()) {
            $this->markTestSkipped('throwaway Redis required');
        }
        (new TestRedis())->client()->flushdb();
        $pl = $this->pipeline(new FakeDatabase(), true, 2);
        $this->assertTrue($pl->run(9, 't', [], fn () => true, fn () => 1)['success']);
        $this->assertTrue($pl->run(9, 't', [], fn () => true, fn () => 1)['success']);
        $third = $pl->run(9, 't', [], fn () => true, fn () => 1);
        $this->assertSame('RATE_LIMITED', $third['errors'][0]['code']);
        $this->assertTrue($pl->run(10, 't', [], fn () => true, fn () => 1)['success'], 'other users are unaffected');
    }

    private function directory(int $linked = 0): AgentDirectoryInterface
    {
        return new class($linked) implements AgentDirectoryInterface {
            public function __construct(private int $linked)
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
                return $this->linked;
            }

            public function findActiveAgent(int $userId): ?array
            {
                return null;
            }

            public function identityTaken(string $issuer, string $subject): bool
            {
                return false;
            }

            public function link(int $userId, string $issuer, string $subject): void
            {
            }

            public function unlink(int $userId): void
            {
            }
        };
    }

    private function diagnostics(array $responses, int $linked = 1): McpDiagnostics
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);

        return new McpDiagnostics($this->directory($linked), $http, 'X_MCP_ENABLED', 'RivetX');
    }

    private function cfg(array $o = []): array
    {
        return $o + ['killed' => false, 'module_on' => true, 'enabled' => false, 'issuer' => 'https://idp.example/realm', 'audience' => 'aud', 'configured' => true];
    }

    public function testDiagnosticsHealthyProvider(): void
    {
        $json = ['Content-Type' => 'application/json'];
        $doc = ['issuer' => 'https://idp.example/realm', 'jwks_uri' => 'https://idp.example/jwks', 'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => ['mcp:read']];
        $out = $this->diagnostics([
            new Response(200, $json, json_encode($doc)),
            new Response(200, $json, json_encode(['keys' => [['kty' => 'RSA', 'use' => 'sig']]])),
        ])->run($this->cfg(), 'mcp.example');
        $by = array_column($out, 'status', 'label');
        $this->assertSame('ok', $by['Issuer URL']);
        $this->assertSame('ok', $by['Identity provider reachable']);
        $this->assertSame('ok', $by['Issuer matches exactly']);
        $this->assertSame('ok', $by['Signing keys']);
        $this->assertSame('ok', $by['PKCE supported']);
        $this->assertSame('ok', $by['mcp:read scope']);
        $this->assertSame('skip', $by['This server answers /mcp']);
        $this->assertSame('ok', $by['Linked agents']);
    }

    public function testDiagnosticsProblemsAreExplained(): void
    {
        $out = $this->diagnostics([new Response(500)], 0)->run($this->cfg(['killed' => true]), 'h');
        $by = array_column($out, 'status', 'label');
        $this->assertSame('fail', $by['Remote MCP switched on']);
        $this->assertStringContainsString('X_MCP_ENABLED=0', $out[0]['detail']);
        $this->assertSame('fail', $by['Identity provider reachable']);
        $this->assertSame('warn', $by['Linked agents']);
        $bad = $this->diagnostics([], 1)->run($this->cfg(['issuer' => 'http://insecure.example']), 'h');
        $this->assertSame('fail', array_column($bad, 'status', 'label')['Issuer URL']);
    }
}
