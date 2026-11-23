<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Enrollment\AttemptLog;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Tests\Support\PanelHarness;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Red team 2026-11-21 CORE-API-3 (Chain I): rotating source addresses inside one IPv6 /64 gave unlimited unauthenticated enrollment failures, each with an attempt
 * row and an "Enrollment Failed" audit row. The limiter now counts a /64 as one address (the full address stays in ip_text), and past an instance-wide threshold
 * the audit log is sampled (one row per 100 attempts, saying how many it stands for) while nothing is refused for the instance as a whole.
 */
final class EnrollmentFloodTest extends RmmTestCase
{
    private const BAD = 'rvte1.aaaaaaaaaaaa.bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testTheLimiterKeyOfAnIpv6AddressIsItsSlash64(): void
    {
        $this->assertSame('2001:db8:bad:1::/64', AttemptLog::limiterAddress('2001:db8:bad:1::ffff'));
        $this->assertSame('2001:db8:bad:1::/64', AttemptLog::limiterAddress('2001:0db8:0bad:0001:1234:5678:9abc:def0'));
        $this->assertSame(AttemptLog::ipHash('s|', '2001:db8:bad:1::1'), AttemptLog::ipHash('s|', '2001:db8:bad:1:ffff::2'));
        $this->assertNotSame(AttemptLog::ipHash('s|', '2001:db8:bad:1::1'), AttemptLog::ipHash('s|', '2001:db8:bad:2::1'));
        $this->assertSame('203.0.113.9', AttemptLog::limiterAddress('203.0.113.9'));
        $this->assertSame('203.0.113.9', AttemptLog::limiterAddress('::ffff:203.0.113.9'), 'an IPv4-mapped address is the IPv4 address');
        $this->assertNotSame(AttemptLog::ipHash('s|', '203.0.113.9'), AttemptLog::ipHash('s|', '203.0.113.10'), 'IPv4 stays per address');
        $this->assertSame('not-an-ip', AttemptLog::limiterAddress('not-an-ip'));
    }

    public function testRotatingAddressesInsideOneSlash64IsRateLimitedAndTheFullAddressIsKept(): void
    {
        $this->h->enable();
        for ($i = 1; $i <= RmmProtocol::ENROLL_RATE_MAX_FAILURES; $i++) {
            [$c] = $this->h->enroll(self::BAD, RmmHarness::device(), sprintf('2001:db8:bad:1::%x', $i));
            $this->assertSame(401, $c);
        }
        [$c, , $j] = $this->h->enroll(self::BAD, RmmHarness::device(), '2001:db8:bad:1::beef');
        $this->assertSame(429, $c, 'the next address of the same /64 is over the limit');
        $this->assertSame('rate_limited', $j['code']);
        [$c] = $this->h->enroll(self::BAD, RmmHarness::device(), '2001:db8:bad:2::1');
        $this->assertSame(401, $c, 'another /64 has its own budget');
        $this->assertSame('2001:db8:bad:1::a', (string) $this->h->one("SELECT ip_text FROM endpoint_agent_enroll_attempts WHERE ip_text = '2001:db8:bad:1::a'"), 'ip_text keeps the full address');
        // the panel enrollment and the installer download share the limiter key
        $p = new PanelHarness([], null, false);
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(401, $p->enroll('rvtp1.aaaaaaaaaaaa.' . str_repeat('b', 40), [], sprintf('2001:db8:cafe:1::%x', $i))[0]);
        }
        $this->assertSame(429, $p->enroll('rvtp1.aaaaaaaaaaaa.' . str_repeat('b', 40), [], '2001:db8:cafe:1::ffff')[0]);
    }

    public function testPastTheInstanceThresholdTheAuditIsSampledAndEnrollmentStillWorks(): void
    {
        $this->h->enable();
        $flood = AttemptLog::AUDIT_FULL + 3 * AttemptLog::AUDIT_SAMPLE_EVERY;
        for ($i = 0; $i < $flood; $i++) {
            $ip = sprintf('2001:db8:%x:%x::1', intdiv($i, 4096) + 1, $i % 4096);   // a fresh /64 every time: the per-address limit never trips
            [$c] = $this->h->enroll(self::BAD, RmmHarness::device(), $ip);
            $this->assertSame(401, $c);
        }
        $this->assertSame($flood, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_enroll_attempts WHERE success = 0 AND reason = 'invalid_token'"), 'every attempt is in the attempt table');
        $failed = array_values(array_filter($this->h->audit->records(), static fn (array $r): bool => $r['action'] === 'Enrollment Failed'));
        $sampled = array_values(array_filter($failed, static fn (array $r): bool => str_contains($r['description'], '[sampled:')));
        $this->assertGreaterThanOrEqual(AttemptLog::AUDIT_FULL, count($failed));
        $this->assertLessThanOrEqual(AttemptLog::AUDIT_FULL + 4, count($failed), 'about one audit row per hundred attempts past the threshold, not one each');
        $this->assertNotSame([], $sampled);
        $this->assertMatchesRegularExpression('/stands for \d+ rejected attempts/', $sampled[0]['description']);
        // nothing is refused for the instance as a whole: a real device still enrolls
        $this->h->asset(['name' => 'Real PC', 'serial' => 'SN-REAL-1']);
        [$c] = $this->h->enroll($this->h->token(), RmmHarness::device(['serial' => 'SN-REAL-1']), '198.51.100.7');
        $this->assertSame(201, $c);
    }

    public function testTheKindsAreSampledApart(): void
    {
        $this->h->enable();
        $log = $this->h->module->attempts();
        $id = 0;
        for ($i = 0; $i <= AttemptLog::AUDIT_FULL; $i++) {
            $id = $log->insert(RmmProtocol::INSTALLER_RATE_SALT, "198.51.100.$i", false, 'installer_invalid_token', '');
        }
        $this->assertNotNull($log->auditSampling(AttemptLog::KIND_INSTALLER, $id), 'installer rejections are past the threshold');
        $this->assertNull($log->auditSampling(AttemptLog::KIND_ENROLL, $id), 'enrollment rejections are counted on their own');
        $this->assertNull($log->auditSampling(AttemptLog::KIND_PANEL, $id));
    }
}
