<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Scripts;

use RivetCore\Rmm\Crypto\CanonicalJson;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * The script library (tables rmm_scripts_v2 and rmm_script_versions).
 *
 * A script has a name, a language, a platform, tags, an owner and flags (`requires_approval`, `destructive`); its text and parameter
 * definition live in immutable numbered versions. Publishing a version signs it with the instance's Ed25519 key (the one that signs jobs)
 * over the canonical JSON of {script id, version, language, platform, SHA-256 of the body, SHA-256 of the definition}; {@see load()} verifies
 * that signature and the hash before anything runs, so a script edited directly in the database is refused, not executed. Bodies are
 * never written to the audit log: callers record {@see bodyHash()} instead.
 *
 * Every method throws \InvalidArgumentException with a message that is safe to show.
 *
 * @api
 */
final class ScriptService
{
    public const NAME_MAX = 100;
    public const MAX_SCRIPTS = 2000;
    public const MAX_VERSIONS = 200;
    public const MAX_TAGS = 10;

    public function __construct(private readonly Sql $sql, private readonly RmmSettings $settings)
    {
    }

    public static function bodyHash(string $body): string
    {
        return hash('sha256', $body);
    }

    /**
     * Create a script and publish its first version.
     *
     * @param array<string,mixed> $in {name, description?, language, body, params?, tags?, requires_approval?, destructive?, timeout_s?, note?}
     * @return array<string,mixed> the script with its current version (body included)
     */
    public function create(array $in, int $userId): array
    {
        $name = self::cleanName($in['name'] ?? null);
        $language = $in['language'] ?? null;
        if (!is_string($language) || !ScriptLanguage::valid($language)) {
            throw new \InvalidArgumentException('language must be ' . implode(', ', ScriptLanguage::all()) . '.');
        }
        $meta = $this->meta($in, null);
        [$body, $schema] = $this->content($language, $in['body'] ?? null, $in['params'] ?? null);
        if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_scripts_v2') >= self::MAX_SCRIPTS) {
            throw new \InvalidArgumentException('The library holds at most ' . self::MAX_SCRIPTS . ' scripts.');
        }
        if ($this->sql->one('SELECT script_id FROM rmm_scripts_v2 WHERE name = ?', [$name]) !== null) {
            throw new \InvalidArgumentException('A script with that name exists.');
        }
        $now = $this->sql->utcNow();
        $note = self::text($in['note'] ?? '', 200);

