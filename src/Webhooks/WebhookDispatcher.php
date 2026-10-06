<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;

/**
 * Synchronous, in-request webhook delivery: fires immediately and logs one row per attempt to
 * `webhook_deliveries`, for callers that want to know the outcome right away. It never throws - a webhook
 * failure must never break the action that triggered it.
 *
 * Bodies are signed with HMAC-SHA256 of the exact bytes sent, using the endpoint's secret. The edition chooses
 * the header names ($headerPrefixes), e.g. ['X-ITFlow', 'X-RivetIT'] to keep what existing receivers verify.
 * Redirects are never followed, only http/https are spoken (CURLOPT_PROTOCOLS) and TLS is always verified.
 *
 * Besides the legacy "<prefix>-Signature" headers (HMAC of the body; unchanged), every request carries
 * X-Rivet-Timestamp (unix seconds) and X-Rivet-Signature-V2: "t=<ts>,v1=<hmac-sha256 of "<ts>.<body>">", so a
 * receiver can reject replays. The timestamp is per ATTEMPT (current clock) while the body stays byte-identical on
 * retries; pass $signedAt to deliverTo() to pin it, and note that an explicit $emittedAt (with no $signedAt) pins the
 * timestamp to that instant.
 *
 * SSRF (UrlPolicy is the single place; its allowedNetworks list can admit specific private LAN ranges, never loopback/link-local): with no UrlPolicy the dispatcher behaves as before (the edition vets URLs on save). Inject a UrlPolicy to vet
 * the URL on every attempt and PIN the connection to the vetted addresses (CURLOPT_RESOLVE, defeats DNS rebinding), or
 * set $requireUrlPolicy=true to make a policy mandatory (a default UrlPolicy is used when none is injected). A URL the
 * policy rejects is never contacted; the attempt is logged with error "endpoint URL not allowed".
 *
 * @api
 */
class WebhookDispatcher
{
    public const DEFAULT_TIMEOUT_SECONDS = 10;

    /** @var \Closure(string,string,list<string>,int,?array{host:string,port:int,ips:list<string>},string=):array{status:?int,body:?string,error:?string} */
    private \Closure $transport;

    private ?UrlPolicy $urlPolicy;

    /**
     * @param list<string> $headerPrefixes
     * @param (\Closure(string,string,list<string>,int,?array{host:string,port:int,ips:list<string>},string=):array{status:?int,body:?string,error:?string})|null $transport
     *        for tests; defaults to curl. The 5th argument is the vetted target to pin to (null without a policy); older 3-4 argument closures still work.
     */
    public function __construct(
        private DatabaseInterface $database,
        private WebhookSubscriptionsInterface $subscriptions,
        private ClockInterface $clock,
        private array $headerPrefixes = ['X-RivetCore'],
        ?\Closure $transport = null,
        private int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?UrlPolicy $urlPolicy = null,
        bool $requireUrlPolicy = false,
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
        $this->urlPolicy = $urlPolicy ?? ($requireUrlPolicy ? new UrlPolicy() : null);
    }

