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
 * Redirects are never followed and TLS is always verified.
 */
class WebhookDispatcher
{
    public const DEFAULT_TIMEOUT_SECONDS = 10;

    /** @var \Closure(string,string,list<string>,int):array{status:?int,body:?string,error:?string} */
    private \Closure $transport;

    /**
     * @param list<string> $headerPrefixes
     * @param (\Closure(string,string,list<string>,int):array{status:?int,body:?string,error:?string})|null $transport for tests; defaults to curl
     */
    public function __construct(
        private DatabaseInterface $database,
        private WebhookSubscriptionsInterface $subscriptions,
        private ClockInterface $clock,
        private array $headerPrefixes = ['X-RivetCore'],
        ?\Closure $transport = null,
        private int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    /**
     * Deliver one event to ONE endpoint, for a queued retryable job. $emittedAt (ISO-8601 UTC, as sent in the first attempt) keeps the
     * body byte-identical across retries, so a receiver can de-duplicate and the signature stays valid. Never throws.
     *
     * @return array{webhook_id:int,http_status:?int,duration_ms:int,error:?string,ok:bool}
     */
    public function deliverTo(int $webhookId, string $eventType, array $payload, int $attempt = 1, ?string $emittedAt = null): array
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
        $body = json_encode([
            'event' => $eventType,
            'timestamp' => $emittedAt ?? $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->sendOne($subscriber, $eventType, $body, max(1, $attempt));
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

        $body = json_encode([
            'event' => $eventType,
            'timestamp' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($subscribers as $subscriber) {
            $results[] = $this->sendOne($subscriber, $eventType, $body);
        }

        return $results;
    }

    private function sendOne(WebhookSubscription $subscriber, string $eventType, string $body, int $attempt = 1): array
    {
        $signature = 'sha256=' . hash_hmac('sha256', $body, $subscriber->secret);
        $headers = ['Content-Type: application/json'];
        foreach ($this->headerPrefixes as $prefix) {
            $headers[] = $prefix . '-Signature: ' . $signature;
            $headers[] = $prefix . '-Event: ' . $eventType;
        }

        $start = microtime(true);
        try {
            $r = ($this->transport)($subscriber->url, $body, $headers, $this->timeoutSeconds);
        } catch (\Throwable $e) {
            $r = ['status' => null, 'body' => null, 'error' => 'transport failed'];
        }
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $snippet = $r['error'] ?? ($r['body'] !== null ? substr($r['body'], 0, 1000) : null);

        try {
            $this->database->execute(
                'INSERT INTO webhook_deliveries
                    (webhook_id, event_type, http_status, duration_ms, attempt_number, request_payload_json, response_body_snippet)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$subscriber->webhookId, $eventType, $r['status'], $durationMs, $attempt, $body, $snippet]
            );
        } catch (\Throwable) {
            // a logging failure must never break the caller
        }

        $ok = $r['error'] === null && $r['status'] !== null && $r['status'] >= 200 && $r['status'] < 300;

        return ['webhook_id' => $subscriber->webhookId, 'http_status' => $r['status'], 'duration_ms' => $durationMs, 'error' => $r['error'] ?? ($ok ? null : 'HTTP ' . $r['status']), 'ok' => $ok];
    }

    /** @return array{status:?int,body:?string,error:?string} */
    private static function curlTransport(string $url, string $body, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => null, 'body' => null, 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch) ?: 'unknown curl error';

            return ['status' => null, 'body' => null, 'error' => $error];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ['status' => $status, 'body' => (string) $response, 'error' => null];
    }
}
