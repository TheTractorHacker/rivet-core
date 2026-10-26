<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Metrics;

use RivetCore\Webhooks\UrlPolicy;
use RivetCore\Webhooks\WebhookDispatcher;

/**
 * The default {@see HttpTransportInterface}: ext-curl when it is loaded, a stream context otherwise. Every request is vetted by the
 * {@see UrlPolicy} and the connection is PINNED to the addresses that were vetted (CURLOPT_RESOLVE, the same options as
 * {@see WebhookDispatcher::curlOptions()}), which defeats DNS rebinding between the check and the connect. TLS is always verified,
 * redirects are never followed, a proxy is never used, the response is read up to {@see MAX_RESPONSE_BYTES} (1 MiB, more is a failure)
 * and the time is bounded to 1..60 seconds.
 *
 * @api
 */
final class CurlHttpTransport implements HttpTransportInterface
{
    public const MAX_RESPONSE_BYTES = 1048576;
    public const MAX_TIMEOUT_SECONDS = 60;

    public function __construct(private readonly UrlPolicy $urlPolicy)
    {
    }

    public function request(string $method, string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'POST') {
            throw new ExternalStoreException('unsupported HTTP method');
        }
        foreach ($headers as $h) {
            if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $h) === 1) {
                throw new ExternalStoreException('invalid request header');
            }
        }
        $target = $this->urlPolicy->vet($url);
        if ($target === null) {
            throw new ExternalStoreException('metric store URL is not allowed by the URL policy');
        }
        $timeout = max(1, min(self::MAX_TIMEOUT_SECONDS, $timeoutSeconds));

        return function_exists('curl_init') ? $this->viaCurl($method, $url, $headers, $body, $timeout, $target) : $this->viaStream($method, $url, $headers, $body, $timeout, $target);
    }

    /**
     * @param list<string> $headers
     * @param array{host:string,port:int,ips:list<string>} $target
     * @return array{status:int, body:string}
     */
    private function viaCurl(string $method, string $url, array $headers, string $body, int $timeout, array $target): array
    {
        $ch = curl_init(WebhookDispatcher::pinnedUrl($url, $target));
        if ($ch === false) {
            throw new ExternalStoreException('could not start the HTTP request');
        }
        $options = WebhookDispatcher::curlOptions($body, $headers, $timeout, $target, 'POST');
        if ($method === 'GET') {
            unset($options[CURLOPT_POST], $options[CURLOPT_POSTFIELDS]);
            $options[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($ch, $options);
        $captured = '';
        $tooLarge = false;
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$captured, &$tooLarge): int {
            if (strlen($captured) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                $tooLarge = true;

                return 0;
            }
            $captured .= $chunk;

            return strlen($chunk);
        });
        $ok = curl_exec($ch);
        $error = $ok === false ? curl_error($ch) : '';
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($tooLarge) {
            throw new ExternalStoreException('metric store response is too large');
        }
        if ($ok === false) {
            throw new ExternalStoreException('metric store request failed: ' . self::clean($error));
        }

        return ['status' => $status, 'body' => $captured];
    }

    /**
     * @param list<string> $headers
     * @param array{host:string,port:int,ips:list<string>} $target
     * @return array{status:int, body:string}
     */
    private function viaStream(string $method, string $url, array $headers, string $body, int $timeout, array $target): array
    {
        $parts = parse_url($url);
        $ip = $target['ips'][0] ?? '';
        if (!is_array($parts) || $ip === '') {
            throw new ExternalStoreException('metric store URL is not allowed by the URL policy');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $hostPart = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $pinned = $scheme . '://' . $hostPart . ':' . $target['port'] . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $headers[] = 'Host: ' . $target['host'] . ':' . $target['port'];
        $context = stream_context_create([
            'http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $method === 'POST' ? $body : '', 'timeout' => $timeout,
                'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true, 'protocol_version' => 1.1],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $target['host'], 'SNI_enabled' => true],
        ]);
        $stream = @fopen($pinned, 'rb', false, $context);
        if ($stream === false) {
            throw new ExternalStoreException('metric store request failed');
        }
        $data = (string) stream_get_contents($stream, self::MAX_RESPONSE_BYTES + 1);
        $meta = stream_get_meta_data($stream);
        fclose($stream);
        $status = 0;
        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if (strlen($data) > self::MAX_RESPONSE_BYTES) {
            throw new ExternalStoreException('metric store response is too large');
        }
        if ($status === 0) {
            throw new ExternalStoreException('metric store request failed');
        }

        return ['status' => $status, 'body' => $data];
    }

    private static function clean(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $s)), 0, 120);
    }
}
