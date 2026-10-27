<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\RequestContextInterface;
use RivetCore\Audit\AuditService;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Security\BaselineChanges;
use RivetCore\Security\BaselineViolation;
use RivetCore\Security\SecurityBaseline as B;
use RivetCore\Tests\Support\FakeDatabase;

final class SecurityBaselineTest extends TestCase
{
    public function testDefaultsAreTheSecureValues(): void
    {
        $this->assertSame(365, B::defaultFor('audit_retention_days'));
        $this->assertSame(14, B::defaultFor('password_min_length'));
        $this->assertSame(30, B::defaultFor('session_idle_minutes'));
        $this->assertTrue(B::defaultFor('mfa_required_users'));
        foreach (['audit_enabled', 'credential_encryption', 'tls_only', 'cookie_secure', 'cookie_httponly', 'mfa_required_administrators'] as $k) {
            $this->assertTrue(B::isLocked($k), $k);
            $this->assertTrue(B::defaultFor($k), $k);
        }
    }

    public function testFloorsAreFrozenNumbers(): void
    {
        $this->assertSame(90, B::MIN_AUDIT_RETENTION_DAYS);
        $this->assertSame(12, B::MIN_PASSWORD_LENGTH);
        $this->assertSame(480, B::MAX_SESSION_IDLE_MINUTES);
    }

    public function testAuditRetentionNeverBelowNinetyDays(): void
    {
        $this->assertSame(365, B::auditRetentionDays(null));
        $this->assertSame(90, B::auditRetentionDays(1));
        $this->assertSame(90, B::auditRetentionDays(-5));
        $this->assertSame(90, B::auditRetentionDays(89));
        $this->assertSame(90, B::auditRetentionDays(90));
        $this->assertSame(2000, B::auditRetentionDays(2000));
    }

    public function testValidateRejectsBelowFloorAboveCeilingAndWrongTypes(): void
    {
        $this->assertSame(90, B::validate('audit_retention_days', 90));
        foreach ([['audit_retention_days', 89], ['password_min_length', 11], ['session_idle_minutes', 481], ['session_idle_minutes', 0],
            ['audit_retention_days', '365'], ['mfa_required_users', 1]] as [$k, $v]) {
            try {
                B::validate($k, $v);
                $this->fail("{$k}={$v} should be refused");
            } catch (BaselineViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLockedSettingsCannotBeTurnedOffAndAreAlwaysOnWhateverIsStored(): void
    {
        foreach (['audit_enabled', 'credential_encryption', 'tls_only', 'cookie_secure', 'cookie_httponly', 'mfa_required_administrators'] as $k) {
            $this->assertTrue(B::validate($k, true));
            $this->assertTrue(B::effective($k, false), "{$k}: a stored false is ignored");
            $this->expectedViolation(static fn () => B::validate($k, false));
        }
    }

    public function testMfaIsAlwaysRequiredForAdministratorsAndOnByDefaultForEveryoneElse(): void
    {
        $this->assertTrue(B::mfaRequired(true, false));
        $this->assertTrue(B::mfaRequired(false, null));
        $this->assertTrue(B::mfaRequired(false, true));
        $this->assertFalse(B::mfaRequired(false, false), 'the one adjustable case: a per-client opt-out for non-administrators');
    }

    public function testPasswordLengthCountsCharactersNotBytes(): void
    {
        $this->assertFalse(B::passwordLengthOk(str_repeat('a', 11)));
        $this->assertTrue(B::passwordLengthOk(str_repeat('a', 12), 1), 'a configured minimum below the floor is lifted to 12');
        $this->assertFalse(B::passwordLengthOk(str_repeat('é', 11)));
        $this->assertTrue(B::passwordLengthOk(str_repeat('é', 14)));
        $this->assertFalse(B::passwordLengthOk(str_repeat('a', 13)), 'the default minimum is 14 when nothing is configured');
    }

    public function testCookieAttributesAreAlwaysSecureHttpOnlyAndNeverSameSiteNone(): void
    {
        foreach ([[], ['samesite' => 'None'], ['samesite' => 'none'], ['samesite' => ''], ['samesite' => 'garbage']] as $req) {
            $a = B::cookieAttributes($req);
            $this->assertTrue($a['secure']);
            $this->assertTrue($a['httponly']);
            $this->assertSame('Lax', $a['samesite']);
            $this->assertSame('/', $a['path']);
        }
        $this->assertSame('Strict', B::cookieAttributes(['samesite' => 'strict'])['samesite']);
        $this->assertSame(28800, B::cookieAttributes(['lifetime' => 10 ** 9])['lifetime'], 'lifetime is capped at the session ceiling');
        $this->assertSame('__Host-sid', B::hostCookieName('sid'));
        $this->assertSame('__Host-sid', B::hostCookieName('__Host-sid'));
    }

    public function testPostgresTransportRule(): void
    {
        $mk = static fn (string $host, string $ssl) => new PostgresConfig('d', 'u', 'p', $host, 5432, $ssl);
        $this->assertNull(B::postgresTransportProblem($mk('/var/run/postgresql', 'disable')), 'unix socket (peer auth) is the recommended setup');
        $this->assertNull(B::postgresTransportProblem($mk('127.0.0.1', 'prefer')));
        $this->assertNull(B::postgresTransportProblem($mk('db.example.test', 'verify-full')));
        $this->assertNull(B::postgresTransportProblem($mk('db.example.test', 'verify-ca')));
        foreach (['disable', 'allow', 'prefer', 'require'] as $ssl) {
            $this->assertNotNull(B::postgresTransportProblem($mk('db.example.test', $ssl)), $ssl);
        }
    }

    public function testEveryChangeIsAuditLoggedAndARefusalIsLoggedToo(): void
    {
        $db = new FakeDatabase();
        $changes = new BaselineChanges(new AuditService($db, $this->ctx()));
        $this->assertSame(180, $changes->change(5, 'audit_retention_days', 365, 180, 'client:7'));
        $this->assertCount(1, $db->calls);
        $this->assertSame('baseline.setting_changed', $db->calls[0]['params'][0]);
        $meta = json_decode((string) $db->calls[0]['params'][6], true);
        $this->assertSame(365, $meta['old']);
        $this->assertSame(180, $meta['new']);

        try {
            $changes->change(5, 'audit_retention_days', 180, 30);
            $this->fail('below the floor must be refused');
        } catch (BaselineViolation) {
        }
        $this->assertCount(2, $db->calls, 'the refused attempt is itself audit-logged');
        $this->assertSame('baseline.change_refused', $db->calls[1]['params'][0]);

        try {
            $changes->change(5, 'audit_enabled', true, false);
            $this->fail('locked');
        } catch (BaselineViolation) {
        }
        $this->assertCount(3, $db->calls);
    }

    public function testUnknownKeyIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        B::defaultFor('nope');
    }

    private function expectedViolation(callable $f): void
    {
        try {
            $f();
            $this->fail('expected BaselineViolation');
        } catch (BaselineViolation) {
            $this->addToAssertionCount(1);
        }
    }

    private function ctx(): RequestContextInterface
    {
        return new class implements RequestContextInterface {
            public function ipAddress(): ?string
            {
                return '10.0.0.9';
            }

            public function userAgent(): ?string
            {
                return 'UA';
            }

            public function requestId(): ?string
            {
                return 'req-1';
            }
        };
    }
}
