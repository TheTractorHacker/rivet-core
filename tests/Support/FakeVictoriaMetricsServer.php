<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

/**
 * A fake VictoriaMetrics for tests. As a class it launches itself with `php -S` on a free loopback port (this same file is the router
 * script) and lets a test steer it; as the router it implements just enough of the real thing:
 *
 *  - POST /write?precision=s|ms|ns  InfluxDB line protocol (escapes, tags, one field, timestamp), stored per series and second, so a
 *    repeated point is one point (idempotent). The series is named <measurement>_<field> ("rmm_value") with the tags as labels.
 *  - POST|GET /api/v1/query_range and /api/v1/query  only the five shapes the store generates: {min,max,avg,count}_over_time(rmm_value{asset="..",
 *    metric="..",instance=".."}[<n>s]); a window is (t-n, t]. Anything else is a 400, so an unescaped label that breaks the shape is noticed.
 *  - GET /health.
 * A path prefix (/insert/0/influx/write) is accepted: the route is the path suffix.
 *
 * Control (setMode): status (answer every request with it), sleep (seconds before answering), body (size of a junk body in bytes),
 * redirect (answer 302), require_auth (the exact Authorization header value, else 401), scope (write|read|all, default all).
 * Everything the server received is recorded ({@see requests()}).
 */
