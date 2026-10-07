<?php

declare(strict_types=1);

/*
 * Regenerates the endpoint agent test vectors shared with the Go agent, from the fixed TEST key (no database needed):
 *
 *   php scripts/endpoint-vectors.php --check   regenerate in memory and compare byte for byte with endpoint-agent/testdata/vectors/
 *                                              (both JSON files and SHA256SUMS); exit 1 on any difference
 *   php scripts/endpoint-vectors.php --write   rewrite the files
 *
 * Files: agent_job_signing_vectors.json (canonical JSON rule, job/check/manifest Ed25519 signatures) and
 * agent_installer_trailer_vectors.json (the stamped-installer footer format). Ed25519 signatures are deterministic, so
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

$files = [
    'agent_installer_trailer_vectors.json' => installerTrailerVectors(),
    'agent_job_signing_vectors.json' => jobSigningVectors(),
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
