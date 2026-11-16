<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

use RivetCore\Webhooks\UrlPolicy;

/**
 * A minimal, read-only client of MeshCentral's control channel (`/control.ashx?moreargs=1`, RFC 6455 over TLS): it signs in with a login cookie, asks for
 * `meshes` and `nodes` and returns them. It sends nothing else.
 *
 * Like {@see MeshService::probe()} the address goes through the injected {@see UrlPolicy} and the connection is made to the addresses the policy vetted (no DNS
 * rebinding); the certificate is verified against the system trust store; there are no redirects (a handshake answer other than 101 is a failure); every wait is
 * bounded by one overall deadline and every frame and the whole exchange by a size cap. The cookie is only ever written to the socket: no error text, return value
 * or log line contains it. Any failure returns `ok => false` with a short fixed message: the caller must then map nothing.
 *
 * Verified only against tests/Support/MockMeshControl.php, never against a real MeshCentral.
 *
 * @api
 */
final class MeshControlClient
{
    public const CONNECT_TIMEOUT_S = 3;
    public const DEADLINE_S = 12;
    public const MAX_FRAME_BYTES = 8388608;
    public const MAX_TOTAL_BYTES = 16777216;
    public const MAX_NODES = 20000;
    public const MAX_HEADER_BYTES = 8192;

    public function __construct(private readonly UrlPolicy $urlPolicy, private readonly bool $allowInsecureHttp = false, private readonly int $deadlineS = self::DEADLINE_S)
    {
    }

