<?php

declare(strict_types=1);

namespace RivetCore\Tests\Security;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use RivetCore\Mcp\AgentDirectoryInterface;
use RivetCore\Mcp\McpDiagnostics;
use RivetCore\Mcp\TokenClaimsGuard;
use RivetCore\Mcp\UnlinkedIdentityStore;

/** MCP surface: RC-SR2-13, -14, -20. */
final class McpSurfaceTest extends SecurityTestCase
{
    /**
     * Documents RC-SR2-13 (carried over from 0.18.1 "known, not changed"): mcp_unlinked_identities uses
     * utf8mb4_general_ci with a unique key on (issuer, subject). OIDC subjects are case-sensitive and PAD SPACE ignores
     * trailing spaces, so two different identities collapse into ONE pending row: the second sighting only updates the
     * first row's email/display name. A signed-in user whose subject differs only by case (or trailing space) can
     * therefore overwrite what the administrator sees for someone else's pending identity, and the stored subject (the
     * one the admin links) is the other person's. The edition's linked-subject column must be case-sensitive too.
     * Flip: assert three rows (needs a utf8mb4_bin column).
     */
    public function testRc13PendingIdentitiesAreMergedCaseInsensitively(): void
    {
        $db = $this->db('mcp_unlinked_identities');
        $store = new UnlinkedIdentityStore($db);
        $store->record('https://idp.example', 'AbC123', ['email' => 'victim@corp.example', 'name' => 'Victim']);
        $store->record('https://idp.example', 'abc123', ['email' => 'attacker@evil.example', 'name' => 'CEO']);
        $store->record('https://idp.example', 'abc123   ', ['email' => 'x@y.example', 'name' => 'Z']);
        $rows = $db->fetchAll('SELECT subject, email, display_name, attempts FROM mcp_unlinked_identities');
        $this->assertCount(1, $rows);
        $this->assertSame('AbC123', $rows[0]['subject'], 'the victim spelling is what an admin would link');
        $this->assertSame('x@y.example', $rows[0]['email'], 'but the displayed email/name came from another identity');
        $this->assertEquals(3, $rows[0]['attempts']);
    }

    /**
     * Documents RC-SR2-14: the "Signing keys" diagnostic fetches whatever https URL the identity provider's discovery
     * document names as jwks_uri (McpConfig::issuerValid() only requires https and a host), through the injected Guzzle
     * client, with no UrlPolicy; and it echoes the client's exception text, which names host and port. On an admin-only
     * page this is a small blind-SSRF / port-scan oracle driven by the IdP document. Uses a mock transport, no network.
     * Flip: assert the loopback jwks_uri is refused without a request being made.
     */
    public function testRc14DiagnosticsFetchesAnyHttpsJwksUri(): void
    {
        $issuer = 'https://idp.example.test/application/o/mcp/';
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['issuer' => $issuer, 'jwks_uri' => 'https://127.0.0.1:9/keys'])),
            new ConnectException('cURL error 7: Failed to connect to 127.0.0.1 port 9 after 0 ms: Couldn\'t connect to server', new Request('GET', 'https://127.0.0.1:9/keys')),
        ]);
        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $agents = new class implements AgentDirectoryInterface {
            public function linkableAgents(): array { return []; }
            public function linkedAgents(): array { return []; }
            public function linkedCount(): int { return 0; }
            public function findActiveAgent(int $userId): ?array { return null; }
            public function identityTaken(string $issuer, string $subject): bool { return false; }
            public function link(int $userId, string $issuer, string $subject): void {}
            public function unlink(int $userId): void {}
        };
        $diag = new McpDiagnostics($agents, new Client(['handler' => $stack]));
        $checks = $diag->run(['killed' => false, 'module_on' => true, 'enabled' => false, 'configured' => false, 'issuer' => $issuer, 'audience' => 'aud'], 'rivet.example.test');
        $this->assertCount(2, $history, 'discovery document, then the IdP-supplied jwks_uri');
        $this->assertSame('127.0.0.1', $history[1]['request']->getUri()->getHost());
        $signing = array_values(array_filter($checks, static fn (array $c): bool => $c['label'] === 'Signing keys'))[0];
        $this->assertSame('fail', $signing['status']);
        $this->assertStringContainsString('127.0.0.1 port 9', $signing['detail']);
    }

    /**
     * Documents RC-SR2-20 (INFO): (1) TokenClaimsGuard::acceptable() checks lifetime shape but never compares exp (or
     * nbf) to the current time and does not look at iss; it relies on the edition's JWT verifier having done so, as its
     * docblock says, so an already-expired token with a sane lifetime passes the guard. (2) UnlinkedIdentityStore stops
     * recording at 200 pending identities, so valid-but-unlinked users can fill the list and hide later ones for 30 days.
     */
    public function testRc20ClaimsGuardTrustsTheUpstreamVerifierAndPendingListIsCapped(): void
    {
        $now = 1_800_000_000;
        $expired = ['aud' => 'mcp-audience', 'iat' => $now - 7200, 'exp' => $now - 3600];
        $this->assertTrue(TokenClaimsGuard::acceptable('user-1', ['mcp:read'], $expired, 'mcp-audience', 'mcp:read', $now), 'expiry is not re-checked here');
        $this->assertFalse(TokenClaimsGuard::acceptable('user-1', ['mcp:read'], ['aud' => ['mcp-audience', 'other'], 'iat' => $now, 'exp' => $now + 60], 'mcp-audience', 'mcp:read', $now), 'a shared audience is refused');
        $this->assertFalse(TokenClaimsGuard::acceptable('user-1', ['mcp:read'], ['aud' => 'mcp-audience', 'iat' => $now, 'exp' => $now + 7200], 'mcp-audience', 'mcp:read', $now), 'a 2 h token is refused');

        $db = $this->db('mcp_unlinked_identities');
        $store = new UnlinkedIdentityStore($db);
        for ($i = 0; $i < UnlinkedIdentityStore::MAX_PENDING + 5; $i++) {
            $store->record('https://idp.example', 'sub-' . $i, ['email' => "u$i@example.test"]);
        }
        $this->assertSame(UnlinkedIdentityStore::MAX_PENDING, (int) $db->fetchOne('SELECT COUNT(*) AS c FROM mcp_unlinked_identities')['c']);
        $db->execute('TRUNCATE TABLE mcp_unlinked_identities');
    }
}
