<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

/** Starts tests/Support/mock_mesh_control.php (a fake MeshCentral control channel) on a free loopback port. Tests read what it received with {@see log()}. */
final class MockMeshControl
{
    public readonly int $port;
    /** @var resource|null */
    private $proc = null;
    private string $scenarioFile;
    private string $logFile;

    /** @param array<string,mixed> $scenario see mock_mesh_control.php */
    public function __construct(array $scenario)
    {
        $this->scenarioFile = sys_get_temp_dir() . '/mock_mesh_ctl_' . bin2hex(random_bytes(4)) . '.json';
        $this->logFile = $this->scenarioFile . '.log';
        $this->scenario($scenario);
        $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
        if ($sock === false) {
            throw new \RuntimeException('no free port');
        }
        $this->port = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
        fclose($sock);
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', __DIR__ . '/mock_mesh_control.php', (string) $this->port, $this->scenarioFile, $this->logFile], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('cannot start the mock Mesh control channel');
        }
        $this->proc = $proc;
        for ($i = 0; $i < 60; ++$i) {
            $f = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $en, $es, 1);
            if ($f !== false) {
                fclose($f);
                $this->clearLog();

                return;
            }
            usleep(100000);
        }
        $this->stop();
        throw new \RuntimeException('the mock Mesh control channel did not start');
    }

    /** @param array<string,mixed> $scenario */
    public function scenario(array $scenario): void
    {
        file_put_contents($this->scenarioFile, json_encode($scenario));
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /** @return list<array<string,mixed>> */
    public function log(): array
    {
        $out = [];
        foreach (is_file($this->logFile) ? (array) file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) {
            $out[] = json_decode($l, true);
        }

        return $out;
    }

    public function clearLog(): void
    {
        @unlink($this->logFile);
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        $this->proc = null;
        @unlink($this->scenarioFile);
        @unlink($this->logFile);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