    /**
     * @param string $base the normalised Mesh address (https; http only when the module allows it)
     * @param string $domain the Mesh domain ('' for the default one)
     * @param string $cookie the login cookie of the service account (see {@see MeshCookie::login()}); a fresh one per connection
     * @param string $expectUser the MeshCentral user id the cookie was minted for (`user/<domain>/<account>`): the server must sign in exactly that account
     * @return array{ok:bool,error?:string,meshes?:list<array{id:string,name:string}>,nodes?:list<array{id:string,name:string,host:string,mesh:string,ip:string,osdesc:string,rname:string}>}
     */
    public function fetch(string $base, string $domain, string $cookie, string $expectUser): array
    {
        $norm = MeshService::normalizeUrlWith($base, $this->allowInsecureHttp);
        if ($norm === null) {
            return self::fail('The MeshCentral address is not valid.');
        }
        try {
            $target = $this->urlPolicy->vet($norm);
        } catch (\Throwable) {
            $target = null;
        }
        if ($target === null) {
            return self::fail(MeshService::POLICY_REFUSAL);
        }
        $p = parse_url($norm);
        $tls = strtolower((string) ($p['scheme'] ?? '')) === 'https';
        $path = rtrim((string) ($p['path'] ?? ''), '/') . ($domain !== '' ? '/' . rawurlencode($domain) : '') . '/control.ashx?moreargs=1';
        $deadline = microtime(true) + max(1, $this->deadlineS);
        $sock = null;
        foreach ($target['ips'] !== [] ? $target['ips'] : [$target['host']] as $ip) {
            $addr = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $ctx = stream_context_create(['ssl' => ['peer_name' => $target['host'], 'SNI_enabled' => true, 'SNI_server_name' => $target['host'], 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
            $s = @stream_socket_client(($tls ? 'ssl://' : 'tcp://') . $addr . ':' . $target['port'], $errno, $errstr, self::CONNECT_TIMEOUT_S, STREAM_CLIENT_CONNECT, $ctx);
            if ($s !== false) {
                $sock = $s;
                break;
            }
        }
        if ($sock === null) {
            return self::fail(MeshService::UNREACHABLE);
        }
        try {
            return $this->session($sock, $target['host'] . ($target['port'] === ($tls ? 443 : 80) ? '' : ':' . $target['port']), $path, $cookie, $expectUser, $deadline);
        } catch (\Throwable) {
            return self::fail(MeshService::UNREACHABLE);   // never the exception text: it could carry what was read from the socket
        } finally {
            @fclose($sock);
        }
    }

    /**
     * @param resource $sock
     * @return array<string,mixed>
     */
    private function session($sock, string $hostHeader, string $path, string $cookie, string $expectUser, float $deadline): array
    {
        stream_set_blocking($sock, true);
        $key = base64_encode(random_bytes(16));
        $req = "GET $path HTTP/1.1\r\nHost: $hostHeader\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\nUser-Agent: RivetCore-MeshVerify\r\n\r\n";
        if (!self::writeAll($sock, $req)) {
            return self::fail(MeshService::UNREACHABLE);
        }
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = self::readSome($sock, 1, $deadline);
            if ($chunk === null || strlen($head) >= self::MAX_HEADER_BYTES) {
                return self::fail(MeshService::UNREACHABLE);
            }
            $head .= $chunk;   // byte by byte: whatever follows the headers is read as frames
        }
        $lines = explode("\r\n", $head);
        if (preg_match('~^HTTP/1\.[01] 101\b~', $lines[0]) !== 1) {
            return self::fail('MeshCentral refused the control connection.');
        }
        $accept = null;
        foreach ($lines as $l) {
            if (stripos($l, 'sec-websocket-accept:') === 0) {
                $accept = trim(substr($l, 21));
            }
        }
        if ($accept === null || !hash_equals(base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)), $accept)) {
            return self::fail('MeshCentral did not complete the WebSocket handshake.');
        }
        $total = 0;
        if (!self::sendText($sock, (string) json_encode(['action' => 'urlargs', 'args' => ['auth' => $cookie]]))) {
            return self::fail(MeshService::UNREACHABLE);
        }
        // MeshCentral answers a good sign-in with `serverinfo` then `userinfo`; a bad one with {"action":"close","cause":"noauth"} and a closed socket. Nothing is
        // requested before `serverinfo` arrived, and nothing is accepted before `userinfo` named the account the cookie was minted for.
        $serverinfo = false;
        $userOk = false;
        $meshes = null;
        $nodes = null;
        $sent = false;
        while (!$serverinfo || !$userOk || $meshes === null || $nodes === null) {
            $msg = $this->readFrame($sock, $deadline, $total, false);
            if (!is_string($msg)) {
                return self::fail($serverinfo ? MeshService::UNREACHABLE : "MeshCentral closed the control connection (the service account or its sign-in was not accepted).");
            }
            $j = json_decode($msg, true);
            if (!is_array($j)) {
                continue;
            }
            switch ($j['action'] ?? null) {
                case 'close':
                    return self::fail("MeshCentral refused the sign-in of the service account.");
                case 'serverinfo':
                    $serverinfo = true;
                    if (!$sent) {
                        $sent = true;
                        if (!self::sendText($sock, "{\"action\":\"meshes\"}") || !self::sendText($sock, "{\"action\":\"nodes\"}")) {
                            return self::fail(MeshService::UNREACHABLE);
                        }
                    }
                    break;
                case 'userinfo':
                    $uid = is_array($j['userinfo'] ?? null) ? ($j['userinfo']['_id'] ?? null) : null;
                    if (!is_string($uid) || !hash_equals($expectUser, $uid)) {
                        return self::fail("MeshCentral signed in a different account than the service account.");
                    }
                    $userOk = true;
                    break;
                case 'meshes':
                    if ($serverinfo && is_array($j['meshes'] ?? null)) {
                        $meshes = self::meshList($j['meshes']);
                    }
                    break;
                case 'nodes':
                    if ($serverinfo && is_array($j['nodes'] ?? null)) {
                        $nodes = self::nodeList($j['nodes']);
                        if ($nodes === null) {
                            return self::fail("MeshCentral answered with more nodes than the verification reads.");
                        }
                    }
                    break;
            }
        }
        @self::writeFrame($sock, 8, pack('n', 1000));

        return ['ok' => true, 'meshes' => $meshes, 'nodes' => $nodes];
    }

    /** @return array{ok:false,error:string} */
    private static function fail(string $why): array
    {
        return ['ok' => false, 'error' => $why];
    }

