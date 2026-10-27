<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\Profile\BuiltInProfiles;
use RivetCore\Compliance\Profile\DataClass;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\ProfileCodec;
use RivetCore\Compliance\Profile\ProfileValidationException;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\Profile\RequirementType;

final class ProfileCodecTest extends TestCase
{
    private static function contractX(): FrameworkProfile
    {
        return new FrameworkProfile('contract_x', 'Contract X', '7-year retention, daily encrypted off-site backups, quarterly restore test', 3, false, [
            new Requirement(RequirementType::RESTORE_TEST_MAX_AGE, null, 92, 'Contract X clause 14.2'),
            new Requirement(RequirementType::BACKUP_ENCRYPTED, null, 1),
            new Requirement(RequirementType::RETENTION_MIN, DataClass::AUDIT_EVENTS, 2555),
            new Requirement(RequirementType::BACKUP_RPO_MAX, null, 24),
            new Requirement(RequirementType::BACKUP_OFFSITE, null, 1),
        ], FrameworkProfile::STATUS_ACTIVE, 'hipaa');
    }

    /** @return array<string,mixed> */
    private static function doc(): array
    {
        return json_decode(ProfileCodec::export(self::contractX()), true, 16, JSON_THROW_ON_ERROR);
    }

    public function testExportThenImportIsALosslessRoundTripAndImportStartsAtVersionOne(): void
    {
        $p = self::contractX();
        $back = ProfileCodec::parse(ProfileCodec::export($p));
        self::assertSame(1, $back->version, 'an import never trusts the version in the file');
        self::assertFalse($back->builtin);
        self::assertSame($p->contentHash(), $back->contentHash());
        self::assertSame('hipaa', $back->basedOn);
        self::assertSame(ProfileCodec::export($back), str_replace('"version": 3', '"version": 1', ProfileCodec::export($p)));
    }

    public function testTheLogPresetsRequirementRoundTripsAsAFlagWithoutAValueOrAClass(): void
    {
        $p = new FrameworkProfile('logs_x', 'Logs X', '', 1, false, [new Requirement(RequirementType::LOG_PRESETS_BASELINE, null, 1, 'audit policy too')]);
        $back = ProfileCodec::parse(ProfileCodec::export($p));
        self::assertSame($p->contentHash(), $back->contentHash());
        self::assertSame([['type' => 'log_presets_baseline', 'note' => 'audit policy too']], json_decode(ProfileCodec::export($p), true)['profile']['requirements']);
        self::assertSame('Security event-log presets are collected', $back->requirements[0]->describe());
        $this->expectException(\InvalidArgumentException::class);
        new Requirement(RequirementType::LOG_PRESETS_BASELINE, 'audit_events', 1);
    }

    public function testRequirementsAreInCanonicalOrderWhateverTheInputOrder(): void
    {
        $types = array_map(static fn (Requirement $r) => $r->type, self::contractX()->requirements);
        self::assertSame(['retention_min', 'backup_rpo_max', 'backup_offsite', 'backup_encrypted', 'restore_test_max_age'], $types);
    }

    public function testExportHasOnlyTheDocumentedFieldsAndNoSecretsOrIdentity(): void
    {
        $d = self::doc();
        self::assertSame(['format', 'schema_version', 'profile'], array_keys($d));
        self::assertSame(['key', 'label', 'description', 'based_on', 'version', 'requirements'], array_keys($d['profile']));
        $flat = ProfileCodec::export(self::contractX());
        self::assertDoesNotMatchRegularExpression('/password|secret|token|user|created|updated|@/i', $flat);
        foreach (BuiltInProfiles::all() as $p) {
            self::assertSame(['key', 'label', 'description', 'based_on', 'version', 'requirements'], array_keys(ProfileCodec::toArray($p)['profile']));
        }
    }

    public function testFlagRequirementsHaveNoValueInTheFile(): void
    {
        $d = self::doc();
        $flags = array_values(array_filter($d['profile']['requirements'], static fn ($r) => in_array($r['type'], ['backup_offsite', 'backup_encrypted'], true)));
        self::assertCount(2, $flags);
        foreach ($flags as $f) {
            self::assertArrayNotHasKey('value', $f);
        }
        $d['profile']['requirements'][] = ['type' => 'backup_covers_all_stores', 'value' => true];
        self::assertSame(1, ProfileCodec::fromArray($d)->requirement('backup_covers_all_stores')?->value);
    }

