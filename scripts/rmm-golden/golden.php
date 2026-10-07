<?php
declare(strict_types=1);

/**
 * Golden HTTP transcripts of the device-facing endpoint-agent API (and a few technician calls).
 *
 *   php golden.php record --base=http://127.0.0.1:8680 --tls-base=http://127.0.0.1:8681 --install=<dir> [--adapter=<file>] [--dir=<golden dir>]
 *   php golden.php replay --base=...                  (same options; compares a fresh run against --dir, exit 1 on any difference)
 *
 * The driver only speaks HTTP to the server under test. State is reset, seeded and inspected through an edition adapter
 * (adapter-rivetit.php: `php adapter <install> <command> <json>` -> JSON), so a Core-backed edition can reuse every scenario with its own adapter.
 * All volatile values (ids, device tokens, timestamps, job ids, signatures, server banners) are masked deterministically; Ed25519 signatures are
 * VERIFIED with the fixed test public key and replaced by a marker. See README.md for the masking rules and how to read the files.
 */

const GOLDEN_FORMAT = 1;
$K = require __DIR__ . '/constants.php';

// ----------------------------------------------------------------------------------------------------------------- options
$mode = $argv[1] ?? '';
$opt = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    }
}
if (!in_array($mode, ['record', 'replay'], true) || empty($opt['base']) || empty($opt['install'])) {
    fwrite(STDERR, "usage: golden.php record|replay --base=URL --install=DIR [--tls-base=URL] [--adapter=FILE] [--dir=DIR] [--ignore-key-order]\n");
    exit(2);
}
$BASE = rtrim($opt['base'], '/');
$TLS = isset($opt['tls-base']) ? rtrim($opt['tls-base'], '/') : null;
$INSTALL = $opt['install'];
$ADAPTER = $opt['adapter'] ?? __DIR__ . '/adapter-rivetit.php';
$DIR = rtrim($opt['dir'] ?? dirname(__DIR__, 2) . '/tests/Fixtures/rmm/golden', '/');

// ----------------------------------------------------------------------------------------------------------------- helpers
function adapter(string $cmd, array $args = []): array
{
    global $ADAPTER, $INSTALL;
    $c = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ADAPTER) . ' ' . escapeshellarg($INSTALL) . ' ' . escapeshellarg($cmd) . ' ' . escapeshellarg(json_encode($args));
    $out = shell_exec($c . ' 2>&1');
    $j = json_decode((string) $out, true);
    if (!is_array($j)) {
        fwrite(STDERR, "adapter $cmd failed: $out\n");
        exit(2);
    }
    return $j;
}

