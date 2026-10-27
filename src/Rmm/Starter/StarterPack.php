<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Starter;

use RivetCore\Rmm\Policy\PolicySettings;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Scripts\ParamSchema;
use RivetCore\Rmm\Scripts\ScriptLanguage;
use RivetCore\Rmm\Scripts\ScriptService;

/**
 * A versioned, checksummed bundle of ready-made RMM content: library scripts, check template sets and starter policy drafts.
 *
 * The bundled pack lives in `data/` next to this class. A pack is a directory:
 *
 *   pack.json                        {format: 1, id, name, version, description}
 *   scripts/<platform>/<slug>.json   one script's metadata (id, name, description, platform, language, risk, tags, timeout_s, params, output)
 *   scripts/<platform>/<slug>.ps1|.sh  the script text, one file per script
 *   checks.json                      {sets: [check template set, ...]}
 *   checks/<file>                    script text of script checks, referenced from checks.json
 *   policies.json                    {policies: [policy draft, ...]}
 *   manifest.json                    {format, pack, version, files: {relative path: sha256}, pack_sha256}
 *
 * Loading verifies the manifest (every file present with its recorded SHA-256, no file the manifest does not list), validates every item against the
 * rules of the library ({@see ParamSchema}, {@see PolicySettings}), refuses a script that fails {@see StarterLint}, and checks the safety rules of the
 * pack: a script that changes the machine defaults to a dry run, a disruptive script requires approval unless the author says otherwise, ids and names are
 * unique. A pack that does not pass is refused as a whole with a message that names the file.
 *
 * `scripts/build-starter-pack.php` rewrites `manifest.json` (and the public catalog) after a pack's files change.
 *
 * @api
 */
final class StarterPack
{
    public const FORMAT = 1;
    public const RISKS = ['read_only', 'changes_system', 'disruptive'];
    /** requires_approval when the author does not say. */
    public const APPROVAL_DEFAULT = ['read_only' => false, 'changes_system' => false, 'disruptive' => true];
    public const PLATFORMS = ['windows', 'linux'];
    public const ID_RE = '/^[a-z0-9][a-z0-9-]{2,59}$/';
    public const PACK_ID_RE = '/^[a-z0-9][a-z0-9-]{2,29}$/';
    /** Tags the installer adds itself; an author does not set them. */
    public const AUTO_TAGS = ['starter'];
    public const MAX_AUTHOR_TAGS = 6;

    /** @var array<string,self> */
    private static array $cache = [];

    /** @var array<string,mixed> */
    private array $meta;
    /** @var array<string,string> */
    private array $files;
    /** @var array<string,array<string,mixed>> id => script */
    private array $scripts = [];
    /** @var array<string,array<string,mixed>> */
    private array $sets = [];
    /** @var array<string,array<string,mixed>> */
    private array $policies = [];

    private function __construct(private readonly string $dir)
    {
        $this->meta = [];
        $this->files = [];
    }

    /** The pack that ships inside the package. */
    public static function bundled(): self
    {
        return self::fromDirectory(__DIR__ . '/data');
    }

    /**
     * @throws \InvalidArgumentException when the pack is not valid; the message names the file
     */
    public static function fromDirectory(string $dir): self
    {
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException("Starter pack folder not found: $dir");
        }
        if (isset(self::$cache[$real])) {
            return self::$cache[$real];
        }
        $p = new self($real);
        $p->load();

