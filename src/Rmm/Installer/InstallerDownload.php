<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Installer;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Enrollment\AttemptLog;
use RivetCore\Rmm\Enrollment\DeviceValidator;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Update\UpdateService;

/**
 * The token-gated installer DOWNLOAD (POST agent_installer): rate limits, token authentication that answers every failure with the
 * same generic 404, preconditions, the stamp payload and the download file name. Creating installers and deployment snippets (the
 * administrator side) is the installer service of the admin layer.
 *
 * @api
 */
final class InstallerDownload
{
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly UpdateService $updates,
        private readonly RmmTenancyInterface $tenancy,
        private readonly RmmAuditInterface $audit,
        private readonly AttemptLog $attempts,
        private readonly string $filenamePrefix = RmmProtocol::INSTALLER_NAME_PREFIX,
    ) {
    }

    public function departmentName(int $clientId): string
    {
        $n = $this->tenancy->clientName($clientId);

        return $n === null ? ('Department ' . $clientId) : (DeviceValidator::cleanText($n, 200) ?? ('Department ' . $clientId));
    }

    /** File-name-safe slug of a client name: [a-z0-9-], at most 40 characters, never empty. */
    public static function slug(string $name): string
    {
        $s = strtolower((string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name));
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        $s = trim(substr($s, 0, 40), '-');

        return $s === '' ? 'department' : $s;
    }

    public function filename(string $department, string $arch): string
    {
        return $this->filenamePrefix . self::slug($department) . '-' . ($arch === 'arm64' ? 'arm64' : 'x64') . '.exe';
    }

    /** CA certificate text to embed, or null when none is configured. */
    public function caPem(): ?string
    {
        $pem = trim((string) ($this->settings->get()['ca_pem'] ?? ''));

        return $pem === '' ? null : $pem;
    }

    /**
     * @param array<string,mixed> $token the enrollment token row
     * @return array{0:?string,1:?string} [payload JSON, refusal message]
     */
    public function payloadFor(array $token, string $tokenPlain, string $installerId): array
    {
        $base = $this->updates->serviceBase();
        if ($base === null) {
            return [null, 'Set the service URL to an https:// address first: the installer needs it to find this server.'];
        }
        try {
            return [InstallerStamp::buildPayload([
                'installer_id' => $installerId,
                'server_url' => $base,
                'enrollment_token' => $tokenPlain,
                'department' => $this->departmentName((int) $token['client_id']),
                'ca_pem' => $this->caPem(),
                'created_at' => $this->sql->isoNow(),
                'expires_at' => (string) Sql::iso((string) $token['expires_at']),
            ]), null];
        } catch (\LengthException) {
            return [null, 'The installer payload is too large (client name or CA certificate).'];
        }
    }

    /** Preconditions shared by both download paths. @return string|null a refusal message */
    public function preflight(string $arch): ?string
    {
        if (!$this->settings->enabled()) {
            return 'The endpoint agent service is switched off. Turn it on first.';
        }
        if (!isset(RmmProtocol::ARCHS[$arch])) {
            return 'Choose Windows x64 (amd64) or ARM64.';
        }
        if ($this->updates->serviceBase() === null) {
            return 'Set the service URL to an https:// address first.';
        }
        if ($this->updates->currentBinary($arch) === null) {
            return "No agent binary is published for $arch. Upload one under Agent binaries and make it current.";
        }

        return null;
    }

    // ---------------------------------------------------------------- rate limit and authentication

    /** Throws 429 when this address (or this token selector) is over its budget. Counts only rows of the installer bucket. */
    public function checkRate(string $ip, string $selector = ''): void
    {
        $r = $this->attempts->byAddress(RmmProtocol::INSTALLER_RATE_SALT, $ip, RmmProtocol::INSTALLER_WINDOW_S);
        $limited = $r['failures'] >= RmmProtocol::INSTALLER_IP_MAX_FAILURES || $r['total'] >= RmmProtocol::INSTALLER_IP_MAX_ATTEMPTS;
        if (!$limited && $selector !== '') {
            $t = $this->attempts->byInstallerSelector($selector, RmmProtocol::INSTALLER_WINDOW_S);
            $limited = $t['ok'] >= RmmProtocol::INSTALLER_TOKEN_MAX_DOWNLOADS || $t['bad'] >= RmmProtocol::INSTALLER_SELECTOR_MAX_FAILURES;
        }
        if ($limited) {
            throw new ApiError(429, 'rate_limited', 'Too many requests. Try again later.', ['Retry-After' => (string) RmmProtocol::INSTALLER_WINDOW_S]);
        }
    }

    /**
     * Authenticate an enrollment token for an installer download. Every failure (malformed, unknown, wrong secret, revoked, expired,
     * used up) is the same generic 404 to the caller; the reason is only in the audit log and the attempt table. The secret
     * comparison runs even when no row matched.
     *
     * @return array<string,mixed> the token row
     * @throws ApiError 404
     */
    public function authenticate(string $tokenStr, string $ip): array
    {
        $parts = explode('.', $tokenStr);
        $well = count($parts) === 3 && $parts[0] === RmmProtocol::ENROLL_TOKEN_PREFIX
            && preg_match(RmmProtocol::ENROLL_TOKEN_SELECTOR_RE, $parts[1]) === 1 && preg_match(RmmProtocol::ENROLL_TOKEN_SECRET_RE, $parts[2]) === 1;
        $selector = $well ? $parts[1] : '';
        $row = $well ? $this->sql->one('SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_selector = ?', [$selector]) : null;
        $match = hash_equals((string) ($row['token_hash'] ?? str_repeat('0', 64)), hash('sha256', $parts[2] ?? ''));
        if (!$well) {
            $this->reject('malformed', $ip, $selector, null);
        }
        if ($row === null || !$match) {
            $this->reject('invalid_token', $ip, $selector, null);
        }
        if ($row['revoked_at'] !== null) {
            $this->reject('revoked', $ip, $selector, $row);
        }
        if (Sql::ts((string) $row['expires_at']) <= $this->sql->time()) {
            $this->reject('expired', $ip, $selector, $row);
        }
        if ((int) $row['use_count'] >= (int) $row['max_uses']) {
            $this->reject('exhausted', $ip, $selector, $row);
        }

        return $row;
    }

    /**
     * @param array<string,mixed>|null $row
     * @throws ApiError always: the same generic 404 whatever the reason
     */
    private function reject(string $reason, string $ip, string $selector, ?array $row): never
    {
        $this->attempts->record(RmmProtocol::INSTALLER_RATE_SALT, $ip, false, 'installer_' . $reason, $selector);
        $this->audit->record('Installer Download Rejected', "Installer download rejected ($reason) from $ip" . ($selector !== '' ? " using token $selector" : ''), $row !== null ? (int) $row['client_id'] : 0, 0);
        throw new ApiError(404, 'not_found', 'Not found.');
    }

    /** @param array<string,mixed> $token */
    public function recordSuccess(array $token, string $ip, string $arch, string $installerId): void
    {
        $this->attempts->record(RmmProtocol::INSTALLER_RATE_SALT, $ip, true, 'installer_download', (string) $token['token_selector']);
        $this->audit->record('Installer Downloaded', "Installer $installerId ($arch) downloaded with token #{$token['token_id']} ({$token['token_selector']}) from $ip", (int) $token['client_id'], 0);
    }
}
