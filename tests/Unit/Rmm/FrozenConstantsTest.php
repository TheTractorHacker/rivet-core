<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\RmmProtocol as P;

/**
 * The wire and storage constants installed agents and stored rows depend on (design section 4). Every literal below was
 * read from RivetIT src/EndpointAgent and api/v1/agent_*.php (origin/beta 4ba51d3). Changing one is a protocol break: add a new
 * endpoint or a migration instead, never edit this test to follow a refactor.
 */
final class FrozenConstantsTest extends TestCase
{
    public function testIdentityStrings(): void
    {
        $this->assertSame('rivetit_agent', P::INTEGRATION_TYPE);
        $this->assertSame('RivetIT Endpoint Agent', P::DEFAULT_INTEGRATION_NAME);
        $this->assertSame('rivetit:', P::AGENT_KEY_PREFIX);
        $this->assertSame('agent:', P::ALERT_KEY_PREFIX);
        $this->assertSame('rivetit-agent-', P::DOWNLOAD_NAME_PREFIX);
        $this->assertSame('RivetIT-Agent-Setup-', P::INSTALLER_NAME_PREFIX);
        $this->assertSame('rivetit-support', P::MESH_DEFAULT_ACCOUNT_TEMPLATE);
        $this->assertSame('[REDACTED]', P::REDACTION_MASK);
    }

    public function testCredentialFormats(): void
    {
        $this->assertSame('rvte1', P::ENROLL_TOKEN_PREFIX);
        $this->assertSame(1, preg_match(P::ENROLL_TOKEN_SELECTOR_RE, str_repeat('a', 12)));
        $this->assertSame(0, preg_match(P::ENROLL_TOKEN_SELECTOR_RE, str_repeat('A', 12)));
        $this->assertSame(0, preg_match(P::ENROLL_TOKEN_SELECTOR_RE, str_repeat('a', 13)));
        $this->assertSame(1, preg_match(P::ENROLL_TOKEN_SECRET_RE, str_repeat('0', 40)));
        $this->assertSame(0, preg_match(P::ENROLL_TOKEN_SECRET_RE, str_repeat('0', 39)));
        $this->assertSame(5000, P::ENROLL_MAX_USES);
        $this->assertSame(['pilot', 'stable'], P::RINGS);
        $this->assertSame(365, P::DEVICE_TOKEN_VALID_DAYS);
        $this->assertSame(16, P::SIGNING_KEY_ID_LENGTH);
        $this->assertSame(1, preg_match(P::DEVICE_TOKEN_BEARER_RE, 'Bearer ' . str_repeat('aZ09', 16), $m));
        $this->assertSame(str_repeat('aZ09', 16), $m[1]);
        $this->assertSame(0, preg_match(P::DEVICE_TOKEN_BEARER_RE, 'Bearer ' . str_repeat('a', 63)));
        $this->assertSame(0, preg_match(P::DEVICE_TOKEN_BEARER_RE, 'Bearer ' . str_repeat('a', 65)));
        $this->assertSame(0, preg_match(P::DEVICE_TOKEN_BEARER_RE, 'bearer ' . str_repeat('a', 64)), 'the scheme is case-sensitive today');
    }

    public function testBodyCapsAndRateLimits(): void
    {
        $this->assertSame([16384, 1048576, 262144, 4096], [P::ENROLL_MAX_BODY, P::CHECKIN_MAX_BODY, P::JOBS_REPORT_MAX_BODY, P::INSTALLER_MAX_BODY]);
        $this->assertSame([40, 60], P::RATE_CHECKIN);
        $this->assertSame([120, 60], P::RATE_JOBS);
        $this->assertSame([60, 60], P::RATE_UPDATE);
        $this->assertSame(['ea-enroll|', 600, 10, 60], [P::ENROLL_RATE_SALT, P::ENROLL_RATE_WINDOW_S, P::ENROLL_RATE_MAX_FAILURES, P::ENROLL_RATE_MAX_ATTEMPTS]);
        $this->assertSame(['ea-installer|', 600, 10, 30, 30, 20], [P::INSTALLER_RATE_SALT, P::INSTALLER_WINDOW_S, P::INSTALLER_IP_MAX_FAILURES, P::INSTALLER_IP_MAX_ATTEMPTS, P::INSTALLER_TOKEN_MAX_DOWNLOADS, P::INSTALLER_SELECTOR_MAX_FAILURES]);
        $this->assertSame(hash('sha256', 'ea-enroll|203.0.113.7'), hash('sha256', P::ENROLL_RATE_SALT . '203.0.113.7'));
    }

