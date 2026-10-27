<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

/** Starts tests/Support/MockMeshCentral.php on a free loopback port with `php -S` and stops it again. */
final class MockMeshServer
{
    /** @var resource|null */
    private $proc = null;
    public readonly int $port;

    public function __construct(string $mode = 'ok')
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
        if ($sock === false) {
            throw new \RuntimeException('no free port');
        }
        $this->port = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
        fclose($sock);
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $this->port, __DIR__ . '/MockMeshCentral.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, __DIR__, array_merge(getenv(), ['MOCK_MESH_MODE' => $mode, 'PHP_CLI_SERVER_WORKERS' => '2']));
        if (!is_resource($proc)) {
            throw new \RuntimeException('cannot start the mock MeshCentral');
        }
        $this->proc = $proc;
        for ($i = 0; $i < 60; ++$i) {
            if (@fsockopen('127.0.0.1', $this->port) !== false) {
                return;
            }
            usleep(100000);
        }
        $this->stop();
        throw new \RuntimeException('the mock MeshCentral did not start');
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        $this->proc = null;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