    /**
     * Deliver one event to ONE endpoint, for a queued retryable job. $emittedAt (ISO-8601 UTC, as sent in the first attempt) keeps the
     * body byte-identical across retries, so a receiver can de-duplicate and the legacy signature stays valid. The v2 signature
     * timestamp is $signedAt if given, else the time of $emittedAt if given, else now (per attempt). Never throws.
     *
     * $options (optional, also readable from WebhookSubscription::$options; these win) selects a platform format:
     *   format          a PayloadFormatter format (json, slack, discord, ntfy, template, ...); the body and Content-Type come from it
     *   format_options  options for the formatter (chat_id, template, app_name, ...)
     *   template, template_encoding   shortcuts for the "template" format
     *   method          POST (default) or PUT
     *   extraHeaders    array<string,string> added to the request (Authentication::headers() + Destination headers); validated
     * The signatures cover the exact bytes sent. A URL containing {txn} gets a per-message id (hash of the body). A bad
     * header or method, or a format that cannot render, fails the attempt without contacting the endpoint.
     *
     * @param array<string,mixed>|null $options
     * @return array{webhook_id:int,http_status:?int,duration_ms:int,error:?string,ok:bool}
     */
    public function deliverTo(int $webhookId, string $eventType, array $payload, int $attempt = 1, ?string $emittedAt = null, ?int $signedAt = null, ?array $options = null): array
    {
        $subscriber = null;
        try {
            $subscriber = $this->subscriptions instanceof WebhookSubscriptionLookupInterface ? $this->subscriptions->find($webhookId) : null;
        } catch (\Throwable) {
            $subscriber = null;
        }
        if ($subscriber === null) {
            // The endpoint was deleted or disabled since the event was queued: nothing to retry.
            return ['webhook_id' => $webhookId, 'http_status' => null, 'duration_ms' => 0, 'error' => 'endpoint no longer exists', 'ok' => false, 'gone' => true];
        }
        $timestamp = $emittedAt ?? $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $opts = array_merge($subscriber->options, $options ?? []);
        $signed = $signedAt ?? ($emittedAt !== null ? (strtotime($emittedAt) ?: null) : null);
        if (self::hasFormatOptions($opts)) {
            return $this->sendFormatted($subscriber, $eventType, $payload, $timestamp, $opts, max(1, $attempt), $signed);
        }
        $body = json_encode([
            'event' => $eventType,
            'timestamp' => $timestamp,
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->sendOne($subscriber, $eventType, $body, max(1, $attempt), $signed);
    }

    /**
     * @return list<array{webhook_id:int,http_status:?int,duration_ms:int,error:?string,ok:bool}>
     */
    public function deliver(string $eventType, array $payload): array
    {
        $results = [];
        try {
            $subscribers = $this->subscriptions->forEvent($eventType);
        } catch (\Throwable) {
            return $results;
        }
        if ($subscribers === []) {
            return $results;
        }

        $timestamp = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $body = json_encode([
            'event' => $eventType,
            'timestamp' => $timestamp,
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($subscribers as $subscriber) {
            $results[] = self::hasFormatOptions($subscriber->options)
                ? $this->sendFormatted($subscriber, $eventType, $payload, $timestamp, $subscriber->options, 1, null)
                : $this->sendOne($subscriber, $eventType, $body);
        }

        return $results;
    }

    /** @param array<string,mixed> $opts */
    private static function hasFormatOptions(array $opts): bool
    {
        return isset($opts['format']) || isset($opts['method']) || !empty($opts['extraHeaders']);
    }

    /**
     * Format-aware delivery: body from PayloadFormatter, headers validated, method restricted. Never throws.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $opts
     */
    private function sendFormatted(WebhookSubscription $subscriber, string $eventType, array $payload, string $timestamp, array $opts, int $attempt, ?int $signedAt): array
    {
        $fail = function (string $error) use ($subscriber, $eventType, $attempt): array {
            $this->logAttempt($subscriber, $eventType, null, 0, $attempt, '', $error);

            return ['webhook_id' => $subscriber->webhookId, 'http_status' => null, 'duration_ms' => 0, 'error' => $error, 'ok' => false];
        };
        $method = strtoupper((string) ($opts['method'] ?? 'POST'));
        if ($method !== 'POST' && $method !== 'PUT') {
            return $fail('unsupported HTTP method');
        }
        $extra = $opts['extraHeaders'] ?? [];
        if (!is_array($extra)) {
            return $fail('invalid extra headers');
        }
        try {
            $format = (string) ($opts['format'] ?? 'json');
            $fo = is_array($opts['format_options'] ?? null) ? $opts['format_options'] : [];
            foreach (['template', 'template_encoding'] as $k) {
                if (isset($opts[$k])) {
                    $fo[$k] = $opts[$k];
                }
            }
            $formatted = PayloadFormatter::format($format, ['event' => $eventType, 'timestamp' => $timestamp, 'data' => $payload], $fo);
        } catch (\Throwable) {
            return $fail('payload format failed');
        }
        $headers = $this->mergeExtraHeaders($formatted->headers, $extra);
        if ($headers === null) {
            return $fail('invalid extra header');
        }

        return $this->sendOne($subscriber, $eventType, $formatted->body, $attempt, $signedAt, $formatted->contentType, $headers, $method);
    }

    /**
     * Validate and merge extra headers (formatter headers first, then configured ones, which win case-insensitively).
     * Returns null when any name or value is unacceptable: bad token, CR/LF/NUL, framing/hop-by-hop headers, our
     * signature/timestamp/event headers.
     *
     * @param array<string,string> $formatter
     * @param array<mixed> $configured
     * @return list<string>|null "Name: value" lines
     */
    private function mergeExtraHeaders(array $formatter, array $configured): ?array
    {
        $merged = [];
        foreach ([$formatter, $configured] as $set) {
            foreach ($set as $name => $value) {
                if (!is_string($name) || !is_scalar($value)) {
                    return null;
                }
                $value = (string) $value;
                if (!Authentication::isValidHeaderName($name) || Authentication::isForbiddenHeaderName($name)
                    || strlen($name) > 64 || strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    return null;
                }
                foreach ($this->headerPrefixes as $prefix) {
                    if (strcasecmp($name, $prefix . '-Signature') === 0 || strcasecmp($name, $prefix . '-Event') === 0) {
                        return null;
                    }
                }
                $merged[strtolower($name)] = $name . ': ' . $value;
            }
        }

        return array_values($merged);
    }

    /**
     * @param list<string> $extraHeaders ready "Name: value" lines, already validated
     * @return array{webhook_id:int,http_status:?int,duration_ms:int,error:?string,ok:bool}
     */
    private function sendOne(WebhookSubscription $subscriber, string $eventType, string $body, int $attempt = 1, ?int $signedAt = null, string $contentType = 'application/json', array $extraHeaders = [], string $method = 'POST'): array
    {
        $signature = 'sha256=' . hash_hmac('sha256', $body, $subscriber->secret);
        $ts = $signedAt ?? $this->clock->now()->getTimestamp();
        $headers = ['Content-Type: ' . $contentType, 'X-Rivet-Timestamp: ' . $ts, 'X-Rivet-Signature-V2: ' . self::signatureV2($ts, $body, $subscriber->secret)];
        foreach ($this->headerPrefixes as $prefix) {
            $headers[] = $prefix . '-Signature: ' . $signature;
            $headers[] = $prefix . '-Event: ' . $eventType;
        }
        array_push($headers, ...$extraHeaders);
        $url = $subscriber->url;
        if ($method === 'PUT' && str_contains($url, '{txn}')) {
            // Matrix-style idempotent PUT: one id per message body, so a retry of the same event reuses it.
            $url = str_replace('{txn}', substr(hash('sha256', $eventType . "\n" . $body), 0, 32), $url);
        }

        $start = microtime(true);
        try {
            $target = null;
            if ($this->urlPolicy !== null) {
                $target = $this->urlPolicy->vet($url);
            }
            if ($this->urlPolicy !== null && $target === null) {
                $r = ['status' => null, 'body' => null, 'error' => 'endpoint URL not allowed'];
            } else {
                $r = ($this->transport)($url, $body, $headers, $this->timeoutSeconds, $target, $method);
            }
        } catch (\Throwable $e) {
            $r = ['status' => null, 'body' => null, 'error' => 'transport failed'];
        }
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $snippet = $r['error'] ?? ($r['body'] !== null ? substr($r['body'], 0, 1000) : null);

        $this->logAttempt($subscriber, $eventType, $r['status'], $durationMs, $attempt, $body, $snippet);

        $ok = $r['error'] === null && $r['status'] !== null && $r['status'] >= 200 && $r['status'] < 300;

        return ['webhook_id' => $subscriber->webhookId, 'http_status' => $r['status'], 'duration_ms' => $durationMs, 'error' => $r['error'] ?? ($ok ? null : 'HTTP ' . $r['status']), 'ok' => $ok];
    }

    private function logAttempt(WebhookSubscription $subscriber, string $eventType, ?int $status, int $durationMs, int $attempt, string $body, ?string $snippet): void
    {
        try {
            $this->database->execute(
                'INSERT INTO webhook_deliveries
                    (webhook_id, event_type, http_status, duration_ms, attempt_number, request_payload_json, response_body_snippet)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$subscriber->webhookId, $eventType, $status, $durationMs, $attempt, $body, $snippet]
            );
        } catch (\Throwable) {
            // a logging failure must never break the caller
        }
    }

    /** Value of X-Rivet-Signature-V2: "t=<ts>,v1=<hex hmac-sha256 of "<ts>.<body>">". */
    public static function signatureV2(int $timestamp, string $body, string $secret): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * The curl options for one delivery. Public/static so the pinning can be asserted without a network call.
     *
     * @param list<string> $headers
     * @param array{host:string,port:int,ips:list<string>}|null $target vetted target to pin to
     * @return array<int,mixed>
     */
    public static function curlOptions(string $body, array $headers, int $timeout, ?array $target = null, string $method = 'POST'): array
    {
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($method === 'PUT') {
            $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
        }
        if ($target !== null && $target['ips'] !== [] && !filter_var($target['host'], FILTER_VALIDATE_IP)) {
            $options[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . implode(',', $target['ips'])];
        }
        if ($target !== null) {
            // A proxy would resolve the name itself and bypass the pin; pinned requests always go direct.
            $options[CURLOPT_PROXY] = '';
            $options[CURLOPT_NOPROXY] = '*';
        }

        return $options;
    }

    /**
     * The URL curl must be given for a vetted target: the same host the pin (CURLOPT_RESOLVE) is keyed on, so a spelling
     * such as "example.com." (trailing dot) cannot make curl resolve the name itself. Scheme, port, path and query are kept.
     *
     * @param array{host:string,port:int,ips:list<string>} $target
     */
    public static function pinnedUrl(string $url, array $target): string
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme'])) {
            return $url;
        }
        $host = str_contains($target['host'], ':') ? '[' . $target['host'] . ']' : $target['host'];

        return strtolower($p['scheme']) . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '')
            . ($p['path'] ?? '') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /** @return array{status:?int,body:?string,error:?string} */
    private static function curlTransport(string $url, string $body, array $headers, int $timeout, ?array $target = null, string $method = 'POST'): array
    {
        if ($target !== null) {
            $url = self::pinnedUrl($url, $target);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => null, 'body' => null, 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, self::curlOptions($body, $headers, $timeout, $target, $method));
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch) ?: 'unknown curl error';

            return ['status' => null, 'body' => null, 'error' => $error];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ['status' => $status, 'body' => (string) $response, 'error' => null];
    }
}
