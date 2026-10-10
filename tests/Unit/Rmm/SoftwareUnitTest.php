<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Device\DeviceState;
use RivetCore\Rmm\Software\SoftwareHash;
use RivetCore\Rmm\Software\SoftwareService;
use RivetCore\Rmm\Software\SoftwareVersion;
use RivetCore\Rmm\Tags\TagService;

/** The pure parts of the software inventory: the shared hash vectors, version ordering, report validation, capability and tag-name cleaning. */
final class SoftwareUnitTest extends TestCase
{
    private const VECTORS = __DIR__ . '/../../../endpoint-agent/testdata/software/hash_vectors.json';

    public function testTheHashMatchesTheVectorsTheAgentAssertsToo(): void
    {
        $v = json_decode((string) file_get_contents(self::VECTORS), true);
        $this->assertGreaterThanOrEqual(4, count($v['cases']));
        foreach ($v['cases'] as $case) {
            $this->assertSame($case['hash'], SoftwareHash::of($case['items']), $case['name']);
            $shuffled = array_reverse($case['items']);
            $this->assertSame($case['hash'], SoftwareHash::of($shuffled), $case['name'] . ' is order independent');
        }
        $this->assertSame(SoftwareHash::EMPTY, SoftwareHash::of([]));
        $this->assertNotSame(SoftwareHash::of([['source' => 'dpkg', 'name' => 'a', 'version' => '1', 'publisher' => null]]), SoftwareHash::of([['source' => 'dpkg', 'name' => 'a', 'version' => '2', 'publisher' => null]]));
        $this->assertSame(SoftwareHash::of([['source' => 'dpkg', 'name' => 'a', 'version' => '1', 'publisher' => null]]), SoftwareHash::of([['source' => 'dpkg', 'name' => 'a', 'version' => '1', 'publisher' => '']]), 'no publisher is the empty publisher');
    }

    public function testTheItemIdentityIsExactSourceAndName(): void
    {
        $this->assertSame(SoftwareHash::key('dpkg', 'curl'), SoftwareHash::key('dpkg', 'curl'));
        $this->assertNotSame(SoftwareHash::key('dpkg', 'curl'), SoftwareHash::key('rpm', 'curl'));
        $this->assertNotSame(SoftwareHash::key('dpkg', 'curl'), SoftwareHash::key('dpkg', 'Curl'));
        $this->assertNotSame(SoftwareHash::key('dpkg', 'curl'), SoftwareHash::key('dpkg', 'curl '));
        $this->assertSame(40, strlen(SoftwareHash::key('registry', 'x')));
    }

    /** @return array<string,array{string,string,int}> */
    public static function versions(): array
    {
        return [
            'equal' => ['1.0', '1.0', 0], 'trailing zeros' => ['1.0', '1.0.0', 0], 'numeric not text' => ['23.01', '9.20', 1], 'minor' => ['8.5.0', '8.10.0', -1],
            'longer is newer' => ['1.0.1', '1.0', 1], 'pre-release older' => ['1.0b1', '1.0', -1], 'pre-release order' => ['131.0b9', '131.0b10', -1], 'epoch ignored' => ['1:2.39-22', '2.40', -1],
            'debian revision' => ['8.5.0-2ubuntu10.6', '8.5.0-2ubuntu10.10', -1], 'letters compare' => ['abc', 'abd', -1], 'number beats letters' => ['1.0.1', '1.0a', 1], 'leading zeros' => ['1.02', '1.2', 0],
            'case' => ['1.0RC1', '1.0rc1', 0], 'empty is oldest' => ['', '1', -1], 'both empty' => ['', '', 0], 'huge numbers' => ['99999999999999999999.1', '99999999999999999998.9', 1],
        ];
    }

    #[DataProvider('versions')]
    public function testVersionOrdering(string $a, string $b, int $want): void
    {
        $this->assertSame($want, SoftwareVersion::compare($a, $b));
        $this->assertSame(-$want, SoftwareVersion::compare($b, $a), 'antisymmetric');
    }

