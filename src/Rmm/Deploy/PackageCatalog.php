<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

use RivetCore\Rmm\Contracts\PackageSourcePolicyInterface;
use RivetCore\Rmm\Support\Sql;

/**
 * The package catalog: packages, their versions and the hosts installers may be downloaded from. Every value an agent will ever be told to run is validated HERE, once,
 * when an administrator saves it, and again when a job is built ({@see DeployService::paramsFor()}).
 *
 * A VERSION IS IMMUTABLE. Its address, hash, size, arguments and detection rule can never be edited, only the version can be retired: to change what is installed, add a
 * new version. That is what makes the hash a pin and not a suggestion (an attacker who gets write access to a catalog row cannot swap the hash of a version that is
 * already assigned to machines without it showing up as a new version in the audit trail).
 *
 * @api
 */
final class PackageCatalog
{
    public const MAX_PACKAGES = 2000;
    public const MAX_VERSIONS_PER_PACKAGE = 100;
    public const MAX_HOSTS = 200;
    private const LABEL_RE = '/^[A-Za-z0-9][A-Za-z0-9.+~:_ -]{0,63}$/D';
    private const HOST_RE = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    public function __construct(private readonly Sql $sql, private readonly PackageSourcePolicyInterface $sources)
    {
    }

    public function sources(): PackageSourcePolicyInterface
    {
        return $this->sources;
    }

    // ------------------------------------------------------------------ hosts

    /** @return list<array{host:string,note:string,created_at:string}> */
    public function hosts(): array
    {
        return array_map(static fn (array $r): array => ['host' => (string) $r['host'], 'note' => (string) $r['note'], 'created_at' => Sql::iso((string) $r['created_at'])],
            $this->sql->all('SELECT * FROM rmm_software_hosts ORDER BY host'));
    }

    /** @return list<string> */
    public function hostNames(): array
    {
        return array_map(static fn (array $r): string => (string) $r['host'], $this->sql->all('SELECT host FROM rmm_software_hosts ORDER BY host'));
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function addHost(mixed $host, mixed $note, int $userId): string
    {
        if (!is_string($host) || preg_match(self::HOST_RE, $host = strtolower(trim($host))) !== 1 || strlen($host) > 253) {
            throw new \InvalidArgumentException('host must be a plain DNS host name such as downloads.example.com (no scheme, port, path or wildcard; an IP address is not accepted).');
        }
        $note = is_string($note) ? trim($note) : '';
        if (mb_strlen($note) > 120 || preg_match('/[\x00-\x1f\x7f]/', $note) === 1) {
            throw new \InvalidArgumentException('note is at most 120 characters of plain text.');
        }
        if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_software_hosts') >= self::MAX_HOSTS && $this->sql->one('SELECT host FROM rmm_software_hosts WHERE host = ?', [$host]) === null) {
            throw new \InvalidArgumentException('At most ' . self::MAX_HOSTS . ' hosts can be listed.');
        }
        $this->sql->run('INSERT INTO rmm_software_hosts (host, note, created_by, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE note = VALUES(note)', [$host, $note, $userId, $this->sql->utcNow()]);

        return $host;
    }

    public function removeHost(mixed $host): bool
    {
        return is_string($host) && $this->sql->run('DELETE FROM rmm_software_hosts WHERE host = ?', [strtolower($host)]) > 0;
    }

    // ------------------------------------------------------------------ packages

