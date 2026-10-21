<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Update;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Enrollment\DeviceValidator;
use RivetCore\Rmm\Http\RmmFileBody;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\AgentOs;
use RivetCore\Rmm\Support\RmmCache;
use RivetCore\Rmm\Support\Sql;

/**
 * The agent update manifest: staged rollout by ring and percentage, min_version compatibility, never a downgrade, and a per-device
 * "skip the version that just failed" rule fed by the agent's reported update result. This is the READ side of binaries and
 * releases (what a device is offered and how a hosted package is located and verified for streaming); uploading and publishing
 * binaries is the admin side (BinaryStore).
 *
 * The manifest is signed with the instance Ed25519 key over the lowercase hex SHA-256 of the package (ASCII), so an agent that only
 * trusts the key pinned at enrollment can verify a package it downloaded from any URL.
 *
 * @api
 */
final class UpdateService
{
    /**
     * @param string|null $binaryDir where hosted binaries live (endpoint_agent_binaries.storage_name); null disables hosted downloads
     * @param bool $allowInsecureHttp tolerate an http:// service URL on loopback (test servers only, never production)
     * @param string|null $hostFallback host a legacy manual release may be served from when no service URL is configured
     */
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly RmmAuditInterface $audit,
        private readonly ?string $binaryDir = null,
        private readonly bool $allowInsecureHttp = false,
        private readonly ?string $hostFallback = null,
        private readonly ?RmmCache $cache = null,
    ) {
    }

    /**
     * Tell the read cache that the release list changed (Core's own writers call this; an edition that writes `endpoint_agent_releases` itself should too).
     */
    public function releasesChanged(): void
    {
        $this->cache?->invalidate(RmmCache::GROUP_RELEASES, true);
    }

    /**
     * The active releases. On a SHARED read cache (cheap to read) they are cached for `cache_fresh_s` seconds: every check-in looks them up, and they change a few times a year.
     * With no shared cache the table is read, as it always was (a database-backed cache would only swap one SELECT for another).
     *
     * @return list<array<string,mixed>>
     */
    private function activeReleases(): array
    {
        $read = fn (): array => $this->sql->all('SELECT * FROM endpoint_agent_releases WHERE active = 1 ORDER BY release_id');
        if ($this->cache === null || !$this->cache->shared()) {
            return $read();
        }
        $limits = $this->settings->limits();

        return $this->cache->remember(RmmCache::GROUP_RELEASES, 'active', $limits['cache_fresh_s'], $limits['cache_stale_s'], $read);
    }

    /**
     * @param array<string,mixed> $dev
     * @return array<string,mixed>|null
     */
    public function manifestFor(array $dev): ?array
    {
        $best = $this->offeredRelease($dev);
        if ($best === null) {
            return null;
        }
        $url = (string) $best['url'];
        if ($best['binary_id'] !== null) {
            // A hosted release: the URL follows the configured service URL (it is served by agent_update). No https service
            // URL means nothing safe to offer, so the device is simply not offered it.
            $hosted = $this->updateUrl((string) $best['arch'], (string) $best['version'], AgentOs::ofRow($best));
            if ($hosted === null) {
                return null;
            }
            $url = $hosted;
        }
        [$sec] = $this->settings->signingKey();

        return ['version' => $best['version'], 'url' => $url, 'sha256' => $best['sha256'], 'signature' => Signer::signManifest((string) $best['sha256'], $sec), 'min_version' => $best['min_version']];
    }

    /**
     * The release row this device is currently offered (same rules as the manifest), or null.
     *
     * @param array<string,mixed> $dev
     * @return array<string,mixed>|null
     */
    public function offeredRelease(array $dev): ?array
    {
        $current = (string) $dev['agent_version'];
        $failed = $this->failedVersions($dev);
        $rings = $dev['ring'] === 'pilot' ? ['pilot', 'stable'] : ['stable'];
        $best = null;
        $os = AgentOs::forDevice($dev);
        foreach ($this->activeReleases() as $r) {
            if (!in_array($r['ring'], $rings, true)) {
                continue;
            }
            if (AgentOs::ofRow($r) !== $os) {
                continue;   // a release is for one operating system only (a row without the column, from before migration 0037, is a Windows release)
            }
            if ($r['arch'] !== '' && $r['arch'] !== (string) $dev['arch']) {
                continue;   // a per-architecture (hosted) release is only for devices of that architecture
            }
            if (version_compare((string) $r['version'], $current, '<=')) {
                continue;   // never offer the same or an older version
            }
            if (version_compare($current, (string) $r['min_version'], '<')) {
                continue;   // too old to jump straight to this one; an intermediate release must come first
            }
            if (in_array($r['version'], $failed, true)) {
                continue;
            }
            if (!self::inRollout((int) $dev['device_id'], $r)) {
                continue;
            }
            if ($best === null || version_compare((string) $r['version'], (string) $best['version'], '>')) {
                $best = $r;
            }
        }

        return $best;
    }

    /**
     * Deterministic per (device, version) bucket 0-99, so raising the percentage only ever adds devices.
     *
     * @param array<string,mixed> $release
     */
    public static function inRollout(int $deviceId, array $release): bool
    {
        $pct = (int) $release['rollout_pct'];
        if ($pct <= 0) {
            return false;
        }

        return $pct >= 100 || (crc32($deviceId . '|' . $release['version']) % 100) < $pct;
    }

    /**
     * @param array<string,mixed> $dev
     * @return list<string>
     */
    private function failedVersions(array $dev): array
    {
        $st = !empty($dev['update_state_json']) ? json_decode((string) $dev['update_state_json'], true) : null;
        $list = is_array($st) && is_array($st['failed_versions'] ?? null) ? $st['failed_versions'] : [];

        return array_values(array_filter($list, 'is_string'));
    }

    /**
     * The agent's own report of its last update attempt (optional check-in field "update_result":
     * {"version":"1.2.3","state":"ok|failed|rolled_back","detail":"..."}). A failed or rolled-back version is not offered to that
     * device again until an administrator clears it (or a newer release exists).
     *
     * @param array<string,mixed> $dev
     */
    public function recordResult(array $dev, mixed $res): void
    {
        if (!is_array($res) || !is_string($res['version'] ?? null) || !in_array($res['state'] ?? '', ['ok', 'failed', 'rolled_back'], true)
            || preg_match(RmmProtocol::AGENT_VERSION_RE, $res['version']) !== 1) {
            return;
        }
        $st = !empty($dev['update_state_json']) ? (json_decode((string) $dev['update_state_json'], true) ?: []) : [];
        $failed = is_array($st) && is_array($st['failed_versions'] ?? null) ? $st['failed_versions'] : [];
        $detail = DeviceValidator::cleanText($res['detail'] ?? '', 300);
        // A package built for another platform says nothing about the version itself: skip-listing it would stop the device from ever taking the fixed package.
        if ($res['state'] !== 'ok' && !in_array($res['version'], $failed, true) && !self::isWrongPlatform($detail)) {
            $failed[] = $res['version'];
        }
        $st = ['failed_versions' => array_slice($failed, -20), 'last' => ['version' => $res['version'], 'state' => $res['state'],
            'detail' => $detail, 'at' => $this->sql->utcNow()]];
        $this->sql->run('UPDATE endpoint_agent_devices SET update_state_json = ? WHERE device_id = ?', [json_encode($st), $dev['device_id']]);
        if ($res['state'] !== 'ok') {
            $this->audit->record('Agent Update Failed', "Device {$dev['device_id']} reported update to {$res['version']} as {$res['state']}", (int) $dev['client_id'], (int) $dev['asset_id']);
        }
    }

    /** Whether an agent's failure text means "this file is not an executable for my platform" (an ELF refused on Windows, a PE on Linux, a wrong CPU). */
    public static function isWrongPlatform(string $detail): bool
    {
        return preg_match('/wrong platform|not a valid (ELF|PE)|MZ header|exec format|wrong (os|architecture)|machine type/i', $detail) === 1;
    }

    public function clearFailures(int $deviceId): void
    {
        $this->sql->run('UPDATE endpoint_agent_devices SET update_state_json = NULL WHERE device_id = ?', [$deviceId]);
    }

    /**
     * Forget the versions a device (or every device) skip-listed after a failed update, so the release is offered again. With a version only that version is removed
     * (the rest of the update state, `last` included, is kept); without one the whole state is cleared. `$os` limits it to devices of that operating system.
     * Called by {@see \RivetCore\Rmm\Binaries\BinaryStore::publishRelease()} for the version it publishes, and by an edition to recover devices that skip-listed a version
     * for a reason that has been fixed (`RmmAdmin::clearUpdateFailures()`).
     *
     * @param int|null $deviceId one device, or null for all
     * @return int number of devices changed
     */
    public function clearUpdateFailures(?int $deviceId = null, ?string $version = null, ?string $os = null): int
    {
        $where = 'update_state_json IS NOT NULL';
        $args = [];
        if ($deviceId !== null) {
            $where .= ' AND device_id = ?';
            $args[] = $deviceId;
        }
        if ($os !== null) {
            $where .= ' AND os = ?';
            $args[] = $os;
        }
        if ($version !== null) {
            $where .= ' AND update_state_json LIKE ?';
            $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $version) . '%';
        }
        $changed = 0;
        foreach ($this->sql->all("SELECT device_id, update_state_json FROM endpoint_agent_devices WHERE $where", $args) as $row) {
            if ($version === null) {
                $this->sql->run('UPDATE endpoint_agent_devices SET update_state_json = NULL WHERE device_id = ?', [$row['device_id']]);
                $changed++;
                continue;
            }
            $st = json_decode((string) $row['update_state_json'], true);
            $list = is_array($st) && is_array($st['failed_versions'] ?? null) ? $st['failed_versions'] : [];
            if (!in_array($version, $list, true)) {
                continue;
            }
            $st['failed_versions'] = array_values(array_filter($list, static fn ($v): bool => $v !== $version));
            $this->sql->run('UPDATE endpoint_agent_devices SET update_state_json = ? WHERE device_id = ?', [json_encode($st), $row['device_id']]);
            $changed++;
        }

        return $changed;
    }

    /** Offer a package hosted elsewhere (legacy manual release). @return string|null error message */
    public function addRelease(string $version, string $url, string $sha256, string $minVersion, string $ring, int $pct, string $notes, int $userId, string $os = AgentOs::WINDOWS): ?string
    {
        $os = AgentOs::normalize($os);
        if ($os === null) {
            return 'The platform must be windows or linux.';
        }
        $osCols = AgentOs::supported($this->sql);
        if ($os !== AgentOs::WINDOWS && !$osCols) {
            return 'This database has not run migration 0037 (agent release platform) yet, so only Windows releases can be added.';
        }
        if (preg_match(RmmProtocol::AGENT_VERSION_RE, $version) !== 1 || preg_match(RmmProtocol::AGENT_VERSION_RE, $minVersion) !== 1) {
            return 'Versions must look like 1.2.3.';
        }
        if (preg_match('/^[0-9a-f]{64}$/', strtolower($sha256)) !== 1) {
            return 'sha256 must be 64 hex characters.';
        }
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || strlen($url) > 500) {
            return 'The package URL must be an https address.';
        }
        $svc = parse_url((string) $this->settings->get()['service_url'], PHP_URL_HOST);
        $svc = is_string($svc) && $svc !== '' ? $svc : (string) $this->hostFallback;
        $svc = strtolower((string) preg_replace('/:\d+$/', '', $svc));
        if ($svc !== '' && strtolower($p['host']) !== $svc) {
            return 'The package must be served from this host (' . $svc . ').';
        }
        if (!in_array($ring, RmmProtocol::RINGS, true)) {
            return 'Unknown ring.';
        }
        $this->sql->run('INSERT INTO endpoint_agent_releases (version, url, sha256, min_version, ring, rollout_pct, notes, created_by, created_at' . ($osCols ? ', os' : '') . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?' . ($osCols ? ', ?' : '') . ')
            ON DUPLICATE KEY UPDATE url = VALUES(url), sha256 = VALUES(sha256), min_version = VALUES(min_version), rollout_pct = VALUES(rollout_pct), notes = VALUES(notes), active = 1',
            array_merge([$version, $url, strtolower($sha256), $minVersion, $ring, max(0, min(100, $pct)), mb_substr($notes, 0, 500), $userId, $this->sql->utcNow()], $osCols ? [$os] : []));
        $this->audit->record('Agent Release Published', "Release $version ($os, $ring, $pct%) published by user $userId", 0, 0);
        $this->clearUpdateFailures(null, $version, $os);
        $this->releasesChanged();

        return null;
    }

    // ------------------------------------------------------------------ hosted binaries (read side)

    /** The configured service URL without a trailing slash, only when it is https (allowInsecureHttp loosens this for loopback tests). */
    public function serviceBase(): ?string
    {
        $u = trim((string) ($this->settings->get()['service_url'] ?? ''));
        $p = parse_url($u);
        if ($p === false || empty($p['host']) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])) {
            return null;
        }
        $scheme = $p['scheme'] ?? '';
        $insecureOk = $this->allowInsecureHttp && $scheme === 'http' && in_array(strtolower($p['host']), ['127.0.0.1', 'localhost', '[::1]'], true);
        if ($scheme !== 'https' && !$insecureOk) {
            return null;
        }

        return rtrim($u, '/');
    }

    /** The https URL a device downloads a hosted release from. Null when no usable service URL is configured. */
    public function updateUrl(string $arch, string $version, string $os = AgentOs::WINDOWS): ?string
    {
        $base = $this->serviceBase();

        // The Windows URL is byte for byte what it always was; another OS adds `&os=` so the request names the platform it wants.
        return $base === null ? null : $base . '/api/v1/agent_update?arch=' . rawurlencode($arch) . '&version=' . rawurlencode($version) . ($os === AgentOs::WINDOWS ? '' : '&os=' . rawurlencode($os));
    }

    /**
     * The binary the installer is stamped from for an architecture.
     *
     * @return array<string,mixed>|null
     */
    public function currentBinary(string $arch, string $os = AgentOs::WINDOWS): ?array
    {
        if (!AgentOs::supported($this->sql)) {
            return $os === AgentOs::WINDOWS ? $this->sql->one('SELECT * FROM endpoint_agent_binaries WHERE arch = ? AND active = 1 AND is_current = 1 ORDER BY binary_id DESC LIMIT 1', [$arch]) : null;
        }

        return $this->sql->one('SELECT * FROM endpoint_agent_binaries WHERE arch = ? AND os = ? AND active = 1 AND is_current = 1 ORDER BY binary_id DESC LIMIT 1', [$arch, $os]);
    }

    /**
     * The hosted binary a device may download right now, or null: only the release the manifest offers THIS device, only for its own
     * architecture and operating system, and only when the stored row still matches the manifest's SHA-256. `$requestedOs` is the optional `os` the agent sent with the
     * request; it must agree with the device's stored OS or nothing is served.
     *
     * @param array<string,mixed> $dev
     * @return array<string,mixed>|null
     */
    public function hostedBinaryFor(array $dev, string $arch, string $version, mixed $requestedOs = null): ?array
    {
        $os = AgentOs::forDevice($dev);
        $asked = AgentOs::normalize($requestedOs);
        if ($asked !== null && $asked !== $os) {
            return null;   // the agent says it is not the platform the device was enrolled as: serve nothing rather than guess
        }
        $rel = $this->offeredRelease($dev);
        if ($rel === null || $rel['binary_id'] === null || $rel['version'] !== $version || (string) $dev['arch'] !== $arch || AgentOs::ofRow($rel) !== $os) {
            return null;
        }
        $bin = $this->sql->one('SELECT * FROM endpoint_agent_binaries WHERE binary_id = ? AND active = 1 AND arch = ? AND version = ?', [(int) $rel['binary_id'], $arch, $version]);
        if ($bin === null || AgentOs::ofRow($bin) !== $os || !hash_equals((string) $rel['sha256'], (string) $bin['sha256'])) {
            return null;
        }

        return $bin;
    }

    /**
     * Whether the stored file of a binary row starts with the executable magic of the platform the row claims: true or false, or null when the file cannot be looked at
     * (no directory, a damaged name, a missing file). The read model shows it so an administrator sees a binary stored under the wrong platform.
     *
     * @param array<string,mixed> $row an endpoint_agent_binaries row
     */
    public function platformOk(array $row): ?bool
    {
        $name = (string) $row['storage_name'];
        if ($this->binaryDir === null || preg_match(RmmProtocol::BINARY_STORAGE_NAME_RE, $name) !== 1) {
            return null;
        }
        $path = rtrim($this->binaryDir, '/') . '/' . $name;

        return is_file($path) ? AgentOs::magicMatches($path, AgentOs::ofRow($row)) : null;
    }

    /**
     * Locate and verify a stored binary and describe it for streaming (size and SHA-256 are checked here, once).
     *
     * @param array<string,mixed> $row an endpoint_agent_binaries row
     * @param string|null $expectOs when set, the file's first bytes must be that OS's executable magic (PE `MZ` or ELF `7f 45 4c 46`); a file that disagrees is never streamed,
     *        the failure is logged and audited and the binary (with its releases) is deactivated so the administrator sees it
     * @return array{0:?RmmFileBody,1:?string} [file body, error message when it cannot be served]
     */
    public function openBinary(array $row, string $trailer = '', ?string $expectOs = null): array
    {
        $name = (string) $row['storage_name'];
        if ($this->binaryDir === null || preg_match(RmmProtocol::BINARY_STORAGE_NAME_RE, $name) !== 1) {
            return [null, 'The stored agent binary is missing or damaged.'];   // never build a path from anything else (no traversal)
        }
        $path = rtrim($this->binaryDir, '/') . '/' . $name;
        clearstatcache(true, $path);   // a long-running worker must not trust a cached size after the file was replaced
        if (!is_file($path) || (int) filesize($path) !== (int) $row['size_bytes']) {
            return [null, 'The stored agent binary is missing or damaged.'];
        }
        if (!hash_equals((string) $row['sha256'], (string) hash_file('sha256', $path))) {
            return [null, 'The stored agent binary failed its integrity check.'];
        }
        if (!is_readable($path)) {
            return [null, 'The stored agent binary could not be read.'];
        }
        if ($expectOs !== null && !AgentOs::magicMatches($path, $expectOs)) {
            error_log("endpoint agent: binary #{$row['binary_id']} ({$row['version']} {$row['arch']}) is not a $expectOs executable; refusing to serve it and deactivating it");
            $this->sql->run('UPDATE endpoint_agent_binaries SET active = 0, is_current = 0 WHERE binary_id = ?', [$row['binary_id']]);
            $this->sql->run('UPDATE endpoint_agent_releases SET active = 0 WHERE binary_id = ?', [$row['binary_id']]);
            $this->releasesChanged();
            $this->audit->record('Agent Binary Invalid', "Stored agent binary #{$row['binary_id']} ({$row['version']} {$row['arch']}) is not a $expectOs executable (wrong platform); it was deactivated and not served", 0, 0);

            return [null, 'The stored agent binary is not an executable for this platform (wrong platform).'];
        }

        return [new RmmFileBody($path, (int) $row['size_bytes'], $trailer === '' ? null : $trailer), null];
    }
}
