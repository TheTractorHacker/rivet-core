<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Policy;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Policy\StarterPack\PolicyPack;

/** The bundled policy pack: every item validates with the real validators, every setting has a rationale, no item targets the wrong OS. */
final class PolicyPackManifestTest extends TestCase
{
    public function testEveryItemValidatesAndResolves(): void
    {
        $p = PolicyPack::bundled();
        $this->assertSame('1.0.0', $p->version());
        $this->assertGreaterThanOrEqual(20, count($p->items()));
        $kinds = [];
        foreach ($p->items() as $i) {
            $r = PolicyPack::resolve($i);   // throws when invalid
            $this->assertNotSame('', $r['description']);
            $this->assertSame(12, strlen(PolicyPack::hash($r['content'])));
            $kinds[$i['kind']] = true;
        }
        $this->assertSame(PolicyPack::KINDS, array_values(array_intersect(PolicyPack::KINDS, array_keys($kinds))), 'every kind is in the pack');
    }

    public function testWindowsItemsNeverTargetLinuxAndTheOtherWayRound(): void
    {
        foreach (PolicyPack::bundled()->items() as $i) {
            if (!in_array($i['kind'], ['check_policy', 'alert_preset'], true)) {
                continue;
            }
            foreach ($i['body']['settings'] as $key => $v) {
                if (!str_starts_with($key, 'check.')) {
                    continue;
                }
                if ($i['platform'] === 'any') {
                    $this->assertContains($v['os'] ?? 'any', ['windows', 'linux', 'any']);
                } else {
                    $this->assertSame($i['platform'], $v['os'] ?? null, "{$i['id']} $key");
                }
            }
        }
    }

    public function testItemsAreUniqueAndMarkersRoundTrip(): void
    {
        $ids = array_column(PolicyPack::bundled()->items(), 'id');
        $this->assertSame($ids, array_values(array_unique($ids)));
        $m = PolicyPack::marker('windows-server', 3, 'abcdef012345');
        $this->assertSame(['windows-server', 3, 'abcdef012345'], PolicyPack::parseMarker('Some text.' . $m));
        $this->assertSame('Some text.', PolicyPack::stripMarker('Some text.' . $m));
        $this->assertNull(PolicyPack::parseMarker('Some text [pp|x|1|abc] more'));
    }

    public function testAnInvalidItemCanNeverBePacked(): void
    {
        $base = (require __DIR__ . '/../../../../src/Rmm/Policy/StarterPack/data/pack.php');
        $try = function (callable $mutate) use ($base): string {
            $m = $base;
            $m['items'] = [$mutate($base['items'][0])];
            try {
                new PolicyPack($m);
            } catch (\InvalidArgumentException $e) {
                return $e->getMessage();
            }

            return '';
        };
        $this->assertStringContainsString('Unknown setting', $try(static function (array $i): array { $i['body']['settings']['bogus.key'] = true; $i['why_settings']['bogus.key'] = 'x'; return $i; }));
        $this->assertStringContainsString('no rationale', $try(static function (array $i): array { unset($i['why_settings']['agent.ring']); return $i; }));
        $this->assertStringContainsString('must say os=windows', $try(static function (array $i): array { unset($i['body']['settings']['check.starter.av']['os']); return $i; }));
        $this->assertStringContainsString('must say os=windows', $try(static function (array $i): array { $i['body']['settings']['check.starter.av']['os'] = 'linux'; return $i; }));
        $this->assertNotSame('', $try(static function (array $i): array { $i['kind'] = 'patch_policy'; $i['body'] = ['settings' => ['ring' => 'nope']]; return $i; }));
        $this->assertNotSame('', $try(static function (array $i): array { $i['body']['settings']['check.starter.disk_free']['params']['warn_free_pct'] = 'x'; return $i; }));
    }
}
