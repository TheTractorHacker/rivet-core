<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The committed snapshot matches the code, and the guard really fails on a breaking or an additive surface change. */
final class ApiSurfaceGuardTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../scripts/api-surface-check.php';
    private const SNAPSHOT = __DIR__ . '/../api-surface.json';

    /** @return array{0:int, 1:string} */
    private function guard(?string $snapshot = null): array
    {
        $env = ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')];   // no RIVETCORE_TEST_DB_*: tables are skipped here
        if ($snapshot !== null) {
            $env['RIVETCORE_API_SNAPSHOT'] = $snapshot;
        }
        $p = proc_open([PHP_BINARY, self::SCRIPT], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($p), $out];
    }

    public function testTheCommittedSnapshotMatchesTheCode(): void
    {
        [$code, $out] = $this->guard();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('unchanged', $out);
    }

    public function testARemovedMethodAndAChangedSignatureAreReportedAsBreaking(): void
    {
        $snap = json_decode((string) file_get_contents(self::SNAPSHOT), true);
        $snap['types']['RivetCore\\Audit\\AuditService']['methods']['vanished'] = 'vanished(): void';
        $snap['types']['RivetCore\\Audit\\AuditReader']['methods']['page'] = 'page(): int';
        $file = tempnam(sys_get_temp_dir(), 'rcapi');
        file_put_contents($file, json_encode($snap));
        [$code, $out] = $this->guard($file);
        unlink($file);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('BREAKING', $out);
        $this->assertStringContainsString('AuditService.methods.vanished', $out);
        $this->assertStringContainsString('AuditReader.methods.page', $out);
    }

    public function testANewTypeAndANewEventAreReportedAsAdditiveAndStillFail(): void
    {
        $snap = json_decode((string) file_get_contents(self::SNAPSHOT), true);
        unset($snap['types']['RivetCore\\Support\\AllowAllPolicy']);
        array_pop($snap['vocabularies']['webhook_events']);
        $file = tempnam(sys_get_temp_dir(), 'rcapi');
        file_put_contents($file, json_encode($snap));
        [$code, $out] = $this->guard($file);
        unlink($file);
        $this->assertSame(1, $code, 'an unreviewed additive change must fail too');
        $this->assertStringContainsString('ADDITIVE  added     types.RivetCore\\Support\\AllowAllPolicy', $out);
        $this->assertStringContainsString('webhook_events entry', $out);
        $this->assertStringNotContainsString('BREAKING  ', $out);
    }

    public function testAMigrationThatDisappearedIsBreaking(): void
    {
        $snap = json_decode((string) file_get_contents(self::SNAPSHOT), true);
        $snap['migrations'][] = '0099_phantom';
        $file = tempnam(sys_get_temp_dir(), 'rcapi');
        file_put_contents($file, json_encode($snap));
        [$code, $out] = $this->guard($file);
        unlink($file);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('BREAKING  removed   migrations entry "0099_phantom"', $out);
    }
}
