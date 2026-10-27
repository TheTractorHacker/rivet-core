<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Metrics\ExternalStoreException;
use RivetCore\Rmm\Metrics\HttpTransportInterface;

/** Records every request and answers from a queue (or a default), without a network. */
final class RecordingHttpTransport implements HttpTransportInterface
{
    /** @var list<array{method:string,url:string,headers:list<string>,body:string}> */
    public array $requests = [];
    /** @var list<array{status:int,body:string}|\Throwable> */
    public array $queue = [];

    /** @param array{status:int,body:string} $default */
    public function __construct(private array $default = ['status' => 204, 'body' => ''])
    {
    }

    public function request(string $method, string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->queue) ?? $this->default;
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public static function down(): ExternalStoreException
    {
        return new ExternalStoreException('metric store request failed: Connection refused');
    }
}