    /** @return array<string, array{callable(array<string,mixed>): array<string,mixed>, string}> */
    public static function invalid(): array
    {
        $req = static fn (array $r) => static function (array $d) use ($r): array {
            $d['profile']['requirements'][] = $r;

            return $d;
        };

        return [
            'unknown top key' => [static fn (array $d) => $d + ['extra' => 1], 'Unknown field in the file'],
            'unknown profile key' => [static function (array $d) { $d['profile']['owner'] = 'x'; return $d; }, 'Unknown field in the profile'],
            'unknown requirement key' => [$req(['type' => 'backup_offsite', 'cmd' => 'rm -rf /']), 'Unknown field in Requirement 6'],
            'proto key' => [static function (array $d) { $d['__proto__'] = []; return $d; }, 'Unknown field'],
            'wrong format' => [static function (array $d) { $d['format'] = 'other'; return $d; }, 'not a RivetCore compliance profile'],
            'newer schema' => [static function (array $d) { $d['schema_version'] = 2; return $d; }, 'newer RivetCore'],
            'schema zero' => [static function (array $d) { $d['schema_version'] = 0; return $d; }, 'must be 1 or more'],
            'schema string' => [static function (array $d) { $d['schema_version'] = '1'; return $d; }, 'whole number'],
            'built-in key' => [static function (array $d) { $d['profile']['key'] = 'hipaa'; return $d; }, 'reserved'],
            'none key' => [static function (array $d) { $d['profile']['key'] = 'none'; return $d; }, 'reserved'],
            'bad key chars' => [static function (array $d) { $d['profile']['key'] = '../etc/passwd'; return $d; }, 'The key must be'],
            'upper key' => [static function (array $d) { $d['profile']['key'] = 'ContractX'; return $d; }, 'The key must be'],
            'empty label' => [static function (array $d) { $d['profile']['label'] = ''; return $d; }, 'The name must have'],
            'long label' => [static function (array $d) { $d['profile']['label'] = str_repeat('a', 121); return $d; }, 'The name must have'],
            'label newline' => [static function (array $d) { $d['profile']['label'] = "a\nb"; return $d; }, 'control characters'],
            'label nul' => [static function (array $d) { $d['profile']['label'] = "a\0b"; return $d; }, 'control characters'],
            'label array' => [static function (array $d) { $d['profile']['label'] = ['x']; return $d; }, 'must be text'],
            'description control' => [static function (array $d) { $d['profile']['description'] = "a\x07b"; return $d; }, 'control characters'],
            'description long' => [static function (array $d) { $d['profile']['description'] = str_repeat('a', 1001); return $d; }, 'at most 1000'],
            'secret in description' => [static function (array $d) { $d['profile']['description'] = 'use password: hunter2 for the portal'; return $d; }, 'secrets'],
            'private key in note' => [$req(['type' => 'backup_offsite', 'note' => '-----BEGIN PRIVATE KEY-----']), 'secrets'],
            'aws key in label' => [static function (array $d) { $d['profile']['label'] = 'AKIAABCDEFGHIJKLMNOP'; return $d; }, 'secrets'],
            'long token in note' => [$req(['type' => 'backup_encrypted', 'note' => str_repeat('A1b2', 12)]), 'secrets'],
            'url credentials' => [static function (array $d) { $d['profile']['description'] = 'see https://admin:pw@example.com/x'; return $d; }, 'secrets'],
            'based_on bad' => [static function (array $d) { $d['profile']['based_on'] = 'a b'; return $d; }, 'based_on'],
            'version string' => [static function (array $d) { $d['profile']['version'] = '3'; return $d; }, '"version"'],
            'version zero' => [static function (array $d) { $d['profile']['version'] = 0; return $d; }, '"version"'],
            'requirements object' => [static function (array $d) { $d['profile']['requirements'] = ['a' => 1]; return $d; }, 'must be a list'],
            'requirement scalar' => [static function (array $d) { $d['profile']['requirements'][] = 'x'; return $d; }, 'must be an object'],
            'unknown type' => [$req(['type' => 'run_script']), 'unknown type'],
            'retention no class' => [$req(['type' => 'retention_min', 'value' => 30]), 'does not apply'],
            'retention bad class' => [$req(['type' => 'retention_min', 'data_class' => 'passwords', 'value' => 30]), 'does not apply'],
            'retention zero' => [$req(['type' => 'retention_min', 'data_class' => 'rmm_logs', 'value' => 0]), 'must be from 1 to 36500'],
            'retention negative' => [$req(['type' => 'retention_min', 'data_class' => 'rmm_logs', 'value' => -1]), 'must be from 1 to 36500'],
            'retention huge' => [$req(['type' => 'retention_min', 'data_class' => 'rmm_logs', 'value' => 36501]), 'must be from 1 to 36500'],
            'retention float' => [$req(['type' => 'retention_min', 'data_class' => 'rmm_logs', 'value' => 30.5]), 'whole number'],
            'retention string' => [$req(['type' => 'retention_min', 'data_class' => 'rmm_logs', 'value' => '30']), 'whole number'],
            'retention bigint' => [$req(['type' => 'retention_min', 'data_class' => 'rmm_logs', 'value' => 99999999999999999999]), 'whole number'],
            'retention null' => [$req(['type' => 'retention_min', 'data_class' => 'rmm_logs', 'value' => null]), 'whole number'],
            'rpo too big' => [$req(['type' => 'backup_rpo_max', 'value' => 8761]), 'must be from 1 to 8760'],
            'rpo with class' => [$req(['type' => 'backup_rpo_max', 'data_class' => 'backups', 'value' => 24]), 'does not take a kind of data'],
            'flag false' => [$req(['type' => 'backup_offsite', 'value' => false]), 'yes/no'],
            'flag number' => [$req(['type' => 'backup_offsite', 'value' => 7]), 'yes/no'],
            'tamper bad class' => [$req(['type' => 'tamper_evidence', 'data_class' => 'metrics_daily']), 'does not apply'],
            'tamper no class' => [$req(['type' => 'tamper_evidence']), 'does not apply'],
            'duplicate' => [static function (array $d) { $d['profile']['requirements'][] = $d['profile']['requirements'][0]; return $d; }, 'listed twice'],
            'too many' => [static function (array $d) { $d['profile']['requirements'] = array_fill(0, 65, ['type' => 'backup_offsite']); return $d; }, 'at most 64'],
        ];
    }

