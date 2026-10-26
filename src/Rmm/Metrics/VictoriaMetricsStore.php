<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Metrics;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Rmm\Contracts\ExternalMetricStoreInterface;
use RivetCore\Support\SystemClock;
use RivetCore\Webhooks\UrlPolicy;

/**
 * Reference {@see ExternalMetricStoreInterface} for VictoriaMetrics (single node, or vminsert + vmselect behind two base URLs).
 *
 * Write: InfluxDB line protocol, `POST <writeBase>/write?precision=s`, one line per point:
 * `rmm,asset=<id>,metric=<key>[,instance=<instance>][,label=<label>] value=<number> <unix seconds>`. VictoriaMetrics names the series
 * `<measurement>_<field>` = `rmm_value`, with the tags as labels. An absent instance is the same series as an empty one. A point with the
 * same series and second is one point (VictoriaMetrics de-duplicates samples with equal timestamps), which is what makes re-delivery safe;
 * run it with `-dedup.minScrapeInterval=1s` so that holds across merges too. Batches are split into requests of at most
 * {@see $maxPointsPerRequest} points. 200/204 is success, 429, 5xx, 3xx, 401/403/407/408 and every transport failure are transient, any other 4xx is
 * permanent ({@see ExternalStoreException::$permanent}).
 *
 * Read: the Prometheus HTTP API, `<readBase>/api/v1/query_range` (series) and `/api/v1/query` (peak) with
 * `min_over_time`, `max_over_time`, `avg_over_time` and `count_over_time` over `rmm_value{asset="..",metric="..",instance=".."}[<step>s]`.
 * A bucket [b, b+step) is evaluated at b+step-1 so a sample on a bucket boundary lands in the bucket it starts. Recommended server flags:
 * `-search.latencyOffset=0s`. Several series with the same asset, key and instance (different `label` tag) are combined in PHP.
 *
 * Security: the base URLs are vetted with the {@see UrlPolicy} before every request series and the transport pins the connection to what
 * was vetted. Plain http is refused unless the host is a private address the policy admits AND allowInsecureHttp is true. The token or
 * password is sent in the Authorization header only and never appears in a message. Label values are escaped (backslash, double quote)
 * and a value with control characters or invalid UTF-8 is not representable: it is skipped on write and has no data on read, so a hostile
 * instance name cannot add a matcher or a second selector.
 *
 * @api
 */
final class VictoriaMetricsStore implements ExternalMetricStoreInterface
{
    public const SERIES_NAME = 'rmm_value';
    public const DEFAULT_POINTS_PER_REQUEST = 2000;
    /** Most buckets one series() call asks the store for; a longer range gets a proportionally wider step. */
    public const MAX_BUCKETS = 5000;
    private const MAX_TEXT_LENGTH = 256;
    private const KEY_RE = '/^[a-z0-9_.]{1,64}\z/';

    private readonly HttpTransportInterface $transport;
    private readonly ClockInterface $clock;
    private readonly string $writeBase;
    private readonly string $readBase;
    private readonly int $maxPointsPerRequest;

    /** Dumps show where the store points, never the credentials. @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['writeBase' => $this->writeBase, 'readBase' => $this->readBase, 'auth' => $this->bearerToken !== null ? 'bearer (hidden)' : ($this->basicAuth !== null ? 'basic (hidden)' : 'none')];
    }

    /**
     * @param array{0:string,1:string}|null $basicAuth [user, password]; mutually exclusive with $bearerToken
     */
    public function __construct(
        string $writeUrl,
        private readonly UrlPolicy $urlPolicy,
        ?HttpTransportInterface $transport = null,
        ?string $readUrl = null,
        #[\SensitiveParameter] private readonly ?string $bearerToken = null,
        #[\SensitiveParameter] private readonly ?array $basicAuth = null,
        private readonly int $timeoutSeconds = 10,
        private readonly bool $allowInsecureHttp = false,
        int $maxPointsPerRequest = self::DEFAULT_POINTS_PER_REQUEST,
        ?ClockInterface $clock = null,
    ) {
        $this->writeBase = self::checkBase($writeUrl);
        $this->readBase = self::checkBase($readUrl ?? $writeUrl);
        if ($bearerToken !== null && $basicAuth !== null) {
            throw new \InvalidArgumentException('Use either a bearer token or basic auth, not both.');
        }
        foreach ([$bearerToken, ...($basicAuth ?? [])] as $secret) {
            if ($secret !== null && preg_match('/[\x00-\x1f\x7f]/', $secret) === 1) {
                throw new \InvalidArgumentException('The metric store credentials contain control characters.');
            }
        }
        $this->maxPointsPerRequest = max(1, $maxPointsPerRequest);
        $this->transport = $transport ?? new CurlHttpTransport($urlPolicy);
        $this->clock = $clock ?? new SystemClock();
    }

