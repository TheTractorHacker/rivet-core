<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Redis\RedisAdmin;
use RivetCore\Redis\RedisConnectionConfig;

/**
 * Starts its own throwaway redis-server processes (requirepass on a plain port; TLS on a second one when this build supports
 * it and openssl can make a certificate) on high local ports, and stops them by PID. Skipped when redis-server is missing.
 */
final class RedisAuthTlsTest extends TestCase
{
    private const PLAIN_PORT = 6397;
    private const TLS_PORT = 6398;

    /** @var array<string, resource> */
    private static array $procs = [];
    private static string $dir = '';
    private static bool $tlsReady = false;
    private static string $skip = '';

    public static function setUpBeforeClass(): void
    {
        $bin = trim((string) shell_exec('command -v redis-server 2>/dev/null'));
        if ($bin === '') {
            self::$skip = 'redis-server is not installed.';

            return;
        }
        self::$dir = sys_get_temp_dir() . '/rivetcore-redis-' . getmypid();
        mkdir(self::$dir, 0700, true);

        self::spawn('plain', [$bin, '--port', (string) self::PLAIN_PORT, '--bind', '127.0.0.1', '--requirepass', 'testpw', '--save', '', '--appendonly', 'no', '--dir', self::$dir]);
        if (!self::waitFor(self::PLAIN_PORT)) {
            self::$skip = 'could not start redis-server on port ' . self::PLAIN_PORT . '.';

            return;
        }
        $c = new \Predis\Client(['host' => '127.0.0.1', 'port' => self::PLAIN_PORT, 'password' => 'testpw']);
        $c->executeRaw(['ACL', 'SETUSER', 'alice', 'on', '>alicepw', '~*', '+@all']);

        if (self::makeCerts()) {
            self::spawn('tls', [$bin, '--port', '0', '--tls-port', (string) self::TLS_PORT, '--bind', '127.0.0.1', '--tls-cert-file', self::$dir . '/srv.crt', '--tls-key-file', self::$dir . '/srv.key',
                '--tls-ca-cert-file', self::$dir . '/ca.crt', '--tls-auth-clients', 'optional', '--requirepass', 'testpw', '--save', '', '--appendonly', 'no', '--dir', self::$dir]);
            self::$tlsReady = self::waitFor(self::TLS_PORT);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$procs as $p) {
            $status = proc_get_status($p);
            if ($status['running']) {
                posix_kill($status['pid'], SIGTERM);
            }
            proc_close($p);
        }
        self::$procs = [];
        if (self::$dir !== '' && is_dir(self::$dir)) {
            foreach (glob(self::$dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(self::$dir);
        }
    }

    /** @param list<string> $cmd */
    private static function spawn(string $name, array $cmd): void
    {
        $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (is_resource($p)) {
            self::$procs[$name] = $p;
        }
    }

    private static function waitFor(int $port): bool
    {
        for ($i = 0; $i < 50; $i++) {
            $s = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 0.2);
            if ($s) {
                fclose($s);

                return true;
            }
            usleep(100000);
        }

        return false;
    }

    private static function makeCerts(): bool
    {
        if (trim((string) shell_exec('command -v openssl 2>/dev/null')) === '') {
            return false;
        }
        $d = escapeshellarg(self::$dir);
        $ext = self::$dir . '/ext.cnf';
        file_put_contents($ext, 'subjectAltName=DNS:localhost,IP:127.0.0.1');
        $cmds = [
            "openssl req -x509 -newkey rsa:2048 -nodes -keyout $d/ca.key -out $d/ca.crt -days 2 -subj /CN=testca",
            "openssl req -newkey rsa:2048 -nodes -keyout $d/srv.key -out $d/srv.csr -subj /CN=localhost",
            "openssl x509 -req -in $d/srv.csr -CA $d/ca.crt -CAkey $d/ca.key -CAcreateserial -out $d/srv.crt -days 2 -extfile " . escapeshellarg($ext),
            "openssl req -x509 -newkey rsa:2048 -nodes -keyout $d/other.key -out $d/other.crt -days 2 -subj /CN=otherca",
        ];
        foreach ($cmds as $cmd) {
            exec($cmd . ' >/dev/null 2>&1', $o, $rc);
            if ($rc !== 0) {
                return false;
            }
        }

        return true;
    }

    protected function setUp(): void
    {
        if (self::$skip !== '') {
            $this->markTestSkipped(self::$skip);
        }
    }

    private function needTls(): void
    {
        if (!self::$tlsReady) {
            $this->markTestSkipped('This redis-server build has no TLS support (or openssl could not make a certificate); TLS tests skipped.');
        }
    }

    private function admin(): RedisAdmin
    {
        return new RedisAdmin([]);
    }

    public function testCorrectPasswordConnects(): void
    {
        $r = $this->admin()->test(['host' => '127.0.0.1', 'port' => self::PLAIN_PORT, 'db' => 0, 'password' => 'testpw']);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('ok', $r['reason']);
    }

    public function testMissingAndWrongPasswordAreAuthFailuresThatNeverEchoThePassword(): void
    {
        foreach ([null, 'wrongpw-12345'] as $pw) {
            $r = $this->admin()->test(['host' => '127.0.0.1', 'port' => self::PLAIN_PORT, 'db' => 0, 'password' => $pw]);
            $this->assertFalse($r['ok']);
            $this->assertSame('auth', $r['reason']);
            $this->assertStringContainsString('Authentication failed', $r['message']);
            $this->assertStringNotContainsString('wrongpw-12345', $r['message']);
        }
    }

    public function testAclUsernameWorksAndWrongUserIsAnAuthFailure(): void
    {
        $ok = $this->admin()->test(new RedisConnectionConfig('127.0.0.1', self::PLAIN_PORT, 0, 'alicepw', 'alice'));
        $this->assertTrue($ok['ok'], $ok['message']);
        $bad = $this->admin()->test(new RedisConnectionConfig('127.0.0.1', self::PLAIN_PORT, 0, 'alicepw', 'mallory'));
        $this->assertSame('auth', $bad['reason']);
        $this->assertStringContainsString('username', $bad['message']);
    }

    public function testClientBuiltFromTheArrayShapeTheEditionsUse(): void
    {
        $c = $this->admin()->client(['host' => '127.0.0.1', 'port' => self::PLAIN_PORT, 'db' => 1, 'password' => 'testpw']);
        $this->assertSame('PONG', (string) $c->ping());
    }

    public function testNothingListeningIsUnreachable(): void
    {
        $r = $this->admin()->test(['host' => '127.0.0.1', 'port' => 6391, 'db' => 0, 'password' => 'testpw']);
        $this->assertSame('unreachable', $r['reason']);
        $this->assertStringNotContainsString('testpw', $r['message']);
    }

    public function testTlsWithTheRightCaConnects(): void
    {
        $this->needTls();
        $r = $this->admin()->test(new RedisConnectionConfig('localhost', self::TLS_PORT, 0, 'testpw', null, true, true, self::$dir . '/ca.crt'));
        $this->assertTrue($r['ok'], $r['message']);
        $c = $this->admin()->client(new RedisConnectionConfig('127.0.0.1', self::TLS_PORT, 0, 'testpw', null, true, true, self::$dir . '/ca.crt'));
        $this->assertSame('PONG', (string) $c->ping());
    }

    public function testTlsWithTheWrongCaIsATlsFailureNotAuth(): void
    {
        $this->needTls();
        $r = $this->admin()->test(new RedisConnectionConfig('localhost', self::TLS_PORT, 0, 'testpw', null, true, true, self::$dir . '/other.crt'));
        $this->assertFalse($r['ok']);
        $this->assertSame('tls', $r['reason']);
        $this->assertStringContainsString('TLS', $r['message']);
        $this->assertStringNotContainsString('testpw', $r['message']);
    }

    public function testTlsWithVerificationOffConnectsToAnUntrustedServer(): void
    {
        $this->needTls();
        $r = $this->admin()->test(new RedisConnectionConfig('127.0.0.1', self::TLS_PORT, 0, 'testpw', null, true, false));
        $this->assertTrue($r['ok'], $r['message']);
    }

    public function testTlsToAPlainPortIsATlsFailure(): void
    {
        $this->needTls();
        $r = $this->admin()->test(new RedisConnectionConfig('127.0.0.1', self::PLAIN_PORT, 0, 'testpw', null, true, false));
        $this->assertFalse($r['ok']);
        $this->assertSame('tls', $r['reason']);
    }

    public function testTlsOkButWrongPasswordIsAuth(): void
    {
        $this->needTls();
        $r = $this->admin()->test(new RedisConnectionConfig('localhost', self::TLS_PORT, 0, 'nope', null, true, true, self::$dir . '/ca.crt'));
        $this->assertSame('auth', $r['reason']);
    }

    public function testMissingCaFileIsReportedBeforeConnecting(): void
    {
        $r = $this->admin()->test(new RedisConnectionConfig('localhost', self::TLS_PORT, 0, 'testpw', null, true, true, '/nonexistent/ca.pem'));
        $this->assertSame('invalid', $r['reason']);
    }
}
