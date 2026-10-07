<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * The examples of docs/modules/rmm.md run: every php block that starts with `// docs-test: <name>` is executed in order against a
 * scratch database with the reference adapters standing in for the edition's (the variables the page says "are your adapters").
 */
final class ModuleDocExamplesTest extends RmmTestCase
{
    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(policy: new AllowUsersPolicy([1 => true, 7 => [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED]]));
    }

    /** @return array<string,string> */
    private function blocks(): array
    {
        $md = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/modules/rmm.md');
        preg_match_all('/```php\n\/\/ docs-test: (\w+)\n(.*?)```/s', $md, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $name, $code]) {
            $out[$name] = $code;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $vars
     * @return array<string,mixed> the variables after the block ran
     */
    private function runBlock(string $code, array $vars): array
    {
        $run = static function (string $__code, array $__vars): array {
            unset($__vars['__output']);
            extract($__vars);
            ob_start();
            try {
                eval($__code);
            } finally {
                $__out = (string) ob_get_clean();
            }
            $after = get_defined_vars();
            unset($after['__code'], $after['__vars'], $after['__out']);
            $after['__output'] = $__out;

            return $after + $__vars;
        };

        return $run($code, $vars);
    }

    public function testTheExamplesOfTheModulePageRun(): void
    {
        $blocks = $this->blocks();
        $this->assertSame(['compose', 'administer', 'technician', 'actions'], array_keys($blocks));
        $h = $this->h;
        $h->enable();
        $h->asset(['name' => 'DOC-PC', 'serial' => 'SER-DOC']);
        [$c, , $j] = $h->enroll($h->token(), $h::device(['serial' => 'SER-DOC', 'hostname' => 'DOC-PC']));
        $this->assertSame(201, $c);
        $tmp = sys_get_temp_dir() . '/rmm_doc_' . bin2hex(random_bytes(4)) . '.exe';
        file_put_contents($tmp, RmmHarness::fakePe(0x8664, 8192, 'doc'));
        $vars = ['database' => $h->counting, 'clock' => $h->clock, 'tenancy' => $h->tenancy, 'assets' => $h->assets, 'bridge' => $h->bridge, 'box' => $h->box, 'audit' => $h->audit,
            'policy' => $h->policy, 'binaryDir' => $h->binaryDir, 'uploadedTmpFile' => $tmp, 'clientId' => $h->clientA, 'deviceId' => (int) $j['device_id']];
        try {
            $vars = $this->runBlock($blocks['compose'], $vars);
            $this->assertSame(401, $vars['response']->status, 'an unknown credential');
            $this->assertStringContainsString('invalid_token', (string) $vars['response']->body);

            $vars = $this->runBlock($blocks['administer'], $vars);
            $this->assertTrue($vars['r']->ok, $vars['r']->message);
            $this->assertStringContainsString('$Server = \'https://rmm.example.com\'', $vars['__output']);
            $this->assertStringContainsString('install-linux.sh', $vars['__output']);
            $this->assertSame(1, (int) $h->one("SELECT is_current FROM endpoint_agent_binaries WHERE version = '1.2.0'"));
            $this->assertSame('pilot', $h->one("SELECT ring FROM endpoint_agent_releases WHERE version = '1.2.0'"));

            $vars = $this->runBlock($blocks['technician'], $vars);
            $this->assertSame(200, $vars['response']->status);
            $this->assertArrayHasKey('data', json_decode((string) $vars['response']->body, true));

            $vars = $this->runBlock($blocks['actions'], $vars);
            $this->assertTrue($vars['r']->ok === false && $vars['r']->http === 403, 'user 7 may not reboot: the example shows the refusal path');
            $this->assertSame('forbidden', $vars['r']->code);
            $this->assertArrayHasKey('items', $vars['list']);
        } finally {
            @unlink($tmp);
        }
    }
}
