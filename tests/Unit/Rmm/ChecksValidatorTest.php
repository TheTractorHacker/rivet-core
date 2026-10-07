<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Settings\ChecksValidator as C;

final class ChecksValidatorTest extends TestCase
{
    public function testValidListIsNormalised(): void
    {
        [$out, $err] = C::validate('[{"key":"svc_spooler","type":"service","params":{"name":"Spooler"},"interval_s":120},{"key":"scr","type":"script","params":{"script":"(Get-Date).Year","timeout_s":10},"interval_s":600},{"key":"pr","type":"pending_reboot","interval_s":3600},{"key":"d","type":"disk","params":{"mount":"C:","warn_pct":85},"interval_s":300,"extra":"dropped"}]');
        $this->assertNull($err);
        $this->assertNotNull($out);
        $this->assertCount(4, $out);
        $this->assertSame(['key', 'type', 'params', 'interval_s'], array_keys($out[3]));
        $this->assertEquals(new \stdClass(), $out[2]['params'], 'absent params become {}');
        $this->assertSame(['script' => '(Get-Date).Year', 'timeout_s' => 10], $out[1]['params']);
        $this->assertSame('{"interval_s":3600,"key":"pr","params":{},"type":"pending_reboot"}', Signer::checkMessage($out[2]));
    }

    public function testScriptTimeoutClamp(): void
    {
        foreach ([[null, 30], [0, 1], [-5, 1], [999, 60], [60, 60], [1, 1], ['12', 12]] as [$in, $want]) {
            $p = ['script' => 'x'] + ($in === null ? [] : ['timeout_s' => $in]);
            [$out] = C::validate((string) json_encode([['key' => 's', 'type' => 'script', 'params' => $p, 'interval_s' => 60]]));
            $this->assertNotNull($out);
            $this->assertSame($want, $out[0]['params']['timeout_s'] ?? null, json_encode($in));
        }
    }

    /** @return list<array{string,string}> */
    private function badCases(): array
    {
        $ok = '{"key":"k","type":"disk","params":{},"interval_s":60}';
        return [
            ['not json', 'Checks must be a JSON list of at most 50 items.'],
            ['{"a":1}', 'Checks must be a JSON list of at most 50 items.'],
            ['"x"', 'Checks must be a JSON list of at most 50 items.'],
            ['[' . implode(',', array_fill(0, 51, str_replace('"k"', '"k"', $ok))) . ']', 'Checks must be a JSON list of at most 50 items.'],
            ['[5]', 'Each check needs a key of letters, digits and _ . : -'],
            ['[{"type":"disk","interval_s":60}]', 'Each check needs a key of letters, digits and _ . : -'],
            ['[{"key":"bad key","type":"disk","interval_s":60}]', 'Each check needs a key of letters, digits and _ . : -'],
            ['[{"key":"' . str_repeat('a', 101) . '","type":"disk","interval_s":60}]', 'Each check needs a key of letters, digits and _ . : -'],
            ['[' . $ok . ',' . $ok . ']', 'Duplicate check key k'],
            ['[{"key":"k","type":"nope","interval_s":60}]', 'Check type must be service, disk, pending_reboot or script.'],
            ['[{"key":"k","interval_s":60}]', 'Check type must be service, disk, pending_reboot or script.'],
            ['[{"key":"k","type":"disk","interval_s":29}]', 'Check interval_s must be 30 to 86400.'],
            ['[{"key":"k","type":"disk","interval_s":86401}]', 'Check interval_s must be 30 to 86400.'],
            ['[{"key":"k","type":"disk"}]', 'Check interval_s must be 30 to 86400.'],
            ['[{"key":"k","type":"disk","params":{"warn_pct":85.5},"interval_s":60}]', 'Check values must be whole numbers (no decimals).'],
            ['[{"key":"k","type":"disk","params":{"n":{"deep":[1,2.5]}},"interval_s":60}]', 'Check values must be whole numbers (no decimals).'],
            ['[{"key":"k","type":"disk","params":"x","interval_s":60}]', 'Check params must be an object.'],
            ['[{"key":"k","type":"script","params":{},"interval_s":60}]', 'Script checks need params.script (at most 8 KiB).'],
            ['[{"key":"k","type":"script","params":{"script":""},"interval_s":60}]', 'Script checks need params.script (at most 8 KiB).'],
            ['[{"key":"k","type":"script","params":{"script":"' . str_repeat('a', 8193) . '"},"interval_s":60}]', 'Script checks need params.script (at most 8 KiB).'],
        ];
    }

    public function testRefusals(): void
    {
        foreach ($this->badCases() as [$json, $msg]) {
            $this->assertSame([null, $msg], C::validate($json), substr($json, 0, 80));
        }
    }

    public function testBoundariesAccepted(): void
    {
        $fifty = [];
        for ($i = 0; $i < 50; $i++) {
            $fifty[] = ['key' => "k$i", 'type' => 'pending_reboot', 'interval_s' => $i % 2 ? 30 : 86400];
        }
        [$out, $err] = C::validate((string) json_encode($fifty));
        $this->assertNull($err);
        $this->assertCount(50, $out ?? []);
        [$out] = C::validate('[{"key":"' . str_repeat('a', 100) . '","type":"script","params":{"script":"' . str_repeat('a', 8192) . '"},"interval_s":60}]');
        $this->assertNotNull($out);
        [$out] = C::validate('[{"key":"A.b:c_d-1","type":"service","params":{"name":"X"},"interval_s":60}]');
        $this->assertNotNull($out);
        $this->assertSame([[], null], C::validate('[]'));
    }
}