    public function testReportValidation(): void
    {
        $h = str_repeat('a', 64);
        $ok = SoftwareService::cleanReport(['mode' => 'full', 'hash' => $h, 'count' => 1, 'items' => [['name' => ' curl ', 'version' => '8', 'publisher' => 'P', 'source' => 'dpkg', 'installed' => '2026-09-02']]]);
        $this->assertSame(['full', $h, null, 1, false], [$ok['mode'], $ok['hash'], $ok['base_hash'], $ok['count'], $ok['truncated']]);
        $this->assertSame(['curl', '8', 'P', 'dpkg', '2026-09-02'], [$ok['items'][0]['name'], $ok['items'][0]['version'], $ok['items'][0]['publisher'], $ok['items'][0]['source'], $ok['items'][0]['installed']]);
        $this->assertSame(SoftwareHash::key('dpkg', 'curl'), $ok['items'][0]['key']);
        $d = SoftwareService::cleanReport(['mode' => 'delta', 'hash' => $h, 'base_hash' => str_repeat('b', 64), 'items' => [], 'removed' => [['source' => 'rpm', 'name' => 'x'], ['source' => 'nope', 'name' => 'y'], 'junk']]);
        $this->assertSame([SoftwareHash::key('rpm', 'x')], $d['removed']);
        $this->assertSame(0, $d['count'], 'count falls back to the items');
        foreach ([null, 'x', [], [1, 2], ['mode' => 'full'], ['mode' => 'full', 'hash' => 'ABC'], ['mode' => 'full', 'hash' => strtoupper($h)], ['mode' => 'delta', 'hash' => $h],
            ['mode' => 'delta', 'hash' => $h, 'base_hash' => 'short'], ['mode' => 'full', 'hash' => $h, 'items' => 'x'], ['mode' => 'full', 'hash' => $h, 'items' => ['a' => 1]],
            ['mode' => 'full', 'hash' => $h, 'items' => [], 'removed' => 5]] as $bad) {
            $this->assertNull(SoftwareService::cleanReport($bad), json_encode($bad));
        }
        $dup = SoftwareService::cleanReport(['mode' => 'full', 'hash' => $h, 'items' => [['name' => 'a', 'version' => '1', 'source' => 'dpkg'], ['name' => 'a', 'version' => '2', 'source' => 'dpkg']]]);
        $this->assertCount(1, $dup['items']);
        $this->assertSame('2', $dup['items'][0]['version'], 'a duplicate identity keeps the last');
        $dates = SoftwareService::cleanReport(['mode' => 'full', 'hash' => $h, 'items' => [
            ['name' => 'a', 'source' => 'dpkg', 'installed' => '1985-01-01'], ['name' => 'b', 'source' => 'dpkg', 'installed' => '2026-13-01'], ['name' => 'c', 'source' => 'dpkg', 'installed' => '20260901'], ['name' => 'd', 'source' => 'dpkg', 'installed' => 5]]]);
        $this->assertSame([null, null, null, null], array_column($dates['items'], 'installed'));
    }

    public function testCapabilityCleaning(): void
    {
        $this->assertSame(['check:disk', 'job:shell', 'software_inventory'], DeviceState::cleanCapabilities(['software_inventory', 'job:shell', 'check:disk', 'job:shell']));
        $this->assertNull(DeviceState::cleanCapabilities(null));
        $this->assertNull(DeviceState::cleanCapabilities('software_inventory'));
        $this->assertNull(DeviceState::cleanCapabilities(['a' => 'b']));
        $this->assertSame(['ok'], DeviceState::cleanCapabilities(['ok', 5, '', 'bad cap', str_repeat('x', 65), ['x'], null]));
        $many = array_map(static fn (int $i): string => 'cap' . $i, range(1, 100));
        $this->assertCount(64, DeviceState::cleanCapabilities($many));
        $this->assertTrue(DeviceState::announces(['capabilities_json' => '["software_inventory"]'], 'software_inventory'));
        $this->assertFalse(DeviceState::announces(['capabilities_json' => null], 'software_inventory'));
        $this->assertFalse(DeviceState::announces(null, 'software_inventory'));
        $this->assertSame([], DeviceState::capabilitiesOf(['capabilities_json' => '{broken']));
    }

    public function testTagNames(): void
    {
        $this->assertSame('Patch Ring 1', TagService::cleanName("  Patch \t Ring   1 "));
        $this->assertSame('a/b:c-d_e.f+g', TagService::cleanName('a/b:c-d_e.f+g'));
        $this->assertSame('Büro Süd', TagService::cleanName('Büro Süd'));
        foreach ([null, 5, '', '  ', '-lead', '_lead', str_repeat('x', 61), 'semi;colon', '<b>', "nul\x00l", 'quote"', "emoji😀"] as $bad) {
            $this->assertNull(TagService::cleanName($bad), var_export($bad, true));
        }
        $this->assertSame(60, strlen((string) TagService::cleanName(str_repeat('x', 60))));
    }
}
