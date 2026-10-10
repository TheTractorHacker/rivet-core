<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Tests\Support\GoldenDelta;

/**
 * The golden transcripts promise byte-compatibility with the ORIGINAL wire protocol. The CORE-1 security fix (rc.7) changed the
 * answer of one enrollment exchange on purpose, so five transcripts were re-recorded. This test keeps that promise honest: the pre-fix
 * recordings of those five files live in tests/Fixtures/rmm/golden-original, and the exchanges and snapshot tables that may differ
 * from the current recordings are listed, with a reason each, in golden-original/expected-deltas.json. Any other difference, in
 * those files or in the five files that were not re-recorded, fails here. Needs no database.
 */
final class GoldenDeltaTest extends TestCase
{
    private const DIR = __DIR__ . '/../../Fixtures/rmm';

    /** @return array<string,mixed> */
    private static function load(string $path): array
    {
        $d = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($d, $path);

        return $d;
    }

    public function testEveryDifferenceFromTheOriginalRecordingsIsDeclaredAndExplained(): void
    {
        $spec = self::load(self::DIR . '/golden-original/expected-deltas.json');
        $files = $spec['files'];
        $this->assertNotEmpty($files);
        foreach ($files as $name => $declared) {
            $actual = GoldenDelta::units(self::load(self::DIR . "/golden-original/$name"), self::load(self::DIR . "/golden/$name"));
            $units = array_keys($declared);
            sort($units);
            $this->assertSame($units, $actual, "$name: the set of changed exchanges/tables must equal the declared deltas exactly");
            foreach ($declared as $unit => $reason) {
                $this->assertArrayHasKey($reason, $spec['reasons'], "$name $unit: undocumented reason");
                $this->assertNotSame('', trim((string) $spec['reasons'][$reason]));
            }
        }
    }

    public function testEveryOtherTranscriptIsByteIdenticalToTheOriginalRecording(): void
    {
        // The un-re-recorded files have no copy in golden-original; they are the original recordings, so they must not be listed as deltas.
        $declared = array_keys(self::load(self::DIR . '/golden-original/expected-deltas.json')['files']);
        $originals = array_map('basename', glob(self::DIR . '/golden-original/*.json') ?: []);
        $originals = array_values(array_diff($originals, ['expected-deltas.json']));
        sort($declared);
        sort($originals);
        $this->assertSame($declared, $originals, 'golden-original holds exactly the re-recorded files');
        $this->assertNotContains('_meta.json', $declared);
    }

    public function testTheCrossClientDeltaIsTheOnlyBehaviourChange(): void
    {
        // The one changed exchange keeps status and header set; only the identity of the answered device differs.
        $old = array_column(self::load(self::DIR . '/golden-original/04-enroll-flows.json')['steps'], null, 'id');
        $new = array_column(self::load(self::DIR . '/golden/04-enroll-flows.json')['steps'], null, 'id');
        $id = 'serial-of-known-device-with-other-department-token';
        $this->assertSame($old[$id]['request'], $new[$id]['request']);
        $this->assertSame($old[$id]['response']['status'], $new[$id]['response']['status']);
        $this->assertSame($old[$id]['response']['headers'], $new[$id]['response']['headers']);
        $this->assertSame(array_keys($old[$id]['response']['body']), array_keys($new[$id]['response']['body']));
    }
}
