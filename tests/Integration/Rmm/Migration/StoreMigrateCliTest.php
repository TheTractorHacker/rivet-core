<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Migration;

use RivetCore\Rmm\Store\Migration\HotTables;
use RivetCore\Tests\Support\StoreMigrationFixture as F;
use RivetCore\Tests\Support\StoreMigrationTestCase;

/** scripts/rmm-store-migrate.php end to end: JSON lines, a summary line, exit codes, the refusals. */
final class StoreMigrateCliTest extends StoreMigrationTestCase
{
    /** @return array{0:int,1:list<array<string,mixed>>,2:string} */
    private function cli(string ...$args): array
    {
        $cmd = [PHP_BINARY, __DIR__ . '/../../../../scripts/rmm-store-migrate.php', '--bootstrap=' . __DIR__ . '/../../../Fixtures/rmm/cli-bootstrap.php', ...$args];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['RIVETCORE_TEST_CLI_PG' => '1']));
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($p);
        $lines = array_values(array_filter(array_map(static fn (string $l): mixed => json_decode($l, true), explode("\n", trim((string) $out)))));

        return [$code, $lines, (string) $err];
    }

    public function testMigrateStatusRollbackAndPurgeFromTheCommandLine(): void
    {
        F::fill($this->maria, time());
        [$code, $lines] = $this->cli('--from=mariadb', '--to=postgres', '--dry-run');
        $this->assertSame(0, $code);
        $this->assertSame('dry_run', $lines[0]['event']);
        $this->assertSame(0, $this->pg->count(HotTables::get('endpoint_agent_check_history')));

        [$code, $lines, $err] = $this->cli('--from=mariadb', '--to=postgres', '--settle=0', '--verify-lag=0', '--sample=all', '--pause-ms=0', '--batch=500');
        $this->assertSame(0, $code, $err . json_encode(end($lines)));
        $summary = end($lines)['summary'];
        $this->assertTrue($summary['ok']);
        $events = array_column($lines, 'event');
        $this->assertContains('batch', $events);
        $this->assertContains('cutover', $events);
        $this->assertSame(F::counts($this->maria), F::counts($this->pg));
        $this->assertNoSecret(json_encode($lines));

        [$code, $lines] = $this->cli('--status');
        $this->assertSame(0, $code);
        $this->assertSame('cutover', end($lines)['summary']['status']['state']);

        [$code, $lines] = $this->cli('--purge-old');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Pass yes', end($lines)['error']);
        [$code] = $this->cli('--bogus');
        $this->assertSame(2, $code);

        [$code, $lines, $err] = $this->cli('--rollback', '--settle=0', '--verify-lag=0', '--sample=all', '--pause-ms=0');
        $this->assertSame(0, $code, $err . json_encode(end($lines)));
        $this->assertSame('mariadb', $this->h->one("SELECT data_store FROM endpoint_agent_settings WHERE id = 1"));
    }
}
