<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\Framework;
use RivetCore\Compliance\Profile\BuiltInProfiles;
use RivetCore\Compliance\Profile\DataClass;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Compliance\RetentionPolicy;

/**
 * "Built-ins stay identical": the existing public constants and the behaviour of RetentionPolicy are pinned to a fixture captured BEFORE the profile
 * model existed (tests/Fixtures/compliance/presets-golden.json), and the built-in profiles must be derived from those constants, nothing else.
 */
final class BuiltInProfilesGoldenTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function golden(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/compliance/presets-golden.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    public function testTheExistingConstantsAreByteIdenticalToTheFixture(): void
    {
        $g = self::golden();
        self::assertSame(json_encode($g['labels']), json_encode(Framework::LABELS));
        self::assertSame(json_encode($g['all']), json_encode(Framework::all()));
        self::assertSame(json_encode($g['profiles']), json_encode(RetentionPolicy::PROFILES));
    }

    public function testRetentionPolicyBehaviourIsUnchanged(): void
    {
        $samples = [-5, 0, 1, 7, 29, 30, 31, 364, 365, 366, 2189, 2190, 2191, 99999];
        foreach (self::golden()['behaviour'] as $p => $want) {
            $got = [
                'valid' => RetentionPolicy::isValidProfile($p), 'floor' => RetentionPolicy::floorDays($p),
                'floor_audit' => RetentionPolicy::floorDaysFor($p, 'audit'), 'floor_deliveries' => RetentionPolicy::floorDaysFor($p, 'deliveries'), 'floor_jobs' => RetentionPolicy::floorDaysFor($p, 'jobs'),
                'effective' => array_map(fn ($d) => RetentionPolicy::effectiveDays($p, $d), $samples),
                'effective_audit' => array_map(fn ($d) => RetentionPolicy::effectiveDaysFor($p, 'audit', $d), $samples),
                'effective_jobs' => array_map(fn ($d) => RetentionPolicy::effectiveDaysFor($p, 'jobs', $d), $samples),
                'below' => array_map(fn ($d) => RetentionPolicy::isBelowFloor($p, $d), $samples),
            ];
            self::assertSame($want, $got, "preset $p");
        }
    }

    public function testBuiltInProfilesAreExactlyTheFiveFrameworksWithTheirPresetFloor(): void
    {
        $all = BuiltInProfiles::all();
        self::assertSame(Framework::all(), array_keys($all));
        foreach ($all as $key => $p) {
            self::assertTrue($p->builtin);
            self::assertSame(Framework::LABELS[$key], $p->label);
            self::assertSame(RetentionPolicy::PROFILES[$key]['label'], $p->shortLabel);
            self::assertSame(RetentionPolicy::PROFILES[$key]['note'], $p->description);
            self::assertCount(1, $p->requirements, 'a built-in asks for nothing the preset did not ask for');
            self::assertSame(RequirementType::RETENTION_MIN, $p->requirements[0]->type);
            self::assertSame(DataClass::AUDIT_EVENTS, $p->requirements[0]->dataClass);
            self::assertSame(RetentionPolicy::floorDays($key), $p->retentionMin(DataClass::AUDIT_EVENTS));
            self::assertSame(0, $p->retentionMin(DataClass::RMM_LOGS), 'no new RMM requirement for a built-in');
        }
    }

    public function testBuiltInProfilesFollowTheConstantsAndNothingElse(): void
    {
        // Same call twice gives equal objects (no hidden state) and the hash depends only on content.
        self::assertEquals(BuiltInProfiles::all(), BuiltInProfiles::all());
        self::assertSame(BuiltInProfiles::get('hipaa')?->contentHash(), BuiltInProfiles::get('hipaa')?->contentHash());
        self::assertNull(BuiltInProfiles::get('none'));
        self::assertNull(BuiltInProfiles::get('nope'));
    }
}