        return $this->sql->transaction(function () use ($name, $language, $meta, $body, $schema, $userId, $now, $note): array {
            $id = $this->sql->insert('INSERT INTO rmm_scripts_v2 (name, description, language, platform, tags_json, owner_id, requires_approval, destructive, timeout_s, current_version, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)', [$name, $meta['description'], $language, ScriptLanguage::defaultPlatform($language), (string) json_encode($meta['tags']), $userId,
                $meta['requires_approval'] ? 1 : 0, $meta['destructive'] ? 1 : 0, $meta['timeout_s'], $now, $now]);
            $this->publish($id, 1, $language, $body, $schema, $note, $userId, $now);

            return $this->get($id, true) ?? [];
        });
    }

    /**
     * Change a script. A new `body` or `params` publishes a new version; description, tags, flags and timeout change in place (they are not part
     * of the signed text but of the script's policy, and apply to every version).
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function update(int $scriptId, array $in, int $userId): array
    {
        return $this->sql->transaction(function () use ($scriptId, $in, $userId): array {
            $cur = $this->sql->one('SELECT * FROM rmm_scripts_v2 WHERE script_id = ? FOR UPDATE', [$scriptId]);
            if ($cur === null) {
                throw new \InvalidArgumentException('Script not found.');
            }
            if ($cur['retired_at'] !== null) {
                throw new \InvalidArgumentException('A retired script cannot be changed. Restore it first.');
            }
            $now = $this->sql->utcNow();
            $name = array_key_exists('name', $in) ? self::cleanName($in['name']) : (string) $cur['name'];
            if ($name !== $cur['name'] && $this->sql->one('SELECT script_id FROM rmm_scripts_v2 WHERE name = ? AND script_id <> ?', [$name, $scriptId]) !== null) {
                throw new \InvalidArgumentException('A script with that name exists.');
            }
            $meta = $this->meta($in, $cur);
            $this->sql->run('UPDATE rmm_scripts_v2 SET name = ?, description = ?, tags_json = ?, requires_approval = ?, destructive = ?, timeout_s = ?, updated_at = ? WHERE script_id = ?',
                [$name, $meta['description'], (string) json_encode($meta['tags']), $meta['requires_approval'] ? 1 : 0, $meta['destructive'] ? 1 : 0, $meta['timeout_s'], $now, $scriptId]);
            if (array_key_exists('body', $in) || array_key_exists('params', $in)) {
                $current = $this->versionRow($scriptId, (int) $cur['current_version']);
                $language = (string) $cur['language'];
                $bodyIn = array_key_exists('body', $in) ? $in['body'] : ($current['body'] ?? null);
                $paramsIn = array_key_exists('params', $in) ? $in['params'] : json_decode((string) ($current['params_schema_json'] ?? '[]'), true);
                [$body, $schema] = $this->content($language, $bodyIn, $paramsIn);
                $same = $current !== null && self::bodyHash($body) === $current['body_sha256'] && self::schemaHash($schema) === self::schemaHash(self::decodeSchema($current['params_schema_json']));
                if (!$same) {
                    $next = (int) $cur['current_version'] + 1;
                    if ($next > self::MAX_VERSIONS) {
                        throw new \InvalidArgumentException('A script keeps at most ' . self::MAX_VERSIONS . ' versions.');
                    }
                    $this->publish($scriptId, $next, $language, $body, $schema, self::text($in['note'] ?? '', 200), $userId, $now);
                }
            }

            return $this->get($scriptId, true) ?? [];
        });
    }

    /** Retire a script (it can no longer be run or scheduled, its history stays) or restore it. */
    public function setRetired(int $scriptId, bool $retired): bool
    {
        $at = $retired ? $this->sql->utcNow() : null;

        return $this->sql->run('UPDATE rmm_scripts_v2 SET retired_at = ?, updated_at = ? WHERE script_id = ? AND ((retired_at IS NULL) = ?)', [$at, $this->sql->utcNow(), $scriptId, $retired ? 1 : 0]) === 1;
    }

    /**
     * @return array<string,mixed>|null the script row as the API shows it; with $withBody also the current version's text
     */
    public function get(int $scriptId, bool $withBody = false): ?array
    {
        $r = $this->sql->one('SELECT * FROM rmm_scripts_v2 WHERE script_id = ?', [$scriptId]);
        if ($r === null) {
            return null;
        }
        $out = self::row($r);
        $v = $this->versionRow($scriptId, (int) $r['current_version']);
        if ($v !== null) {
            $out['current'] = self::versionOut($v, $withBody);
        }

        return $out;
    }

    /**
     * @param array{q?:string,language?:string,tag?:string,include_retired?:bool,limit?:int,offset?:int} $f
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function all(array $f = []): array
    {
        $where = [];
        $p = [];
        if (empty($f['include_retired'])) {
            $where[] = 's.retired_at IS NULL';
        }
        if (!empty($f['q'])) {
            $where[] = '(s.name LIKE ? OR s.description LIKE ?)';
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            array_push($p, $like, $like);
        }
        if (!empty($f['language'])) {
            $where[] = 's.language = ?';
            $p[] = (string) $f['language'];
        }
        if (!empty($f['tag'])) {
            $where[] = 's.tags_json LIKE ?';
            $p[] = '%' . addcslashes((string) json_encode((string) $f['tag'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '%_\\') . '%';
        }
        $w = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM rmm_scripts_v2 s$w", $p);
        $limit = max(1, min(500, (int) ($f['limit'] ?? 100)));
        $offset = max(0, (int) ($f['offset'] ?? 0));
        $items = [];
        foreach ($this->sql->all("SELECT s.* FROM rmm_scripts_v2 s$w ORDER BY s.name LIMIT $limit OFFSET $offset", $p) as $r) {
            $items[] = self::row($r);
        }

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<array<string,mixed>> newest first, without bodies */
    public function versions(int $scriptId): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT * FROM rmm_script_versions WHERE script_id = ? ORDER BY version DESC', [$scriptId]) as $r) {
            $out[] = self::versionOut($r, false);
        }

        return $out;
    }

    /** @return array<string,mixed>|null one version, body included */
    public function version(int $scriptId, int $version): ?array
    {
        $r = $this->versionRow($scriptId, $version);

        return $r === null ? null : self::versionOut($r, true);
    }

    /**
     * The script and one version ready to run, after the integrity check. `$version` null means the current version.
     *
     * @return array{0:?array{script:array<string,mixed>,version:array<string,mixed>,schema:list<array<string,mixed>>},1:?string} [loaded, error]
     */
    public function load(int $scriptId, ?int $version = null): array
    {
        $s = $this->sql->one('SELECT * FROM rmm_scripts_v2 WHERE script_id = ?', [$scriptId]);
        if ($s === null) {
            return [null, 'Script not found.'];
        }
        if ($s['retired_at'] !== null) {
            return [null, 'That script is retired.'];
        }
        $v = $this->versionRow($scriptId, $version ?? (int) $s['current_version']);
        if ($v === null) {
            return [null, 'That script version does not exist.'];
        }
        $err = $this->integrity($s, $v);
        if ($err !== null) {
            return [null, $err];
        }

        return [['script' => $s, 'version' => $v, 'schema' => self::decodeSchema($v['params_schema_json'])], null];
    }

    /**
     * Re-sign a version (or every version) with the CURRENT instance key: needed after the signing key was rotated, because a script signed by
     * the old key no longer verifies. Hashes are recomputed from the stored text; an administrator vouches for the text by calling this.
     */
    public function resign(int $scriptId, ?int $version = null): int
    {
        $s = $this->sql->one('SELECT * FROM rmm_scripts_v2 WHERE script_id = ?', [$scriptId]);
        if ($s === null) {
            throw new \InvalidArgumentException('Script not found.');
        }
        $n = 0;
        foreach ($this->sql->all('SELECT * FROM rmm_script_versions WHERE script_id = ?' . ($version === null ? '' : ' AND version = ?'), $version === null ? [$scriptId] : [$scriptId, $version]) as $v) {
            [$sig, $keyId] = $this->sign($scriptId, (int) $v['version'], (string) $s['language'], (string) $s['platform'], self::bodyHash((string) $v['body']), self::schemaHash(self::decodeSchema($v['params_schema_json'])));
            $this->sql->run('UPDATE rmm_script_versions SET body_sha256 = ?, signature = ?, signing_key_id = ? WHERE script_id = ? AND version = ?', [self::bodyHash((string) $v['body']), $sig, $keyId, $scriptId, $v['version']]);
            ++$n;
        }

        return $n;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $v
     */
    private function integrity(array $s, array $v): ?string
    {
        $bodyHash = self::bodyHash((string) $v['body']);
        if (!hash_equals((string) $v['body_sha256'], $bodyHash)) {
            return 'The stored script text does not match its recorded hash. It was not run.';
        }
        try {
            [, $pub] = $this->settings->signingKey();
        } catch (\RuntimeException) {
            return 'The instance signing key is not available.';
        }
        $msg = self::signedMessage((int) $s['script_id'], (int) $v['version'], (string) $s['language'], (string) $s['platform'], $bodyHash, self::schemaHash(self::decodeSchema($v['params_schema_json'])));
        if (!Signer::verify($msg, (string) $v['signature'], $pub)) {
            return 'The script signature does not verify (edited outside the library, or signed with an earlier key: ask an administrator to re-sign it). It was not run.';
        }

        return null;
    }

    /** @param list<array<string,mixed>> $schema */
    private function publish(int $scriptId, int $version, string $language, string $body, array $schema, string $note, int $userId, string $now): void
    {
        $platform = ScriptLanguage::defaultPlatform($language);
        $hash = self::bodyHash($body);
        [$sig, $keyId] = $this->sign($scriptId, $version, $language, $platform, $hash, self::schemaHash($schema));
        $this->sql->run('INSERT INTO rmm_script_versions (script_id, version, body, body_sha256, params_schema_json, signature, signing_key_id, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$scriptId, $version, $body, $hash, (string) json_encode($schema), $sig, $keyId, $note, $userId, $now]);
        $this->sql->run('UPDATE rmm_scripts_v2 SET current_version = ?, updated_at = ? WHERE script_id = ?', [$version, $now, $scriptId]);
    }

    /** @return array{0:string,1:string} [signature, key id] */
    private function sign(int $scriptId, int $version, string $language, string $platform, string $bodyHash, string $schemaHash): array
    {
        try {
            [$sec, , $keyId] = $this->settings->signingKey();
        } catch (\RuntimeException) {
            throw new \InvalidArgumentException('The instance signing key is not available: generate it in the module settings first.');
        }

        return [Signer::sign(self::signedMessage($scriptId, $version, $language, $platform, $bodyHash, $schemaHash), $sec), $keyId];
    }

    public static function signedMessage(int $scriptId, int $version, string $language, string $platform, string $bodySha256, string $schemaSha256): string
    {
        return CanonicalJson::encode(['kind' => 'rmm_script', 'v' => 1, 'script_id' => $scriptId, 'version' => $version, 'language' => $language, 'platform' => $platform,
            'body_sha256' => $bodySha256, 'schema_sha256' => $schemaSha256]);
    }

    /** @param list<array<string,mixed>> $schema */
    public static function schemaHash(array $schema): string
    {
        return hash('sha256', CanonicalJson::encode($schema));
    }

    /** @return list<array<string,mixed>> */
    public static function decodeSchema(mixed $json): array
    {
        $d = is_string($json) && $json !== '' ? json_decode($json, true) : [];

        /** @var list<array<string,mixed>> $out */
        $out = is_array($d) && array_is_list($d) ? $d : [];

        return $out;
    }

    /**
     * @return array{0:string,1:list<array<string,mixed>>} [validated body, normalised parameter definition]
     */
    private function content(string $language, mixed $body, mixed $params): array
    {
        if (!is_string($body) || trim($body) === '' || str_contains($body, "\0") || !mb_check_encoding($body, 'UTF-8')) {
            throw new \InvalidArgumentException('A script needs text (UTF-8, no NUL bytes).');
        }
        if (strlen($body) > RmmProtocol::JOB_MAX_SCRIPT_BYTES) {
            throw new \InvalidArgumentException('A script is at most ' . RmmProtocol::JOB_MAX_SCRIPT_BYTES . ' bytes.');
        }
        if ($language === ScriptLanguage::POWERSHELL && mb_strlen($body) > ScriptLanguage::POWERSHELL_MAX_CHARS) {
            throw new \InvalidArgumentException('A PowerShell script is at most ' . ScriptLanguage::POWERSHELL_MAX_CHARS . ' characters (it travels in an encoded command line).');
        }
        [$schema, $err] = ParamSchema::normalizeSchema($params);
        if ($err !== null || $schema === null) {
            throw new \InvalidArgumentException($err ?? 'Invalid parameters.');
        }

        return [$body, $schema];
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed>|null $cur the stored row when updating
     * @return array{description:string,tags:list<string>,requires_approval:bool,destructive:bool,timeout_s:int}
     */
    private function meta(array $in, ?array $cur): array
    {
        $cfg = $this->settings->get();
        $desc = array_key_exists('description', $in) ? self::text($in['description'], 500) : (string) ($cur['description'] ?? '');
        $tags = $cur === null ? [] : (json_decode((string) ($cur['tags_json'] ?? '[]'), true) ?: []);
        if (array_key_exists('tags', $in)) {
            $t = $in['tags'];
            if (!is_array($t) || !array_is_list($t) || count($t) > self::MAX_TAGS) {
                throw new \InvalidArgumentException('Tags are a list of at most ' . self::MAX_TAGS . ' words.');
            }
            $tags = [];
            foreach ($t as $x) {
                if (!is_string($x) || preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.:-]{0,29}$/', trim($x)) !== 1) {
                    throw new \InvalidArgumentException('A tag is 1 to 30 letters, digits, spaces and _ . : -.');
                }
                $tags[strtolower(trim($x))] = trim($x);
            }
            $tags = array_values($tags);
        }
        $timeout = array_key_exists('timeout_s', $in) ? $in['timeout_s'] : ($cur['timeout_s'] ?? $cfg['job_default_timeout_s']);
        if (!is_int($timeout) && !(is_string($timeout) && ctype_digit($timeout)) && !is_numeric($timeout)) {
            throw new \InvalidArgumentException('timeout_s is a whole number of seconds.');
        }
        $timeout = (int) $timeout;
        if ($timeout < 1 || $timeout > (int) $cfg['job_max_timeout_s']) {
            throw new \InvalidArgumentException('timeout_s is 1 to ' . (int) $cfg['job_max_timeout_s'] . '.');
        }

        /** @var list<string> $tags */
        return ['description' => $desc, 'tags' => $tags,
            'requires_approval' => array_key_exists('requires_approval', $in) ? (bool) $in['requires_approval'] : (bool) ($cur['requires_approval'] ?? false),
            'destructive' => array_key_exists('destructive', $in) ? (bool) $in['destructive'] : (bool) ($cur['destructive'] ?? false),
            'timeout_s' => $timeout];
    }

    /** @return array<string,mixed>|null */
    private function versionRow(int $scriptId, int $version): ?array
    {
        return $this->sql->one('SELECT * FROM rmm_script_versions WHERE script_id = ? AND version = ?', [$scriptId, $version]);
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function row(array $r): array
    {
        $tags = json_decode((string) ($r['tags_json'] ?? '[]'), true);

        return ['script_id' => (int) $r['script_id'], 'name' => (string) $r['name'], 'description' => (string) $r['description'], 'language' => (string) $r['language'],
            'platform' => (string) $r['platform'], 'tags' => is_array($tags) ? array_values($tags) : [], 'owner_id' => (int) $r['owner_id'],
            'requires_approval' => (int) $r['requires_approval'] === 1, 'destructive' => (int) $r['destructive'] === 1, 'timeout_s' => (int) $r['timeout_s'],
            'current_version' => (int) $r['current_version'], 'retired' => $r['retired_at'] !== null, 'updated_at' => Sql::iso((string) $r['updated_at'])];
    }

    /**
     * @param array<string,mixed> $v
     * @return array<string,mixed>
     */
    private static function versionOut(array $v, bool $withBody): array
    {
        $out = ['version' => (int) $v['version'], 'body_sha256' => (string) $v['body_sha256'], 'params' => self::decodeSchema($v['params_schema_json']), 'signing_key_id' => (string) $v['signing_key_id'],
            'note' => (string) $v['note'], 'created_by' => (int) $v['created_by'], 'created_at' => Sql::iso((string) $v['created_at']), 'size_bytes' => strlen((string) $v['body'])];
        if ($withBody) {
            $out['body'] = (string) $v['body'];
        }

        return $out;
    }

    private static function cleanName(mixed $v): string
    {
        $s = is_string($v) ? trim($v) : '';
        if ($s === '' || mb_strlen($s) > self::NAME_MAX || preg_match('/[\x00-\x1f]/', $s) === 1) {
            throw new \InvalidArgumentException('A script needs a name of 1 to ' . self::NAME_MAX . ' characters.');
        }

        return $s;
    }

    private static function text(mixed $v, int $max): string
    {
        $s = is_string($v) ? trim($v) : '';
        if (mb_strlen($s) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $s) === 1) {
            throw new \InvalidArgumentException("Text is limited to $max characters.");
        }

        return $s;
    }
}
