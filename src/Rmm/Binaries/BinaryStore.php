<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Binaries;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Http\FileDownload;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Update\UpdateService;

/**
 * Hosted agent executables (unstamped), one row per (version, architecture): validate an uploaded file, store it, publish it, make it
 * the one new installers are stamped from, offer it as an update and serve it.
 *
 * Files live under the directory the edition gives the module (`binary_dir`): it must be denied over HTTP by the edition's web server
 * rules, git-ignored and outside the in-app backup. File names are random and never derived from user input (`bin_<32 hex>.bin`). Rows say
 * what may be served: `active` = published (installer and self-update may use it), `is_current` = the one a new installer is stamped
 * from. A delete only deactivates: the file is kept so a device that was offered the version can still finish its update.
 *
 * VALIDATION. {@see inspect()} reads the executable headers (PE: MZ, PE signature, machine type, not a DLL; ELF: class, byte order,
 * machine), refuses a wrong architecture, an oversize or tiny file and a file that already carries an installer footer, and computes the
 * SHA-256. A released version never changes under devices: re-publishing the identical file is a no-op, a different file for an
 * existing (version, arch) is refused. Only Windows (PE) agents can be hosted today: the binaries table has no platform column, so a
 * Linux agent is installed from the release tarball with install-linux.sh instead (see docs/modules/rmm.md).
 *
 * @api
 */
final class BinaryStore
{
    /** Machine type (e_machine) of the ELF architectures the agent is built for. */
    public const ELF_MACHINES = BinaryInspector::ELF_MACHINES;

    /**
     * @param string|null $storageDir where binaries live; null disables storing (publish refuses)
     * @param int|null $maxBytes the size cap of one binary (default 64 MiB, at least 1024)
     */
    public function __construct(
        private readonly Sql $sql,
        private readonly UpdateService $updates,
        private readonly RmmAuditInterface $audit,
        private readonly ?string $storageDir,
        private readonly ?int $maxBytes = null,
    ) {
    }

    // ---------------------------------------------------------------- limits

    public function maxBytes(): int
    {
        return $this->maxBytes === null ? RmmProtocol::BINARY_DEFAULT_MAX_BYTES : max(RmmProtocol::BINARY_MIN_BYTES, $this->maxBytes);
    }

    /** The largest upload the web form can really accept: the cap above, bounded by PHP's own limits. */
    public function effectiveUploadLimit(): int
    {
        $lim = $this->maxBytes();
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $b = self::iniBytes((string) ini_get($k));
            if ($b > 0) {
                $lim = min($lim, $b);
            }
        }