    /** @return list<array<string,mixed>> */
    public function packages(): array
    {
        $rows = $this->sql->all('SELECT p.*, (SELECT COUNT(*) FROM rmm_package_versions v WHERE v.package_id = p.package_id AND v.retired = 0) AS versions,
            (SELECT COUNT(*) FROM rmm_package_assignments a WHERE a.package_id = p.package_id) AS assignments FROM rmm_packages p ORDER BY p.name');

        return array_map(self::packageRow(...), $rows);
    }

    /** @return array<string,mixed>|null */
    public function package(int $packageId): ?array
    {
        $r = $this->sql->one('SELECT p.*, (SELECT COUNT(*) FROM rmm_package_versions v WHERE v.package_id = p.package_id AND v.retired = 0) AS versions,
            (SELECT COUNT(*) FROM rmm_package_assignments a WHERE a.package_id = p.package_id) AS assignments FROM rmm_packages p WHERE p.package_id = ?', [$packageId]);

        return $r === null ? null : self::packageRow($r);
    }

    /**
     * @param array<string,mixed> $in {name, publisher?, description?, enabled?, two_person?}
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function savePackage(?int $packageId, array $in, int $userId): array
    {
        $cur = $packageId === null ? null : $this->package($packageId);
        if ($packageId !== null && $cur === null) {
            throw new \InvalidArgumentException('Package not found.');
        }
        $name = $in['name'] ?? $cur['name'] ?? null;
        if (!is_string($name) || preg_match('/^[^\x00-\x1f\x7f]{1,100}$/Du', trim($name)) !== 1) {
            throw new \InvalidArgumentException('name is 1 to 100 characters of plain text.');
        }
        $name = trim($name);
        $publisher = $in['publisher'] ?? $cur['publisher'] ?? '';
        $description = $in['description'] ?? $cur['description'] ?? '';
        foreach (['publisher' => [$publisher, 100], 'description' => [$description, 300]] as $k => [$v, $max]) {
            if (!is_string($v) || mb_strlen($v) > $max || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $v) === 1) {
                throw new \InvalidArgumentException("$k is at most $max characters of plain text.");
            }
        }
        $enabled = array_key_exists('enabled', $in) ? ($in['enabled'] === true || $in['enabled'] === 1) : ($cur['enabled'] ?? true);
        $two = array_key_exists('two_person', $in) ? ($in['two_person'] === true || $in['two_person'] === 1) : ($cur['two_person'] ?? false);
        $dup = $this->sql->one('SELECT package_id FROM rmm_packages WHERE name = ? AND package_id <> ?', [$name, $packageId ?? 0]);
        if ($dup !== null) {
            throw new \InvalidArgumentException('A package with that name exists.');
        }
        $now = $this->sql->utcNow();
        if ($packageId === null) {
            if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_packages') >= self::MAX_PACKAGES) {
                throw new \InvalidArgumentException('At most ' . self::MAX_PACKAGES . ' packages can be defined.');
            }
            $packageId = $this->sql->insert('INSERT INTO rmm_packages (name, publisher, description, enabled, two_person, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$name, $publisher, $description, $enabled ? 1 : 0, $two ? 1 : 0, $userId, $now, $now]);
        } else {
            $this->sql->run('UPDATE rmm_packages SET name = ?, publisher = ?, description = ?, enabled = ?, two_person = ?, updated_at = ? WHERE package_id = ?',
                [$name, $publisher, $description, $enabled ? 1 : 0, $two ? 1 : 0, $now, $packageId]);
        }

        return $this->package($packageId) ?? [];
    }

    /**
     * Delete a package that nothing refers to.
     *
     * @return string|null null when deleted, else why not
     */
    public function deletePackage(int $packageId): ?string
    {
        if ($this->package($packageId) === null) {
            return 'not_found';
        }
        if ($this->sql->one('SELECT assignment_id FROM rmm_package_assignments WHERE package_id = ?', [$packageId]) !== null) {
            return 'assigned';
        }
        $this->sql->transaction(function () use ($packageId): bool {
            $this->sql->run('DELETE FROM rmm_package_installs WHERE package_id = ?', [$packageId]);
            $this->sql->run('DELETE FROM rmm_package_versions WHERE package_id = ?', [$packageId]);
            $this->sql->run('DELETE FROM rmm_packages WHERE package_id = ?', [$packageId]);

            return true;
        });

        return null;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function packageRow(array $r): array
    {
        return ['package_id' => (int) $r['package_id'], 'name' => (string) $r['name'], 'publisher' => (string) $r['publisher'], 'description' => (string) $r['description'],
            'enabled' => (int) $r['enabled'] === 1, 'two_person' => (int) $r['two_person'] === 1, 'versions' => (int) ($r['versions'] ?? 0), 'assignments' => (int) ($r['assignments'] ?? 0),
            'created_at' => Sql::iso((string) $r['created_at']), 'updated_at' => Sql::iso((string) $r['updated_at'])];
    }

    // ------------------------------------------------------------------ versions

    /** @return list<array<string,mixed>> */
    public function versions(int $packageId): array
    {
        return array_map(self::versionRow(...), $this->sql->all('SELECT * FROM rmm_package_versions WHERE package_id = ? ORDER BY version_id DESC', [$packageId]));
    }

    /** @return array<string,mixed>|null */
    public function version(int $versionId): ?array
    {
        $r = $this->sql->one('SELECT * FROM rmm_package_versions WHERE version_id = ?', [$versionId]);

        return $r === null ? null : self::versionRow($r);
    }

    /**
     * Add a version to a package. All fields are checked here; the row can not be edited afterwards.
     *
     * @param array<string,mixed> $in {version, os, fmt, pkg_name?, pin_version?, url?, sha256?, size_bytes?, silent_args?, product_code?, detect_kind?, detect_value?, detect_min?}
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function addVersion(int $packageId, array $in, int $userId): array
    {
        if ($this->package($packageId) === null) {
            throw new \InvalidArgumentException('Package not found.');
        }
        $allowed = ['version', 'os', 'fmt', 'pkg_name', 'pin_version', 'url', 'sha256', 'size_bytes', 'silent_args', 'product_code', 'detect_kind', 'detect_value', 'detect_min'];
        if (array_diff(array_keys($in), $allowed) !== []) {
            throw new \InvalidArgumentException('Unknown field: ' . mb_substr((string) array_values(array_diff(array_keys($in), $allowed))[0], 0, 40) . '.');
        }
        $row = self::validateVersion($in, $this->sources);
        if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_package_versions WHERE package_id = ?', [$packageId]) >= self::MAX_VERSIONS_PER_PACKAGE) {
            throw new \InvalidArgumentException('At most ' . self::MAX_VERSIONS_PER_PACKAGE . ' versions per package.');
        }
        if ($this->sql->one('SELECT version_id FROM rmm_package_versions WHERE package_id = ? AND os = ? AND fmt = ? AND version = ?', [$packageId, $row['os'], $row['fmt'], $row['version']]) !== null) {
            throw new \InvalidArgumentException('That version exists already. A version cannot be changed: add a new one.');
        }
        $id = $this->sql->insert('INSERT INTO rmm_package_versions (package_id, version, os, fmt, pkg_name, pin_version, url, sha256, size_bytes, silent_args, product_code, detect_kind, detect_value, detect_min, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$packageId, $row['version'], $row['os'], $row['fmt'], $row['pkg_name'], $row['pin_version'], $row['url'], $row['sha256'], $row['size_bytes'],
            $row['silent_args'], $row['product_code'], $row['detect_kind'], $row['detect_value'], $row['detect_min'], $userId, $this->sql->utcNow()]);

        return $this->version($id) ?? [];
    }

    public function retireVersion(int $versionId, bool $retired): bool
    {
        $this->sql->run('UPDATE rmm_package_versions SET retired = ? WHERE version_id = ?', [$retired ? 1 : 0, $versionId]);

        return $this->version($versionId) !== null;
    }

    /**
     * Check and normalise a version. Pure apart from asking the source policy.
     *
     * @param array<string,mixed> $in
     * @return array{version:string,os:string,fmt:string,pkg_name:string,pin_version:int,url:string,sha256:string,size_bytes:int,silent_args:string,product_code:string,detect_kind:string,detect_value:string,detect_min:string}
     * @throws \InvalidArgumentException
     */
    public static function validateVersion(array $in, PackageSourcePolicyInterface $sources): array
    {
        $label = $in['version'] ?? null;
        if (!is_string($label) || preg_match(self::LABEL_RE, $label) !== 1) {
            throw new \InvalidArgumentException('version is 1 to 64 characters: letters, digits and . + ~ : _ - or a space.');
        }
        $os = $in['os'] ?? null;
        $fmt = $in['fmt'] ?? null;
        if (!is_string($os) || !in_array($os, DeployProtocol::OSES, true) || !is_string($fmt) || !in_array($fmt, DeployProtocol::FORMATS, true) || !DeployProtocol::formatFits($fmt, $os)) {
            throw new \InvalidArgumentException('os is windows or linux; fmt is msi or exe on Windows and apt or dnf on Linux.');
        }
        $row = ['version' => $label, 'os' => $os, 'fmt' => $fmt, 'pkg_name' => '', 'pin_version' => 0, 'url' => '', 'sha256' => '', 'size_bytes' => 0, 'silent_args' => '', 'product_code' => '',
            'detect_kind' => '', 'detect_value' => '', 'detect_min' => ''];
        if ($os === 'linux') {
            foreach (['url', 'sha256', 'size_bytes', 'silent_args', 'product_code', 'detect_kind', 'detect_value', 'detect_min'] as $k) {
                if (array_key_exists($k, $in)) {
                    throw new \InvalidArgumentException("$k does not apply to a Linux package: the machine's own package manager and its configured repositories supply the files.");
                }
            }
            $pkg = $in['pkg_name'] ?? null;
            if (!is_string($pkg) || preg_match(DeployProtocol::PACKAGE_RE, $pkg) !== 1) {
                throw new \InvalidArgumentException('pkg_name is a package name in lower case (letters, digits and + . _ -, not starting with a symbol).');
            }
            $pin = $in['pin_version'] ?? false;
            if (!is_bool($pin)) {
                throw new \InvalidArgumentException('pin_version is true or false.');
            }
            if ($pin && preg_match(DeployProtocol::PIN_RE, $label) !== 1) {
                throw new \InvalidArgumentException('To pin the version, the version text must be the exact package version (letters, digits and . + ~ : _ -, no spaces).');
            }
            $row['pkg_name'] = $pkg;
            $row['pin_version'] = $pin ? 1 : 0;
            $row['detect_kind'] = 'package';
            $row['detect_value'] = $pkg;
            $row['detect_min'] = $pin && preg_match(DeployProtocol::MIN_RE, $label) === 1 ? $label : '';

            return $row;
        }
        foreach (['pkg_name', 'pin_version'] as $k) {
            if (array_key_exists($k, $in)) {
                throw new \InvalidArgumentException("$k does not apply to a Windows installer.");
            }
        }
        $url = SourceUrl::normalise($in['url'] ?? null);
        if ($url === null) {
            throw new \InvalidArgumentException('url must be a plain https address: a host name (no IP address), the default port, no user information, no fragment, at most ' . DeployProtocol::URL_MAX . ' characters.');
        }
        if (str_contains($url, '?')) {
            throw new \InvalidArgumentException('An address with a query string is refused: it would travel in every job record, and a token in it would be readable by anyone who can read jobs. Serve the installer from a host that needs no token in the address (the hash pins its content).');
        }
        if (!$sources->allows($url, 0)) {
            throw new \InvalidArgumentException('The host of that address is not on the list of hosts installers may be downloaded from. An administrator adds it first.');
        }
        $sha = $in['sha256'] ?? null;
        $sha = is_string($sha) ? strtolower($sha) : null;
        if ($sha === null || preg_match(DeployProtocol::SHA256_RE, $sha) !== 1) {
            throw new \InvalidArgumentException('sha256 is the 64 hex digits of the installer\'s SHA-256 hash. It is required: the agent refuses a file that does not match.');
        }
        $size = $in['size_bytes'] ?? null;
        if (!is_int($size) || $size < 1 || $size > DeployProtocol::SIZE_MAX) {
            throw new \InvalidArgumentException('size_bytes is the exact size of the installer, 1 to ' . DeployProtocol::SIZE_MAX . ' bytes.');
        }
        $row['url'] = $url;
        $row['sha256'] = $sha;
        $row['size_bytes'] = $size;
        $code = $in['product_code'] ?? null;
        if ($fmt === 'msi') {
            if (!is_string($code) || preg_match(DeployProtocol::PRODUCT_CODE_RE, $code) !== 1) {
                throw new \InvalidArgumentException('An msi needs its product_code, such as {12345678-ABCD-1234-ABCD-1234567890AB}: it is how the agent detects and removes it.');
            }
            if (array_key_exists('silent_args', $in)) {
                throw new \InvalidArgumentException('An msi is installed with fixed options (/qn /norestart); silent_args is for an exe.');
            }
            $row['product_code'] = strtoupper($code);
            $row['detect_kind'] = 'product_code';
            $row['detect_value'] = strtoupper($code);
            $min = $in['detect_min'] ?? '';
            if (!is_string($min) || ($min !== '' && preg_match(DeployProtocol::MIN_RE, $min) !== 1)) {
                throw new \InvalidArgumentException('detect_min is a version such as 2.4.1, or empty.');
            }
            $row['detect_min'] = $min;
            foreach (['detect_kind', 'detect_value'] as $k) {
                if (array_key_exists($k, $in)) {
                    throw new \InvalidArgumentException("$k does not apply to an msi: the product code is the detection rule.");
                }
            }

            return $row;
        }
        if ($code !== null) {
            throw new \InvalidArgumentException('An exe has no product code.');
        }
        $args = $in['silent_args'] ?? '';
        if (!is_string($args) || !DeployProtocol::argsOk($args)) {
            throw new \InvalidArgumentException('silent_args may hold letters, digits, spaces and / \\ - _ = . : , + only (no quotes), at most ' . DeployProtocol::ARGS_MAX_TOKENS . ' words of '
                . DeployProtocol::ARGS_MAX_TOKEN_CHARS . ' characters, separated by single spaces.');
        }
        $dk = $in['detect_kind'] ?? null;
        $dv = $in['detect_value'] ?? null;
        $dm = $in['detect_min'] ?? '';
        if (!is_string($dk) || $dk === 'package' || !DeployProtocol::detectOk($dk, $dv, $dm, 'windows')) {
            throw new \InvalidArgumentException('An exe needs a detection rule: detect_kind product_code or uninstall_name, detect_value, and optionally detect_min.');
        }
        $row['silent_args'] = $args;
        $row['detect_kind'] = $dk;
        $row['detect_value'] = (string) $dv;
        $row['detect_min'] = (string) $dm;

        return $row;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function versionRow(array $r): array
    {
        return ['version_id' => (int) $r['version_id'], 'package_id' => (int) $r['package_id'], 'version' => (string) $r['version'], 'os' => (string) $r['os'], 'fmt' => (string) $r['fmt'],
            'pkg_name' => (string) $r['pkg_name'], 'pin_version' => (int) $r['pin_version'] === 1, 'url' => (string) $r['url'], 'sha256' => (string) $r['sha256'], 'size_bytes' => (int) $r['size_bytes'],
            'silent_args' => (string) $r['silent_args'], 'product_code' => (string) $r['product_code'], 'detect_kind' => (string) $r['detect_kind'], 'detect_value' => (string) $r['detect_value'],
            'detect_min' => (string) $r['detect_min'], 'retired' => (int) $r['retired'] === 1, 'created_at' => Sql::iso((string) $r['created_at'])];
    }
}
