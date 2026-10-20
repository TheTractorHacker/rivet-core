<?php

declare(strict_types=1);

/*
 * Regenerates the endpoint agent test vectors shared with the Go agent, from the fixed TEST key (no database needed):
 *
 *   php scripts/endpoint-vectors.php --check   regenerate in memory and compare byte for byte with endpoint-agent/testdata/vectors/
 *                                              (both JSON files and SHA256SUMS); exit 1 on any difference
 *   php scripts/endpoint-vectors.php --write   rewrite the files
 *
 * Files: agent_job_signing_vectors.json (canonical JSON rule, job/check/manifest Ed25519 signatures),
 * agent_installer_trailer_vectors.json (the stamped-installer footer format; FROZEN, never regenerate it with different bytes) and
 * agent_software_deploy_vectors.json (ADDED in 1.0.0-rc.21: accept/reject vectors for the parameters of the software jobs, replayed by the Go agent),
 * agent_check_os_vectors.json (ADDED in 1.0.0-rc.24: signed check definitions with the optional os member and the keys delivered per OS, replayed by the Go agent),
 * agent_change_monitor_vectors.json (ADDED in 1.0.0-rc.22: the watch policy decisions, the secret-name rule and one complete signed change configuration, replayed by the Go agent), and
 * agent_installer_mesh_vectors.json (ADDED in 1.0.0-rc.12: payloads that carry the optional `install_mesh_agent` / `mesh` members, plus the
 * Mesh agent-id and install-argument tables both sides must agree on; an additive file so the frozen one keeps its bytes). Ed25519 signatures are deterministic, so
 * regenerating changes nothing. The key derives from a fixed seed; never use it anywhere real. The trailer vectors are written
 * with raw pack()/hash() on purpose (an independent statement of the format) and then cross-checked against Installer\InstallerStamp.
 * The bytes are the RivetIT origin/beta bytes: do not change generator output without changing the protocol.
 */

require __DIR__ . '/../vendor/autoload.php';

use RivetCore\Rmm\Crypto\CanonicalJson;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Installer\InstallerStamp;

$dir = dirname(__DIR__) . '/endpoint-agent/testdata/vectors';
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--check', '--write'], true)) {
    fwrite(STDERR, "usage: php scripts/endpoint-vectors.php --check|--write\n");
    exit(2);
}