    public function testJobsAndCheckinCaps(): void
    {
        $this->assertSame([5, 500000, 5], [P::JOBS_WAIT_MAX_S, P::JOBS_POLL_STEP_US, P::JOBS_OFFER_LIMIT]);
        $this->assertSame([102400, 60], [P::JOB_MAX_SCRIPT_BYTES, P::JOB_DEADLINE_GRACE_S]);
        $this->assertSame(['queued', 'running', 'succeeded', 'failed', 'timed_out', 'cancelled', 'expired'], P::JOB_STATES);
        $this->assertSame(['succeeded', 'failed', 'timed_out', 'cancelled', 'expired'], P::JOB_FINAL_STATES);
        $this->assertSame(['running', 'succeeded', 'failed', 'timed_out', 'cancelled'], P::JOB_REPORTABLE_STATES);
        $this->assertSame(['result_lost', 'never_started', 'no_result_by_deadline', 'device_retired'], P::JOB_REASONS);
        $this->assertSame([100, 100, 32, 65536, 300], [P::CHECKIN_MAX_CHECKS, P::CHECKIN_MAX_BUFFERED, P::CHECKIN_MAX_DISKS, P::CHECKIN_MAX_INVENTORY_BYTES, P::CHECKIN_FUTURE_SKEW_S]);
        $this->assertSame(['ok', 'warn', 'fail', 'unknown'], P::CHECK_STATUSES);
        $this->assertSame(1, preg_match(P::CHECK_KEY_RE, 'svc.spooler:state-1_x'));
        $this->assertSame(0, preg_match(P::CHECK_KEY_RE, str_repeat('a', 101)));
        $this->assertSame(0, preg_match(P::CHECK_KEY_RE, 'has space'));
    }

    public function testDeviceIdentityRules(): void
    {
        $this->assertSame(['linked', 'pending_approval', 'rejected'], P::LINK_STATES);
        $this->assertSame(1, preg_match(P::MAC_RE, 'aa:bb:cc:dd:ee:ff'));
        $this->assertSame(0, preg_match(P::MAC_RE, 'AA:BB:CC:DD:EE:FF'));
        $this->assertSame(0, preg_match(P::MAC_RE, 'aa-bb-cc-dd-ee-ff'));
        $this->assertSame('00:00:00:00:00:00', P::NULL_MAC);
        $this->assertSame(1, preg_match(P::INSTALL_ID_RE, '123E4567-e89b-12d3-a456-426614174000'));
        $this->assertSame(1, preg_match(P::MACHINE_GUID_RE, '{12345678-1234-1234-1234-123456789abc}'));
        $this->assertSame(1, preg_match(P::AGENT_VERSION_RE, '0.1.0-beta.1'));
        $this->assertSame(0, preg_match(P::AGENT_VERSION_RE, '0.1'));
        $this->assertCount(16, P::JUNK_SERIALS);
        $this->assertSame(
            ['', '0', 'none', 'n/a', 'na', 'unknown', 'default string', 'to be filled by o.e.m.', 'system serial number', 'serial number', 'not specified', 'not applicable', 'xxxxxxxx', '123456789', '1234567890', 'default'],
            P::JUNK_SERIALS
        );
    }

    public function testBinariesAndUpdates(): void
    {
        $this->assertSame(['amd64' => 0x8664, 'arm64' => 0xAA64], P::ARCHS);
        $this->assertSame(1, preg_match(P::BINARY_VERSION_RE, '12.34.56+build.7'));
        $this->assertSame(0, preg_match(P::BINARY_VERSION_RE, "1.2.3\n"), '\\z, not $: a trailing newline is refused');
        $this->assertSame(0, preg_match(P::BINARY_VERSION_RE, '123456.1.1'));
        $this->assertSame(1, preg_match(P::BINARY_STORAGE_NAME_RE, 'bin_' . str_repeat('0f', 16) . '.bin'));
        $this->assertSame(0, preg_match(P::BINARY_STORAGE_NAME_RE, 'bin_' . str_repeat('0f', 16) . '.bin/../x'));
        $this->assertSame([67108864, 1024], [P::BINARY_DEFAULT_MAX_BYTES, P::BINARY_MIN_BYTES]);
    }

    public function testInstallerTrailer(): void
    {
        $this->assertSame('RIVETIT-EMBED-v1', P::EMBED_MAGIC);
        $this->assertSame(52, P::EMBED_FOOTER_LEN);
        $this->assertSame(4 + 32 + strlen(P::EMBED_MAGIC), P::EMBED_FOOTER_LEN, 'length(4) + sha256(32) + magic');
        $this->assertSame([16384, 1], [P::EMBED_MAX_PAYLOAD, P::EMBED_PAYLOAD_VERSION]);
    }

    public function testMeshAndHttp(): void
    {
        $this->assertSame([3, 11], [P::MESH_COOKIE_ACCESS, P::MESH_VIEWMODE]);
        $this->assertSame(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION, P::JSON_FLAGS);
        $this->assertSame(
            ['invalid_token', 'revoked', 'expired', 'forbidden', 'not_found', 'method_not_allowed', 'conflict', 'too_large', 'invalid', 'tls_required', 'rate_limited', 'internal', 'unavailable', 'token_in_url',
                'confirmation_required', 'queued', 'cancelled', 'device_offline', 'unmapped', 'not_configured', 'device_retired', 'mesh_unavailable', 'disabled'],
            P::ERROR_CODES
        );
        $this->assertSame('windows', P::DEFAULT_PLATFORM);
        $this->assertContains('linux', P::PLATFORMS);
    }
}