        return self::$cache[$real] = $p;
    }

    /** Forget the loaded packs (tests that change a pack on disk). */
    public static function forget(): void
    {
        self::$cache = [];
    }

    // ------------------------------------------------------------------ identity

    public function id(): string
    {
        return (string) $this->meta['id'];
    }

    public function name(): string
    {
        return (string) $this->meta['name'];
    }

    public function version(): string
    {
        return (string) $this->meta['version'];
    }

    public function description(): string
    {
        return (string) ($this->meta['description'] ?? '');
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /** SHA-256 over the sorted `path:hash` lines of every file: one value that changes when anything in the pack changes. */
    public function packHash(): string
    {
        return self::hashOfFiles($this->files);
    }

    // ------------------------------------------------------------------ content

    /** @return list<array<string,mixed>> every script, text included */
    public function scripts(): array
    {
        return array_values($this->scripts);
    }

    /** @return array<string,mixed>|null */
    public function script(string $id): ?array
    {
        return $this->scripts[$id] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function checkSets(): array
    {
        return array_values($this->sets);
    }

    /** @return list<array<string,mixed>> the policy drafts as defined (see {@see resolvePolicy()} for the settings an install writes) */
    public function policies(): array
    {
        return array_values($this->policies);
    }

    /** @return array<string,mixed>|null */
    public function policy(string $id): ?array
    {
        return $this->policies[$id] ?? null;
    }

    /**
     * What the pack offers, without any script text: the catalog the API serves and the website lists.
     *
     * @return array<string,mixed>
     */
    public function catalog(): array
    {
        $scripts = [];
        foreach ($this->scripts as $s) {
            unset($s['body']);
            $scripts[] = $s;
        }
        $sets = [];
        foreach ($this->sets as $set) {
            $sets[] = ['id' => $set['id'], 'name' => $set['name'], 'description' => $set['description'], 'platform' => $set['platform'], 'use' => $set['use'],
                'params' => $set['params'], 'checks' => array_map(static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label'], 'type' => $c['type'], 'interval_s' => $c['interval_s'], 'when' => $c['when'] ?? null], $set['checks'])];
        }
        $policies = [];
        foreach ($this->policies as $p) {
            $policies[] = ['id' => $p['id'], 'name' => $p['name'], 'description' => $p['description'], 'platform' => $p['platform'], 'check_sets' => $p['check_sets'],
                'params' => $this->policyParams($p), 'content_hash' => self::policyHash($this->resolvePolicy($p, $this->exampleParams($p))['settings'])];
        }

        return ['pack' => ['id' => $this->id(), 'name' => $this->name(), 'version' => $this->version(), 'description' => $this->description(), 'pack_sha256' => $this->packHash()],
            'counts' => ['scripts' => count($scripts), 'windows' => count(array_filter($scripts, static fn (array $s): bool => $s['platform'] === 'windows')),
                'linux' => count(array_filter($scripts, static fn (array $s): bool => $s['platform'] === 'linux')), 'check_sets' => count($sets), 'policies' => count($policies)],
            'scripts' => $scripts, 'check_sets' => $sets, 'policies' => $policies];
    }

    /**
     * The params a policy draft asks for: those of its check sets, by name.
     *
     * @param array<string,mixed> $policy
     * @return list<array<string,mixed>>
     */
    public function policyParams(array $policy): array
    {
        $out = [];
        foreach ((array) $policy['check_sets'] as $sid) {
            foreach ((array) ($this->sets[(string) $sid]['params'] ?? []) as $p) {
                $out[(string) $p['name']] = $p;
            }
        }

        return array_values($out);
    }

    /**
     * The settings of a policy draft with the given parameter values. A check whose `when` parameter is empty is left out and listed in `omitted`.
     * Values are validated like any policy body, so a bad parameter value is an \InvalidArgumentException.
     *
     * @param array<string,mixed> $policy one of {@see policies()}
     * @param array<string,mixed> $params parameter name => value (missing = the parameter's default)
     * @return array{settings:array<string,array{mode:string,value:mixed}>,omitted:list<string>,params:array<string,mixed>}
     */
    public function resolvePolicy(array $policy, array $params = []): array
    {
        $values = [];
        foreach ($this->policyParams($policy) as $def) {
            $name = (string) $def['name'];
            $v = array_key_exists($name, $params) ? $params[$name] : ($def['default'] ?? null);
            $values[$name] = self::paramValue($def, $v);
        }
        $settings = [];
        $omitted = [];
        foreach ((array) $policy['settings'] as $k => $v) {
            $settings[(string) $k] = $v;
        }
        foreach ((array) $policy['check_sets'] as $sid) {
            foreach ((array) $this->sets[(string) $sid]['checks'] as $c) {
                $when = $c['when'] ?? null;
                if (is_string($when) && ($values[$when] ?? null) === null) {
                    $omitted[] = (string) $c['key'];
                    continue;
                }
                $one = ['type' => $c['type'], 'params' => self::substitute($c['params'], $values, $this->dir), 'interval_s' => $c['interval_s']];
                $settings['check.' . $c['key']] = ['mode' => 'override', 'value' => $one];
            }
        }
        [$norm, $err] = PolicySettings::normalize($settings);
        if ($err !== null || $norm === null) {
            throw new \InvalidArgumentException($err ?? 'Invalid policy settings.');
        }

        return ['settings' => $norm, 'omitted' => $omitted, 'params' => $values];
    }

    /** @param array<string,array{mode:string,value:mixed}> $settings */
    public static function policyHash(array $settings): string
    {
        return hash('sha256', (string) json_encode($settings === [] ? new \stdClass() : $settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The hash that says whether an installed script version is the pack's: language, platform, SHA-256 of the text and of the parameter definition.
     *
     * @param list<array<string,mixed>> $params a normalised parameter definition
     */
    public static function scriptHash(string $language, string $platform, string $body, array $params): string
    {
        return hash('sha256', $language . '|' . $platform . '|' . hash('sha256', $body) . '|' . ScriptService::schemaHash($params));
    }

    // ------------------------------------------------------------------ manifest

    /**
     * The manifest a pack folder should carry: every file but `manifest.json` with its SHA-256.
     *
     * @return array<string,mixed>
     */
    public static function buildManifest(string $dir): array
    {
        $files = self::scan($dir);
        $pack = json_decode((string) file_get_contents($dir . '/pack.json'), true);
        if (!is_array($pack)) {
            throw new \InvalidArgumentException('pack.json is not valid JSON.');
        }

        return ['format' => self::FORMAT, 'pack' => (string) ($pack['id'] ?? ''), 'version' => (string) ($pack['version'] ?? ''), 'files' => $files, 'pack_sha256' => self::hashOfFiles($files)];
    }

    /** @param array<string,string> $files */
    private static function hashOfFiles(array $files): string
    {
        ksort($files, SORT_STRING);
        $lines = '';
        foreach ($files as $path => $hash) {
            $lines .= $path . ':' . $hash . "\n";
        }

        return hash('sha256', $lines);
    }

    /** @return array<string,string> relative path => sha256 of every file but manifest.json */
    private static function scan(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f instanceof \SplFileInfo || !$f->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            if ($rel === 'manifest.json') {
                continue;
            }
            $out[$rel] = hash_file('sha256', $f->getPathname()) ?: '';
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    // ------------------------------------------------------------------ loading

    private function load(): void
    {
        $pack = $this->json('pack.json');
        if (($pack['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException('pack.json: format must be ' . self::FORMAT . '.');
        }
        if (!is_string($pack['id'] ?? null) || preg_match(self::PACK_ID_RE, $pack['id']) !== 1) {
            throw new \InvalidArgumentException('pack.json: id is 3 to 30 lower case letters, digits and -.');
        }
        if (!is_string($pack['version'] ?? null) || preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $pack['version']) !== 1) {
            throw new \InvalidArgumentException('pack.json: version is MAJOR.MINOR.PATCH.');
        }
        if (!is_string($pack['name'] ?? null) || $pack['name'] === '' || mb_strlen($pack['name']) > 100) {
            throw new \InvalidArgumentException('pack.json: name is 1 to 100 characters.');
        }
        $this->meta = $pack;

        $manifest = $this->json('manifest.json');
        $listed = $manifest['files'] ?? null;
        if (($manifest['format'] ?? null) !== self::FORMAT || !is_array($listed)) {
            throw new \InvalidArgumentException('manifest.json: not a manifest of this format.');
        }
        $actual = self::scan($this->dir);
        foreach ($listed as $path => $hash) {
            if (!isset($actual[(string) $path])) {
                throw new \InvalidArgumentException("manifest.json lists $path, which is missing.");
            }
            if (!is_string($hash) || !hash_equals($hash, $actual[(string) $path])) {
                throw new \InvalidArgumentException("$path does not match the checksum in manifest.json.");
            }
        }
        foreach (array_keys($actual) as $path) {
            if (!isset($listed[$path])) {
                throw new \InvalidArgumentException("$path is not listed in manifest.json (run scripts/build-starter-pack.php).");
            }
        }
        $this->files = $actual;
        if (($manifest['pack'] ?? null) !== $pack['id'] || ($manifest['version'] ?? null) !== $pack['version'] || ($manifest['pack_sha256'] ?? null) !== self::hashOfFiles($actual)) {
            throw new \InvalidArgumentException('manifest.json does not describe this pack and version.');
        }

        foreach (array_keys($this->files) as $path) {
            if (str_starts_with($path, 'checks/')) {
                foreach (StarterLint::check((string) file_get_contents($this->dir . '/' . $path), 'read_only', str_ends_with($path, '.ps1') ? 'powershell' : 'bash') as [$label, $text]) {
                    throw new \InvalidArgumentException("$path: $label ($text)");
                }
            }
        }
        $this->loadScripts();
        $this->loadSets();
        $this->loadPolicies();
    }

    private function loadScripts(): void
    {
        $names = [];
        foreach (array_keys($this->files) as $path) {
            if (preg_match('#^scripts/(windows|linux)/([a-z0-9-]{3,50})\.json$#', $path, $m) !== 1) {
                continue;
            }
            $d = $this->json($path);
            $s = $this->script0($path, $m[1], $m[2], $d);
            if (isset($this->scripts[$s['id']])) {
                throw new \InvalidArgumentException("$path: the id {$s['id']} is used twice.");
            }
            if (isset($names[mb_strtolower($s['name'])])) {
                throw new \InvalidArgumentException("$path: the name {$s['name']} is used twice.");
            }
            $names[mb_strtolower($s['name'])] = 1;
            $this->scripts[$s['id']] = $s;
        }
        ksort($this->scripts, SORT_STRING);
        foreach (array_keys($this->files) as $path) {
            if (preg_match('#^scripts/(windows|linux)/([a-z0-9-]{3,50})\.(ps1|sh)$#', $path, $m) === 1 && !isset($this->files["scripts/{$m[1]}/{$m[2]}.json"])) {
                throw new \InvalidArgumentException("$path has no metadata file.");
            }
        }
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function script0(string $path, string $platform, string $slug, array $d): array
    {
        $fail = static fn (string $why): \InvalidArgumentException => new \InvalidArgumentException("$path: $why");
        $known = ['id', 'name', 'description', 'platform', 'language', 'risk', 'tags', 'timeout_s', 'params', 'output', 'requires_approval'];
        foreach (array_keys($d) as $k) {
            if (!in_array($k, $known, true)) {
                throw $fail("unknown member $k");
            }
        }
        $id = $d['id'] ?? null;
        if (!is_string($id) || preg_match(self::ID_RE, $id) !== 1) {
            throw $fail('id is 3 to 60 lower case letters, digits and -');
        }
        $name = $d['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 90 || preg_match('/[\x00-\x1f]/', $name) === 1) {
            throw $fail('name is 1 to 90 characters');
        }
        $desc = $d['description'] ?? null;
        if (!is_string($desc) || $desc === '' || mb_strlen($desc) > 300) {
            throw $fail('description is 1 to 300 characters');
        }
        if (($d['platform'] ?? null) !== $platform) {
            throw $fail("platform must be $platform");
        }
        $language = $d['language'] ?? null;
        if (!is_string($language) || !ScriptLanguage::valid($language) || ScriptLanguage::defaultPlatform($language) !== $platform) {
            throw $fail('language does not run on ' . $platform);
        }
        $risk = $d['risk'] ?? null;
        if (!is_string($risk) || !in_array($risk, self::RISKS, true)) {
            throw $fail('risk must be ' . implode(', ', self::RISKS));
        }
        $tags = $d['tags'] ?? [];
        if (!is_array($tags) || !array_is_list($tags) || $tags === [] || count($tags) > self::MAX_AUTHOR_TAGS) {
            throw $fail('tags are 1 to ' . self::MAX_AUTHOR_TAGS . ' words');
        }
        foreach ($tags as $t) {
            if (!is_string($t) || preg_match('/^[a-z0-9][a-z0-9 _.:-]{0,29}$/', $t) !== 1 || $t === 'starter' || str_starts_with($t, 'risk:')) {
                throw $fail('a tag is 1 to 30 lower case letters, digits and _ . : - (not "starter" or "risk:...")');
            }
        }
        $timeout = $d['timeout_s'] ?? null;
        if (!is_int($timeout) || $timeout < 10 || $timeout > 3600) {
            throw $fail('timeout_s is 10 to 3600');
        }
        [$params, $err] = ParamSchema::normalizeSchema($d['params'] ?? []);
        if ($params === null) {
            throw $fail((string) $err);
        }
        $byName = [];
        foreach ($params as $p) {
            $byName[(string) $p['name']] = $p;
        }
        if ($risk !== 'read_only') {
            $dry = $byName['dry_run'] ?? null;
            if ($dry === null || $dry['type'] !== 'bool' || ($dry['default'] ?? null) !== true) {
                throw $fail('a script that changes the machine needs a bool parameter dry_run whose default is true');
            }
        }
        $output = $d['output'] ?? null;
        if (!is_string($output) || $output === '' || mb_strlen($output) > 400) {
            throw $fail('output is 1 to 400 characters');
        }
        $approval = array_key_exists('requires_approval', $d) ? $d['requires_approval'] : self::APPROVAL_DEFAULT[$risk];
        if (!is_bool($approval)) {
            throw $fail('requires_approval is true or false');
        }
        if ($risk === 'disruptive' && $approval === false) {
            throw $fail('a disruptive script requires approval');
        }
        $ext = $language === ScriptLanguage::POWERSHELL ? 'ps1' : 'sh';
        $bodyPath = "scripts/$platform/$slug.$ext";
        if (!isset($this->files[$bodyPath])) {
            throw $fail("$bodyPath is missing");
        }
        $body = (string) file_get_contents($this->dir . '/' . $bodyPath);
        if ($body === '' || str_contains($body, "\0") || !mb_check_encoding($body, 'UTF-8') || str_contains($body, "\r")) {
            throw $fail("$bodyPath must be UTF-8 text with LF line ends and no NUL bytes");
        }
        $max = $language === ScriptLanguage::POWERSHELL ? 8500 : 60000;
        if (($language === ScriptLanguage::POWERSHELL ? mb_strlen($body) : strlen($body)) > $max) {
            throw $fail("$bodyPath is longer than $max");
        }
        foreach (StarterLint::check($body, $risk, $language) as [$label, $text]) {
            throw $fail("$bodyPath: $label ($text)");
        }

        return ['id' => $id, 'name' => $name, 'description' => $desc, 'platform' => $platform, 'language' => $language, 'risk' => $risk,
            'requires_approval' => $approval, 'destructive' => $risk === 'disruptive', 'tags' => $tags, 'timeout_s' => $timeout, 'params' => $params, 'output' => $output,
            'file' => $bodyPath, 'size_bytes' => strlen($body), 'body_sha256' => hash('sha256', $body), 'content_hash' => self::scriptHash($language, $platform, $body, $params), 'body' => $body];
    }

    private function loadSets(): void
    {
        $d = $this->json('checks.json');
        $sets = $d['sets'] ?? null;
        if (!is_array($sets) || !array_is_list($sets)) {
            throw new \InvalidArgumentException('checks.json: sets is a list.');
        }
        foreach ($sets as $s) {
            $set = $this->set0($s);
            if (isset($this->sets[$set['id']])) {
                throw new \InvalidArgumentException("checks.json: the set {$set['id']} is used twice.");
            }
            $this->sets[$set['id']] = $set;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function set0(mixed $s): array
    {
        $fail = static fn (string $why): \InvalidArgumentException => new \InvalidArgumentException("checks.json: $why");
        if (!is_array($s) || !is_string($s['id'] ?? null) || preg_match(self::ID_RE, $s['id']) !== 1) {
            throw $fail('a set needs an id');
        }
        $id = $s['id'];
        foreach (['name' => 100, 'description' => 300] as $k => $max) {
            if (!is_string($s[$k] ?? null) || $s[$k] === '' || mb_strlen($s[$k]) > $max) {
                throw $fail("set $id: $k is 1 to $max characters");
            }
        }
        if (!in_array($s['platform'] ?? null, self::PLATFORMS, true)) {
            throw $fail("set $id: platform is windows or linux");
        }
        if (!in_array($s['use'] ?? null, ['workstation', 'windows-server', 'linux-server'], true)) {
            throw $fail("set $id: use is workstation, windows-server or linux-server");
        }
        $params = [];
        foreach ((array) ($s['params'] ?? []) as $p) {
            if (!is_array($p) || !is_string($p['name'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $p['name']) !== 1 || !in_array($p['type'] ?? null, ['string', 'int', 'list'], true)) {
                throw $fail("set $id: a parameter needs a name and a type of string, int or list");
            }
            $params[] = ['name' => $p['name'], 'type' => $p['type'], 'label' => (string) ($p['label'] ?? $p['name']), 'description' => (string) ($p['description'] ?? ''),
                'default' => $p['default'] ?? null, 'example' => $p['example'] ?? ($p['default'] ?? null)];
        }
        $checks = [];
        $keys = [];
        foreach ((array) ($s['checks'] ?? []) as $c) {
            if (!is_array($c) || !is_string($c['key'] ?? null) || !RmmProtocol::matches(RmmProtocol::CHECK_KEY_RE, $c['key']) || !is_string($c['type'] ?? null) || !is_int($c['interval_s'] ?? null)) {
                throw $fail("set $id: a check needs key, type and interval_s");
            }
            if (isset($keys[$c['key']])) {
                throw $fail("set $id: the check key {$c['key']} is used twice");
            }
            $keys[$c['key']] = 1;
            $when = $c['when'] ?? null;
            if ($when !== null && !in_array($when, array_column($params, 'name'), true)) {
                throw $fail("set $id: check {$c['key']} waits for the unknown parameter " . json_encode($when));
            }
            $checks[] = ['key' => $c['key'], 'label' => (string) ($c['label'] ?? $c['key']), 'type' => $c['type'], 'interval_s' => $c['interval_s'], 'params' => $c['params'] ?? [], 'when' => $when];
        }
        if ($checks === []) {
            throw $fail("set $id has no checks");
        }

        return ['id' => $id, 'name' => $s['name'], 'description' => $s['description'], 'platform' => $s['platform'], 'use' => $s['use'], 'params' => $params, 'checks' => $checks];
    }

    private function loadPolicies(): void
    {
        $d = $this->json('policies.json');
        $list = $d['policies'] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException('policies.json: policies is a list.');
        }
        $names = [];
        foreach ($list as $p) {
            $fail = static fn (string $why): \InvalidArgumentException => new \InvalidArgumentException("policies.json: $why");
            if (!is_array($p) || !is_string($p['id'] ?? null) || preg_match(self::ID_RE, $p['id']) !== 1) {
                throw $fail('a policy needs an id');
            }
            $id = $p['id'];
            if (isset($this->policies[$id])) {
                throw $fail("the policy $id is used twice");
            }
            if (!is_string($p['name'] ?? null) || $p['name'] === '' || mb_strlen($p['name']) > 100 || isset($names[mb_strtolower($p['name'])])) {
                throw $fail("policy $id: name is 1 to 100 characters and unique");
            }
            $names[mb_strtolower($p['name'])] = 1;
            if (!is_string($p['description'] ?? null) || $p['description'] === '' || mb_strlen($p['description']) > 180) {
                throw $fail("policy $id: description is 1 to 180 characters (the installer adds its marker)");
            }
            if (!in_array($p['platform'] ?? null, self::PLATFORMS, true)) {
                throw $fail("policy $id: platform is windows or linux");
            }
            $sets = $p['check_sets'] ?? null;
            if (!is_array($sets) || !array_is_list($sets) || $sets === []) {
                throw $fail("policy $id: check_sets is a list");
            }
            foreach ($sets as $sid) {
                if (!is_string($sid) || !isset($this->sets[$sid])) {
                    throw $fail("policy $id: unknown check set " . json_encode($sid));
                }
            }
            $p['settings'] = is_array($p['settings'] ?? null) ? $p['settings'] : [];
            $this->policies[$id] = $p;
            // The draft must be a valid policy with its example values (and with none), or the pack is refused.
            $this->resolvePolicy($p, $this->exampleParams($p));
            $this->resolvePolicy($p, []);
        }
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    private function exampleParams(array $policy): array
    {
        $out = [];
        foreach ($this->policyParams($policy) as $p) {
            $out[(string) $p['name']] = $p['example'] ?? $p['default'] ?? null;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $def
     */
    private static function paramValue(array $def, mixed $v): mixed
    {
        $name = (string) $def['name'];
        if ($v === null || $v === '' || $v === []) {
            return null;
        }
        switch ($def['type']) {
            case 'int':
                if (is_string($v) && preg_match('/^-?\d{1,9}$/', trim($v)) === 1) {
                    $v = (int) trim($v);
                }
                if (!is_int($v) || $v < 0 || $v > 1000000) {
                    throw new \InvalidArgumentException("Parameter $name must be a whole number from 0 to 1000000.");
                }

                return $v;
            case 'list':
                if (is_string($v)) {
                    $v = preg_split('/\s*[,\n]\s*/', trim($v)) ?: [];
                }
                if (!is_array($v) || !array_is_list($v) || count($v) > 10) {
                    throw new \InvalidArgumentException("Parameter $name must be a list of at most 10 values.");
                }
                $out = [];
                foreach ($v as $x) {
                    if (!is_string($x) || $x === '' || strlen($x) > 300 || preg_match('/[\x00-\x1f]/', $x) === 1) {
                        throw new \InvalidArgumentException("Parameter $name has an invalid entry.");
                    }
                    $out[] = $x;
                }

                return $out === [] ? null : $out;
            default:
                if (!is_string($v) || strlen($v) > 253 || preg_match('/[\x00-\x1f]/', $v) === 1 || trim($v) === '') {
                    throw new \InvalidArgumentException("Parameter $name must be text of at most 253 characters.");
                }

                return trim($v);
        }
    }

    /**
     * Replace `{"$param": name}` objects by the value, `{"$file": path}` objects by the text of a pack file (whose `{{name}}` tokens become whole
     * numbers), anywhere in a check's params.
     *
     * @param array<string,mixed> $values parameter name => resolved value
     */
    private static function substitute(mixed $node, array $values, string $dir): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        if (count($node) === 1 && isset($node['$param']) && is_string($node['$param'])) {
            return $values[$node['$param']] ?? null;
        }
        if (count($node) === 1 && isset($node['$file']) && is_string($node['$file'])) {
            $rel = $node['$file'];
            if (preg_match('#^checks/[A-Za-z0-9._-]{1,80}$#', $rel) !== 1 || !is_file($dir . '/' . $rel)) {
                throw new \InvalidArgumentException("Check script $rel is not in the pack.");
            }
            $text = (string) file_get_contents($dir . '/' . $rel);

            return (string) preg_replace_callback('/\{\{([a-z][a-z0-9_]{0,31})\}\}/', static function (array $m) use ($values): string {
                $v = $values[$m[1]] ?? null;
                if (!is_int($v)) {
                    throw new \InvalidArgumentException("The check script needs the whole number parameter {$m[1]}.");
                }

                return (string) $v;
            }, $text);
        }
        $out = [];
        foreach ($node as $k => $v) {
            $r = self::substitute($v, $values, $dir);
            if ($r !== null) {
                $out[$k] = $r;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function json(string $rel): array
    {
        $path = $this->dir . '/' . $rel;
        if (!is_file($path)) {
            throw new \InvalidArgumentException("$rel is missing.");
        }
        $d = json_decode((string) file_get_contents($path), true);
        if (!is_array($d)) {
            throw new \InvalidArgumentException("$rel is not valid JSON.");
        }

        return $d;
    }
}
