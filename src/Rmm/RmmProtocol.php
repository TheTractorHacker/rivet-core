<?php

declare(strict_types=1);

namespace RivetCore\Rmm;

/**
 * The frozen wire and storage constants of the endpoint agent protocol, in one place. Installed agents, enrolled devices and
 * stored rows depend on every value here: changing one is a protocol break (see tests/Unit/Rmm/FrozenConstantsTest.php).
 * Values are those of RivetIT src/EndpointAgent at origin/beta; the agent-visible names keep the RivetIT spelling on purpose.
 *
 * @api
 */
final class RmmProtocol
{
    // ---- integration identity
    public const INTEGRATION_TYPE = 'rivetit_agent';
    public const DEFAULT_INTEGRATION_NAME = 'RivetIT Endpoint Agent';
    public const AGENT_KEY_PREFIX = 'rivetit:';
    public const ALERT_KEY_PREFIX = 'agent:';

    // ---- credentials
    public const ENROLL_TOKEN_PREFIX = 'rvte1';
    public const ENROLL_TOKEN_SELECTOR_RE = '/^[0-9a-f]{12}$/';
    public const ENROLL_TOKEN_SECRET_RE = '/^[0-9a-f]{40}$/';
    public const ENROLL_MAX_USES = 5000;
    public const RINGS = ['pilot', 'stable'];
    public const DEVICE_TOKEN_BEARER_RE = '/^Bearer\s+([A-Za-z0-9]{64})$/';
    public const DEVICE_TOKEN_VALID_DAYS = 365;
    public const SIGNING_KEY_ID_LENGTH = 16;

    // ---- request bodies (bytes)
    public const ENROLL_MAX_BODY = 16384;
    public const CHECKIN_MAX_BODY = 1048576;
    public const JOBS_REPORT_MAX_BODY = 262144;
    public const INSTALLER_MAX_BODY = 4096;

    // ---- rate limits: [limit, window seconds] per device (edition-supplied closure), buckets "agent_<kind>:<device_id>"
    public const RATE_CHECKIN = [40, 60];
    public const RATE_JOBS = [120, 60];
    public const RATE_UPDATE = [60, 60];

    // ---- DB-backed limits, per IP hash ('ea-enroll|' . ip, 'ea-installer|' . ip)
    public const ENROLL_RATE_SALT = 'ea-enroll|';
    public const ENROLL_RATE_WINDOW_S = 600;
    public const ENROLL_RATE_MAX_FAILURES = 10;
    public const ENROLL_RATE_MAX_ATTEMPTS = 60;
    public const INSTALLER_RATE_SALT = 'ea-installer|';
    public const INSTALLER_WINDOW_S = 600;
    public const INSTALLER_IP_MAX_FAILURES = 10;
    public const INSTALLER_IP_MAX_ATTEMPTS = 30;
    public const INSTALLER_TOKEN_MAX_DOWNLOADS = 30;
    public const INSTALLER_SELECTOR_MAX_FAILURES = 20;

    // ---- job polling and jobs
    public const JOBS_WAIT_MAX_S = 5;
    public const JOBS_POLL_STEP_US = 500000;
    public const JOBS_OFFER_LIMIT = 5;
    public const JOB_MAX_SCRIPT_BYTES = 102400;
    public const JOB_DEADLINE_GRACE_S = 60;
    public const JOB_STATES = ['queued', 'running', 'succeeded', 'failed', 'timed_out', 'cancelled', 'expired'];
    public const JOB_FINAL_STATES = ['succeeded', 'failed', 'timed_out', 'cancelled', 'expired'];
    public const JOB_REPORTABLE_STATES = ['running', 'succeeded', 'failed', 'timed_out', 'cancelled'];
    public const JOB_REASONS = ['result_lost', 'never_started', 'no_result_by_deadline', 'device_retired'];

    // ---- check-in caps
    public const CHECKIN_MAX_CHECKS = 100;
    public const CHECKIN_MAX_BUFFERED = 100;
    public const CHECKIN_MAX_DISKS = 32;
    public const CHECKIN_MAX_INVENTORY_BYTES = 65536;
    public const CHECKIN_FUTURE_SKEW_S = 300;
    public const CHECK_KEY_RE = '/^[A-Za-z0-9_.:-]{1,100}$/';
    public const CHECK_STATUSES = ['ok', 'warn', 'fail', 'unknown'];

    // ---- device identity
    public const LINK_STATES = ['linked', 'pending_approval', 'rejected'];
    public const MAC_RE = '/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/';
    public const NULL_MAC = '00:00:00:00:00:00';
    public const INSTALL_ID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    public const MACHINE_GUID_RE = '/^[A-Za-z0-9{}-]{1,64}$/';
    public const AGENT_VERSION_RE = '/^\d{1,5}\.\d{1,5}\.\d{1,5}([-+][0-9A-Za-z.-]{1,20})?$/';
    public const JUNK_SERIALS = [
        '', '0', 'none', 'n/a', 'na', 'unknown', 'default string', 'to be filled by o.e.m.', 'system serial number',
        'serial number', 'not specified', 'not applicable', 'xxxxxxxx', '123456789', '1234567890', 'default',
    ];

    // ---- binaries and updates
    public const ARCHS = ['amd64' => 0x8664, 'arm64' => 0xAA64];
    public const BINARY_VERSION_RE = '/^\d{1,5}\.\d{1,5}\.\d{1,5}([-+][0-9A-Za-z.-]{1,20})?\z/';
    public const BINARY_STORAGE_NAME_RE = '/^bin_[0-9a-f]{32}\.bin$/';
    public const BINARY_DEFAULT_MAX_BYTES = 67108864;
    public const BINARY_MIN_BYTES = 1024;
    public const DOWNLOAD_NAME_PREFIX = 'rivetit-agent-';
    public const INSTALLER_NAME_PREFIX = 'RivetIT-Agent-Setup-';

    // ---- installer trailer
    public const EMBED_MAGIC = 'RIVETIT-EMBED-v1';
    public const EMBED_FOOTER_LEN = 52;
    public const EMBED_MAX_PAYLOAD = 16384;
    public const EMBED_PAYLOAD_VERSION = 1;

    // ---- MeshCentral
    public const MESH_DEFAULT_ACCOUNT_TEMPLATE = 'rivetit-support';
    public const MESH_COOKIE_ACCESS = 3;
    public const MESH_VIEWMODE = 11;

    // ---- redaction
    public const REDACTION_MASK = '[REDACTED]';

    // ---- HTTP
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;
    public const ERROR_CODES = [
        'invalid_token', 'revoked', 'expired', 'forbidden', 'not_found', 'method_not_allowed', 'conflict', 'too_large', 'invalid',
        'tls_required', 'rate_limited', 'internal', 'unavailable', 'token_in_url',
        'confirmation_required', 'queued', 'cancelled', 'device_offline', 'unmapped', 'not_configured', 'device_retired',
        'mesh_unavailable', 'disabled',
    ];

    // ---- platforms. Today's server accepts 'windows' (and 'linux' only under a test flag); Phase 0 admits Linux as a platform.
    // A device that reports no capability block is treated as today's Windows agent; macOS is deferred (add it here, never rename).
    public const DEFAULT_PLATFORM = 'windows';
    public const PLATFORMS = ['windows', 'linux'];
}