    // ------------------------------------------------------------------ write

    public function write(array $points): void
    {
        $lines = [];
        foreach ($points as $p) {
            $line = self::lineProtocol($p);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            return;
        }
        $this->vet($this->writeBase);
        foreach (array_chunk($lines, $this->maxPointsPerRequest) as $chunk) {
            $r = $this->send('POST', $this->writeBase . '/write?precision=s', 'text/plain; charset=utf-8', implode("\n", $chunk) . "\n");
            self::expectSuccess($r['status'], 'write');
        }
    }

    /**
     * One line of line protocol, or null when the point cannot be represented (bad asset or key, non-finite value, control characters or
     * invalid UTF-8 in the instance, an instance over 256 bytes). A label that cannot be represented is left out, the point is kept.
     *
     * @internal exposed for tests
     *
     * @param array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string} $p
     */
    public static function lineProtocol(array $p): ?string
    {
        $instance = self::text($p['instance'] ?? null);
        if ($p['asset_id'] <= 0 || preg_match(self::KEY_RE, $p['key']) !== 1 || !is_finite((float) $p['value']) || $instance === null) {
            return null;
        }
        $label = $p['label'] ?? null;
        $label = $label === null ? '' : (self::text($label) ?? '');
        $tags = 'asset=' . $p['asset_id'] . ',metric=' . self::escapeTag($p['key']);
        if ($instance !== '') {
            $tags .= ',instance=' . self::escapeTag($instance);
        }
        if ($label !== '') {
            $tags .= ',label=' . self::escapeTag(mb_substr($label, 0, 64));
        }
        $value = is_int($p['value']) ? (string) $p['value'] : (string) json_encode((float) $p['value']);

        return 'rmm,' . $tags . ' value=' . $value . ' ' . $p['at']->getTimestamp();
    }

    /**
     * Escapes a tag value for line protocol: backslash, comma, space and equals sign get a backslash.
     *
     * @internal exposed for tests
     */
    public static function escapeTag(string $v): string
    {
        return strtr($v, ['\\' => '\\\\', ',' => '\\,', ' ' => '\\ ', '=' => '\\=']);
    }

