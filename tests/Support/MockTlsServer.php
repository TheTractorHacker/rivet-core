<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

/** Starts tests/Support/mock_tls_server.php: an HTTPS server on a free loopback port with a fresh self-signed certificate. */
final class MockTlsServer
{
    public readonly int $port;
    /** SHA-384 and SHA-256 (hex) of the DER certificate the server presents. */
    public readonly string $sha384;
    public readonly string $sha256;
    /** @var resource|null */
    private $proc = null;
    private string $pemFile;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('cannot generate a key');
        }
        $csr = openssl_csr_new(['commonName' => 'mesh.test.invalid'], $key);
        $cert = $csr === false ? false : openssl_csr_sign($csr, null, $key, 2);
        if ($cert === false) {
            throw new \RuntimeException('cannot sign a certificate');
        }
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        $der = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $certPem), true);
        $this->sha384 = hash('sha384', (string) $der);
        $this->sha256 = hash('sha256', (string) $der);
        $this->pemFile = sys_get_temp_dir() . '/mock_tls_' . bin2hex(random_bytes(4)) . '.pem';
        file_put_contents($this->pemFile, $certPem . $keyPem);
        chmod($this->pemFile, 0600);
        $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
        if ($sock === false) {
            throw new \RuntimeException('no free port');
        }
        $this->port = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
        fclose($sock);
        $proc = proc_open([PHP_BINARY, __DIR__ . '/mock_tls_server.php', $this->pemFile, (string) $this->port], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('cannot start the mock TLS server');
        }
        $this->proc = $proc;
        for ($i = 0; $i < 60; ++$i) {
            $f = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $en, $es, 1);
            if ($f !== false) {
                fclose($f);

                return;
            }
            usleep(100000);
        }
        $this->stop();
        throw new \RuntimeException('the mock TLS server did not start');
    }

    public function url(): string
    {
        return 'https://127.0.0.1:' . $this->port;
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        $this->proc = null;
        if (isset($this->pemFile)) {
            @unlink($this->pemFile);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
