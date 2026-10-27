<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Redis\RateLimiter;
use RivetCore\Redis\RedisAdmin;
use RivetCore\Redis\RedisClientProviderInterface;
use RivetCore\Redis\RedisConnectionConfig;

/**
 * Redis authentication, ACL and TLS against throwaway servers (core security audit 2026-10). Skipped unless the environment names them:
 *   RIVETCORE_TEST_REDIS_AUTH_HOST / _AUTH_PASS         a server with requirepass
 *   RIVETCORE_TEST_REDIS_ACL_USER / _ACL_PASS           a user limited to keys svc:* and no dangerous commands
 *   RIVETCORE_TEST_REDIS_TLS_HOST / _TLS_CA / _TLS_OTHER_CA   a TLS-only server (requirepass as _AUTH_PASS) and the CA files
 */
final class RedisAuthTest extends TestCase
{
    private function env(string $k): string
    {
        $v = getenv($k);
        if ($v === false || $v === '') {
            $this->markTestSkipped("$k not set (throwaway Redis with authentication required).");
        }

        return $v;
    }

    private function cfg(string $hostVar, ?string $password, array $over = []): RedisConnectionConfig
    {
        return new RedisConnectionConfig(...($over + ['host' => $this->env($hostVar), 'port' => 6379, 'password' => $password]));
    }

    public function testAServerWithAPasswordRefusesAnonymousAndWrongPasswordsWithoutEchoingThem(): void
    {
        $admin = new RedisAdmin([]);
        $none = $admin->test($this->cfg('RIVETCORE_TEST_REDIS_AUTH_HOST', null));
        $this->assertFalse($none['ok']);
        $this->assertSame('auth', $none['reason']);
        $wrong = 'wrong-' . bin2hex(random_bytes(6));
        $bad = $admin->test($this->cfg('RIVETCORE_TEST_REDIS_AUTH_HOST', $wrong));
        $this->assertSame('auth', $bad['reason']);
        $this->assertStringNotContainsString($wrong, json_encode($bad, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($this->env('RIVETCORE_TEST_REDIS_AUTH_PASS'), json_encode($bad, JSON_THROW_ON_ERROR));
        $ok = $admin->test($this->cfg('RIVETCORE_TEST_REDIS_AUTH_HOST', $this->env('RIVETCORE_TEST_REDIS_AUTH_PASS')));
        $this->assertTrue($ok['ok'], $ok['message']);
    }

    public function testRateLimiterFailsOpenOnAuthFailureButEnforcesWithTheRightCredentials(): void
    {
        $mk = fn (?string $pw): RedisClientProviderInterface => new class ($this->cfg('RIVETCORE_TEST_REDIS_AUTH_HOST', $pw)) implements RedisClientProviderInterface {
            public function __construct(private RedisConnectionConfig $c)
            {
            }

            public function client(): ?\Predis\Client
            {
                return new \Predis\Client($this->c->toPredisParameters(0.5));
            }
        };
        $bucket = 'audit-' . bin2hex(random_bytes(4));
        // documented fail-open: a wrong password disables the limit rather than the application
        $open = new RateLimiter($mk('wrong'), 'audit:');
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($open->hit($bucket, 2, 30)['allowed']);
        }
        $real = new RateLimiter($mk($this->env('RIVETCORE_TEST_REDIS_AUTH_PASS')), 'audit:');
        $this->assertTrue($real->hit($bucket, 2, 30)['allowed']);
        $this->assertTrue($real->hit($bucket, 2, 30)['allowed']);
        $this->assertFalse($real->hit($bucket, 2, 30)['allowed'], 'with working credentials the third hit is refused');
    }

    public function testAnAclUserCanOnlyTouchItsOwnKeyPrefix(): void
    {
        $c = new \Predis\Client($this->cfg('RIVETCORE_TEST_REDIS_AUTH_HOST', $this->env('RIVETCORE_TEST_REDIS_ACL_PASS'), ['username' => $this->env('RIVETCORE_TEST_REDIS_ACL_USER')])->toPredisParameters(0.5));
        $c->set('svc:audit', '1');
        $this->assertSame('1', $c->get('svc:audit'));
        $c->del(['svc:audit']);
        try {
            $c->set('other:audit', '1');
            $this->fail('the ACL must refuse a key outside svc:*');
        } catch (\Predis\Response\ServerException $e) {
            $this->assertStringContainsString('NOPERM', $e->getMessage());
        }
        try {
            $c->flushall();
            $this->fail('dangerous commands are not granted');
        } catch (\Predis\Response\ServerException $e) {
            $this->assertStringContainsString('NOPERM', $e->getMessage());
        }
        $this->assertSame('auth', (new RedisAdmin([]))->test($this->cfg('RIVETCORE_TEST_REDIS_AUTH_HOST', 'nope', ['username' => $this->env('RIVETCORE_TEST_REDIS_ACL_USER')]))['reason']);
    }

    public function testKeysWithSpacesAndNewlinesAreInertData(): void
    {
        $c = new \Predis\Client($this->cfg('RIVETCORE_TEST_REDIS_AUTH_HOST', $this->env('RIVETCORE_TEST_REDIS_AUTH_PASS'))->toPredisParameters(0.5));
        $key = "audit:k ey\r\nFLUSHALL\r\n*";
        $c->set($key, 'v');
        $this->assertSame('v', $c->get($key));
        $c->del([$key]);
        $this->assertNotNull($c->ping());
    }

    public function testTlsWithTheRightCaWorksAndWithAnotherCaIsRefused(): void
    {
        $host = $this->env('RIVETCORE_TEST_REDIS_TLS_HOST');
        $pw = $this->env('RIVETCORE_TEST_REDIS_AUTH_PASS');
        $admin = new RedisAdmin([]);
        $good = $admin->test(new RedisConnectionConfig($host, 6379, 0, $pw, null, true, true, $this->env('RIVETCORE_TEST_REDIS_TLS_CA')));
        $this->assertTrue($good['ok'], $good['message']);
        $other = $admin->test(new RedisConnectionConfig($host, 6379, 0, $pw, null, true, true, $this->env('RIVETCORE_TEST_REDIS_TLS_OTHER_CA')));
        $this->assertFalse($other['ok'], 'a certificate not signed by the configured CA must fail verification');
        $this->assertSame('tls', $other['reason']);
        $this->assertStringNotContainsString($pw, json_encode($other, JSON_THROW_ON_ERROR));
        $off = $admin->test(new RedisConnectionConfig($host, 6379, 0, $pw, null, true, false));
        $this->assertTrue($off['ok'], 'verification explicitly off (admin choice) connects: ' . $off['message']);
        $plain = $admin->test(new RedisConnectionConfig($host, 6379, 0, $pw, null, false));
        $this->assertFalse($plain['ok'], 'plain text to a TLS-only port is refused');
    }
}
