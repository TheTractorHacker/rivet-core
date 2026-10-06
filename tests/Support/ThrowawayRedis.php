<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

/**
 * Starts a throwaway redis-server on a high local port (optionally with requirepass and/or TLS) and stops it by PID.
 * Never connects to, or stops, any Redis it did not start. The caller picks ports that nothing else on the machine uses;
 * RIVETCORE_TEST_REDIS_AUTH_PORT_BASE moves the base on a shared box.
 */
final class ThrowawayRedis
{
    /** @var resource|null */
    private $proc = null;
    private int $pid = 0;

    private function __construct(public readonly int $port, public readonly string $dir, public readonly bool $tls)
    {
    }

    public static function available(): bool
    {
        return trim((string) shell_exec('command -v redis-server 2>/dev/null')) !== '';
    }

    public static function portBase(): int
    {
        return (int) (getenv('RIVETCORE_TEST_REDIS_AUTH_PORT_BASE') ?: 6397);
    }

    /** A private temp directory for sockets, certificates and the server's working files. */
    public static function tempDir(string $label): string
    {
        $d = sys_get_temp_dir() . '/rivetcore-' . $label . '-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($d, 0700, true);

        return $d;
    }

    /**
     * @param string|null $password requirepass, or null for none
     * @return self|null null when the server did not come up (port taken, or no TLS support in this build)
     */
    public static function start(int $port, string $dir, ?string $password = null, bool $tls = false): ?self
    {
        $bin = trim((string) shell_exec('command -v redis-server 2>/dev/null'));
        if ($bin === '') {
            return null;
        }
        $busy = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 0.2);
        if ($busy) {
            fclose($busy);

            return null;   // something else already listens there: never adopt it
        }
        $cmd = [$bin, '--bind', '127.0.0.1', '--save', '', '--appendonly', 'no', '--dir', $dir];
        if ($tls) {
            array_push($cmd, '--port', '0', '--tls-port', (string) $port, '--tls-cert-file', $dir . '/srv.crt', '--tls-key-file', $dir . '/srv.key', '--tls-ca-cert-file', $dir . '/ca.crt', '--tls-auth-clients', 'no');
        } else {
            array_push($cmd, '--port', (string) $port);
        }
        if ($password !== null) {
            array_push($cmd, '--requirepass', $password);
        }
        $me = new self($port, $dir, $tls);
        $me->proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . '/stdout.log', 'w'], 2 => ['file', $dir . '/stderr.log', 'w']], $pipes);
        if (!is_resource($me->proc)) {
            return null;
        }
        $me->pid = (int) proc_get_status($me->proc)['pid'];
        for ($i = 0; $i < 50; $i++) {
            $st = proc_get_status($me->proc);
            if (!$st['running']) {
                return null;   // exited (port in use, TLS not compiled in, bad certificate)
            }
            $s = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 0.2);
            if ($s) {
                fclose($s);

                return $me;
            }
            usleep(100000);
        }
        $me->stop();

        return null;
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            $st = proc_get_status($this->proc);
            if ($st['running']) {
                posix_kill($st['pid'], SIGTERM);
            }
            proc_close($this->proc);
            $this->proc = null;
        }
    }

    public static function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /** Self-signed CA, a server certificate for localhost/127.0.0.1 signed by it, and an unrelated CA. False when openssl is missing. */
    public static function makeCerts(string $dir): bool
    {
        if (trim((string) shell_exec('command -v openssl 2>/dev/null')) === '') {
            return false;
        }
        $d = escapeshellarg($dir);
        file_put_contents($dir . '/ext.cnf', 'subjectAltName=DNS:localhost,IP:127.0.0.1');
        foreach ([
            "openssl req -x509 -newkey rsa:2048 -nodes -keyout $d/ca.key -out $d/ca.crt -days 2 -subj /CN=testca",
            "openssl req -newkey rsa:2048 -nodes -keyout $d/srv.key -out $d/srv.csr -subj /CN=localhost",
            "openssl x509 -req -in $d/srv.csr -CA $d/ca.crt -CAkey $d/ca.key -CAcreateserial -out $d/srv.crt -days 2 -extfile " . escapeshellarg($dir . '/ext.cnf'),
            "openssl req -x509 -newkey rsa:2048 -nodes -keyout $d/other.key -out $d/other.crt -days 2 -subj /CN=otherca",
        ] as $cmd) {
            exec($cmd . ' >/dev/null 2>&1', $o, $rc);
            if ($rc !== 0) {
                return false;
            }
        }

        return true;
    }
}