final class FakeVictoriaMetricsServer
{
    /** @var resource|null */
    private $proc = null;
    public readonly int $port;
    private readonly string $dir;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/fakevm_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/control.json', '{}');
        $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
        if ($sock === false) {
            throw new \RuntimeException('no free port');
        }
        $this->port = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
        fclose($sock);
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $this->port, __FILE__],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, __DIR__, array_merge(getenv(), ['FAKE_VM_DIR' => $this->dir]));
        if (!is_resource($proc)) {
            throw new \RuntimeException('cannot start the fake VictoriaMetrics');
        }
        $this->proc = $proc;
        for ($i = 0; $i < 60; ++$i) {
            $c = @fsockopen('127.0.0.1', $this->port);
            if ($c !== false) {
                fclose($c);

                return;
            }
            usleep(100000);
        }
        $this->stop();
        throw new \RuntimeException('the fake VictoriaMetrics did not start');
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /** @param array<string,mixed> $control */
    public function setMode(array $control): void
    {
        file_put_contents($this->dir . '/control.json', (string) json_encode($control));
    }

    public function reset(): void
    {
        $this->setMode([]);
        @unlink($this->dir . '/data.json');
        @unlink($this->dir . '/requests.jsonl');
    }

    /** @return list<array{method:string,path:string,query:string,auth:?string,type:?string,body:string}> */
    public function requests(): array
    {
        $out = [];
        foreach (file($this->dir . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $out[] = json_decode($line, true);
        }

        return $out;
    }

    /** Number of distinct (series, second) samples stored. */
    public function storedSamples(): int
    {
        $d = json_decode((string) @file_get_contents($this->dir . '/data.json'), true);
        $n = 0;
        foreach (is_array($d) ? $d : [] as $s) {
            $n += count($s['samples']);
        }

        return $n;
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        $this->proc = null;
        if (isset($this->dir) && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    // ------------------------------------------------------------------ the router side

    public static function serve(): void
    {
        $dir = (string) getenv('FAKE_VM_DIR');
        $path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $query = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        $body = (string) file_get_contents('php://input');
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        file_put_contents($dir . '/requests.jsonl', json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'query' => $query, 'auth' => $auth,
            'type' => $_SERVER['CONTENT_TYPE'] ?? null, 'body' => $body]) . "\n", FILE_APPEND);
        $control = json_decode((string) @file_get_contents($dir . '/control.json'), true);
        $control = is_array($control) ? $control : [];
        $route = match (true) {
            str_ends_with($path, '/api/v1/query_range') => 'query_range',
            str_ends_with($path, '/api/v1/query') => 'query',
            str_ends_with($path, '/write') => 'write',
            $path === '/health' => 'health',
            default => 'unknown',
        };
        $kind = $route === 'write' ? 'write' : 'read';
        $scope = (string) ($control['scope'] ?? 'all');
        $steered = $scope === 'all' || $scope === $kind;
        if ($steered && isset($control['sleep'])) {
            sleep((int) $control['sleep']);
        }
        if ($steered && isset($control['require_auth']) && $auth !== $control['require_auth']) {
            self::reply(401, 'unauthorized');

            return;
        }
        if ($steered && !empty($control['redirect'])) {
            header('Location: http://127.0.0.1:1/elsewhere', true, 302);

            return;
        }
        if ($steered && isset($control['body'])) {
            self::reply((int) ($control['status'] ?? 200), str_repeat('x', (int) $control['body']));

            return;
        }
        if ($steered && isset($control['status'])) {
            self::reply((int) $control['status'], 'forced');

            return;
        }
        $params = [];
        parse_str($query, $params);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route !== 'write') {
            parse_str($body, $form);
            $params = $form + $params;
        }
        switch ($route) {
            case 'health':
                self::reply(200, 'OK');
                break;
            case 'write':
                self::write($dir, (string) ($params['precision'] ?? 'ns'), $body);
                break;
            case 'query_range':
            case 'query':
                self::query($dir, $route, $params);
                break;
            default:
                self::reply(404, 'not found');
        }
    }

    private static function reply(int $status, string $body, string $type = 'text/plain'): void
    {
        http_response_code($status);
        header('Content-Type: ' . $type);
        echo $body;
    }

    /** @return array<string,array{labels:array<string,string>,samples:array<int,float>}> */
    private static function load(string $dir): array
    {
        $d = json_decode((string) @file_get_contents($dir . '/data.json'), true);

        return is_array($d) ? $d : [];
    }

    private static function write(string $dir, string $precision, string $body): void
    {
        $divisor = match ($precision) {
            's' => 1,
            'ms' => 1000,
            'ns' => 1_000_000_000,
            default => 0,
        };
        if ($divisor === 0) {
            self::reply(400, 'bad precision');

            return;
        }
        $data = self::load($dir);
        foreach (explode("\n", $body) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parsed = self::parseLine($line);
            if ($parsed === null) {
                self::reply(400, 'cannot parse line');

                return;
            }
            [$labels, $ts, $value] = $parsed;
            $ts = intdiv($ts, $divisor);
            ksort($labels);
            $id = (string) json_encode($labels);
            $data[$id]['labels'] = $labels;
            $data[$id]['samples'][$ts] = $value;
        }
        file_put_contents($dir . '/data.json', json_encode($data));
        self::reply(204, '');
    }

    /** @return array{0:array<string,string>,1:int,2:float}|null */
    private static function parseLine(string $line): ?array
    {
        $parts = self::splitUnescaped($line, ' ');
        if (count($parts) !== 3) {
            return null;
        }
        $head = self::splitUnescaped($parts[0], ',');
        $measurement = self::unescape(array_shift($head));
        $labels = [];
        foreach ($head as $tag) {
            $kv = self::splitUnescaped($tag, '=');
            if (count($kv) !== 2 || $kv[0] === '' || $kv[1] === '') {
                return null;
            }
            $labels[self::unescape($kv[0])] = self::unescape($kv[1]);
        }
        $fields = self::splitUnescaped($parts[1], ',');
        if (count($fields) !== 1) {
            return null;
        }
        $fv = self::splitUnescaped($fields[0], '=');
        if (count($fv) !== 2 || !is_numeric($fv[1]) || !preg_match('/^-?\d+$/', $parts[2])) {
            return null;
        }
        $labels['__name__'] = $measurement . '_' . self::unescape($fv[0]);

        return [$labels, (int) $parts[2], (float) $fv[1]];
    }

    /** @return list<string> */
    private static function splitUnescaped(string $s, string $sep): array
    {
        $out = [];
        $cur = '';
        for ($i = 0, $n = strlen($s); $i < $n; ++$i) {
            if ($s[$i] === '\\' && $i + 1 < $n) {
                $cur .= $s[$i] . $s[++$i];
            } elseif ($s[$i] === $sep) {
                $out[] = $cur;
                $cur = '';
            } else {
                $cur .= $s[$i];
            }
        }
        $out[] = $cur;

        return $out;
    }

    private static function unescape(string $s): string
    {
        return (string) preg_replace('/\\\\([ ,=\\\\])/', '$1', $s);
    }

    /** @param array<string,mixed> $p */
    private static function query(string $dir, string $route, array $p): void
    {
        $q = (string) ($p['query'] ?? '');
        $m = [];
        if (preg_match('/^(min|max|avg|count)_over_time\(rmm_value\{asset="(\d+)",metric="((?:[^"\\\\]|\\\\.)*)",instance="((?:[^"\\\\]|\\\\.)*)"\}\[(\d+)s\]\)$/s', $q, $m) !== 1) {
            self::reply(400, 'unsupported query', 'text/plain');

            return;
        }
        $fn = $m[1];
        $asset = $m[2];
        $metric = preg_replace('/\\\\(["\\\\])/', '$1', $m[3]);
        $instance = preg_replace('/\\\\(["\\\\])/', '$1', $m[4]);
        $window = (int) $m[5];
        $series = [];
        foreach (self::load($dir) as $s) {
            $l = $s['labels'];
            if (($l['__name__'] ?? '') === 'rmm_value' && ($l['asset'] ?? '') === $asset && ($l['metric'] ?? '') === $metric && ($l['instance'] ?? '') === $instance) {
                $series[] = $s;
            }
        }
        $evals = [];
        if ($route === 'query_range') {
            $start = (int) ($p['start'] ?? 0);
            $end = (int) ($p['end'] ?? 0);
            $step = max(1, (int) ($p['step'] ?? 60));
            if (($end - $start) / $step > 11000) {
                self::reply(422, 'too many points');

                return;
            }
            for ($t = $start; $t <= $end; $t += $step) {
                $evals[] = $t;
            }
        } else {
            $evals[] = (int) ($p['time'] ?? time());
        }
        $result = [];
        foreach ($series as $s) {
            $values = [];
            foreach ($evals as $t) {
                $in = [];
                foreach ($s['samples'] as $ts => $v) {
                    if ($ts > $t - $window && $ts <= $t) {
                        $in[] = $v;
                    }
                }
                if ($in === []) {
                    continue;
                }
                $v = match ($fn) {
                    'min' => min($in),
                    'max' => max($in),
                    'avg' => array_sum($in) / count($in),
                    default => (float) count($in),
                };
                $values[] = [$t, (string) json_encode((float) $v)];
            }
            if ($values === []) {
                continue;
            }
            $metricLabels = $s['labels'];
            unset($metricLabels['__name__']);
            $result[] = $route === 'query_range' ? ['metric' => (object) $metricLabels, 'values' => $values] : ['metric' => (object) $metricLabels, 'value' => $values[0]];
        }
        self::reply(200, (string) json_encode(['status' => 'success', 'data' => ['resultType' => $route === 'query_range' ? 'matrix' : 'vector', 'result' => $result]]), 'application/json');
    }
}

if (PHP_SAPI === 'cli-server' && getenv('FAKE_VM_DIR') !== false) {
    FakeVictoriaMetricsServer::serve();
}
