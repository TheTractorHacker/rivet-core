<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;

/** The committed vector files are the bytes RivetIT origin/beta ships; the Go agent reads the same files. */
final class EndpointVectorFilesTest extends TestCase
{
    /** sha256 of the RivetIT beta files (the tests/fixtures copies and the Go-side testdata copies are identical). */
    private const RIVETIT_BASELINE = [
        'agent_installer_trailer_vectors.json' => '8a89e10349425d6370fcb9b56dbd25426b7116f24de5bb34f8b8d25055059304',
        'agent_job_signing_vectors.json' => '9850daf57252afbfc7eee7f9531239b9f8e0e68fb491b56bff5682e94f85deb6',
    ];

    /** Files ADDED after the baseline (never edit the two above; add a file instead). */
    private const ADDED = [
        'agent_installer_mesh_vectors.json' => '39fb641e12abb3d10eac10132fc640991014c5dc994d8e8ca5f6e31ba2af61ea',   // 1.0.0-rc.12: the optional MeshCentral agent members
        'agent_software_deploy_vectors.json' => 'f52af62a9163a63b869d378e513bde81490c598aec7caae469ed58e2a3554ef2',   // 1.0.0-rc.21: accept/reject vectors of the software job parameters
        'agent_change_monitor_vectors.json' => 'aa20e416e88f667681f169d26b38ea6dd53a94397a4d1d6012d6bc173df6a50e',   // 1.0.0-rc.22: the change-monitoring watch policy, secret names and a signed configuration
        'agent_check_os_vectors.json' => 'dfbf0dceb2cc9dbd06d392787d30a2399b26c12299e2f909e7b95b1d533dbaab',   // 1.0.0-rc.24: signed check definitions with the optional os member, delivered keys per OS
        'agent_hypervisor_vectors.json' => '6830b9fec805d857994d518889c0f7bc84f265ab58cdba58b442a69ed6b8148b',   // 1.0.0-rc.29: a signed hypervisor configuration and check-in blocks with the server verdict
        'agent_change_monitor_rc31_vectors.json' => 'aadc386bdb3183c1bf69df7f3deec0cb5efba9e7c5636d805abd5fa538078988',   // 1.0.0-rc.31: change-monitor redaction rows and the extra deny-list rows (the rc.22 file is untouched)
        'agent_change_monitor_rc38_vectors.json' => 'b1c7b28f5d46e5d12824b5956531a98cfe92df1a57b25512b8a43c3c8e17b4e2',   // 1.0.0-rc.38: quoted values, space-separated directives and the panel credentials (the rc.22 and rc.31 files are untouched)
    ];

    public function testFilesMatchTheRivetItBaseline(): void
    {
        foreach (self::RIVETIT_BASELINE as $file => $sha) {
            $this->assertSame($sha, hash_file('sha256', RmmVectors::dir() . '/' . $file), $file);
        }
    }

    public function testSha256SumsFileAgreesWithTheBytes(): void
    {
        $lines = file(RmmVectors::dir() . '/SHA256SUMS', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $listed = [];
        foreach ($lines as $line) {
            $this->assertSame(1, preg_match('/^([0-9a-f]{64})  (\S+)$/', $line, $m), $line);
            $listed[$m[2]] = $m[1];
            $this->assertSame($m[1], hash_file('sha256', RmmVectors::dir() . '/' . $m[2]), $m[2]);
        }
        $this->assertSame(self::RIVETIT_BASELINE + self::ADDED, $listed, 'the two baseline lines keep their place and bytes; additions only come after them');
    }

    public function testAddedFilesMatchTheirPinnedHash(): void
    {
        foreach (self::ADDED as $file => $sha) {
            $this->assertSame($sha, hash_file('sha256', RmmVectors::dir() . '/' . $file), $file);
        }
    }

    public function testGeneratorReproducesTheFilesByteForByte(): void
    {
        $script = dirname(__DIR__, 3) . '/scripts/endpoint-vectors.php';
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --check 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