function jobSigningVectors(): string
{
    $seed = hash('sha256', 'RivetIT-agent-TEST-seed', true);   // 32 bytes, fixed
    [$pub, $sec] = Signer::keypairFromSeed($seed);

    $jobCases = [
        'minimal reboot job (null script, empty params object)' => '{"job_id":"11111111-2222-4333-8444-555555555555","attempt":1,"type":"reboot","script":null,"params":{},"timeout_s":60,"max_output_bytes":65536,"issued_at":"2026-10-06T12:00:00Z","expires_at":"2026-10-06T13:00:00Z"}',
        'powershell job, unsorted input keys, nested params' => '{"expires_at":"2026-10-06T13:00:00Z","issued_at":"2026-10-06T12:00:00Z","max_output_bytes":65536,"timeout_s":300,"params":{"Zeta":true,"Alpha":"x","Mid":{"b":2,"a":[3,2,1]},"N":null},"script":"Get-Service | Where-Object Status -eq \'Running\'","type":"powershell","attempt":2,"job_id":"aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"}',
        'script with quotes, backslashes, control characters and unicode' => '{"job_id":"99999999-8888-4777-8666-555555555555","attempt":1,"type":"powershell","script":"Write-Host \"a\\\\b\"\n\t\u0001 café ' . "\u{2028}" . ' <&> / 😀","params":{},"timeout_s":5,"max_output_bytes":1024,"issued_at":"2026-10-06T12:00:00Z","expires_at":"2026-10-06T12:05:00Z"}',
        'collect job with parameters of every scalar type' => '{"job_id":"01010101-0202-4303-8404-050505050505","attempt":3,"type":"collect","script":null,"params":{"Count":5,"Flag":false,"Name":"Ünï","Nothing":null},"timeout_s":30,"max_output_bytes":4096,"issued_at":"2026-10-06T00:00:00Z","expires_at":"2026-10-06T01:00:00Z"}',
    ];
    $jobs = [];
    foreach ($jobCases as $name => $json) {
        $canonical = CanonicalJson::encode(json_decode($json));
        $jobs[] = ['name' => $name, 'job_json' => $json, 'canonical' => $canonical, 'signature' => Signer::sign($canonical, $sec)];
    }

    $canonOnly = [
        ['name' => 'keys sort by UTF-8 bytes', 'input' => '{"b":1,"a":2,"B":3,"é":4,"aa":5}', 'canonical' => null],
        ['name' => 'empty containers stay distinct', 'input' => '{"o":{},"a":[]}', 'canonical' => null],
        ['name' => 'string escaping', 'input' => '["\\"","\\\\","\b\f\n\r\t","\u0000\u001f\u007f","' . "\u{2028}\u{2029}" . '","/"]', 'canonical' => null],
        ['name' => 'integers and literals', 'input' => '[0,-5,9007199254740991,true,false,null]', 'canonical' => null],
    ];
    foreach ($canonOnly as &$c) {
        $c['canonical'] = CanonicalJson::encode(json_decode($c['input']));
    }
    unset($c);

    $sha = hash('sha256', 'RivetIT test package v1.2.3');
    $checkJson = '{"key":"disk_c","type":"disk","params":{"mount":"C:","warn_pct":85,"fail_pct":95},"interval_s":300}';
    $checkCanonical = CanonicalJson::encode(json_decode($checkJson));
    $out = [
        'description' => 'RivetIT endpoint agent signing vectors. Canonical JSON: UTF-8, object keys sorted by their UTF-8 bytes (recursively), arrays in order, no whitespace, strings escape only \\" \\\\ \\b \\f \\n \\r \\t and other code points below U+0020 as \\u00xx (lowercase hex), everything else raw (including / < > & U+007F U+2028 U+2029), integers only, true/false/null. The signed message is the canonical form of the job object WITHOUT its "signature" member; the signature is Ed25519, base64 (standard alphabet, padded).',
        'test_key' => ['seed_derivation' => 'SHA-256 of the ASCII string RivetIT-agent-TEST-seed (raw 32 bytes)', 'seed_hex' => bin2hex($seed), 'public_key_base64' => $pub, 'secret_key_base64_libsodium' => $sec],
        'jobs' => $jobs,
        'canonical_only' => $canonOnly,
        'update_manifest' => ['description' => 'The signature is Ed25519 over the lowercase hex SHA-256 string of the package, as ASCII bytes.', 'sha256' => $sha, 'signature' => Signer::sign($sha, $sec)],
        'check_definition' => ['description' => 'Check definitions are signed the same way as jobs (canonical JSON of the object without "signature").', 'check_json' => $checkJson,
            'canonical' => $checkCanonical, 'signature' => Signer::sign($checkCanonical, $sec)],
    ];

    return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

function installerTrailerVectors(): string
{
    $magic = 'RIVETIT-EMBED-v1';
    $footer = static fn (string $p): string => pack('N', strlen($p)) . hash('sha256', $p, true) . $magic;
    $payload = static function (array $over = []): string {
        return (string) json_encode(array_merge([
            'version' => 1, 'installer_id' => '3f2b8c1e-5d4a-4e7b-9a10-6c2d8e9f0a1b', 'server_url' => 'https://rivet.example.com/rivetit',
            'enrollment_token' => 'rvte1.0123456789ab.0123456789abcdef0123456789abcdef01234567', 'department' => 'Acme Corp', 'ca_pem' => null,
            'created_at' => '2026-10-06T12:00:00Z', 'expires_at' => '2026-10-09T12:00:00Z',
        ], $over), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };
    $fakeExe = static fn (int $n): string => 'MZ' . str_repeat("\x90\x00\xFF\x7F", intdiv($n, 4)) . substr("PE\0\0", 0, $n % 4);
    $vec = [];
    $add = static function (string $name, string $exe, string $pl, string $note) use (&$vec, $footer): void {
        $vec[] = ['name' => $name, 'note' => $note, 'exe_hex' => bin2hex($exe), 'payload' => $pl, 'payload_length' => strlen($pl), 'payload_sha256_hex' => hash('sha256', $pl),
            'footer_hex' => bin2hex($footer($pl)), 'stamped_hex' => bin2hex($exe . $pl . $footer($pl))];
    };
    $add('basic', $fakeExe(64), $payload(), 'ordinary installer, no CA');
    $add('with_ca_and_unicode', $fakeExe(10), $payload(['department' => "Müller & Söhne \"Ost\" 日本", 'ca_pem' => "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----\n"]), 'UTF-8 department, CA PEM with newlines, escaped quotes');
    $add('one_byte_exe', 'M', $payload(), 'the original executable is a single byte');
    $add('empty_exe', '', $payload(), 'degenerate: nothing before the payload (the agent finds exe_length 0)');
    $add('magic_inside_body', 'MZ....RIVETIT-EMBED-v1....body', $payload(), 'the magic string inside the exe body is not a footer (a real agent binary contains the constant)');
    $add('minimal_payload', $fakeExe(8), '{}', 'two-byte payload: the format check passes, semantic checks belong to the parser');
    $pad = 16384 - strlen($payload(['department' => '']));
    $add('max_payload_16384', $fakeExe(32), $payload(['department' => str_repeat('d', $pad)]), 'payload exactly 16384 bytes: accepted');
    $neg = [];
    $addn = static function (string $name, string $stamped, string $why) use (&$neg): void {
        $neg[] = ['name' => $name, 'reason' => $why, 'stamped_hex' => bin2hex($stamped), 'expect' => 'reject'];
    };
    $p = $payload();
    $exe = $fakeExe(40);
    $good = $exe . $p . $footer($p);
    $addn('too_short', substr($good, -51), 'shorter than the 52-byte footer');
    $addn('empty_file', '', 'no bytes at all');
    $addn('bad_magic', substr($good, 0, -1) . '2', 'last byte of the magic changed');
    $addn('bad_magic_case', substr($good, 0, -16) . strtolower($magic), 'magic compared case-sensitively');
    $bad = $good;
    $bad[strlen($good) - 20] = $bad[strlen($good) - 20] ^ "\x01";
    $addn('bad_sha256', $bad, 'one bit of the stored hash flipped');
    $pp = $p;
    $pp[3] = 'X';
    $addn('payload_corrupted', $exe . $pp . $footer($p), 'payload changed after hashing');
    $addn('truncated_one_byte', substr($good, 0, -1), 'file truncated by one byte (magic incomplete)');
    $addn('length_zero', $exe . pack('N', 0) . hash('sha256', '', true) . $magic, 'declared payload length 0');
    $addn('length_exceeds_file', $exe . pack('N', 100000) . hash('sha256', $p, true) . $magic, 'declared length larger than the file');
    $big = str_repeat('a', 16385);
    $addn('length_16385', $exe . $big . pack('N', 16385) . hash('sha256', $big, true) . $magic, 'declared length one over the 16384 bound, hash valid');
    $addn('length_off_by_one_short', $exe . $p . pack('N', strlen($p) - 1) . hash('sha256', $p, true) . $magic, 'length does not match the hashed payload');
    $addn('length_off_by_one_long', $exe . $p . pack('N', strlen($p) + 1) . hash('sha256', $p, true) . $magic, 'length does not match the hashed payload (includes one exe byte)');
    $nj = 'not json at all';
    $addn('payload_not_json', $exe . $nj . $footer($nj), 'footer valid, payload is not JSON: the parser must refuse');
    $arr = '["rvte1.x"]';
    $addn('payload_json_array', $exe . $arr . $footer($arr), 'footer valid, payload is a JSON array, not an object');

    // Cross-check the independent statement above against the production class.
    foreach ($vec as $v) {
        $exeBytes = (string) hex2bin($v['exe_hex']);
        if (bin2hex(InstallerStamp::stamp($exeBytes, $v['payload'])) !== $v['stamped_hex'] || InstallerStamp::read((string) hex2bin($v['stamped_hex'])) === null) {
            fwrite(STDERR, "InstallerStamp disagrees with vector {$v['name']}\n");
            exit(1);
        }
    }
    foreach ($neg as $n) {
        if (InstallerStamp::read((string) hex2bin($n['stamped_hex'])) !== null) {
            fwrite(STDERR, "InstallerStamp accepts negative vector {$n['name']}\n");
            exit(1);
        }
    }

    return json_encode(['format' => 'RIVETIT-EMBED-v1', 'description' => 'stamped_exe = original exe || payload || footer; footer = uint32 BE payload length (4) || SHA-256 of payload (32) || ASCII "RIVETIT-EMBED-v1" (16) = 52 bytes; payload <= 16384 bytes. The agent reads the LAST 52 bytes of its own exe. Hex strings are lowercase.',
        'footer_length' => 52, 'max_payload' => 16384, 'vectors' => $vec, 'negative' => $neg], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * The optional MeshCentral agent members of the installer payload. Written with raw json_encode on purpose, then cross-checked against
 * InstallerStamp::buildPayload() (which must produce the same bytes for the three payloads it can build).
 */
function installerMeshVectors(): string
{
    $magic = 'RIVETIT-EMBED-v1';
    $footer = static fn (string $p): string => pack('N', strlen($p)) . hash('sha256', $p, true) . $magic;
    $base = [
        'version' => 1, 'installer_id' => '3f2b8c1e-5d4a-4e7b-9a10-6c2d8e9f0a1b', 'server_url' => 'https://rivet.example.com/rivetit',
        'enrollment_token' => 'rvte1.0123456789ab.0123456789abcdef0123456789abcdef01234567', 'department' => 'Acme Corp', 'ca_pem' => null,
        'created_at' => '2026-10-06T12:00:00Z', 'expires_at' => '2026-10-09T12:00:00Z',
    ];
    $group = '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';
    $pin = hash('sha384', 'RivetIT mesh test server certificate');
    $ids = ['linux/386' => 5, 'linux/amd64' => 6, 'linux/arm64' => 26, 'windows/386' => 3, 'windows/amd64' => 4, 'windows/arm64' => 36];
    $args = ['linux' => ['-install'], 'windows' => ['-fullinstall']];
    $enc = static fn (array $p): string => (string) json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $fakeExe = 'MZ' . str_repeat("\x90\x00\xFF\x7F", 16);
    $vec = [];
    $add = static function (string $name, string $note, string $payload, array $expect, ?array $built = null) use (&$vec, $footer, $fakeExe): void {
        $vec[] = ['name' => $name, 'note' => $note, 'exe_hex' => bin2hex($fakeExe), 'payload' => $payload, 'payload_length' => strlen($payload), 'payload_sha256_hex' => hash('sha256', $payload),
            'footer_hex' => bin2hex($footer($payload)), 'stamped_hex' => bin2hex($fakeExe . $payload . $footer($payload)), 'expect' => $expect, 'build_fields' => $built];
    };
    $meshPinned = ['url' => 'https://mesh.example.com', 'meshid' => $group, 'pin' => $pin, 'agent_ids' => $ids, 'install_args' => $args];
    $meshOpen = ['url' => 'https://mesh.example.com:8443/mesh', 'meshid' => $group, 'agent_ids' => $ids, 'install_args' => $args];
    $override = $ids;
    $override['windows/arm64'] = 40;
    $override['linux/amd64'] = 99;
    $meshOverride = ['url' => 'https://mesh.example.com', 'meshid' => $group, 'pin' => strtoupper(substr($pin, 0, 64)), 'agent_ids' => $override, 'install_args' => ['linux' => ['-install', '--installPath=/opt/mesh'], 'windows' => ['-fullinstall']]];
    $add('mesh_pinned_sha384', 'asks for the Mesh agent, server certificate pinned by its SHA-384', $enc($base + ['install_mesh_agent' => true, 'mesh' => $meshPinned]),
        ['outcome' => 'install', 'url' => 'https://mesh.example.com', 'pin_len' => 96, 'agent_id' => ['linux/amd64' => 6, 'windows/amd64' => 4]],
        $base + ['install_mesh_agent' => true, 'mesh' => $meshPinned]);
    $add('mesh_system_roots', 'no pin: the agent verifies the Mesh server with the system trust store', $enc($base + ['install_mesh_agent' => true, 'mesh' => $meshOpen]),
        ['outcome' => 'install', 'url' => 'https://mesh.example.com:8443/mesh', 'pin_len' => 0, 'agent_id' => ['linux/amd64' => 6]], $base + ['install_mesh_agent' => true, 'mesh' => $meshOpen]);
    $add('mesh_table_override', 'a corrected agent-id table and install arguments, a pin in upper case (the agent normalises it)', $enc($base + ['install_mesh_agent' => true, 'mesh' => $meshOverride]),
        ['outcome' => 'install', 'url' => 'https://mesh.example.com', 'pin_len' => 64, 'agent_id' => ['linux/amd64' => 99, 'windows/arm64' => 40, 'windows/amd64' => 4]], $base + ['install_mesh_agent' => true, 'mesh' => $meshOverride]);
    $add('mesh_not_requested', 'install_mesh_agent false: Mesh is not touched even though a source is present', $enc($base + ['install_mesh_agent' => false, 'mesh' => $meshPinned]), ['outcome' => 'disabled']);
    $add('mesh_requested_without_source', 'asks for the agent but carries no source: the RivetIT install must still work, the Mesh part records an error', $enc($base + ['install_mesh_agent' => true]), ['outcome' => 'unusable']);
    $add('mesh_wrong_types', 'install_mesh_agent is a string and mesh a number: never an install, never a parse failure', $enc($base + ['install_mesh_agent' => 'yes', 'mesh' => 5]), ['outcome' => 'disabled']);
    $add('mesh_bad_source', 'asks for the agent with an http:// address: refused by the agent, the RivetIT install still works', $enc($base + ['install_mesh_agent' => true, 'mesh' => ['url' => 'http://mesh.example.com', 'meshid' => $group]]), ['outcome' => 'unusable']);
    // ADDED in 1.0.0-rc.15: the group id in MeshCentral's 64-character URL form (the shape a real MeshCentral prints in its download address). The value is a fresh
    // random 48-byte id made for these vectors, not any real server's. A pinned-free system-roots source, like a Let's Encrypt server.
    $formHex = 'e10df2d66e829b53c567652d4d91add5c62f457fe0d70c32f9d2012df54eb7aad9e0cab782a7c99b0992317e235d849e';
    $formUrl = '4Q3y1m6Cm1PFZ2UtTZGt1cYvRX$g1wwy@dIBLfVOt6rZ4Mq3gqfJmwmSMX4jXYSe';
    $meshUrlForm = ['url' => 'https://mesh.example.com', 'meshid' => $formUrl, 'agent_ids' => $ids, 'install_args' => $args];
    $meshHexForm = ['url' => 'https://mesh.example.com', 'meshid' => $formHex, 'agent_ids' => $ids, 'install_args' => $args];
    $expectUrl = ['outcome' => 'install', 'url' => 'https://mesh.example.com', 'pin_len' => 0, 'agent_id' => ['linux/amd64' => 6, 'windows/amd64' => 4], 'wire_meshid' => $formUrl,
        'download_query' => ['windows/amd64' => '/meshagents?id=4&meshid=' . $formUrl . '&installflags=0', 'linux/amd64' => '/meshagents?id=6&meshid=' . $formUrl . '&installflags=0']];
    $add('mesh_url_form_meshid', 'meshid is MeshCentral\'s 64-character URL form (carries @ and $): sent to the Mesh server exactly as given, as the server now writes it', $enc($base + ['install_mesh_agent' => true, 'mesh' => $meshUrlForm]),
        $expectUrl, $base + ['install_mesh_agent' => true, 'mesh' => $meshUrlForm]);
    $add('mesh_hex_meshid_derives_url_form', 'meshid is the hex of the same 48 bytes (what a pre-rc.15 server wrote): the agent derives the identical URL form for the download address', $enc($base + ['install_mesh_agent' => true, 'mesh' => $meshHexForm]),
        $expectUrl, $base + ['install_mesh_agent' => true, 'mesh' => $meshHexForm]);
    $add('mesh_bad_meshid_forms', 'meshid with a space, an ampersand and a quote is refused by the agent; the RivetIT install still works', $enc($base + ['install_mesh_agent' => true, 'mesh' => ['url' => 'https://mesh.example.com', 'meshid' => 'a b&c"' . substr($formUrl, 6)]]), ['outcome' => 'unusable']);
    $add('unknown_future_members', 'members no agent knows yet are ignored (this is how an OLD agent reads every vector in this file)', $enc($base + ['install_mesh_agent' => true, 'mesh' => $meshOpen, 'future_member' => ['a' => 1], 'another' => [1, 2, 3]]), ['outcome' => 'install', 'url' => 'https://mesh.example.com:8443/mesh', 'pin_len' => 0, 'agent_id' => ['linux/amd64' => 6]]);

    // The three payloads the server can build must be exactly these bytes; and a payload that does not ask for Mesh is exactly the frozen one.
    foreach ($vec as $v) {
        if ($v['build_fields'] !== null) {
            $fields = $v['build_fields'];
            $fields['mesh']['pin'] = $fields['mesh']['pin'] ?? null;
            if ($fields['mesh']['pin'] === null) {
                unset($fields['mesh']['pin']);
            }
            if ($v['name'] === 'mesh_table_override') {
                continue;   // the pin of this vector is deliberately not in the normalised form the server writes
            }
            if (InstallerStamp::buildPayload($fields) !== $v['payload']) {
                fwrite(STDERR, "InstallerStamp::buildPayload disagrees with mesh vector {$v['name']}\n");
                exit(1);
            }
        }
    }
    if (InstallerStamp::buildPayload($base) !== $enc($base)) {
        fwrite(STDERR, "a payload without Mesh must be byte for byte the frozen one\n");
        exit(1);
    }
    foreach ($vec as &$v) {
        unset($v['build_fields']);
    }
    unset($v);

    // The two forms of a device group id both sides must convert identically (base64 of the 48 bytes, "+" written "@", "/" written "$").
    $forms = [];
    foreach ([['fresh_random_with_at_and_dollar', $formHex, $formUrl], ['sequence_00_to_2f', bin2hex(implode('', array_map('chr', range(0, 47)))), null], ['fb_ff_bf_then_01', bin2hex("\xfb\xff\xbf" . str_repeat("\x01", 45)), null]] as [$name, $hex, $url]) {
        $derived = strtr(base64_encode((string) hex2bin($hex)), ['+' => '@', '/' => '$']);
        if ($url !== null && $url !== $derived) {
            fwrite(STDERR, "group form vector $name: hand-written URL form differs from the derivation\n");
            exit(1);
        }
        if (\RivetCore\Rmm\Mesh\MeshAgentCatalog::hexToUrl($hex) !== $derived || \RivetCore\Rmm\Mesh\MeshAgentCatalog::urlToHex($derived) !== $hex
            || \RivetCore\Rmm\Mesh\MeshAgentCatalog::normalizeGroupId($derived) !== $hex || \RivetCore\Rmm\Mesh\MeshAgentCatalog::normalizeGroupId('mesh//' . $derived) !== $hex) {
            fwrite(STDERR, "MeshAgentCatalog disagrees with group form vector $name\n");
            exit(1);
        }
        $forms[] = ['name' => $name, 'hex' => $hex, 'url_form' => $derived];
    }

    return json_encode(['format' => 'RIVETIT-EMBED-v1', 'description' => 'Installer payloads that carry the OPTIONAL MeshCentral agent members (install_mesh_agent, mesh). Additive: payload version stays 1, the footer format is unchanged, and an agent that does not know the members ignores them. expect.outcome: install (the agent must parse a usable source), disabled (no Mesh install, the payload still parses) or unusable (Mesh requested but the source is unusable: the install of the RivetIT agent must still succeed). The default tables are what both the server (MeshAgentCatalog) and the agent (internal/mesh) must contain. Verified against a real MeshCentral (rc.15): the download address shape <mesh>/meshagents?id=4&meshid=<64-character URL form>&installflags=0 (id 4 = Windows x64). Still unverified: the Linux ids, the install arguments and the node-id locations. group_forms: the hex and the URL form of one group id, both directions.',
        'default_agent_ids' => $ids, 'default_install_args' => $args, 'group_forms' => $forms, 'vectors' => $vec], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * ADDED in 1.0.0-rc.21: the parameter contract of the software jobs (`software_install`, `software_detect`) and the download-address rule, as accept/reject
 * vectors both sides must agree on. Each case is labelled by hand (`valid`) and the generator refuses to write when the PHP validators disagree with a label, so
 * the file states the contract, not whatever the code happens to do. The Go agent replays the same file against its own parsers (internal/deploy).
 */
function softwareDeployVectors(): string
{
    $sha = str_repeat('ab', 32);
    $code = '{12345678-ABCD-1234-ABCD-1234567890AB}';
    $msi = ['action' => 'install', 'mode' => 'run', 'fmt' => 'msi', 'url' => 'https://downloads.example.com/a/setup.msi', 'sha256' => $sha, 'size' => 1048576, 'product_code' => $code,
        'detect_min' => '2.4.1', 'max_minutes' => 30];
    $exe = ['action' => 'install', 'mode' => 'dry_run', 'fmt' => 'exe', 'url' => 'https://downloads.example.com/s.exe', 'sha256' => $sha, 'size' => 5000, 'args' => '/S /norestart',
        'detect_kind' => 'uninstall_name', 'detect_value' => 'Acme Agent', 'detect_min' => '', 'max_minutes' => 10];
    $apt = ['action' => 'install', 'mode' => 'run', 'fmt' => 'apt', 'pkg' => 'rivetit-dummy', 'version' => '1.5-1', 'max_minutes' => 30];
    $rm = ['action' => 'uninstall', 'mode' => 'run', 'fmt' => 'msi', 'product_code' => $code, 'max_minutes' => 30];
    $cases = [];
    $install = static function (string $name, array $params, bool $valid) use (&$cases): void {
        $cases[] = ['name' => $name, 'params' => $params, 'valid' => $valid];
    };
    $install('msi install', $msi, true);
    $install('msi install, defaults only', ['action' => 'install', 'fmt' => 'msi', 'url' => $msi['url'], 'sha256' => $sha, 'size' => 10, 'product_code' => $code], true);
    $install('msi install without a minimum version', array_diff_key($msi, ['detect_min' => 1]), true);
    $install('msi uninstall', $rm, true);
    $install('exe install', $exe, true);
    $install('exe install without args', array_diff_key($exe, ['args' => 1]), true);
    $install('exe install, detection by product code', ['detect_kind' => 'product_code', 'detect_value' => $code] + $exe, true);
    $install('apt install', $apt, true);
    $install('apt uninstall', ['action' => 'uninstall', 'mode' => 'run', 'fmt' => 'apt', 'pkg' => 'rivetit-dummy'], true);
    $install('dnf install, dry run', ['action' => 'install', 'fmt' => 'dnf', 'pkg' => 'rivetit-dummy'], true);
    $install('apt install, pinned epoch version', ['version' => '1:2.0~rc1+dfsg-1'] + $apt, true);
    $install('url with a query string and port 443', ['url' => 'https://downloads.example.com:443/a/setup.msi?token=A1_b-2.c~d%20e'] + $msi, true);
    $install('max_minutes at the top of its range', ['max_minutes' => 120] + $msi, true);
    $install('size at the cap', ['size' => 1073741824] + $msi, true);
    $bad = [
        'empty params' => [],
        'unknown key' => $msi + ['extra' => 1],
        'unknown key on apt' => $apt + ['cmd' => 'id'],
        'action missing' => array_diff_key($msi, ['action' => 1]),
        'action unknown' => ['action' => 'upgrade'] + $msi,
        'action upper case' => ['action' => 'Install'] + $msi,
        'mode unknown' => ['mode' => 'install'] + $msi,
        'mode as a boolean' => ['mode' => true] + $msi,
        'fmt unknown' => ['fmt' => 'deb'] + $msi,
        'fmt missing' => array_diff_key($msi, ['fmt' => 1]),
        'msi with a package name' => $msi + ['pkg' => 'x'],
        'msi without a url' => array_diff_key($msi, ['url' => 1]),
        'msi without a hash' => array_diff_key($msi, ['sha256' => 1]),
        'msi without a size' => array_diff_key($msi, ['size' => 1]),
        'msi with exe arguments' => $msi + ['args' => '/S'],
        'msi uninstall with a url' => $rm + ['url' => $msi['url']],
        'msi uninstall with a hash' => $rm + ['sha256' => $sha],
        'msi uninstall without a product code' => array_diff_key($rm, ['product_code' => 1]),
        'exe uninstall' => ['action' => 'uninstall'] + array_diff_key($exe, ['url' => 1, 'sha256' => 1, 'size' => 1, 'args' => 1]),
        'exe with a product code' => $exe + ['product_code' => $code],
        'exe without a detection rule' => array_diff_key($exe, ['detect_kind' => 1, 'detect_value' => 1, 'detect_min' => 1]),
        'exe with a half detection rule' => array_diff_key($exe, ['detect_value' => 1]),
        'exe detection by package' => ['detect_kind' => 'package', 'detect_value' => 'x'] + $exe,
        'url http' => ['url' => 'http://downloads.example.com/a.msi'] + $msi,
        'url ftp' => ['url' => 'ftp://downloads.example.com/a.msi'] + $msi,
        'url file' => ['url' => 'file:///etc/passwd'] + $msi,
        'url with user information' => ['url' => 'https://user@downloads.example.com/a.msi'] + $msi,
        'url with user and password' => ['url' => 'https://user:pw@downloads.example.com/a.msi'] + $msi,
        'url host after at sign' => ['url' => 'https://downloads.example.com@evil.example/a.msi'] + $msi,
        'url with a backslash' => ['url' => 'https://evil.example\\@downloads.example.com/a.msi'] + $msi,
        'url with a fragment' => ['url' => 'https://downloads.example.com/a.msi#x'] + $msi,
        'url with another port' => ['url' => 'https://downloads.example.com:8443/a.msi'] + $msi,
        'url with an empty port' => ['url' => 'https://downloads.example.com:/a.msi'] + $msi,
        'url with an IPv4 host' => ['url' => 'https://127.0.0.1/a.msi'] + $msi,
        'url with a decimal host' => ['url' => 'https://2130706433/a.msi'] + $msi,
        'url with a hex host' => ['url' => 'https://0x7f.0.0.1/a.msi'] + $msi,
        'url with an IPv6 host' => ['url' => 'https://[::1]/a.msi'] + $msi,
        'url with a single label host' => ['url' => 'https://localhost/a.msi'] + $msi,
        'url with a trailing dot' => ['url' => 'https://downloads.example.com./a.msi'] + $msi,
        'url with a space' => ['url' => 'https://downloads.example.com/a b.msi'] + $msi,
        'url with a newline' => ['url' => "https://downloads.example.com/a.msi\n"] + $msi,
        'url with a tab' => ['url' => "https://downloads.example.com/a\t.msi"] + $msi,
        'url with a NUL' => ['url' => "https://downloads.example.com/a\0.msi"] + $msi,
        'url with upper case in the host' => ['url' => 'https://Downloads.Example.com/a.msi'] + $msi,
        'url with a non-ASCII host' => ['url' => "https://d\u{f6}wnloads.example.com/a.msi"] + $msi,
        'url too long' => ['url' => 'https://downloads.example.com/' . str_repeat('a', 600)] + $msi,
        'url empty' => ['url' => ''] + $msi,
        'url as a number' => ['url' => 5] + $msi,
        'hash upper case' => ['sha256' => strtoupper($sha)] + $msi,
        'hash too short' => ['sha256' => substr($sha, 1)] + $msi,
        'hash too long' => ['sha256' => $sha . 'a'] + $msi,
        'hash not hex' => ['sha256' => str_repeat('g', 64)] + $msi,
        'hash with a newline' => ['sha256' => $sha . "\n"] + $msi,
        'size zero' => ['size' => 0] + $msi,
        'size negative' => ['size' => -1] + $msi,
        'size over the cap' => ['size' => 1073741825] + $msi,
        'size as a string' => ['size' => '100'] + $msi,
        'size as a float' => ['size' => 100.5] + $msi,
        'size as a boolean' => ['size' => true] + $msi,
        'args with a quote' => ['args' => '/S "x"'] + $exe,
        'args with an ampersand' => ['args' => '/S & calc'] + $exe,
        'args with a semicolon' => ['args' => '/S;calc'] + $exe,
        'args with a pipe' => ['args' => '/S|x'] + $exe,
        'args with a dollar' => ['args' => '/S $(id)'] + $exe,
        'args with a backtick' => ['args' => '/S `id`'] + $exe,
        'args with a percent' => ['args' => '/S %TEMP%'] + $exe,
        'args with a newline' => ['args' => "/S\n/x"] + $exe,
        'args with a double space' => ['args' => '/S  /x'] + $exe,
        'args with a leading space' => ['args' => ' /S'] + $exe,
        'args with 13 words' => ['args' => implode(' ', array_fill(0, 13, '/x'))] + $exe,
        'args with a long word' => ['args' => str_repeat('a', 81)] + $exe,
        'args over 200 characters' => ['args' => implode(' ', array_fill(0, 60, 'abcd'))] + $exe,
        'args as an array' => ['args' => ['/S']] + $exe,
        'product code without braces' => ['product_code' => '12345678-ABCD-1234-ABCD-1234567890AB'] + $msi,
        'product code with a bad digit' => ['product_code' => '{1234567G-ABCD-1234-ABCD-1234567890AB}'] + $msi,
        'product code with a newline' => ['product_code' => $code . "\n"] + $msi,
        'detect_kind unknown' => ['detect_kind' => 'file'] + $msi,
        'msi with a second detection rule' => ['detect_kind' => 'product_code', 'detect_value' => $code] + $msi,
        'msi uninstall with a minimum version' => $rm + ['detect_min' => '1.0'],
        'detect_min with a leading letter' => ['detect_min' => 'v2.4'] + $msi,
        'detect_min with a space' => ['detect_min' => '2.4 1'] + $msi,
        'detect_min too long' => ['detect_min' => '1' . str_repeat('0', 64)] + $msi,
        'detect_value with a control character' => ['detect_kind' => 'uninstall_name', 'detect_value' => "Acme\x01"] + $exe,
        'detect_value empty' => ['detect_kind' => 'uninstall_name', 'detect_value' => ''] + $exe,
        'max_minutes zero' => ['max_minutes' => 0] + $msi,
        'max_minutes over the cap' => ['max_minutes' => 121] + $msi,
        'max_minutes as a string' => ['max_minutes' => '30'] + $msi,
        'max_minutes as a float' => ['max_minutes' => 30.5] + $msi,
        'apt without a package' => array_diff_key($apt, ['pkg' => 1]),
        'apt with an option as the package' => ['pkg' => '-oAPT::Foo'] + $apt,
        'apt with a space in the package' => ['pkg' => 'a b'] + $apt,
        'apt with a command in the package' => ['pkg' => 'x;touch /w/pwned'] + $apt,
        'apt with a substitution in the package' => ['pkg' => '$(touch /w/pwned)'] + $apt,
        'apt with a newline after the package' => ['pkg' => "pkg\n"] + $apt,
        'apt with an upper case package' => ['pkg' => 'Pkg'] + $apt,
        'apt with a package starting with a dot' => ['pkg' => '.hidden'] + $apt,
        'apt with a package starting with a plus' => ['pkg' => '+x'] + $apt,
        'apt with a 101 character package' => ['pkg' => str_repeat('a', 101)] + $apt,
        'apt with an arch suffix' => ['pkg' => 'libc6:amd64'] + $apt,
        'apt with a url' => $apt + ['url' => $msi['url']],
        'apt with a hash' => $apt + ['sha256' => $sha],
        'apt with arguments' => $apt + ['args' => '/S'],
        'apt with a product code' => $apt + ['product_code' => $code],
        'apt with a detection rule' => $apt + ['detect_kind' => 'package', 'detect_value' => 'rivetit-dummy', 'detect_min' => ''],
        'apt uninstall with a version' => ['action' => 'uninstall'] + $apt,
        'apt version with a space' => ['version' => '1 2'] + $apt,
        'apt version starting with a symbol' => ['version' => '~1'] + $apt,
        'apt version with an equals sign' => ['version' => '1=2'] + $apt,
        'apt version with a semicolon' => ['version' => '1;id'] + $apt,
        'apt version too long' => ['version' => 'a' . str_repeat('1', 64)] + $apt,
        'apt version as a number' => ['version' => 1.5] + $apt,
        'msi install with a version' => ['version' => '1.0'] + $msi,
    ];
    foreach ($bad as $name => $params) {
        $install($name, $params, false);
    }
    $rules = static fn (array $list): array => ['rules' => json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    $pkgRule = ['key' => 'p1', 'kind' => 'package', 'value' => 'openssl', 'min' => '3.0'];
    $winRule = ['key' => 'p2', 'kind' => 'product_code', 'value' => $code, 'min' => ''];
    $nameRule = ['key' => 'p3', 'kind' => 'uninstall_name', 'value' => 'Acme Agent (x64)', 'min' => '2.4.1'];
    $detect = [];
    $d = static function (string $name, array $params, bool $valid) use (&$detect): void {
        $detect[] = ['name' => $name, 'params' => $params, 'valid' => $valid];
    };
    $d('one package rule', $rules([$pkgRule]), true);
    $d('a product code rule', $rules([$winRule]), true);
    $d('an uninstall name rule with unicode', $rules([['value' => "Caf\u{e9} Agent"] + $nameRule]), true);
    $d('three rules', $rules([$pkgRule, $winRule, $nameRule]), true);
    $d('no minimum', $rules([['min' => ''] + $pkgRule]), true);
    foreach ([
        'no rules key' => [],
        'rules as an array, not text' => ['rules' => [$pkgRule]],
        'rules text is not JSON' => ['rules' => 'not json'],
        'rules text is an object' => ['rules' => json_encode($pkgRule)],
        'rules text is an empty list' => ['rules' => '[]'],
        'rules with an unknown member' => $rules([$pkgRule + ['cmd' => 'id']]),
        'rules missing a member' => $rules([array_diff_key($pkgRule, ['min' => 1])]),
        'rules with a duplicate key' => $rules([$pkgRule, ['value' => 'zlib'] + $pkgRule]),
        'rules key with upper case' => $rules([['key' => 'P1'] + $pkgRule]),
        'rules key starting with a symbol' => $rules([['key' => '-p1'] + $pkgRule]),
        'rules key too long' => $rules([['key' => 'a' . str_repeat('1', 40)] + $pkgRule]),
        'rules kind unknown' => $rules([['kind' => 'file'] + $pkgRule]),
        'package rule with an option as the name' => $rules([['value' => '-oAPT::Foo'] + $pkgRule]),
        'package rule with an upper case name' => $rules([['value' => 'OpenSSL'] + $pkgRule]),
        'product code rule with a bad code' => $rules([['value' => 'nope'] + $winRule]),
        'uninstall name rule with a control character' => $rules([['value' => "A\x01"] + $nameRule]),
        'uninstall name rule empty' => $rules([['value' => ''] + $nameRule]),
        'uninstall name rule too long' => $rules([['value' => str_repeat('a', 121)] + $nameRule]),
        'minimum with a letter first' => $rules([['min' => 'v1'] + $pkgRule]),
        'member as a number' => $rules([['value' => 5] + $pkgRule]),
        'an unknown parameter next to rules' => $rules([$pkgRule]) + ['extra' => 1],
        'rules text over 1000 characters' => ['rules' => json_encode(array_map(static fn (int $i): array => ['key' => "p$i", 'kind' => 'uninstall_name', 'value' => str_repeat('a', 100), 'min' => ''], range(1, 12)))],
        'more than 25 rules' => $rules(array_map(static fn (int $i): array => ['key' => "p$i", 'kind' => 'package', 'value' => 'a', 'min' => ''], range(1, 26))),
    ] as $name => $params) {
        $d($name, $params, false);
    }
    // The PHP validators must agree with every label, or the file would record a wrong contract.
    foreach ($cases as $c) {
        [, $err] = \RivetCore\Rmm\Deploy\DeployJobTypes::install($c['params']);
        if (($err === null) !== $c['valid']) {
            fwrite(STDERR, "software deploy vector '{$c['name']}': the label says " . ($c['valid'] ? 'valid' : 'invalid') . ', the PHP validator says ' . ($err ?? 'valid') . "\n");
            exit(1);
        }
    }
    foreach ($detect as $c) {
        [, $err] = \RivetCore\Rmm\Deploy\DeployJobTypes::detect($c['params']);
        if (($err === null) !== $c['valid']) {
            fwrite(STDERR, "software detect vector '{$c['name']}': the label says " . ($c['valid'] ? 'valid' : 'invalid') . ', the PHP validator says ' . ($err ?? 'valid') . "\n");
            exit(1);
        }
    }

    return json_encode(['format' => 'RIVETIT-SOFTWARE-DEPLOY-v1', 'description' => 'Accept/reject vectors for the parameters of the software_install and software_detect jobs (RMM Phase 5). install: the params object of a software_install job; detect: the params object of a software_detect job (rules is a JSON text). valid=true means a server builds it and an agent must accept it; valid=false means the server never sends it and an agent must refuse it (invalid_params) before it runs or downloads anything. The Go agent replays this file against internal/deploy; the PHP validators are checked against it by the generator.',
        'install' => $cases, 'detect' => $detect], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * Change monitoring (1.0.0-rc.22): the watch policy decisions both sides must agree on, the secret-name rule, and one complete SIGNED configuration (domain line, canonical
 * JSON, Ed25519 by the test key) that the Go agent must accept and that a one-character change must break.
 */
function changeMonitorVectors(): string
{
    $seed = hash('sha256', 'RivetIT-agent-TEST-seed', true);
    [$pub, $sec] = Signer::keypairFromSeed($seed);
    $policy = new \RivetCore\Rmm\Change\ChangeWatchPolicy();
    $withRoot = new \RivetCore\Rmm\Change\ChangeWatchPolicy(['/srv/app']);
    $run = 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run';
    $registry = [
        $run, $run . '\\Sub', 'hklm\\software\\microsoft\\windows\\currentversion\\run', 'HKEY_LOCAL_MACHINE\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run', 'HKU\\*\\Software\\Microsoft\\Windows\\CurrentVersion\\Run',
        'HKU\\S-1-5-21-1-2-3-1001\\Software\\Microsoft\\Windows\\CurrentVersion\\Run', 'HKU\\.DEFAULT\\Software\\Acme', 'HKLM\\SYSTEM\\CurrentControlSet\\Services', 'HKLM\\SYSTEM\\ControlSet001\\Services\\Tcpip',
        'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Lsa', 'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Terminal Server', 'HKLM\\SOFTWARE\\Microsoft\\Windows Defender\\Exclusions\\Paths',
        'HKLM\\SAM', 'HKLM\\SAM\\SAM\\Domains', 'hklm\\sam\\sam', 'HKEY_LOCAL_MACHINE\\SAM\\SAM', 'HKLM\\SECURITY', 'HKLM\\SECURITY\\Policy\\Secrets', 'HKEY_LOCAL_MACHINE\\security\\policy',
        'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Lsa\\JD', 'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Lsa\\Skew1', 'HKLM\\SYSTEM\\ControlSet001\\Control\\Lsa\\GBG', 'HKLM\\system\\currentcontrolset\\control\\lsa\\data',
        'HKLM\\BCD00000000\\Objects', 'HKLM\\SOFTWARE\\Acme\\Secrets', 'HKLM\\SOFTWARE\\Acme\\SAM\\x', 'HKLM', 'HKLM\\', 'HKLM\\SOFTWARE', 'HKLM\\SYSTEM', 'HKU', 'HKU\\S-1-5-18', 'HKCU\\Software\\Acme\\Agent',
        'HKCR\\Acme\\Shell', '\\Registry\\Machine\\SAM', 'HKLM\\\\SOFTWARE\\Acme\\Agent', $run . '\\', ' ' . $run, $run . ' ', 'HKLM/SOFTWARE/Acme/Agent', 'HKLM\\SOFTWARE\\Acme\\..\\..\\SAM', 'HKLM\\SOFTWARE\\.\\Acme\\Agent',
        'HKU\\notasid\\Software\\Acme', "HKLM\\SOFTWARE\\Acme\\Ag\nent", "HKLM\\SOFTWARE\\Acme\0\\x", "HKLM\\SOFTWARE\\Acme\u{200b}\\Agent", '',
    ];
    $files = [
        '/etc/ssh/sshd_config', '/etc/ssh/sshd_config.d', '/etc/sudoers', '/etc/sudoers.d/10-ops', '/etc/passwd', '/etc/group', '/etc/crontab', '/etc/cron.d', '/var/spool/cron', '/var/spool/cron/crontabs/root',
        '/etc/systemd/system', '/etc/systemd/system/multi-user.target.wants/x.service', '/usr/local/lib/systemd/system', '/root/.ssh/authorized_keys', '/home/bob/.ssh/authorized_keys', '/etc/hosts', '/etc/ld.so.preload',
        '/etc/shadow', '/etc/shadow-', '/etc/gshadow', '/etc/security/opasswd', '/etc/ssh/ssh_host_rsa_key', '/etc/ssh/ssh_host_ed25519_key.pub', '/etc/ssl/private/site.key', '/etc/ssl/private', '/root/.ssh/id_rsa',
        '/home/bob/.ssh/id_ed25519', '/etc/letsencrypt/live/x/privkey.pem', '/etc/wireguard/wg0.conf', '/etc/openvpn/client.key', '/etc/NetworkManager/system-connections/Home.nmconnection', '/etc/krb5.keytab',
        '/etc/ppp/chap-secrets', '/etc/mysql/debian.cnf', '/etc/nginx/.htpasswd', '/etc/nginx/server.key', '/etc/apt/auth.conf', '/etc', '/', '/root', '/home', '/home/bob', '/home/bob/.bash_history', '/proc/1/environ', '/var/log/auth.log',
        'etc/hosts', './etc/hosts', '/etc/./hosts', '/etc//hosts', '/etc/hosts/', '/etc/../root/.ssh/id_rsa', '/etc\\hosts', '~root/.ssh', 'file:///etc/hosts', " /etc/hosts", '/etc/hosts ', "/etc/ho\0sts", "/etc/ho\nsts", "/etc/ho\u{200b}sts", '',
        '/etc/shadow.bak', '/etc/shadow~', '/etc/shadow.old', '/etc/gshadow.bak', '/etc/postfix/sasl_passwd', '/etc/samba/smbpasswd', '/etc/ppp/passwd.client', '/etc/wpa_supplicant/wpa_supplicant.conf', '/etc/sssd/sssd.conf',
        '/etc/ssh/ssh_host_ed25519_key', '/etc/ssl/foo_key', '/etc/foo/id_ed25519', '/etc/x./y', '/etc/x/y.', '/etc/passwd-', '/etc/sudoers.d/10-ops.',
        '/srv/app/app.conf', '/srv/app/id_rsa', '/srv/app/site.key', '/srv/app', '/srv', '/srv/application/x.conf',
        'C:\\Windows\\System32\\drivers\\etc\\hosts', 'c:\\windows\\system32\\drivers\\etc\\hosts', 'D:\\Windows\\System32\\GroupPolicy\\Machine\\Registry.pol', 'C:\\Windows\\System32\\config\\SAM', 'C:\\Windows\\System32\\config\\SECURITY',
        'C:\\Windows\\System32\\config\\RegBack\\SAM', 'C:\\Windows\\NTDS\\ntds.dit', 'C:\\Users\\bob\\NTUSER.DAT', 'C:\\Windows\\System32\\drivers\\etc\\..\\..\\config\\SAM', 'C:/Windows/System32/config/SAM', 'C:\\', 'C:\\Windows', '\\\\server\\share\\x',
        'C:\\Windows\\System32\\drivers\\etc\\key.pfx',
    ];
    $decide = static fn (\RivetCore\Rmm\Change\ChangeWatchPolicy $p, string $kind, array $targets): array => array_map(static fn (string $t): array => ['target' => $t, 'allowed' => $p->allows($kind, $t, 7)], $targets);
    $names = ['Password', 'DbPassword', 'user_pwd', 'ApiToken', 'api-key', 'ApiKey', 'PrivateKey', 'ClientSecret', 'SharedKey', 'ConnectionString', 'AccessKey', 'SasToken', 'Cookie', 'passphrase', 'Credentials',
        'Start', 'ImagePath', 'ObjectName', 'DisplayName', 'Shell', 'Userinit', 'EnableLUA', 'fDenyTSConnections', 'PortNumber', '(Default)', 'Server', 'KeyboardLayout', 'Monkey', 'Tokenizer', 'Compass'];
    $secret = array_map(static fn (string $n): array => ['name' => $n, 'secret' => \RivetCore\Rmm\Change\ChangeProtocol::secretName($n)], $names);

    $config = ['v' => 1, 'kind' => 'change_config', 'platform' => 'windows', 'interval_s' => 300, 'batch_max' => 100, 'rate_per_min' => 120, 'max_value_bytes' => 256, 'seq' => 1760000000,
        'watches' => [
            ['id' => 'win.startup', 'kind' => 'registry', 'paths' => [['key' => $run, 'depth' => 0], ['key' => 'HKU\\*\\Software\\Microsoft\\Windows\\CurrentVersion\\Run', 'depth' => 0],
                ['key' => 'HKLM\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Winlogon', 'depth' => 0, 'values' => ['Shell', 'Userinit']]]],
            ['id' => 'win.local_admins', 'kind' => 'group', 'name' => 'Administrators'],
            ['id' => 'win.hosts', 'kind' => 'file', 'paths' => [['path' => 'C:\\Windows\\System32\\drivers\\etc\\hosts']]],
            ['id' => 'win.tasks', 'kind' => 'task'],
            ['id' => 'custom.acme', 'kind' => 'registry', 'paths' => [['key' => 'HKLM\\SOFTWARE\\Acme\\Caf' . "\u{e9}", 'depth' => 2]]],
        ]];
    $config['version'] = substr(hash('sha256', Signer::changeConfigMessage($config)), 0, 16);
    $message = Signer::changeConfigMessage($config);
    $config['signature'] = Signer::sign($message, $sec);

    $out = [
        'format' => 'RIVETIT-CHANGE-MONITOR-v1',
        'description' => 'Change monitoring vectors (1.0.0-rc.22). policy: what ChangeWatchPolicy::allows() answers (the Go agent\'s RegistryKeyAllowed and FileAllowed must give the same answer for every row; extra_root rows are for a policy with /srv/app added, which the agent takes from config.json). secret_names: ChangeProtocol::secretName(). signed_config: a complete config.change object; the signed message is the domain line, a line feed, then the canonical JSON of the object without its signature; the agent must accept it with the test public key, and must refuse it after any change.',
        'test_key' => ['public_key_base64' => $pub],
        'policy' => ['registry' => $decide($policy, 'registry', $registry), 'file' => $decide($policy, 'file', $files),
            'file_extra_root' => ['root' => '/srv/app', 'rows' => $decide($withRoot, 'file', ['/srv/app/app.conf', '/srv/app/id_rsa', '/srv/app/site.key', '/srv/app', '/srv', '/srv/application/x.conf', '/etc/shadow', '/srv/app/../../etc/shadow'])]],
        'secret_names' => $secret,
        'signed_config' => ['sign_domain' => \RivetCore\Rmm\Change\ChangeProtocol::SIGN_DOMAIN, 'signed_message' => $message, 'config' => $config, 'expected_watch_ids' => array_column($config['watches'], 'id')],
    ];

    return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

function checkOsVectors(): string
{
    $seed = hash('sha256', 'RivetIT-agent-TEST-seed', true);
    [$pub, $sec] = Signer::keypairFromSeed($seed);
    $schedule = [
        ['key' => 'svc_eventlog', 'type' => 'service', 'params' => ['name' => 'EventLog', 'expected' => 'running'], 'interval_s' => 300, 'os' => 'windows'],
        ['key' => 'svc_cron', 'type' => 'service', 'params' => ['name' => 'cron', 'missing' => 'warn'], 'interval_s' => 300, 'os' => 'linux'],
        ['key' => 'disk_any', 'type' => 'disk', 'params' => ['mount' => '/'], 'interval_s' => 600],
        ['key' => 'pending_reboot', 'type' => 'pending_reboot', 'params' => [], 'interval_s' => 3600, 'os' => 'windows'],
    ];
    $signed = [];
    foreach ($schedule as $c) {
        $item = $c;
        if ($item['params'] === []) {
            $item['params'] = new \stdClass();
        }
        $item['signature'] = Signer::sign(Signer::checkMessage($item), $sec);
        $signed[] = $item;
    }
    $keys = static fn (array $l): array => array_column($l, 'key');
    $out = [
        'description' => 'Per-OS check targeting (1.0.0-rc.24). signed_checks: check definitions as the server delivers them, each signed by the test key over the canonical JSON of the entry INCLUDING its optional os member (windows or linux; absent means every OS). The agent verifies the object exactly as delivered. delivered: the keys the server delivers to a device of that os (RmmChecks::forOs); the agent must run exactly these keys from the same list (it drops any check whose os is not its own GOOS). missing_values: the service check param missing (fail, warn, ignore; default fail).',
        'test_key' => ['public_key_base64' => $pub],
        'signed_checks' => json_decode((string) json_encode($signed)),   // objects, so empty params stay {}
        'delivered' => ['windows' => $keys(\RivetCore\Rmm\Checks\RmmChecks::forOs($schedule, 'windows')), 'linux' => $keys(\RivetCore\Rmm\Checks\RmmChecks::forOs($schedule, 'linux'))],
        'missing_values' => ['fail', 'warn', 'ignore'],
    ];

    return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

$files = [
    'agent_installer_trailer_vectors.json' => installerTrailerVectors(),
    'agent_job_signing_vectors.json' => jobSigningVectors(),
    'agent_installer_mesh_vectors.json' => installerMeshVectors(),   // appended last: the two frozen lines of SHA256SUMS keep their place
    'agent_software_deploy_vectors.json' => softwareDeployVectors(),   // rc.21: additive again
    'agent_change_monitor_vectors.json' => changeMonitorVectors(),   // rc.22: additive again
    'agent_check_os_vectors.json' => checkOsVectors(),   // rc.24: additive again
];
$sums = '';
foreach ($files as $name => $bytes) {
    $sums .= hash('sha256', $bytes) . '  ' . $name . "\n";
}
$files['SHA256SUMS'] = $sums;

$differ = [];
foreach ($files as $name => $bytes) {
    $path = $dir . '/' . $name;
    if ($mode === '--write') {
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            fwrite(STDERR, "cannot create $dir\n");
            exit(1);
        }
        file_put_contents($path, $bytes);
        echo "wrote $path\n";
    } elseif (!is_file($path) || file_get_contents($path) !== $bytes) {
        $differ[] = $name;
    }
}
if ($mode === '--check') {
    if ($differ !== []) {
        fwrite(STDERR, 'DIFFERS: ' . implode(', ', $differ) . "\n");
        exit(1);
    }
    echo "endpoint vectors reproduce byte for byte\n";
}
