<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Http\RmmFileBody;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Update\UpdateService;

/**
 * Hosted installer files: a vendor installer an administrator uploads to the server instead of pointing at a download address, for vendors whose addresses are
 * account-specific or expire. Reuses the agent-binary pattern ({@see \RivetCore\Rmm\Binaries\BinaryStore}): the file lives in a directory OUTSIDE the web root that the
 * edition configures (`binary_dir`, here its `hosted` subdirectory, created 0750 with deny files), under a random name that is never derived from the upload
 * (`hosted_<36 chars uuid>.bin`); the database row holds the SHA-256 and the exact size, and the file is checked against both every time it is served.
 *
 * WHAT IS CHECKED: a size cap (default 512 MiB, at most the 1 GiB the agent accepts), a Windows installer header (`MZ` for an exe, the OLE compound-file signature for an
 * msi; anything else is refused), a regular file that is not a symbolic link. The file is NEVER opened as an archive or unpacked here, so there is nothing to expand: a
 * zip bomb is just a file of its stated size, and the agent only runs it if its hash equals the pin.
 *
 * HOW A DEVICE GETS IT: a hosted version's job carries a URL to this server whose last path segment is a random 48 hex digit secret that exists only for that job: it is
 * sealed in the job sidecar like any vendor secret ({@see \RivetCore\Rmm\Job\JobExtras}), only its SHA-256 is stored ({@see grant()}), it works only while that job is
 * queued or running, only for the file that job was built for, and only for a limited time. The agent sends no credentials when it downloads, which is why the secret is
 * in the address; it is never logged or audited.
 *
 * @api
 */
final class HostedFiles
{
    public const DEFAULT_MAX_BYTES = 536870912;
    public const GRANT_TTL_S = 86400;
    public const MAX_FILES = 200;
    public const ROUTE = 'agent_software';
    private const NAME_RE = '/^hosted_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.bin$/D';
    private const OLE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    public function __construct(
        private readonly Sql $sql,
        private readonly UpdateService $updates,
        private readonly RmmAuditInterface $audit,
        private readonly ?string $binaryDir,
        private readonly ?int $maxBytes = null,
    ) {
    }

    public function maxBytes(): int
    {
        return min(DeployProtocol::SIZE_MAX, max(1024, $this->maxBytes ?? self::DEFAULT_MAX_BYTES));
    }

    /** The directory for hosted files (a subdirectory of the binary directory), created 0750 with deny files; null when none is configured. */
    public function dir(): ?string
    {
        if ($this->binaryDir === null || $this->binaryDir === '') {
            return null;
        }
        $dir = rtrim($this->binaryDir, '/') . '/hosted';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            if (!is_file("$dir/.htaccess")) {
                @file_put_contents("$dir/.htaccess", "Require all denied\nOptions -Indexes -ExecCGI\n");
            }
            if (!is_file("$dir/index.html")) {
                @file_put_contents("$dir/index.html", '');
            }
        }

        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    /**
     * Store an uploaded installer. `$path` is the temporary file the web server received (the caller decides whether it was really an upload).
     *
     * @return array{ok:bool,error?:string,file?:array<string,mixed>}
     */
    public function store(string $path, string $originalName, int $userId): array
    {
        $dir = $this->dir();
        if ($dir === null) {
            return ['ok' => false, 'error' => 'No directory for hosted installers is configured or it is not writable.'];
        }
        if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_hosted_files') >= self::MAX_FILES) {
            return ['ok' => false, 'error' => 'At most ' . self::MAX_FILES . ' hosted installers. Delete one that no version uses first.'];
        }
        if (is_link($path) || !is_file($path)) {
            return ['ok' => false, 'error' => 'The upload is not a regular file.'];
        }
        $size = (int) filesize($path);
        if ($size < 1024 || $size > $this->maxBytes()) {
            return ['ok' => false, 'error' => 'An installer is 1 KiB to ' . round($this->maxBytes() / 1048576) . ' MiB.'];
        }
        $head = (string) file_get_contents($path, false, null, 0, 8);
        if (!str_starts_with($head, 'MZ') && $head !== self::OLE) {
            return ['ok' => false, 'error' => 'Only a Windows installer is accepted: an .exe (MZ header) or an .msi (compound file header).'];
        }
        $sha = (string) hash_file('sha256', $path);
        $name = 'hosted_' . JobService::uuid() . '.bin';
        $dest = $dir . '/' . $name;
        if (!@copy($path, $dest)) {
            return ['ok' => false, 'error' => 'The file could not be stored.'];
        }
        @chmod($dest, 0640);
        if (!hash_equals($sha, (string) hash_file('sha256', $dest)) || (int) filesize($dest) !== $size) {
            @unlink($dest);

            return ['ok' => false, 'error' => 'The stored copy does not match the upload.'];
        }
        $display = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', basename(str_replace('\\', '/', $originalName)));
        $display = $display === '' || $display[0] === '.' ? 'installer' : mb_substr($display, 0, 100);
        $id = $this->sql->insert('INSERT INTO rmm_hosted_files (filename, storage_name, sha256, size_bytes, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$display, substr($name, 7, 36), $sha, $size, $userId, $this->sql->utcNow()]);
        $this->audit->record('Hosted Installer Uploaded', "User $userId uploaded hosted installer #$id ($display, $size bytes, sha256 $sha)", 0, 0);

        return ['ok' => true, 'file' => $this->file($id)];
    }