        return $lim;
    }

    /** php.ini shorthand ("8M", "2G", "512K", "-1") to bytes; 0 means unlimited. */
    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g':
                $n *= 1024;
                // no break
            case 'm':
                $n *= 1024;
                // no break
            case 'k':
                $n *= 1024;
        }

        return $n;
    }

    public static function human(int $n): string
    {
        return $n >= 1048576 ? round($n / 1048576, 1) . ' MiB' : ($n >= 1024 ? round($n / 1024, 1) . ' KiB' : $n . ' B');
    }

    // ---------------------------------------------------------------- storage

    /** The storage directory, created (0750) with deny and index files for Apache and a careless vhost; null when none is configured. */
    public function storageDir(): ?string
    {
        $dir = $this->storageDir;
        if ($dir === null || $dir === '') {
            return null;
        }
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

        return $dir;
    }

    /**
     * @param array<string,mixed> $row an endpoint_agent_binaries row
     * @throws \RuntimeException when the stored name is not one this class generated or no directory is configured
     */
    public function pathFor(array $row): string
    {
        $name = (string) $row['storage_name'];
        $dir = $this->storageDir();
        if ($dir === null || preg_match(RmmProtocol::BINARY_STORAGE_NAME_RE, $name) !== 1) {
            throw new \RuntimeException('bad storage name');   // never build a path from anything else (no traversal)
        }

        return rtrim($dir, '/') . '/' . $name;
    }

    // ---------------------------------------------------------------- validation

    /**
     * Identify an executable by its headers ({@see BinaryInspector::detect()}).
     *
     * @return array{format:string,machine:int,arch:?string,dll:bool,size:int}|string a description (format `pe` or `elf`; arch null for a machine type the agent is not built for) or an error message
     */
    public function detect(string $path): array|string
    {
        return BinaryInspector::detect($path);
    }

    /**
     * Validate a candidate Windows agent executable on disk ({@see BinaryInspector::inspect()} with this store's size cap).
     *
     * @return array{sha256:string,size:int}|string a description on success, an error message otherwise
     */
    public function inspect(string $path, string $arch, ?int $maxBytes = null): array|string
    {
        return BinaryInspector::inspect($path, $arch, $maxBytes ?? $this->maxBytes());
    }

    // ---------------------------------------------------------------- publishing

    /**
     * Validate, copy into storage and register a binary. Re-publishing the identical file for the same (version, arch) is a no-op;
     * a different file for an existing (version, arch) is refused (a released version never changes under devices).
     *
     * @param array{activate?:bool,release_ring?:?string,rollout_pct?:int,notes?:string} $opts
     * @return array{ok:bool,error?:string,binary?:array<string,mixed>,created?:bool}
     */
    public function publish(string $path, string $version, string $arch, int $userId, array $opts = []): array
    {
        if (preg_match(RmmProtocol::BINARY_VERSION_RE, $version) !== 1) {
            return ['ok' => false, 'error' => 'The version must look like 1.2.3 (optionally 1.2.3-rc1).'];
        }
        if ($this->storageDir() === null) {
            return ['ok' => false, 'error' => 'No agent binary directory is configured for this installation.'];
        }
        $info = $this->inspect($path, $arch);
        if (is_string($info)) {
            return ['ok' => false, 'error' => $info];
        }
        $existing = $this->sql->one('SELECT * FROM endpoint_agent_binaries WHERE version = ? AND arch = ?', [$version, $arch]);
        $created = false;
        if ($existing !== null) {
            if (!hash_equals((string) $existing['sha256'], $info['sha256'])) {
                return ['ok' => false, 'error' => "Version $version for $arch is already published with different contents. Use a new version number."];
            }
            $bin = $existing;
            if (!is_file($this->pathFor($bin))) {   // the row survived but the file was lost: restore it from this upload
                $this->copyIn($path, (string) $bin['storage_name']);
            }
        } else {
            $name = 'bin_' . bin2hex(random_bytes(16)) . '.bin';
            $this->copyIn($path, $name);
            $id = $this->sql->insert('INSERT INTO endpoint_agent_binaries (version, arch, sha256, size_bytes, storage_name, uploaded_by, active, is_current, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?)',
                [$version, $arch, $info['sha256'], $info['size'], $name, $userId, $this->sql->utcNow()]);
            $bin = $this->sql->one('SELECT * FROM endpoint_agent_binaries WHERE binary_id = ?', [$id]) ?? [];
            $created = true;
        }
        if ((int) $bin['active'] === 0) {
            $this->sql->run('UPDATE endpoint_agent_binaries SET active = 1 WHERE binary_id = ?', [$bin['binary_id']]);
        }
        if (!empty($opts['activate'])) {
            $this->setCurrent((int) $bin['binary_id']);
        }
        $ring = $opts['release_ring'] ?? null;
        if ($ring !== null && $ring !== '') {
            $e = $this->publishRelease((int) $bin['binary_id'], $ring, (int) ($opts['rollout_pct'] ?? 10), (string) ($opts['notes'] ?? ''), $userId);
            if ($e !== null) {
                return ['ok' => false, 'error' => $e, 'binary' => $bin, 'created' => $created];
            }
        }

        return ['ok' => true, 'binary' => $this->sql->one('SELECT * FROM endpoint_agent_binaries WHERE binary_id = ?', [$bin['binary_id']]) ?? [], 'created' => $created];
    }

    private function copyIn(string $src, string $name): void
    {
        $dir = $this->storageDir();
        if ($dir === null || !is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException('The agent binary directory ' . (string) $dir . ' is not writable by the web server.');
        }
        $tmp = "$dir/.incoming_" . bin2hex(random_bytes(8));
        if (!copy($src, $tmp)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not store the file.');
        }
        @chmod($tmp, 0640);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // Published by root from the CLI: hand the file to the directory's owner (the web server user), or PHP-FPM cannot read it.
            @chown($tmp, (int) fileowner($dir));
            @chgrp($tmp, (int) filegroup($dir));
        }
        if (!rename($tmp, "$dir/$name")) {
            @unlink($tmp);
            throw new \RuntimeException('Could not store the file.');
        }
    }

    /** Make this binary the one new installers are stamped from for its architecture (and make sure it is active). */
    public function setCurrent(int $binaryId): bool
    {
        $b = $this->sql->one('SELECT * FROM endpoint_agent_binaries WHERE binary_id = ?', [$binaryId]);
        if ($b === null) {
            return false;
        }
        $this->sql->transaction(function () use ($b, $binaryId): void {
            $this->sql->run('UPDATE endpoint_agent_binaries SET is_current = 0 WHERE arch = ?', [$b['arch']]);
            $this->sql->run('UPDATE endpoint_agent_binaries SET is_current = 1, active = 1 WHERE binary_id = ?', [$binaryId]);
        });

        return true;
    }

    /** "Delete": deactivate the binary and its release rows. The file stays. */
    public function deactivate(int $binaryId): bool
    {
        $n = $this->sql->run('UPDATE endpoint_agent_binaries SET active = 0, is_current = 0 WHERE binary_id = ?', [$binaryId]);
        $this->sql->run('UPDATE endpoint_agent_releases SET active = 0 WHERE binary_id = ?', [$binaryId]);

        return $n > 0;
    }

    /** Reactivate a deactivated binary (it is not offered as an update again until {@see publishRelease()} runs). */
    public function activate(int $binaryId): bool
    {
        if ($this->sql->one('SELECT binary_id FROM endpoint_agent_binaries WHERE binary_id = ?', [$binaryId]) === null) {
            return false;
        }
        $this->sql->run('UPDATE endpoint_agent_binaries SET active = 1 WHERE binary_id = ?', [$binaryId]);

        return true;
    }

    /**
     * Create or refresh the update release row that offers this binary to enrolled agents of its architecture.
     *
     * @return string|null error message
     */
    public function publishRelease(int $binaryId, string $ring, int $pct, string $notes, int $userId): ?string
    {
        $b = $this->sql->one('SELECT * FROM endpoint_agent_binaries WHERE binary_id = ? AND active = 1', [$binaryId]);
        if ($b === null) {
            return 'Unknown or inactive binary.';
        }
        if (!in_array($ring, RmmProtocol::RINGS, true)) {
            return 'Unknown ring.';
        }
        $url = $this->updates->updateUrl((string) $b['arch'], (string) $b['version']);
        if ($url === null) {
            return 'Set an https service URL first: agents download hosted updates from it.';
        }
        $this->sql->run('INSERT INTO endpoint_agent_releases (version, url, sha256, min_version, ring, rollout_pct, notes, created_by, created_at, arch, binary_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE url = VALUES(url), sha256 = VALUES(sha256), rollout_pct = VALUES(rollout_pct), notes = VALUES(notes), active = 1, binary_id = VALUES(binary_id)',
            [$b['version'], $url, $b['sha256'], '0.0.0', $ring, max(0, min(100, $pct)), mb_substr($notes, 0, 500), $userId, $this->sql->utcNow(), $b['arch'], $b['binary_id']]);
        $this->audit->record('Agent Release Published', "Release {$b['version']} ({$b['arch']}, $ring, $pct%) from hosted binary #{$b['binary_id']} by user $userId", 0, 0);

        return null;
    }

    /** Change the rollout percentage and the active flag of a release row (a rollout can be paused with 0 or switched off). */
    public function updateRelease(int $releaseId, int $pct, bool $active): bool
    {
        if ($this->sql->one('SELECT release_id FROM endpoint_agent_releases WHERE release_id = ?', [$releaseId]) === null) {
            return false;
        }
        $this->sql->run('UPDATE endpoint_agent_releases SET rollout_pct = ?, active = ? WHERE release_id = ?', [max(0, min(100, $pct)), $active ? 1 : 0, $releaseId]);

        return true;
    }

    // ---------------------------------------------------------------- serving

    /**
     * A download response for a stored binary (optionally followed by an installer trailer): the exact Content-Length, the file
     * streamed by the emitter in 64 KiB chunks (memory use does not depend on the file size). Size and SHA-256 are verified first,
     * so a damaged or swapped file is never served.
     *
     * @param array<string,mixed> $row an endpoint_agent_binaries row
     * @return RmmResponse|string the response, or an error message (nothing to send)
     */
    public function download(array $row, string $filename, string $trailer = ''): RmmResponse|string
    {
        [$file, $error] = $this->updates->openBinary($row, $trailer);
        if ($file === null) {
            return (string) $error;
        }

        return FileDownload::response($file, $filename);
    }
}
