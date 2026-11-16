<?php

/*
 * Stand-in for the control channel of a MeshCentral server (`/control.ashx?moreargs=1`), RFC 6455 over plain TCP on loopback, one connection at a time. Started by
 * MockMeshControl with: php mock_mesh_control.php <port> <scenario.json> <log.jsonl>. It speaks the shapes of MeshCentral 1.3.1 as reported by the Console lane:
 * serverinfo, userinfo, then {"action":"nodes","nodes":{"mesh//<id>":[node,...]}} (the mesh id is the KEY, nodes have no meshid) and {"action":"meshes","meshes":[...]}; a bad
 * sign-in is {"action":"close","cause":"noauth"} and a closed socket. Never a real MeshCentral.
 *
 * Scenario members: key_hex, domain, account (the cookie must decode, with key_hex, to user/<domain>/<account>), mode (ok|noauth|userinfo_mismatch|no_handshake|bad_accept|
 * silent|no_serverinfo|huge), meshes, nodes.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use RivetCore\Rmm\Mesh\MeshCookie;

[, $port, $scenarioFile, $logFile] = $argv;
$srv = stream_socket_server('tcp://127.0.0.1:' . $port, $en, $es);
$log = static function (array $e) use ($logFile): void {
    file_put_contents($logFile, json_encode($e) . "\n", FILE_APPEND);
};
$readFrame = static function ($c): ?array {
    $h = fread($c, 2);
    if ($h === false || strlen($h) < 2) {
        return null;
    }
    $op = ord($h[0]) & 0x0F;
    $len = ord($h[1]) & 0x7F;
    if ($len === 126) {
        $len = unpack('n', (string) fread($c, 2))[1];
    } elseif ($len === 127) {
        $len = unpack('J', (string) fread($c, 8))[1];
    }
    $mask = (ord($h[1]) & 0x80) ? (string) fread($c, 4) : '';
    $data = '';
    while (strlen($data) < $len) {
        $d = fread($c, $len - strlen($data));
        if ($d === false || $d === '') {
            return null;
        }
        $data .= $d;
    }
    if ($mask !== '') {
        $data ^= str_repeat($mask, intdiv($len, 4) + 1);
        $data = substr($data, 0, $len);
    }

    return [$op, $data];
};
$send = static function ($c, string $payload, int $op = 1, bool $fin = true): void {
    $n = strlen($payload);
    fwrite($c, chr(($fin ? 0x80 : 0) | $op) . ($n < 126 ? chr($n) : ($n < 65536 ? chr(126) . pack('n', $n) : chr(127) . pack('J', $n))) . $payload);
};
while (true) {
    $c = @stream_socket_accept($srv, 300);
    if ($c === false) {
        continue;
    }
    $sc = json_decode((string) file_get_contents($scenarioFile), true);
    $mode = $sc['mode'] ?? 'ok';
    stream_set_timeout($c, 5);
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $b = fread($c, 1);
        if ($b === false || $b === '') {
            continue 2;
        }
        $head .= $b;
    }
    preg_match('~^GET (\S+) HTTP~', $head, $m);
    $path = $m[1] ?? '';
    preg_match('/Sec-WebSocket-Key: (\S+)/i', $head, $k);
    $log(['event' => 'handshake', 'path' => $path]);
    if ($mode === 'no_handshake') {
        fwrite($c, "HTTP/1.1 302 Found\r\nLocation: https://elsewhere.invalid/\r\n\r\n");
        fclose($c);
        continue;
    }
    $accept = base64_encode(sha1(($k[1] ?? '') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    fwrite($c, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . ($mode === 'bad_accept' ? 'AAAA' : $accept) . "\r\n\r\n");
    $f = $readFrame($c);
    $first = $f === null ? null : json_decode($f[1], true);
    $cookie = (string) ($first['args']['auth'] ?? '');
    $claims = $cookie === '' ? null : MeshCookie::decode($cookie, (string) $sc['key_hex']);
    $user = 'user/' . ($sc['domain'] ?? '') . '/' . ($sc['account'] ?? '');
    $log(['event' => 'first_frame', 'cookie' => $cookie, 'action' => $first['action'] ?? null, 'cookie_in_path' => $cookie !== '' && str_contains($path, $cookie), 'cookie_user' => $claims['u'] ?? null, 'cookie_ok' => ($claims['u'] ?? null) === $user]);
    if ($mode === 'silent') {
        sleep(30);
        fclose($c);
        continue;
    }
    if ($mode === 'noauth' || ($claims['u'] ?? null) !== $user) {
        $send($c, json_encode(['action' => 'close', 'cause' => 'noauth', 'msg' => 'noauth']));
        fclose($c);
        continue;
    }
    if ($mode !== 'no_serverinfo') {
        $send($c, json_encode(['action' => 'serverinfo', 'serverinfo' => ['name' => 'mock', 'domain' => $sc['domain'] ?? '']]));
    }
    $send($c, "\x01\x02", 9);   // a ping: the client must answer and go on
    $send($c, json_encode(['action' => 'userinfo', 'userinfo' => ['_id' => $mode === 'userinfo_mismatch' ? 'user//someone-else' : $user, 'name' => 'svc']]));
    $want = ['meshes' => false, 'nodes' => false];
    while (!($want['meshes'] && $want['nodes'])) {
        $f = $readFrame($c);
        if ($f === null || $f[0] === 8) {
            break;
        }
        if ($f[0] === 10) {
            $log(['event' => 'pong']);
            continue;
        }
        $a = json_decode($f[1], true)['action'] ?? null;
        $log(['event' => 'request', 'action' => $a]);
        if ($a === 'meshes') {
            $send($c, json_encode(['action' => 'meshes', 'meshes' => $sc['meshes'] ?? []]));
            $want['meshes'] = true;
        } elseif ($a === 'nodes') {
            $body = json_encode(['action' => 'nodes', 'nodes' => $mode === 'huge' ? ['mesh//x' => [str_repeat('A', 9000000)]] : ($sc['nodes'] ?? new stdClass())]);
            $half = intdiv(strlen($body), 2);
            $send($c, substr($body, 0, $half), 1, false);   // fragmented: the client joins the continuation
            $send($c, substr($body, $half), 0, true);
            $want['nodes'] = true;
        }
    }
    // Drain what the client still sends (its pong, its close) before closing: closing with unread data makes the kernel reset the connection and drop what we just sent.
    stream_set_timeout($c, 1);
    while (($fr = $readFrame($c)) !== null && $fr[0] !== 8) {
        if ($fr[0] === 10) {
            $log(['event' => 'pong']);
        }
    }
    fclose($c);
}
