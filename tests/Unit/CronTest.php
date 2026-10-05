<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Cron\JobCatalog;
use RivetCore\Cron\JobRunner;

final class CronTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rc-cron-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/cron', 0777, true);
        mkdir($this->root . '/scripts', 0777, true);
        mkdir($this->root . '/other', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/{cron,scripts,other,state}/*', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        foreach (['cron', 'scripts', 'other', 'state'] as $d) {
            @rmdir($this->root . '/' . $d);
        }
        @rmdir($this->root);
    }

    public function testLogPathOnlyUnderVarLog(): void
    {
        $this->assertSame('/var/log/x.log', JobRunner::logPathFromCommand('/usr/bin/php /v/cron/x.php >> /var/log/x.log 2>&1'));
        $this->assertNull(JobRunner::logPathFromCommand('/usr/bin/php /v/cron/x.php >> /etc/passwd 2>&1'));
        $this->assertNull(JobRunner::logPathFromCommand('/usr/bin/php /v/cron/x.php'));
    }

    public function testArgumentsAreStrictlyParsed(): void
    {
        $s = '/v/cron/w.php';
        $this->assertSame(['--task=odoo'], JobRunner::argumentsFromCommand("/usr/bin/php $s --task=odoo >> /var/log/w.log 2>&1", $s));
        $this->assertSame([], JobRunner::argumentsFromCommand("/usr/bin/php $s >> /var/log/w.log 2>&1", $s));
        $this->assertNull(JobRunner::argumentsFromCommand("/usr/bin/php $s --task=odoo; rm -rf / >> /var/log/w.log", $s));
        $this->assertNull(JobRunner::argumentsFromCommand("/usr/bin/php $s \$(id) >> /var/log/w.log", $s));
    }

    public function testPhpBinaryFromCommand(): void
    {
        $this->assertSame('/usr/bin/php8.4', JobRunner::phpBinaryFromCommand('/usr/bin/php8.4 /v/a.php'));
        $this->assertSame('/usr/bin/php', JobRunner::phpBinaryFromCommand('php /v/a.php'));
    }

    public function testTailStripsControlCharsAndHandlesMissing(): void
    {
        file_put_contents($this->root . '/t.log', "one\n\x1b[31mred\x1b[0m\n\nthree\nfour\n");
        $t = JobRunner::tail($this->root . '/t.log', 3);
        $this->assertCount(3, $t['lines']);
        $this->assertStringNotContainsString("\x1b", implode('', $t['lines']));
        $this->assertSame(['lines' => [], 'mtime' => null], JobRunner::tail($this->root . '/missing.log'));
        $this->assertSame(['lines' => [], 'mtime' => null], JobRunner::tail(null));
        @unlink($this->root . '/t.log');
    }

    public function testStartRunsAllowedScriptAndReportsExit(): void
    {
        $script = $this->root . '/cron/ok.php';
        file_put_contents($script, "<?php echo 'hello from job';");
        $r = new JobRunner($this->root, $this->root . '/state');
        $res = $r->start('ok', $script, [], PHP_BINARY);
        $this->assertTrue($res['ok'], $res['message']);
        for ($i = 0; $i < 60 && $r->state('ok', $script)['running']; $i++) {
            usleep(100000);
        }
        $st = $r->state('ok', $script);
        $this->assertFalse($st['running']);
        $this->assertSame(0, $st['exit']);
        $this->assertContains('hello from job', $st['lines']);
    }

    public function testStartRefusesScriptsOutsideCronAndScripts(): void
    {
        file_put_contents($this->root . '/other/evil.php', '<?php echo 1;');
        $r = new JobRunner($this->root, $this->root . '/state');
        $this->assertFalse($r->start('evil', $this->root . '/other/evil.php', [], PHP_BINARY)['ok']);
        $this->assertFalse($r->start('missing', $this->root . '/cron/nope.php', [], PHP_BINARY)['ok']);
    }

    public function testStartRefusesBadArgsAndBinary(): void
    {
        file_put_contents($this->root . '/cron/a.php', '<?php echo 1;');
        $r = new JobRunner($this->root, $this->root . '/state');
        $this->assertSame('Unexpected argument.', $r->start('a', $this->root . '/cron/a.php', ['--ok', 'bad;arg'], PHP_BINARY)['message']);
        $this->assertSame('Unexpected PHP binary.', $r->start('a', $this->root . '/cron/a.php', [], '/bin/sh')['message']);
    }

    public function testCatalog(): void
    {
        $c = new JobCatalog(
            ['a.php' => ['label' => 'A', 'description' => 'd', 'run_now' => true, 'note' => ''], 's.php' => ['label' => 'S', 'description' => 'd', 'run_now' => true, 'note' => '', 'dir' => 'scripts']],
            ['a.php']
        );
        $this->assertSame('cron', $c->dir('a.php'));
        $this->assertSame('scripts', $c->dir('s.php'));
        $this->assertTrue($c->needsArguments('a.php'));
        $this->assertFalse($c->needsArguments('s.php'));
        $this->assertFalse($c->describe('mystery.php')['run_now']);
        $this->assertCount(2, $c->all());
    }
}