    #[DataProvider('invalid')]
    public function testMaliciousOrInvalidFilesAreRefusedWithAClearMessage(callable $mutate, string $expect): void
    {
        $doc = $mutate(self::doc());
        try {
            ProfileCodec::parse((string) json_encode($doc));
            self::fail('accepted an invalid file');
        } catch (ProfileValidationException $e) {
            self::assertStringContainsString($expect, implode(' | ', $e->errors()));
            self::assertStringNotContainsString('/etc/passwd', implode('', $e->errors()), 'a message never repeats the rejected value');
        }
    }

    public function testMalformedAndOversizedInputIsRefusedBeforeAnyProcessing(): void
    {
        foreach (['', 'not json', '{', '[]', '"x"', '123', 'null', '{"format":"rivetcore-compliance-profile"}', "\xff\xfe"] as $bad) {
            try {
                ProfileCodec::parse($bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (ProfileValidationException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(ProfileValidationException::class);
        $this->expectExceptionMessage('larger than');
        ProfileCodec::parse(str_repeat(' ', ProfileCodec::MAX_BYTES + 1));
    }

    public function testDeepNestingIsRefused(): void
    {
        $deep = '{"format":"rivetcore-compliance-profile","schema_version":1,"profile":' . str_repeat('[', 40) . str_repeat(']', 40) . '}';
        $this->expectException(ProfileValidationException::class);
        ProfileCodec::parse($deep);
    }

    public function testALongSlugOrUrlPathInANoteIsNotMistakenForASecret(): void
    {
        $d = self::doc();
        $d['profile']['requirements'][0]['note'] = 'see /policies/data-retention-and-backup-schedule-for-contract-x-v2';
        self::assertSame(1, ProfileCodec::fromArray($d)->version);
    }

    public function testAByteOrderMarkIsTolerated(): void
    {
        self::assertSame('contract_x', ProfileCodec::parse("\xEF\xBB\xBF" . ProfileCodec::export(self::contractX()))->key);
    }

    public function testAllErrorsAreReportedTogetherAndCapped(): void
    {
        $d = self::doc();
        $d['profile']['key'] = 'X';
        $d['profile']['label'] = '';
        $d['profile']['requirements'] = array_fill(0, 30, ['type' => 'nope']);
        try {
            ProfileCodec::fromArray($d);
            self::fail();
        } catch (ProfileValidationException $e) {
            self::assertGreaterThan(2, count($e->errors()));
            self::assertLessThanOrEqual(ProfileValidationException::MAX_ERRORS, count($e->errors()));
        }
    }

    public function testDirectConstructionEnforcesTheSameRules(): void
    {
        $this->expectException(ProfileValidationException::class);
        new FrameworkProfile('iso27001', 'Mine', '', 1, false, []);
    }

    public function testTheRetentionFloorsOfAProfile(): void
    {
        $p = self::contractX();
        self::assertSame([DataClass::AUDIT_EVENTS => 2555], $p->retentionFloors());
        self::assertSame(2555, $p->retentionMin(DataClass::AUDIT_EVENTS));
        self::assertSame(0, $p->retentionMin(DataClass::RMM_LOGS));
        self::assertSame('Contract X (version 3)', $p->displayName());
    }
}