    /**
     * @param array<mixed> $in
     * @return list<array{id:string,name:string}>
     */
    private static function meshList(array $in): array
    {
        $out = [];
        foreach ($in as $m) {
            if (is_array($m) && is_string($m['_id'] ?? null) && ($hex = MeshVerifySettings::groupHex($m['_id'])) !== null) {
                $out[] = ['id' => $hex, 'name' => self::str($m['name'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * The `nodes` answer is an object keyed by mesh id holding lists of nodes (a flat list is accepted too); a node has no `meshid` of its own.
     *
     * @param array<mixed> $in
     * @return list<array{id:string,name:string,host:string,mesh:string,ip:string,osdesc:string,rname:string}>|null null when over the cap
     */
    private static function nodeList(array $in): ?array
    {
        $out = [];
        foreach ($in as $key => $group) {
            $items = is_array($group) && array_is_list($group) ? $group : (is_array($group) && isset($group['_id']) ? [$group] : []);
            foreach ($items as $n) {
                if (!is_array($n) || !is_string($n['_id'] ?? null) || ($id = MeshNodeId::canonical($n['_id'])) === null) {
                    continue;
                }
                $mesh = is_string($key) ? MeshVerifySettings::groupHex($key) : null;   // the mesh id is the KEY: a node object has no meshid
                if (count($out) >= self::MAX_NODES) {
                    return null;
                }
                $out[] = ['id' => $id, 'name' => self::str($n['name'] ?? ''), 'host' => self::str($n['host'] ?? ''), 'mesh' => $mesh ?? '', 'ip' => self::str($n['ip'] ?? ''), 'osdesc' => self::str($n['osdesc'] ?? ''), 'rname' => self::str($n['rname'] ?? '')];
            }
        }

        return $out;
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? mb_substr($v, 0, 200) : '';
    }

    // ------------------------------------------------------------------ RFC 6455

    /** @param resource $sock */
    private static function sendText($sock, string $payload): bool
    {
        return self::writeFrame($sock, 1, $payload);
    }

    /** @param resource $sock */
    private static function writeFrame($sock, int $opcode, string $payload): bool
    {
        $n = strlen($payload);
        $hdr = chr(0x80 | $opcode) . ($n < 126 ? chr(0x80 | $n) : ($n < 65536 ? chr(0x80 | 126) . pack('n', $n) : chr(0x80 | 127) . pack('J', $n)));
        $mask = random_bytes(4);
        $masked = $payload ^ str_repeat($mask, intdiv($n, 4) + 1);

        return self::writeAll($sock, $hdr . $mask . substr($masked, 0, $n));
    }

    /**
     * One complete text message (continuation frames joined; ping answered, pong and binary ignored). Null on a timeout when `$soft`, false on a close, an error, a
     * timeout otherwise or a size cap.
     *
     * @param resource $sock
     */
    private function readFrame($sock, float $deadline, int &$total, bool $soft): string|false|null
    {
        $msg = '';
        $started = false;
        while (true) {
            $h = self::readExact($sock, 2, $deadline);
            if ($h === null) {
                return $soft && !$started ? null : false;
            }
            $b1 = ord($h[0]);
            $b2 = ord($h[1]);
            $op = $b1 & 0x0F;
            $len = $b2 & 0x7F;
            if (($b2 & 0x80) !== 0 || ($b1 & 0x70) !== 0) {
                return false;   // a server never masks; no extension was negotiated
            }
            if ($len === 126) {
                $e = self::readExact($sock, 2, $deadline);
                $len = $e === null ? -1 : (int) unpack('n', $e)[1];
            } elseif ($len === 127) {
                $e = self::readExact($sock, 8, $deadline);
                $len = $e === null ? -1 : (int) unpack('J', $e)[1];
            }
            if ($len < 0 || $len > self::MAX_FRAME_BYTES || strlen($msg) + $len > self::MAX_FRAME_BYTES || ($total += $len) > self::MAX_TOTAL_BYTES) {
                return false;
            }
            $data = $len === 0 ? '' : self::readExact($sock, $len, $deadline);
            if ($data === null) {
                return false;
            }
            if ($op === 8) {
                return false;
            }
            if ($op === 9) {
                self::writeFrame($sock, 10, substr($data, 0, 125));
                continue;
            }
            if ($op === 10 || $op === 2) {
                continue;
            }
            if ($op === 1 || $op === 0) {
                $msg .= $data;
                $started = true;
                if (($b1 & 0x80) !== 0) {
                    return $msg;
                }
                continue;
            }

            return false;
        }
    }

    /** @param resource $sock */
    private static function readExact($sock, int $n, float $deadline): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $c = self::readSome($sock, min(65536, $n - strlen($buf)), $deadline);
            if ($c === null) {
                return null;
            }
            $buf .= $c;
        }

        return $buf;
    }

    /** @param resource $sock */
    private static function readSome($sock, int $max, float $deadline): ?string
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            return null;
        }
        stream_set_timeout($sock, (int) $left, (int) (fmod($left, 1.0) * 1000000));
        $c = @fread($sock, $max);

        return $c === false || $c === '' ? null : $c;
    }

    /** @param resource $sock */
    private static function writeAll($sock, string $data): bool
    {
        $off = 0;
        $len = strlen($data);
        while ($off < $len) {
            $w = @fwrite($sock, substr($data, $off));
            if ($w === false || $w === 0) {
                return false;
            }
            $off += $w;
        }

        return true;
    }
}