    // ------------------------------------------------------------------ read

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until, int $stepSeconds): array
    {
        $sel = self::selector($assetId, $key, $instance);
        if ($sel === null || $until < $since) {
            return [];
        }
        $step = max(1, $stepSeconds);
        $first = MetricSteps::floor($since->getTimestamp(), $step);
        $last = MetricSteps::floor($until->getTimestamp(), $step);
        $buckets = intdiv($last - $first, $step) + 1;
        if ($buckets > self::MAX_BUCKETS) {
            $step *= (int) ceil($buckets / self::MAX_BUCKETS);
            $first = MetricSteps::floor($since->getTimestamp(), $step);
            $last = MetricSteps::floor($until->getTimestamp(), $step);
        }
        $this->vet($this->readBase);
        $res = [];
        foreach (['min', 'max', 'avg', 'count'] as $agg) {
            $res[$agg] = $this->queryRange($agg . '_over_time(' . $sel . '[' . $step . 's])', $first + $step - 1, $last + $step - 1, $step);
        }
        /** @var array<int,array{n:float,sum:float,min:float,max:float}> $acc */
        $acc = [];
        foreach ($res['count'] as $labels => $counts) {
            foreach ($counts as $ts => $n) {
                $min = $res['min'][$labels][$ts] ?? null;
                $max = $res['max'][$labels][$ts] ?? null;
                $avg = $res['avg'][$labels][$ts] ?? null;
                if ($n < 1 || $min === null || $max === null || $avg === null) {
                    continue;
                }
                $a = $acc[$ts] ?? ['n' => 0.0, 'sum' => 0.0, 'min' => $min, 'max' => $max];
                $acc[$ts] = ['n' => $a['n'] + $n, 'sum' => $a['sum'] + $avg * $n, 'min' => min($a['min'], $min), 'max' => max($a['max'], $max)];
            }
        }
        ksort($acc);
        $out = [];
        foreach ($acc as $ts => $a) {
            $start = $ts - $step + 1;
            if ($start < $first) {
                continue;
            }
            $out[] = ['at' => self::utc($start), 'min' => $a['min'], 'max' => $a['max'], 'avg' => $a['sum'] / $a['n'], 'samples' => (int) round($a['n'])];
        }

        return $out;
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        $sel = self::selector($assetId, $key, $instance);
        if ($sel === null) {
            return null;
        }
        $now = $this->clock->now()->getTimestamp();
        $from = MetricSteps::floor($since->getTimestamp(), MetricSteps::HOUR);
        $step = MetricSteps::forSpan(max(0, $now - $from));
        if ($step === MetricSteps::DAY) {
            $from = MetricSteps::floor($from, $step);
        }
        if ($now < $from) {
            return null;
        }
        $window = ($now - $from + 1) . 's';
        $this->vet($this->readBase);
        $agg = [];
        foreach (['min', 'max', 'avg', 'count'] as $fn) {
            $agg[$fn] = $this->queryInstant($fn . '_over_time(' . $sel . '[' . $window . '])', $now);
        }
        $n = 0.0;
        $sum = 0.0;
        $min = null;
        $max = null;
        foreach ($agg['count'] as $labels => $count) {
            if ($count < 1 || !isset($agg['min'][$labels], $agg['max'][$labels], $agg['avg'][$labels])) {
                continue;
            }
            $n += $count;
            $sum += $agg['avg'][$labels] * $count;
            $min = $min === null ? $agg['min'][$labels] : min($min, $agg['min'][$labels]);
            $max = $max === null ? $agg['max'][$labels] : max($max, $agg['max'][$labels]);
        }
        if ($n < 1 || $min === null || $max === null) {
            return null;
        }
        $firstEval = MetricSteps::floor($from, $step) + $step - 1;
        $lastEval = MetricSteps::floor($now, $step) + $step - 1;
        $byTs = [];
        foreach ($this->queryRange('max_over_time(' . $sel . '[' . $step . 's])', $firstEval, $lastEval, $step) as $points) {
            foreach ($points as $ts => $v) {
                $byTs[$ts] = isset($byTs[$ts]) ? max($byTs[$ts], $v) : $v;
            }
        }
        $peakTs = $from + $step - 1;
        $best = null;
        ksort($byTs);
        foreach ($byTs as $ts => $v) {
            if ($best === null || $v >= $best) {
                $best = $v;
                $peakTs = $ts;
            }
        }

        return ['min' => $min, 'max' => $max, 'avg' => $sum / $n, 'samples' => (int) round($n), 'peak_at' => self::utc($peakTs - $step + 1)];
    }

    public function ping(): bool
    {
        try {
            $parts = parse_url($this->readBase);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                return false;
            }
            $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $this->vet($origin . '/');
            $r = $this->send('GET', $origin . '/health', '', '');

            return $r['status'] >= 200 && $r['status'] < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The PromQL series selector for one (asset, key, instance), or null when it cannot be written safely (non-positive asset, key outside
     * [a-z0-9_.]{1,64}, instance with control characters, invalid UTF-8 or over 256 bytes). Backslash and double quote are escaped, so the
     * values cannot leave their quotes. A null or empty instance selects the series without an instance label.
     *
     * @internal exposed for tests
     */
    public static function selector(int $assetId, string $key, ?string $instance): ?string
    {
        $inst = self::text($instance);
        if ($assetId <= 0 || preg_match(self::KEY_RE, $key) !== 1 || $inst === null) {
            return null;
        }

        return self::SERIES_NAME . '{asset="' . $assetId . '",metric="' . self::escapePromql($key) . '",instance="' . self::escapePromql($inst) . '"}';
    }

    /** @internal exposed for tests */
    public static function escapePromql(string $v): string
    {
        return strtr($v, ['\\' => '\\\\', '"' => '\\"']);
    }

    // ------------------------------------------------------------------ http

    /**
     * @return array<string,array<int,float>> series (keyed by its label set) => evaluation time => value
     */
    private function queryRange(string $expr, int $start, int $end, int $step): array
    {
        $r = $this->send('POST', $this->readBase . '/api/v1/query_range', 'application/x-www-form-urlencoded',
            http_build_query(['query' => $expr, 'start' => $start, 'end' => $end, 'step' => $step], '', '&', PHP_QUERY_RFC3986));
        self::expectSuccess($r['status'], 'query');
        $out = [];
        foreach (self::results($r['body'], 'matrix') as $item) {
            $values = $item['values'] ?? null;
            if (!is_array($values)) {
                continue;
            }
            $k = self::labelKey($item);
            foreach ($values as $pair) {
                $v = self::pair($pair);
                if ($v !== null) {
                    $out[$k][$v[0]] = $v[1];
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string,float>
     */
    private function queryInstant(string $expr, int $time): array
    {
        $r = $this->send('POST', $this->readBase . '/api/v1/query', 'application/x-www-form-urlencoded',
            http_build_query(['query' => $expr, 'time' => $time], '', '&', PHP_QUERY_RFC3986));
        self::expectSuccess($r['status'], 'query');
        $out = [];
        foreach (self::results($r['body'], 'vector') as $item) {
            $v = self::pair($item['value'] ?? null);
            if ($v !== null) {
                $out[self::labelKey($item)] = $v[1];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function results(string $body, string $type): array
    {
        try {
            $json = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExternalStoreException('metric store returned an unreadable answer');
        }
        $data = is_array($json) ? ($json['data'] ?? null) : null;
        if (!is_array($json) || ($json['status'] ?? null) !== 'success' || !is_array($data) || ($data['resultType'] ?? null) !== $type || !is_array($data['result'] ?? null)) {
            throw new ExternalStoreException('metric store returned an unexpected answer');
        }

        return array_values(array_filter($data['result'], 'is_array'));
    }

    /** @param array<string,mixed> $item */
    private static function labelKey(array $item): string
    {
        $m = is_array($item['metric'] ?? null) ? $item['metric'] : [];
        ksort($m);

        return (string) json_encode($m);
    }

    /** @return array{0:int,1:float}|null */
    private static function pair(mixed $pair): ?array
    {
        if (!is_array($pair) || count($pair) !== 2 || !is_numeric($pair[0] ?? null) || !is_string($pair[1] ?? null) || !is_numeric($pair[1])) {
            return null;
        }
        $v = (float) $pair[1];

        return is_finite($v) ? [(int) $pair[0], $v] : null;
    }

    /**
     * @return array{status:int, body:string}
     */
    private function send(string $method, string $url, string $contentType, string $body): array
    {
        $headers = ['Accept: application/json'];
        if ($contentType !== '') {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        if ($this->bearerToken !== null && $this->bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->bearerToken;
        } elseif ($this->basicAuth !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->basicAuth[0] . ':' . $this->basicAuth[1]);
        }
        try {
            return $this->transport->request($method, $url, $headers, $body, $this->timeoutSeconds);
        } catch (ExternalStoreException $e) {
            throw new ExternalStoreException($e->getMessage(), $e->permanent);
        } catch (\Throwable) {
            // not chained on purpose: a transport's own message could carry the request (and with it the credentials)
            throw new ExternalStoreException('metric store request failed');
        }
    }

    private static function expectSuccess(int $status, string $what): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }
        // 4xx other than 429 means this request will never be accepted; authentication problems heal with a configuration fix and are retried.
        $permanent = $status >= 400 && $status < 500 && !in_array($status, [401, 403, 407, 408, 429], true);
        throw new ExternalStoreException('metric store ' . $what . ' failed: HTTP ' . $status, $permanent);
    }

    /** Vets one base URL; refuses plain http unless it is an explicitly allowed private destination. */
    private function vet(string $url): void
    {
        $target = $this->urlPolicy->vet($url);
        if ($target === null) {
            throw new ExternalStoreException('metric store URL is not allowed by the URL policy');
        }
        if (str_starts_with(strtolower($url), 'http://')) {
            $private = true;
            foreach ($target['ips'] as $ip) {
                $private = $private && !UrlPolicy::isPublicIp($ip);
            }
            if (!$this->allowInsecureHttp || !$private) {
                throw new ExternalStoreException('plain http to the metric store is refused (use https)');
            }
        }
    }

    private static function checkBase(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host'])
            || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            throw new \InvalidArgumentException('The metric store URL must be http(s)://host[:port][/path] without credentials, query or fragment.');
        }

        return rtrim($url, '/');
    }

    /** The text as is when it can be a label value, '' for null, null when it cannot (control characters, invalid UTF-8, too long). */
    private static function text(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return '';
        }
        if (strlen($s) > self::MAX_TEXT_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $s) === 1 || !mb_check_encoding($s, 'UTF-8')) {
            return null;
        }

        return $s;
    }

    private static function utc(int $ts): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $ts);
    }
}