    /** @return array<string,mixed>|null */
    public function file(int $fileId): ?array
    {
        $r = $this->sql->one('SELECT f.*, (SELECT COUNT(*) FROM rmm_version_hosted h WHERE h.file_id = f.file_id) AS used FROM rmm_hosted_files f WHERE f.file_id = ?', [$fileId]);

        return $r === null ? null : self::row($r);
    }

    /** @return list<array<string,mixed>> */
    public function files(): array
    {
        return array_map(self::row(...), $this->sql->all('SELECT f.*, (SELECT COUNT(*) FROM rmm_version_hosted h WHERE h.file_id = f.file_id) AS used FROM rmm_hosted_files f ORDER BY f.file_id DESC LIMIT 500'));
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function row(array $r): array
    {
        return ['file_id' => (int) $r['file_id'], 'filename' => (string) $r['filename'], 'sha256' => (string) $r['sha256'], 'size_bytes' => (int) $r['size_bytes'],
            'used_by_versions' => (int) $r['used'], 'created_at' => Sql::iso((string) $r['created_at'])];
    }

    /** Delete a hosted file nothing refers to (the stored file too). */
    public function delete(int $fileId): ?string
    {
        $r = $this->sql->one('SELECT * FROM rmm_hosted_files WHERE file_id = ?', [$fileId]);
        if ($r === null) {
            return 'not_found';
        }
        if ($this->sql->one('SELECT 1 AS x FROM rmm_version_hosted WHERE file_id = ?', [$fileId]) !== null) {
            return 'in_use';
        }
        $this->sql->run('DELETE FROM rmm_hosted_files WHERE file_id = ?', [$fileId]);
        $p = $this->pathFor($r);
        if ($p !== null) {
            @unlink($p);
        }

        return null;
    }

    /** @param array<string,mixed> $row */
    private function pathFor(array $row): ?string
    {
        $dir = $this->dir();
        $name = 'hosted_' . (string) $row['storage_name'] . '.bin';

        return $dir === null || preg_match(self::NAME_RE, $name) !== 1 ? null : $dir . '/' . $name;   // never a path from anything else (no traversal)
    }

    // ------------------------------------------------------------------ job-bound access

    /**
     * Make the download address of a hosted file for one install job. Returns the address (to be sealed in the job's secret slot) or null when no usable https
     * service address is configured. Only the hash of the secret is kept.
     */
    public function grant(string $jobId, int $fileId): ?string
    {
        $base = $this->updates->serviceBase();
        if ($base === null || $this->file($fileId) === null) {
            return null;
        }
        $secret = bin2hex(random_bytes(24));
        $url = $base . '/api/v1/' . self::ROUTE . '/' . $jobId . '/' . $secret;
        if (str_starts_with($url, 'https://') && SourceUrl::normalise($url) === null) {
            return null;   // the agent only downloads from a plain https DNS name; an IP address or a port is refused there, so refuse it here
        }
        $this->sql->run('INSERT INTO rmm_hosted_grants (job_id, file_id, secret_hash, expires_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE file_id = VALUES(file_id), secret_hash = VALUES(secret_hash), expires_at = VALUES(expires_at)',
            [$jobId, $fileId, hash('sha256', $secret), $this->sql->utcAt(self::GRANT_TTL_S)]);

        return $url;
    }

    /** Remove the grant of a job that could not be created. */
    public function revoke(string $jobId): void
    {
        $this->sql->run('DELETE FROM rmm_hosted_grants WHERE job_id = ?', [$jobId]);
    }

    /**
     * The file a download request may get, or null (every refusal looks the same to the caller). The grant must exist, match the secret, be unexpired, and its job
     * must still be a queued or running `software_install` job; the stored file must still match its size and hash.
     */
    public function open(string $jobId, string $secret): ?RmmFileBody
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $jobId) !== 1 || preg_match('/^[0-9a-f]{48}$/D', $secret) !== 1) {
            return null;
        }
        $g = $this->sql->one("SELECT g.*, j.state FROM rmm_hosted_grants g JOIN endpoint_agent_jobs j ON j.job_id = g.job_id
            WHERE g.job_id = ? AND g.expires_at > ? AND j.type = 'software_install' AND j.state IN ('queued','running')", [$jobId, $this->sql->utcNow()]);
        if ($g === null || !hash_equals((string) $g['secret_hash'], hash('sha256', $secret))) {
            return null;
        }
        $f = $this->sql->one('SELECT * FROM rmm_hosted_files WHERE file_id = ?', [$g['file_id']]);
        $path = $f === null ? null : $this->pathFor($f);
        if ($f === null || $path === null) {
            return null;
        }
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path) || !is_readable($path) || (int) filesize($path) !== (int) $f['size_bytes']) {
            return null;
        }
        // The SHA-256 of up to 512 MiB is computed once per version of the file (security review F7), not on every request: the stat fingerprint (inode, size, mtime, ctime) of a
        // file that was hashed and found intact is kept, and any change to the file changes it. Without migration 0038 (no column) the file is hashed every time, as before.
        $fp = self::fingerprint($path);
        $canCache = array_key_exists('verified_fp', $f);
        if (!$canCache || $fp === '' || !hash_equals((string) $f['verified_fp'], $fp)) {
            if (!hash_equals((string) $f['sha256'], (string) hash_file('sha256', $path))) {
                return null;
            }
            // Only a file at least two seconds old is remembered: stat times have a one second resolution, so a change in the same second as the check could keep the
            // fingerprint (the "racy" case); such a file is simply hashed again next time.
            if ($canCache && $fp !== '' && self::fingerprint($path) === $fp && self::ageSeconds($path) >= 2) {
                $this->sql->run('UPDATE rmm_hosted_files SET verified_fp = ? WHERE file_id = ?', [$fp, $f['file_id']]);
            }
        }

        return new RmmFileBody($path, (int) $f['size_bytes'], null, (string) $f['sha256']);
    }

    private static function ageSeconds(string $path): int
    {
        clearstatcache(true, $path);
        $st = @stat($path);

        return $st === false ? 0 : time() - max((int) $st['mtime'], (int) $st['ctime']);
    }

    /** inode, size, mtime and ctime of a file as one string ('' when it cannot be read). */
    private static function fingerprint(string $path): string
    {
        clearstatcache(true, $path);
        $st = @stat($path);

        return $st === false ? '' : hash('sha256', implode(':', [$st['ino'], $st['size'], $st['mtime'], $st['ctime']]));
    }

    /** Housekeeping: grants of finished jobs are of no further use. */
    public function pruneGrants(): int
    {
        return $this->sql->run("DELETE g FROM rmm_hosted_grants g LEFT JOIN endpoint_agent_jobs j ON j.job_id = g.job_id
            WHERE g.expires_at <= ? OR j.job_id IS NULL OR j.state NOT IN ('queued','running')", [$this->sql->utcNow()]);
    }
}