/** Canonical JSON exactly as the agent protocol defines it (independent re-implementation: sorted keys, integers only, minimal escapes). */
function canon($v): string
{
    if ($v === null) {
        return 'null';
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_int($v)) {
        return (string) $v;
    }
    if (is_float($v)) {
        throw new InvalidArgumentException('float in signed content');
    }
    if (is_string($v)) {
        $out = preg_replace_callback('/["\\\\\x00-\x1f]/', static function ($m) {
            static $map = ['"' => '\\"', '\\' => '\\\\', "\x08" => '\\b', "\x0c" => '\\f', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t'];
            return $map[$m[0]] ?? sprintf('\\u%04x', ord($m[0]));
        }, $v);
        return '"' . $out . '"';
    }
    if ($v instanceof stdClass) {
        $a = (array) $v;
        $keys = array_map('strval', array_keys($a));
        usort($keys, 'strcmp');
        $p = [];
        foreach ($keys as $k) {
            $p[] = canon($k) . ':' . canon($a[$k] ?? $a[(int) $k] ?? null);
        }
        return '{' . implode(',', $p) . '}';
    }
    if (is_array($v)) {
        return '[' . implode(',', array_map('canon', $v)) . ']';
    }
    throw new InvalidArgumentException('unsupported');
}

function sigOk(string $message, ?string $sigB64): bool
{
    global $K;
    $sig = is_string($sigB64) ? base64_decode($sigB64, true) : false;
    $pk = base64_decode($K['signing_public_b64'], true);
    return $sig !== false && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($sig, $message, $pk);
}

/** @return array{status:int, headers:list<array{0:string,1:string}>, body:string} */
function httpCall(string $base, string $method, string $path, array $headers, ?string $body): array
{
    $ch = curl_init($base . $path);
    $hs = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: rmm-golden/1', 'Expect:'], $headers),
        CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$hs) {
            if (strpos($line, ':') !== false) {
                [$n, $v] = explode(':', $line, 2);
                $hs[] = [strtolower(trim($n)), trim($v)];
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) {
        fwrite(STDERR, "transport error calling $method $path\n");
        exit(2);
    }
    return ['status' => $code, 'headers' => $hs, 'body' => (string) $raw];
}

// ----------------------------------------------------------------------------------------------------------------- the run
final class Run
{
    public array $files = [];          // file name => document
    private array $cur = [];           // current document
    private string $curName = '';
    private array $alias = [];         // space => [real => n]
    private array $named = [];         // space => [real => name]
    private array $vals = [];          // template variables: 'dev:dev2' => 7, 'tok:dev2' => '...', 'job:j1' => uuid
    public array $problems = [];
    public array $assetNames = [];     // asset id => 'linkable' ...
    public array $binaries = [];       // "version/arch" => ['sha256','size']
    private string $base;
    private ?string $tls;

    public function __construct(string $base, ?string $tls)
    {
        $this->base = $base;
        $this->tls = $tls;
    }

    // ---- alias handling -------------------------------------------------------------------------------------------
    private function aliasOf(string $space, $real, string $label): string
    {
        $key = (string) $real;
        if (isset($this->named[$space][$key])) {
            return '<' . $label . ':' . $this->named[$space][$key] . '>';
        }
        $this->alias[$space] ??= [];
        if (!isset($this->alias[$space][$key])) {
            $this->alias[$space][$key] = count($this->alias[$space]) + 1;
        }
        return '<' . $label . '#' . $this->alias[$space][$key] . '>';
    }

    public function name(string $space, $real, string $name): void
    {
        $this->named[$space][(string) $real] = $name;
    }

    public function set(string $key, $v): void
    {
        $this->vals[$key] = $v;
        if (str_starts_with($key, 'dev:')) {
            $this->name('device', $v, substr($key, 4));
        }
        if (str_starts_with($key, 'job:')) {
            $this->name('uuid', $v, 'job:' . substr($key, 4));
        }
    }

    public function get(string $key)
    {
        return $this->vals[$key] ?? throw new RuntimeException("unbound $key");
    }

    // ---- templates ------------------------------------------------------------------------------------------------
    /** Resolve {{ts:N}}, {{dev:x}}, {{tok:x}}, {{job:x}}, {{base}} in strings (recursively). */
    private function resolve($v)
    {
        if (is_array($v)) {
            return array_map(fn($x) => $this->resolve($x), $v);
        }
        if (!is_string($v) || strpos($v, '{{') === false) {
            return $v;
        }
        return preg_replace_callback('/\{\{([a-z]+)(?::([A-Za-z0-9_+-]+))?\}\}/', function ($m) {
            return match ($m[1]) {
                'ts' => gmdate('Y-m-d\TH:i:s\Z', time() + (int) $m[2]),
                'dev' => (string) $this->get('dev:' . $m[2]),
                'tok' => (string) $this->get('tok:' . $m[2]),
                'job' => (string) $this->get('job:' . $m[2]),
                'old' => (string) $this->get('old:' . $m[2]),
                'base' => $this->base,
                default => $m[0],
            };
        }, $v);
    }

    // ---- masking --------------------------------------------------------------------------------------------------
    private function maskString(string $s): string
    {
        $s = str_replace($this->base, '<BASE>', $s);
        if ($this->tls !== null) {
            $s = str_replace($this->tls, '<TLSBASE>', $s);
        }
        $s = preg_replace('/\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\b/', '<TS>', $s);
        $s = preg_replace('/\b\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\b/', '<DT>', $s);
        // Fixed test install ids (00000000-0000-4000-8000-...) are part of the scenario and stay readable; every other UUID (job ids, installer ids) is aliased.
        $s = preg_replace_callback('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/', fn($m) => str_starts_with($m[0], '00000000-0000-4000-8000-') || str_starts_with($m[0], '11111111-1111-4111') ? $m[0] : $this->aliasOf('uuid', $m[0], 'UUID'), $s);
        foreach (array_keys($this->alias['secret'] ?? []) as $real) {
            $s = str_replace((string) $real, $this->aliasOf('secret', $real, 'TOKEN'), $s);
        }
        if (strlen($s) > 2000) {
            $s = '<STRING len=' . strlen($s) . ' sha256=' . hash('sha256', $s) . '>';
        }
        return $s;
    }

    /** Mask a decoded JSON value (objects as stdClass). Signatures are verified here when the caller gave a context. */
    private function mask($v, string $key = '', array $ctx = [])
    {
        if ($v instanceof stdClass) {
            $o = new stdClass();
            foreach ((array) $v as $k => $x) {
                $o->$k = $this->mask($x, (string) $k, $ctx + ['parent' => $v]);
            }
            return $o;
        }
        if (is_array($v)) {
            return array_map(fn($x) => $this->mask($x, $key, $ctx), $v);
        }
        if ($v === null || is_bool($v)) {
            return $v;
        }
        if ($key === 'signature') {
            return $this->signatureMarker($v, $ctx);
        }
        if ($key === 'device_token' && is_string($v)) {
            return $this->aliasOf('secret', $v, 'TOKEN');
        }
        if (is_int($v) || is_float($v)) {
            return match ($key) {
                'device_id' => $this->aliasOf('device', $v, 'DEVICE'),
                'matched_asset_id', 'asset_id' => $this->aliasOf('asset', $v, 'ASSET'),
                'alert_id' => $this->aliasOf('alert', $v, 'ALERT'),
                'created_by' => $this->aliasOf('user', $v, 'USER'),
                'owned_by_device_id' => $this->aliasOf('device', $v, 'DEVICE'),
                'age_s' => '<AGE>',
                'id' => ($ctx['entity'] ?? '') === 'device' ? $this->aliasOf('device', $v, 'DEVICE') : $v,
                default => $v,
            };
        }
        if (is_string($v)) {
            return $this->maskString($v);
        }
        return $v;
    }

    private function signatureMarker($sig, array $ctx): string
    {
        if (!is_string($sig)) {
            return '<SIG:missing>';
        }
        $p = $ctx['parent'] ?? null;
        $kind = $ctx['sigkind'] ?? 'object';
        if ($kind === 'manifest') {
            $ok = isset($p->sha256) && sigOk((string) $p->sha256, $sig);
        } else {
            $copy = clone $p;
            unset($copy->signature);
            try {
                $ok = sigOk(canon($copy), $sig);
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        if (!$ok) {
            $this->problems[] = "signature of a $kind did not verify in " . $this->curName;
        }
        return $ok ? '<SIG:ok:' . $kind . '>' : '<SIG:INVALID:' . $kind . '>';
    }

    /** Walk a response so check definitions / manifests get the right signature context. */
    private function maskResponseJson($j)
    {
        // Tag signature kinds by walking known locations before the generic pass.
        $tag = function ($node, string $kind, callable $self) {
            return $node;
        };
        $out = $this->mask($j, '', ['entity' => 'none']);
        return $out;
    }

    // ---- document / step recording -----------------------------------------------------------------------------------
    public function begin(string $file, string $title): void
    {
        $this->curName = $file;
        $this->cur = ['scenario' => $title, 'format' => GOLDEN_FORMAT, 'steps' => []];
    }

    public function end(): void
    {
        $this->files[$this->curName] = $this->cur;
        $this->cur = [];
    }

    public function snapshot(string $name, array $tables = ['endpoint_agent_devices', 'endpoint_agent_checks', 'endpoint_agent_checkins', 'endpoint_agent_jobs']): void
    {
        $all = adapter('snapshot');
        $keep = array_intersect_key($all, array_flip($tables));
        $this->cur['snapshots'][$name] = $this->maskSnapshot($keep);
    }

    public function hook(string $hook, array $args = []): void
    {
        adapter('hook', ['hook' => $hook] + $args);
        $this->cur['steps'][] = ['id' => 'hook:' . $hook, 'hook' => $hook, 'args' => $this->maskHookArgs($args)];
    }

    private function maskHookArgs(array $a): array
    {
        if (isset($a['device_id'])) {
            $a['device_id'] = $this->aliasOf('device', $a['device_id'], 'DEVICE');
        }
        return $a;
    }

    private function maskSnapshot(array $s): array
    {
        $out = [];
        foreach ($s as $table => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            if ($table === 'endpoint_agent_settings') {
                $rows = [$rows];
            }
            $o = [];
            foreach ($rows as $r) {
                $m = [];
                foreach ($r as $c => $v) {
                    $m[$c] = $this->maskCell($table, $c, $v);
                }
                $o[] = $m;
            }
            $out[$table] = $table === 'endpoint_agent_settings' ? $o[0] : $o;
        }
        return $out;
    }

    private function maskCell(string $table, string $col, $v)
    {
        if ($v === null) {
            return null;
        }
        if (in_array($col, ['device_id'], true)) {
            return $this->aliasOf('device', $v, 'DEVICE');
        }
        if ($col === 'asset_id') {
            return (int) $v === 0 ? 0 : $this->aliasOf('asset', $v, 'ASSET');
        }
        if (in_array($col, ['token_id', 'enrolled_via_token_id'], true)) {
            return $this->aliasOf('token', $v, 'TOKENROW');
        }
        if ($col === 'binary_id') {
            return $this->aliasOf('binary', $v, 'BINARY');
        }
        if (in_array($col, ['release_id', 'attempt_id'], true)) {
            return $this->aliasOf('row:' . $table, $v, 'ID');
        }
        if ($col === 'alert_id') {
            return $this->aliasOf('alert', $v, 'ALERT');
        }
        if (in_array($col, ['token_hash', 'signing_private_key_enc', 'storage_name'], true)) {
            return '<' . strtoupper($col) . '>';
        }
        if ($col === 'signing_public_key' || $col === 'signing_key_id') {
            return $v;
        }
        if (in_array($col, ['integration_id'], true)) {
            return '<INTEGRATION_ID>';
        }
        if (in_array($col, ['created_by', 'resolved_by'], true) && (int) $v > 0) {
            return $this->aliasOf('user', $v, 'USER');
        }
        if (str_ends_with($col, '_json') && is_string($v) && ($j = json_decode($v)) !== null) {
            return $this->maskWithContext($j, '');
        }
        if ($col === 'service_url') {
            return str_replace($this->base, '<BASE>', (string) $v);
        }
        if ($col === 'last_ip' && $v !== '127.0.0.1') {
            return '<IP>';
        }
        return is_string($v) ? $this->maskString($v) : $v;
    }

    /**
     * One request/response exchange. Options: auth (template, "Bearer <value>"), headers[], json (template), raw (string), form (array), gen (['bytes'=>n,'fill'=>'x']),
     * base (main|tls), expect (int|int[]), binary (callable(string $body, array $resp): array extra meta), sigkinds (not used), note.
     */
    public function step(string $id, string $method, string $path, array $o = []): array
    {
        $baseUrl = ($o['base'] ?? 'main') === 'tls' ? (string) $this->tls : $this->base;
        $reqHeaders = [];
        $recHeaders = [];
        if (isset($o['auth'])) {
            $reqHeaders[] = 'Authorization: Bearer ' . $this->resolve($o['auth']);
            $recHeaders['authorization'] = 'Bearer ' . $this->maskAuth((string) $o['auth']);
        }
        foreach ($o['headers'] ?? [] as $h) {
            $reqHeaders[] = $this->resolve($h);
            [$n, $v] = explode(':', $h, 2);
            $recHeaders[strtolower(trim($n))] = trim($v);
        }
        $body = null;
        $recBody = null;
        if (array_key_exists('json', $o)) {
            $reqHeaders[] = 'Content-Type: application/json';
            $recHeaders['content-type'] = 'application/json';
            $body = json_encode($this->resolve($o['json']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $recBody = ['json' => $this->requestTemplate($o['json'])];
        } elseif (isset($o['jsonraw'])) {
            $reqHeaders[] = 'Content-Type: application/json';
            $recHeaders['content-type'] = 'application/json';
            $body = $this->resolve($o['jsonraw']);
            $recBody = ['json_text' => $o['jsonraw']];
        } elseif (isset($o['form'])) {
            $reqHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
            $recHeaders['content-type'] = 'application/x-www-form-urlencoded';
            $body = http_build_query($this->resolve($o['form']));
            $recBody = ['form' => $o['form']];
        } elseif (isset($o['gen'])) {
            $reqHeaders[] = 'Content-Type: application/json';
            $recHeaders['content-type'] = 'application/json';
            $body = (string) ($o['gen']['prefix'] ?? '') . str_repeat((string) $o['gen']['fill'], (int) $o['gen']['bytes']);
            $recBody = ['generated' => $o['gen']];
        } elseif (isset($o['raw'])) {
            $body = (string) $o['raw'];
            $recBody = ['raw' => $o['raw']];
        }
        $realPath = $this->resolve($path);
        $r = httpCall($baseUrl, $method, $realPath, $reqHeaders, $body);

        $json = null;
        $isJson = false;
        foreach ($r['headers'] as [$n, $v]) {
            if ($n === 'content-type' && stripos($v, 'application/json') === 0) {
                $isJson = true;
            }
        }
        if ($isJson) {
            $json = json_decode($r['body'], false);
        }
        $resp = ['status' => $r['status'], 'headers' => $r['headers'], 'body' => $r['body'], 'json' => $json];
        if (isset($o['pre'])) {
            ($o['pre'])($resp);   // bind ids/tokens from the raw response BEFORE masking so every later mention uses the same alias
        }

        $exp = $o['expect'] ?? null;
        if ($exp !== null && !in_array($r['status'], (array) $exp, true)) {
            $this->problems[] = "$id: expected " . implode('|', (array) $exp) . ", got {$r['status']}: " . substr($r['body'], 0, 200);
        }

        $step = ['id' => $id, 'note' => $o['note'] ?? null];
        if ($step['note'] === null) {
            unset($step['note']);
        }
        $step['request'] = ['method' => $method, 'path' => $this->requestTemplate($path), 'base' => ($o['base'] ?? 'main'), 'headers' => $recHeaders] + ($recBody !== null ? ['body' => $recBody] : []);
        $step['response'] = ['status' => $r['status'], 'headers' => $this->maskHeaders($r['headers'], $isJson)];
        if ($isJson) {
            $step['response']['body'] = $this->maskJsonResponse($json);
        } elseif (isset($o['binary'])) {
            $meta = ['length' => strlen($r['body']), 'sha256' => hash('sha256', $r['body'])];
            $extra = ($o['binary'])($r['body'], $resp, $this) ?: [];
            if (($extra['sha256'] ?? null) === false) {   // content depends on a per-download id/time: only the length is stable
                unset($meta['sha256'], $extra['sha256']);
            }
            $step['response']['body_binary'] = $meta + $extra;
        } elseif ($r['body'] !== '') {
            $step['response']['body_text'] = $this->maskString($r['body']);
        }
        $this->cur['steps'][] = $step;
        return $resp;
    }

    /** Request templates are recorded verbatim (they contain only fixed fixtures and {{...}} placeholders), except tokens which are fixed too. */
    private function requestTemplate($v)
    {
        return $v;
    }

    private function maskAuth(string $a): string
    {
        if (preg_match('/^\{\{(tok|old):([A-Za-z0-9_]+)\}\}$/', $a, $m)) {
            return $this->aliasOf('secret', $this->get($m[1] . ':' . $m[2]), 'TOKEN');
        }
        return strlen($a) === 64 && ctype_xdigit($a) ? '<64-hex literal>' : $a;
    }

    private function maskHeaders(array $h, bool $isJson): array
    {
        $o = [];
        foreach ($h as [$n, $v]) {
            $v = match ($n) {
                'date' => '<DATE>',
                'host' => '<HOST>',
                'x-powered-by' => 'PHP/<VERSION>',
                'content-length' => $isJson ? '<LEN>' : $v,
                default => $this->maskString($v),
            };
            $o[] = $n . ': ' . $v;
        }
        sort($o, SORT_STRING);
        return $o;
    }

    public function maskJsonResponse($j)
    {
        return $this->maskWithContext($j, '');
    }

    /** Generic masking plus signature context: check definitions, job objects and update manifests. */
    private function maskWithContext($node, string $key, array $ctx = [])
    {
        if ($node instanceof stdClass) {
            $ctx2 = $ctx;
            if ($key === 'update' && isset($node->sha256, $node->signature)) {
                $ctx2['sigkind'] = 'manifest';
            } elseif ($key === 'checks' || isset($node->job_id) ) {
                $ctx2['sigkind'] = isset($node->job_id) ? 'job' : 'check';
            } elseif (isset($node->interval_s, $node->key, $node->type)) {
                $ctx2['sigkind'] = 'check';
            }
            if (isset($node->job_id, $node->device_id, $node->signature)) {
                $ctx2['sigkind'] = 'job';
            }
            $o = new stdClass();
            foreach ((array) $node as $k => $x) {
                $c = $ctx2;
                $c['parent'] = $node;
                $c['entity'] = (isset($node->hostname, $node->link_state) || (isset($node->status, $node->link_state) && $k === 'id')) ? 'device' : ($ctx['entity'] ?? '');
                $o->$k = is_string($k) && $k === 'signature' ? $this->signatureMarker($x, $c) : $this->maskWithContext($x, (string) $k, $c);
            }
            return $o;
        }
        if (is_array($node)) {
            return array_map(fn($x) => $this->maskWithContext($x, $key, $ctx), $node);
        }
        return $this->mask($node, $key, $ctx);
    }

    // ---- helpers for scenarios --------------------------------------------------------------------------------------
    /** Send $n requests without recording each one; record the status histogram. The caller records the next request with step(). */
    public function burst(string $id, int $n, callable $build, string $note = ''): void
    {
        $hist = [];
        for ($i = 1; $i <= $n; $i++) {
            [$method, $path, $o] = $build($i);
            $reqHeaders = [];
            if (isset($o['auth'])) {
                $reqHeaders[] = 'Authorization: Bearer ' . $this->resolve($o['auth']);
            }
            $body = null;
            if (array_key_exists('json', $o)) {
                $reqHeaders[] = 'Content-Type: application/json';
                $body = json_encode($this->resolve($o['json']));
            }
            $r = httpCall($this->base, $method, $this->resolve($path), $reqHeaders, $body);
            $hist[$r['status']] = ($hist[$r['status']] ?? 0) + 1;
        }
        $this->cur['steps'][] = ['id' => $id, 'burst' => ['requests' => $n, 'status_histogram' => $hist, 'note' => $note]];
    }

    public function currentDocument(): array
    {
        return $this->cur;
    }
}

// ----------------------------------------------------------------------------------------------------------------- scenarios
function dev(string $serial, string $install, array $over = []): array
{
    return array_merge([
        'install_id' => $install, 'machine_guid' => substr(hash('sha256', 'guid|' . $serial), 0, 16), 'hostname' => 'GOLD-' . strtoupper(substr(hash('sha256', $serial), 0, 4)),
        'os' => 'windows', 'os_version' => 'Windows 11 23H2', 'arch' => 'amd64', 'serial' => $serial, 'manufacturer' => 'Dell', 'model' => 'Latitude 7440',
        'mac_addresses' => ['AA-BB-CC-' . strtoupper(substr(hash('sha256', 'mac|' . $serial), 0, 2)) . '-00-01'], 'agent_version' => '1.0.0',
    ], $over);
}

function iid(int $n): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $n);
}

function runScenarios(Run $g, array $K, ?string $tlsBase): void
{
    $T = fn(string $n) => $K['tokens'][$n]['token'];
    // $enr(id, token, device, options, name): the response's device_id / device_token are bound to $name before masking.
    $enr = function (string $id, string $tok, array $d, array $o = [], ?string $name = null) use ($g) {
        $pre = function (array $resp) use ($g, $name) {
            $j = $resp['json'];
            if ($name !== null && is_object($j) && isset($j->device_id)) {
                $g->set('dev:' . $name, (int) $j->device_id);
            }
            if ($name !== null && is_object($j) && isset($j->device_token)) {
                $g->set('tok:' . $name, (string) $j->device_token);
            }
        };
        return $g->step($id, 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $tok, 'device' => $d], 'pre' => $pre] + $o);
    };
    $ADMIN = $K['admin_api_token'];

    // ======================================================================== 01 service switched off
    $g->begin('01-disabled.json', 'service disabled: what each endpoint answers before the module is switched on');
    $g->step('enroll-disabled', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $T('c1'), 'device' => dev('GOLD-D0', iid(900))], 'expect' => 403]);
    $g->step('enroll-get-disabled', 'GET', '/api/v1/agent_enroll', ['expect' => 405]);
    $g->step('installer-disabled', 'POST', '/api/v1/agent_installer', ['json' => ['token' => $T('inst'), 'arch' => 'amd64'], 'expect' => 403]);
    $g->step('checkin-garbage-bearer-disabled', 'POST', '/api/v1/agent_checkin', ['auth' => str_repeat('a', 64), 'json' => ['seq' => 1], 'expect' => 401]);
    $g->hook('enable');
    $g->end();

    // ======================================================================== 02 TLS
    if ($tlsBase !== null) {
        $g->begin('02-tls.json', 'plain http is refused with 426 on every device endpoint; a loopback TLS-terminating proxy header is accepted');
        $tls = ['base' => 'tls'];
        foreach (['enroll' => ['POST', '/api/v1/agent_enroll'], 'checkin' => ['POST', '/api/v1/agent_checkin'], 'jobs' => ['GET', '/api/v1/agent_jobs'], 'update' => ['GET', '/api/v1/agent_update?arch=amd64&version=1.1.0'],
            'installer' => ['POST', '/api/v1/agent_installer']] as $k => [$m, $p]) {
            $g->step("plain-http-$k", $m, $p, $tls + ['expect' => 426]);
        }
        $g->step('forwarded-proto-http', 'GET', '/api/v1/agent_enroll', $tls + ['headers' => ['X-Forwarded-Proto: http'], 'expect' => 426]);
        $g->step('forwarded-proto-https-passes-tls-gate', 'GET', '/api/v1/agent_enroll', $tls + ['headers' => ['X-Forwarded-Proto: https'], 'expect' => 405]);
        $g->end();
    }

    // ======================================================================== 03 enrollment errors
    $g->begin('03-enroll-errors.json', 'enrollment: transport, body, token and device validation errors');
    $g->step('get-not-allowed', 'GET', '/api/v1/agent_enroll', ['expect' => 405]);
    $g->step('put-not-allowed', 'PUT', '/api/v1/agent_enroll', ['expect' => 405]);
    $g->step('body-not-json', 'POST', '/api/v1/agent_enroll', ['raw' => 'not json', 'expect' => 422]);
    $g->step('body-json-list', 'POST', '/api/v1/agent_enroll', ['jsonraw' => '[]', 'expect' => 422]);
    $g->step('body-too-large', 'POST', '/api/v1/agent_enroll', ['gen' => ['bytes' => 20000, 'fill' => 'x'], 'expect' => 413]);
    $g->step('missing-device', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $T('c1')], 'expect' => 422]);
    $g->step('token-garbage', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => 'garbage', 'device' => dev('GOLD-E1', iid(901))], 'expect' => 401]);
    $g->step('token-unknown-selector', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => 'rvte1.' . str_repeat('c', 12) . '.' . str_repeat('d', 40), 'device' => dev('GOLD-E1', iid(901))], 'expect' => 401]);
    $wrong = 'rvte1.' . explode('.', $T('c1'))[1] . '.' . str_repeat('e', 40);
    $g->step('token-wrong-secret', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $wrong, 'device' => dev('GOLD-E1', iid(901))], 'expect' => 401]);
    $g->step('token-expired', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $T('expired'), 'device' => dev('GOLD-E1', iid(901))], 'expect' => 401]);
    $g->step('token-revoked', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $T('revoked'), 'device' => dev('GOLD-E1', iid(901))], 'expect' => 401]);
    $g->step('token-exhausted', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $T('exhausted'), 'device' => dev('GOLD-E1', iid(901))], 'expect' => 403]);
    $g->snapshot('attempts-after-errors', ['endpoint_agent_enroll_attempts_by_reason']);
    $g->hook('clear_attempts');
    $bad = dev('GOLD-E2', iid(902));
    foreach (['install_id' => null, 'machine_guid' => 'not-hex!', 'arch' => 'sparc', 'os' => 'linux', 'hostname' => ''] as $f => $val) {
        $d = $bad;
        if ($val === null) {
            unset($d[$f]);
        } else {
            $d[$f] = $val;
        }
        $g->step("device-invalid-$f", 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $T('c1'), 'device' => $d], 'expect' => 422]);
    }
    $g->snapshot('attempts-after-invalid-device', ['endpoint_agent_enroll_attempts_by_reason']);
    $g->hook('clear_attempts');
    $g->snapshot('tokens-use-count-unchanged-by-errors', ['endpoint_agent_enrollment_tokens']);
    $g->end();

    // ======================================================================== 04 enrollment flows
    $g->begin('04-enroll-flows.json', 'enrollment: new device, linked match, re-enroll, reinstall, identity conflict, scope mismatch, junk serial, auto-create, revoked');
    $enr('new-unmatched-device', $T('c1'), dev('GOLD-SER-NEW1', iid(1)), ['expect' => 201], 'dev1');
    $enr('new-device-linked-by-serial', $T('c1'), dev('GOLD-SER-LINK', iid(2)), ['expect' => 201], 'dev2');
    $g->set('old:dev2', (string) $g->get('tok:dev2'));
    $enr('re-enroll-same-install-id', $T('c1'), dev('GOLD-SER-LINK', iid(2)), ['expect' => 201, 'note' => 'same install_id: same device_id, new credential, the old one stops working'], 'dev2');
    $g->step('old-credential-after-re-enroll', 'POST', '/api/v1/agent_checkin', ['auth' => '{{old:dev2}}', 'json' => ['seq' => 1], 'expect' => 401]);
    $enr('reinstall-new-install-id-same-machine-guid', $T('c1'), dev('GOLD-SER-NEW1', iid(11)), ['expect' => 201, 'note' => 'new install_id, same machine_guid and serial: the existing device is reused'], 'dev1');
    $enr('reinstall-new-install-id-new-guid-same-serial', $T('c1'), dev('GOLD-SER-LINK', iid(12), ['machine_guid' => 'ffffffffffff0001']), ['expect' => 201, 'note' => 'Windows reinstall: new MachineGuid, same BIOS serial'], 'dev2');
    $g->step('identity-conflict-same-install-id-other-machine', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $T('c1'), 'device' => dev('GOLD-SER-NEW1', iid(11), ['machine_guid' => 'eeeeeeeeeeee0002'])], 'expect' => 409]);
    $enr('scope-mismatch-asset-in-other-department', $T('c1'), dev('GOLD-SER-OTHER', iid(3)), ['expect' => 201, 'note' => 'serial matches an asset of Dept B but the token belongs to Dept A: pending approval, never linked across departments'], 'dev3');
    $g->step('technician-detail-scope-mismatch', 'GET', '/api/v1/endpoint_devices/{{dev:dev3}}', ['auth' => $ADMIN, 'expect' => 200]);
    $enr('serial-of-known-device-with-other-department-token', $T('c2'), dev('GOLD-SER-OTHER', iid(13)), ['expect' => 201, 'note' => 'reinstall lookup is by machine_guid or serial and ignores the token department: the Dept A device is reused']);
    $g->step('junk-serial-is-ignored', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $T('c1'), 'device' => dev('To be filled by O.E.M.', iid(4), ['machine_guid' => 'aaaaaaaaaaaa0004'])], 'expect' => 201]);
    $g->hook('set_setting', ['values' => ['unmatched_policy' => 'auto_create']]);
    $enr('auto-create-policy-new-asset', $T('c1'), dev('GOLD-SER-AUTO1', iid(5)), ['expect' => 201], 'dev5');
    $g->hook('set_setting', ['values' => ['unmatched_policy' => 'approval']]);
    $enr('enroll-for-revocation', $T('c1'), dev('GOLD-SER-REVOKE', iid(6)), ['expect' => 201], 'dev6');
    $g->hook('revoke_device', ['device_id' => $g->get('dev:dev6')]);
    $g->step('enroll-revoked-device', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $T('c1'), 'device' => dev('GOLD-SER-REVOKE', iid(6))], 'expect' => 403]);
    $g->step('technician-list', 'GET', '/api/v1/endpoint_devices', ['auth' => $ADMIN, 'expect' => 200]);
    $g->step('technician-detail-unknown-device', 'GET', '/api/v1/endpoint_devices/999999999', ['auth' => $ADMIN, 'expect' => 404]);
    $g->step('technician-no-credential', 'GET', '/api/v1/endpoint_devices', ['expect' => 401]);
    $g->snapshot('after-enrollment', ['endpoint_agent_devices', 'endpoint_agent_enrollment_tokens', 'endpoint_agent_settings', 'endpoint_agent_releases', 'endpoint_agent_binaries', 'endpoint_agent_enroll_attempts_by_reason']);
    $g->end();

    // ======================================================================== 05 check-in
    $g->begin('05-checkin.json', 'check-in: auth, validation, idempotent seq, inventory, metrics, buffered samples, check debounce, update manifest, caps');
    $T2 = '{{tok:dev2}}';
    $ci = fn(string $id, int $seq, array $over = [], array $o = []) => $g->step($id, 'POST', '/api/v1/agent_checkin', ['auth' => $T2, 'json' => array_merge(['seq' => $seq, 'collected_at' => '{{ts:-20}}', 'agent_version' => '1.0.0',
        'metrics' => ['cpu_pct' => 12.5, 'mem_pct' => 40, 'disk' => [['mount' => 'C:', 'used_pct' => 60.8]], 'net_rx_bps' => 1000.5, 'net_tx_bps' => 500], 'checks' => [], 'buffered' => []], $over)] + $o);
    $g->step('no-credential', 'POST', '/api/v1/agent_checkin', ['json' => ['seq' => 1], 'expect' => 401]);
    $g->step('unknown-credential', 'POST', '/api/v1/agent_checkin', ['auth' => str_repeat('a', 64), 'json' => ['seq' => 1], 'expect' => 401]);
    $g->step('malformed-bearer', 'POST', '/api/v1/agent_checkin', ['auth' => 'short', 'json' => ['seq' => 1], 'expect' => 401]);
    $g->step('get-not-allowed', 'GET', '/api/v1/agent_checkin', ['auth' => $T2, 'expect' => 405]);
    $g->step('body-not-json', 'POST', '/api/v1/agent_checkin', ['auth' => $T2, 'raw' => 'nope', 'expect' => 422]);
    $g->step('body-json-list', 'POST', '/api/v1/agent_checkin', ['auth' => $T2, 'jsonraw' => '[1,2]', 'expect' => 422]);
    $ci('seq-is-string', 0, ['seq' => '7'], ['expect' => 422]);
    $ci('seq-negative', 0, ['seq' => -1], ['expect' => 422]);
    $ci('agent-version-invalid', 1, ['agent_version' => 'v1'], ['expect' => 422]);
    $ci('collected-at-missing', 1, ['collected_at' => null], ['expect' => 422]);
    $ci('collected-at-in-the-future', 1, ['collected_at' => '{{ts:3600}}'], ['expect' => 422]);
    $ci('checks-not-a-list', 1, ['checks' => (object) ['a' => 1]], ['expect' => 422]);
    $ci('check-status-invalid', 1, ['checks' => [['key' => 'disk_c', 'status' => 'meh']]], ['expect' => 422]);
    $g->step('inventory-too-large', 'POST', '/api/v1/agent_checkin', ['auth' => $T2, 'json' => ['seq' => 1, 'collected_at' => '{{ts:-20}}', 'agent_version' => '1.0.0', 'inventory' => ['blob' => str_repeat('y', 70000)]], 'expect' => 422]);
    $g->step('body-over-1-mib', 'POST', '/api/v1/agent_checkin', ['auth' => $T2, 'gen' => ['bytes' => 1048577, 'fill' => 'z'], 'expect' => 413]);
    $g->hook('expire_device_token', ['device_id' => $g->get('dev:dev2')]);
    $ci('credential-expired', 1, [], ['expect' => 401]);
    $g->hook('expire_device_token', ['device_id' => $g->get('dev:dev2'), 'restore' => true]);
    $inventory = ['hostname' => 'GOLD-INV', 'os' => 'windows', 'os_version' => 'Windows 11 23H2', 'manufacturer' => 'Dell', 'model' => 'Latitude 7440', 'serial' => 'GOLD-SER-LINK',
        'cpu' => ['model' => 'Intel i7-1355U', 'cores' => 10], 'memory_total_bytes' => 17179869184,
        'disks' => [['mount' => 'C:', 'total_bytes' => 512000000000, 'free_bytes' => 200000000000, 'fs' => 'NTFS']],
        'network' => [['name' => 'Ethernet', 'mac' => 'AA-BB-CC-DD-EE-01', 'ips' => ['10.0.0.5', 'not-an-ip']]], 'uptime_s' => 7200, 'logged_in_user' => '<script>alert(1)</script>', 'pending_reboot' => false];
    $ci('first-checkin-with-inventory', 1, ['inventory' => $inventory, 'checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => 'C: 61% used'], ['key' => 'svc_eventlog', 'status' => 'ok', 'detail' => 'running']]], ['expect' => 200,
        'note' => 'response carries signed checks and, because agent 1.0.0 is older than the stable 1.1.0 release, a signed update manifest']);
    $ci('duplicate-seq-is-idempotent', 1, ['inventory' => $inventory, 'metrics' => ['cpu_pct' => 99, 'mem_pct' => 99]], ['expect' => 200, 'note' => 'same seq again: acknowledged, nothing applied twice']);
    $ci('buffered-samples', 2, ['buffered' => [
        ['collected_at' => '{{ts:-600}}', 'metrics' => ['cpu_pct' => 5, 'mem_pct' => 30], 'checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => 'old']]],
        ['collected_at' => '{{ts:-300}}', 'metrics' => ['cpu_pct' => 7, 'mem_pct' => 31]],
        ['collected_at' => 'garbage', 'metrics' => ['cpu_pct' => 1]],
        'not-an-object',
    ]], ['expect' => 200, 'note' => 'two valid backlog samples, one with a bad timestamp and one non-object: the bad ones are dropped, the request still succeeds']);
    $ci('metrics-null', 3, ['metrics' => null], ['expect' => 200]);
    $g->snapshot('after-first-checkins');
    $ci('check-fail-1', 4, ['checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'C: 97% used']]], ['expect' => 200]);
    $ci('check-fail-2', 5, ['checks' => [['key' => 'disk_c', 'status' => 'warn', 'detail' => 'C: 96% used']]], ['expect' => 200]);
    $ci('check-fail-3-opens-alert', 6, ['checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'C: 97% used']]], ['expect' => 200]);
    $g->snapshot('check-alert-open');
    $ci('check-ok-1', 7, ['checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => 'C: 70% used']]], ['expect' => 200]);
    $ci('check-ok-2-resolves-alert', 8, ['checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => 'C: 70% used']]], ['expect' => 200]);
    $g->snapshot('check-alert-resolved');
    $ci('check-unknown-changes-nothing', 9, ['checks' => [['key' => 'disk_c', 'status' => 'unknown', 'detail' => '']]], ['expect' => 200]);
    $g->step('pending-approval-device-checkin', 'POST', '/api/v1/agent_checkin', ['auth' => '{{tok:dev1}}', 'json' => ['seq' => 1, 'collected_at' => '{{ts:-5}}', 'agent_version' => '1.0.0', 'metrics' => ['cpu_pct' => 3, 'mem_pct' => 20]], 'expect' => 200]);
    $g->step('revoked-device-checkin', 'POST', '/api/v1/agent_checkin', ['auth' => '{{tok:dev6}}', 'json' => ['seq' => 1, 'collected_at' => '{{ts:-5}}', 'agent_version' => '1.0.0'], 'expect' => 401]);
    $g->snapshot('after-checkins');
    $g->end();

    // ======================================================================== 06 jobs
    $g->begin('06-jobs.json', 'jobs: technician submit, device offer (signed), report, idempotent replay, conflicts, redaction, truncation, cancel, long poll');
    $JT = '{{tok:dev2}}';
    $bindJob = fn(string $name) => function (array $resp) use ($g, $name) {
        if (is_object($resp['json']) && isset($resp['json']->job_id)) {
            $g->set('job:' . $name, (string) $resp['json']->job_id);
        }
    };
    $sub = fn(string $id, array $body, array $o = []) => $g->step($id, 'POST', '/api/v1/endpoint_devices/{{dev:dev2}}/jobs', ['auth' => $ADMIN, 'json' => $body] + $o);
    $g->step('jobs-no-credential', 'GET', '/api/v1/agent_jobs', ['expect' => 401]);
    $g->step('jobs-put-not-allowed', 'PUT', '/api/v1/agent_jobs', ['auth' => $JT, 'expect' => 405]);
    $g->step('jobs-empty', 'GET', '/api/v1/agent_jobs', ['auth' => $JT, 'expect' => 200]);
    $sub('submit-powershell', ['type' => 'powershell', 'script' => 'Get-Date', 'timeout_s' => 120, 'params' => ['Name' => 'x', 'Count' => 3]], ['expect' => 201, 'pre' => $bindJob('j1')]);
    $sub('submit-unknown-type', ['type' => 'format-disk'], ['expect' => [400, 422]]);
    $sub('submit-script-missing', ['type' => 'powershell'], ['expect' => [400, 422]]);
    $g->step('checkin-reports-jobs-pending', 'POST', '/api/v1/agent_checkin', ['auth' => $JT, 'json' => ['seq' => 10, 'collected_at' => '{{ts:-5}}', 'agent_version' => '1.0.0'], 'expect' => 200]);
    $g->step('offer-signed-job', 'GET', '/api/v1/agent_jobs', ['auth' => $JT, 'expect' => 200]);
    $g->step('offer-again-within-ack-window-is-empty', 'GET', '/api/v1/agent_jobs', ['auth' => $JT, 'expect' => 200]);
    $rep = fn(string $id, array $b, array $o = []) => $g->step($id, 'POST', '/api/v1/agent_jobs', ['auth' => $JT, 'json' => $b] + $o);
    $rep('report-body-invalid', ['job_id' => 'x'], ['expect' => 422]);
    $rep('report-unknown-job', ['job_id' => '11111111-1111-4111-8111-111111111111', 'attempt' => 1, 'state' => 'running'], ['expect' => 404]);
    $rep('report-attempt-never-issued', ['job_id' => '{{job:j1}}', 'attempt' => 5, 'state' => 'running'], ['expect' => 409]);
    $g->step('report-by-another-device', 'POST', '/api/v1/agent_jobs', ['auth' => '{{tok:dev1}}', 'json' => ['job_id' => '{{job:j1}}', 'attempt' => 1, 'state' => 'running'], 'expect' => 404]);
    $rep('report-running', ['job_id' => '{{job:j1}}', 'attempt' => 1, 'state' => 'running', 'started_at' => '{{ts:-2}}'], ['expect' => 200]);
    $secretOut = "ok\npassword=hunter2\nAuthorization: Bearer abcdefghijklmnop12345\ntoken: " . str_repeat('ab', 32) . "\nAKIAABCDEFGHIJKLMNOP\n" . $K['tokens']['c1']['token']
        . "\n-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA\n-----END RSA PRIVATE KEY-----\nplain line stays";
    $rep('report-succeeded-with-secrets', ['job_id' => '{{job:j1}}', 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => $secretOut, 'started_at' => '{{ts:-2}}', 'finished_at' => '{{ts:-1}}'], ['expect' => 200]);
    $rep('report-same-final-again-is-idempotent', ['job_id' => '{{job:j1}}', 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => $secretOut], ['expect' => 200]);
    $rep('report-different-final-conflicts', ['job_id' => '{{job:j1}}', 'attempt' => 1, 'state' => 'failed', 'exit_code' => 1, 'output' => 'x'], ['expect' => 409]);
    $g->step('technician-job-history-shows-redacted-output', 'GET', '/api/v1/endpoint_devices/{{dev:dev2}}/jobs', ['auth' => $ADMIN, 'expect' => 200]);
    usleep(1100000);   // distinct created_at seconds: the technician job list orders by created_at
    $sub('submit-collect-job', ['type' => 'collect'], ['expect' => 201, 'pre' => $bindJob('j2')]);
    $g->step('offer-collect-job', 'GET', '/api/v1/agent_jobs', ['auth' => $JT, 'expect' => 200]);
    $big = str_repeat("0123456789abcdef\n", 5000);
    $rep('report-failed-with-oversized-output-is-truncated', ['job_id' => '{{job:j2}}', 'attempt' => 1, 'state' => 'failed', 'exit_code' => 1, 'output' => $big], ['expect' => 200]);
    $g->step('technician-job-detail-truncated', 'GET', '/api/v1/endpoint_devices/{{dev:dev2}}/jobs', ['auth' => $ADMIN, 'expect' => 200]);
    usleep(1100000);
    $sub('submit-job-to-cancel', ['type' => 'powershell', 'script' => 'Start-Sleep 600'], ['expect' => 201, 'pre' => $bindJob('j3')]);
    $g->step('cancel-queued-job', 'POST', '/api/v1/endpoint_devices/{{dev:dev2}}/jobs/{{job:j3}}/cancel', ['auth' => $ADMIN, 'json' => new stdClass(), 'expect' => 200]);
    $g->step('offer-after-cancel-is-empty', 'GET', '/api/v1/agent_jobs', ['auth' => $JT, 'expect' => 200]);
    $g->step('long-poll-wait-1-returns-empty', 'GET', '/api/v1/agent_jobs?wait=1', ['auth' => $JT, 'expect' => 200]);
    $g->step('report-body-over-256-kib', 'POST', '/api/v1/agent_jobs', ['auth' => $JT, 'gen' => ['bytes' => 262145, 'fill' => 'q'], 'expect' => 413]);
    $g->snapshot('after-jobs');
    $g->end();

    // ======================================================================== 07 update
    $g->begin('07-update.json', 'hosted update download: only the release the manifest offers this device, only for its own arch; everything else is a generic 404');
    $UP = '/api/v1/agent_update';
    $g->step('no-credential', 'GET', "$UP?arch=amd64&version=1.1.0", ['expect' => 401]);
    $g->step('post-not-allowed', 'POST', "$UP?arch=amd64&version=1.1.0", ['auth' => $JT, 'expect' => 405]);
    $g->step('missing-params', 'GET', $UP, ['auth' => $JT, 'expect' => 422]);
    $g->step('bad-arch', 'GET', "$UP?arch=sparc&version=1.1.0", ['auth' => $JT, 'expect' => 422]);
    $g->step('bad-version', 'GET', "$UP?arch=amd64&version=latest", ['auth' => $JT, 'expect' => 422]);
    $g->step('version-not-offered', 'GET', "$UP?arch=amd64&version=1.0.0", ['auth' => $JT, 'expect' => 404]);
    $g->step('version-unknown', 'GET', "$UP?arch=amd64&version=9.9.9", ['auth' => $JT, 'expect' => 404]);
    $g->step('other-architecture', 'GET', "$UP?arch=arm64&version=1.1.0", ['auth' => $JT, 'expect' => 404]);
    $g->step('download-offered-release', 'GET', "$UP?arch=amd64&version=1.1.0", ['auth' => $JT, 'expect' => 200, 'binary' => function (string $body, array $resp, Run $run) use ($g) {
        $pub = $g->binaries['1.1.0/amd64'] ?? null;
        return ['equals_published' => $pub !== null && $pub['sha256'] === hash('sha256', $body) && $pub['size'] === strlen($body) ? 'amd64@1.1.0' : false];
    }]);
    $g->step('download-twice-same-bytes', 'GET', "$UP?arch=amd64&version=1.1.0", ['auth' => $JT, 'expect' => 200, 'binary' => fn($b) => []]);
    $g->end();

    // ======================================================================== 08 installer
    $g->begin('08-installer.json', 'token-gated installer download: stamped executable, generic 404s, token never in the URL, size cap');
    $IN = '/api/v1/agent_installer';
    $inspectFor = fn(string $token, string $department) => function (string $body, array $resp, Run $run) use ($g, $token, $department) {
        $len = strlen($body);
        if ($len < 52) {
            return ['trailer' => 'too short'];
        }
        $footer = substr($body, -52);
        $plen = unpack('N', substr($footer, 0, 4))[1];
        $magic = substr($footer, 36);
        $payload = substr($body, $len - 52 - $plen, $plen);
        $exe = substr($body, 0, $len - 52 - $plen);
        $pub = $g->binaries['1.0.0/amd64'] ?? null;
        $d = json_decode($payload);
        $m = [
            'magic' => $magic, 'footer_sha256_matches_payload' => hash('sha256', $payload, true) === substr($footer, 4, 32), 'payload_length' => $plen,
            'exe_prefix_equals_published' => $pub !== null && hash('sha256', $exe) === $pub['sha256'] ? 'amd64@1.0.0' : false,
            'sha256' => false,
        ];
        if (is_object($d)) {
            $m['payload_keys'] = array_keys((array) $d);
            $m['payload'] = $g->maskJsonResponse($d);
            $m['payload_enrollment_token_is_the_requested_one'] = ($d->enrollment_token ?? null) === $token;
            $m['payload_department_is_the_expected_one'] = ($d->department ?? null) === $department;
        }
        return $m;
    };
    $dl = fn(string $id, string $tokName, array $o) => $g->step($id, 'POST', $IN, $o + ['binary' => $inspectFor($T($tokName), $K['clients'][$K['tokens'][$tokName]['client_id']])]);
    $dl('download-json-body', 'inst', ['json' => ['token' => $T('inst'), 'arch' => 'amd64'], 'expect' => 200]);
    $dl('download-form-body', 'inst', ['form' => ['token' => $T('inst'), 'arch' => 'amd64'], 'expect' => 200]);
    $dl('download-bearer-header', 'inst', ['auth' => $T('inst'), 'json' => ['arch' => 'amd64'], 'expect' => 200]);
    $dl('download-other-department-token', 'inst2', ['json' => ['token' => $T('inst2'), 'arch' => 'amd64'], 'expect' => 200]);
    $g->step('arch-without-published-binary', 'POST', $IN, ['json' => ['token' => $T('inst'), 'arch' => 'arm64'], 'expect' => 409]);
    $g->step('get-not-allowed', 'GET', $IN, ['expect' => 405]);
    $g->step('token-in-url', 'POST', "$IN?token=" . $T('inst'), ['json' => ['arch' => 'amd64'], 'expect' => 400]);
    $g->step('empty-body', 'POST', $IN, ['expect' => 422]);
    $g->step('arch-invalid', 'POST', $IN, ['json' => ['token' => $T('inst'), 'arch' => 'sparc'], 'expect' => 422]);
    $g->step('body-over-4-kib', 'POST', $IN, ['gen' => ['bytes' => 5000, 'fill' => 'x'], 'expect' => 413]);
    $g->step('token-garbage', 'POST', $IN, ['json' => ['token' => 'garbage', 'arch' => 'amd64'], 'expect' => 404]);
    $g->step('token-unknown-selector', 'POST', $IN, ['json' => ['token' => 'rvte1.' . str_repeat('c', 12) . '.' . str_repeat('d', 40), 'arch' => 'amd64'], 'expect' => 404]);
    $g->step('token-revoked', 'POST', $IN, ['json' => ['token' => $T('revoked'), 'arch' => 'amd64'], 'expect' => 404]);
    $g->step('token-expired', 'POST', $IN, ['json' => ['token' => $T('expired'), 'arch' => 'amd64'], 'expect' => 404]);
    $g->step('token-used-up', 'POST', $IN, ['json' => ['token' => $T('exhausted'), 'arch' => 'amd64'], 'expect' => 404]);
    $g->snapshot('after-installer', ['endpoint_agent_enrollment_tokens', 'endpoint_agent_enroll_attempts_by_reason']);
    $g->hook('clear_attempts');
    $g->end();

    // ======================================================================== 09 rate limits
    $g->begin('09-rate-limits.json', '429 behaviour: enrollment and installer failure budgets (database), per-device buckets for check-in, jobs and update (Redis)');
    $g->burst('enroll-10-failures', 10, fn($i) => ['POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => 'rvte1.' . str_repeat('c', 12) . '.' . str_repeat('d', 40), 'device' => dev('GOLD-RL', iid(950))]]], 'ten invalid tokens from one address (401 each)');
    $g->step('enroll-eleventh-is-rate-limited', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => 'rvte1.' . str_repeat('c', 12) . '.' . str_repeat('d', 40), 'device' => dev('GOLD-RL', iid(950))], 'expect' => 429]);
    $g->step('enroll-valid-token-while-limited', 'POST', '/api/v1/agent_enroll', ['json' => ['enrollment_token' => $T('ratelimit'), 'device' => dev('GOLD-RL', iid(950))], 'expect' => 429]);
    $g->hook('clear_attempts');
    $enr('enroll-after-window-reset', $T('ratelimit'), dev('GOLD-RL', iid(950)), ['expect' => 201], 'devrl');
    $g->burst('installer-10-failures', 10, fn($i) => ['POST', '/api/v1/agent_installer', ['json' => ['token' => 'garbage', 'arch' => 'amd64']]], 'ten unknown tokens from one address (404 each)');
    $g->step('installer-eleventh-is-rate-limited', 'POST', '/api/v1/agent_installer', ['json' => ['token' => 'garbage', 'arch' => 'amd64'], 'expect' => 429]);
    $g->step('installer-valid-token-while-limited', 'POST', '/api/v1/agent_installer', ['json' => ['token' => $T('inst'), 'arch' => 'amd64'], 'expect' => 429]);
    $g->hook('clear_attempts');
    $g->burst('checkin-40-allowed', 40, fn($i) => ['POST', '/api/v1/agent_checkin', ['auth' => '{{tok:devrl}}', 'json' => ['seq' => $i, 'collected_at' => '{{ts:-5}}', 'agent_version' => '1.0.0']]], '40 check-ins in one minute are the budget');
    $g->step('checkin-41st-is-rate-limited', 'POST', '/api/v1/agent_checkin', ['auth' => '{{tok:devrl}}', 'json' => ['seq' => 41, 'collected_at' => '{{ts:-5}}', 'agent_version' => '1.0.0'], 'expect' => 429]);
    $g->burst('jobs-120-allowed', 120, fn($i) => ['GET', '/api/v1/agent_jobs', ['auth' => '{{tok:devrl}}']], '120 job polls per minute');
    $g->step('jobs-121st-is-rate-limited', 'GET', '/api/v1/agent_jobs', ['auth' => '{{tok:devrl}}', 'expect' => 429]);
    $g->burst('update-60-allowed', 60, fn($i) => ['GET', '/api/v1/agent_update', ['auth' => '{{tok:devrl}}']], '60 update requests per minute (422 each: no arch/version)');
    $g->step('update-61st-is-rate-limited', 'GET', '/api/v1/agent_update', ['auth' => '{{tok:devrl}}', 'expect' => 429]);
    $g->snapshot('final', ['endpoint_agent_devices', 'endpoint_agent_checkins', 'endpoint_agent_enrollment_tokens', 'endpoint_agent_enroll_attempts_by_reason']);
    $g->end();
}

// ----------------------------------------------------------------------------------------------------------------- main
$reset = adapter('reset', ['base_url' => $BASE, 'enabled' => false]);
$g = new Run($BASE, $TLS);
foreach ($reset['asset_ids'] as $name => $id) {
    $g->name('asset', $id, $name);
}
foreach ($reset['binaries'] as $b) {
    $g->binaries[$b['version'] . '/' . $b['arch']] = ['sha256' => $b['sha256'], 'size' => $b['size']];
}
try {
    runScenarios($g, $K, $TLS);
} catch (Throwable $e) {
    fwrite(STDERR, 'ABORTED: ' . $e->getMessage() . "\nproblems so far:\n  " . implode("\n  ", $g->problems) . "\n");
    exit(1);
}

$docs = $g->files;
$docs['_meta.json'] = [
    'format' => GOLDEN_FORMAT,
    'baseline' => 'RivetIT origin/beta c26957c0b (see docs/design/endpoint-baseline.md)',
    'signing_public_key' => $K['signing_public_b64'],
    'signing_key_id' => $reset['signing_key_id'],
    'files' => array_values(array_filter(array_keys($docs), fn($f) => $f !== '_meta.json')),
    'transport_headers_ignored_by_core_comparison' => ['date', 'host', 'connection', 'x-powered-by', 'content-length'],
    'edition_headers_not_produced_by_core' => ['access-control-allow-origin', 'access-control-allow-methods', 'access-control-allow-headers'],
];
$enc = fn($d) => json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n";

if ($g->problems) {
    fwrite(STDERR, "PROBLEMS (" . count($g->problems) . "):\n  " . implode("\n  ", $g->problems) . "\n");
}
if ($mode === 'record') {
    if (!is_dir($DIR)) {
        mkdir($DIR, 0775, true);
    }
    foreach (glob("$DIR/*.json") ?: [] as $old) {
        unlink($old);
    }
    foreach ($docs as $name => $doc) {
        file_put_contents("$DIR/$name", $enc($doc));
    }
    echo 'recorded ' . count($docs) . " files into $DIR\n";
    exit($g->problems ? 1 : 0);
}

// replay: compare
$norm = function ($v) use (&$norm, $opt) {
    if (is_array($v) && !empty($opt['ignore-key-order']) && !array_is_list($v)) {
        ksort($v);
    }
    return is_array($v) ? array_map($norm, $v) : $v;
};
$diffs = 0;
$expectedFiles = array_map('basename', glob("$DIR/*.json") ?: []);
foreach (array_unique(array_merge($expectedFiles, array_keys($docs))) as $name) {
    $path = "$DIR/$name";
    if (!isset($docs[$name])) {
        echo "MISSING in run: $name\n";
        $diffs++;
        continue;
    }
    if (!is_file($path)) {
        echo "NOT RECORDED: $name\n";
        $diffs++;
        continue;
    }
    $a = $norm(json_decode((string) file_get_contents($path), true));
    $b = $norm(json_decode($enc($docs[$name]), true));
    if ($a === $b) {
        continue;
    }
    $diffs++;
    echo "DIFF in $name\n";
    $walk = function ($x, $y, string $p) use (&$walk, &$shown) {
        if ($shown >= 8) {
            return;
        }
        if (is_array($x) && is_array($y)) {
            foreach (array_unique(array_merge(array_keys($x), array_keys($y))) as $k) {
                $walk($x[$k] ?? '<absent>', $y[$k] ?? '<absent>', $p . '/' . $k);
            }
        } elseif ($x !== $y) {
            $shown++;
            echo "  $p\n    recorded: " . json_encode($x, JSON_UNESCAPED_SLASHES) . "\n    actual:   " . json_encode($y, JSON_UNESCAPED_SLASHES) . "\n";
        }
    };
    $shown = 0;
    $walk($a, $b, '');
}
echo $diffs === 0 && !$g->problems ? "replay identical (" . count($docs) . " files)\n" : "replay FAILED ($diffs differing files, " . count($g->problems) . " problems)\n";
exit($diffs === 0 && !$g->problems ? 0 : 1);
