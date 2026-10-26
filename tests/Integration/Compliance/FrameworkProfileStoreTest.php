<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Compliance;

use RivetCore\Compliance\Framework;
use RivetCore\Compliance\Profile\BuiltInProfiles;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\FrameworkProfileStore;
use RivetCore\Compliance\Profile\ProfileValidationException;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Tests\Support\ProfileTestCase;

final class FrameworkProfileStoreTest extends ProfileTestCase
{
    private function contractX(): FrameworkProfile
    {
        return $this->store->create('contract_x', 'Contract X', '7-year retention, daily encrypted off-site backups, quarterly restore test', self::contractXRequirements(), 7, 'Alex Admin');
    }

    public function testTheMigrationIsIdempotentAndCreatesNoRowsForBuiltIns(): void
    {
        $runner = new MigrationRunner($this->db, CoreMigrations::all(), $this->clock);
        self::assertSame([], $runner->run(), 'a second run applies nothing');
        $m = new \RivetCore\Compliance\Migration\Migration0046FrameworkProfiles();
        $m->up($this->db);
        $m->up($this->db);   // CREATE TABLE IF NOT EXISTS: running the body again changes nothing and does not fail
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM compliance_profiles')['c']);
        self::assertCount(5, $this->store->list(), 'the built-ins exist without rows');
    }

    public function testBuiltInsAreListedFirstAndAreReadOnly(): void
    {
        $this->contractX();
        $keys = array_map(static fn (FrameworkProfile $p) => $p->key, $this->store->list());
        self::assertSame([...Framework::all(), 'contract_x'], $keys);
        foreach (Framework::all() as $k) {
            foreach ([
                fn () => $this->store->update($k, 'x', '', [], 1, 'A'),
                fn () => $this->store->archive($k, 1, 'A'),
                fn () => $this->store->unarchive($k, 1, 'A'),
                fn () => $this->store->delete($k, 1, 'A'),
                fn () => $this->store->create($k, 'Mine', '', [], 1, 'A'),
                fn () => $this->store->revertTo($k, 1, 1, 'A'),
            ] as $op) {
                try {
                    $op();
                    self::fail("changed built-in $k");
                } catch (ProfileValidationException $e) {
                    self::assertTrue(str_contains($e->getMessage(), 'built-in') || str_contains($e->getMessage(), 'reserved'), $k . ': ' . $e->getMessage());
                }
            }
        }
        self::assertSame(BuiltInProfiles::all()['hipaa']->contentHash(), $this->store->get('hipaa')?->contentHash());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM compliance_profiles WHERE profile_key IN (\'hipaa\',\'iso27001\')')['c']);
    }

    public function testCreateReadBackAndTheTextIsStoredAsGiven(): void
    {
        $p = $this->contractX();
        self::assertSame(1, $p->version);
        self::assertFalse($p->builtin);
        self::assertSame('Contract X', $p->label);
        self::assertCount(7, $p->requirements);
        self::assertSame(2555, $p->retentionMin('audit_events'));
        $again = $this->store->get('contract_x');
        self::assertSame($p->contentHash(), $again?->contentHash());
        self::assertSame(['profile.create'], array_column($this->audited, 0));
        self::assertSame('Alex Admin', $this->audited[0][1]['by']);
    }

    public function testDuplicateKeysAndBadInputAreRefused(): void
    {
        $this->contractX();
        foreach ([
            fn () => $this->store->create('contract_x', 'Again', '', [], 1, 'A'),
            fn () => $this->store->create('Bad Key', 'x', '', [], 1, 'A'),
            fn () => $this->store->create('okay_key', '', '', [], 1, 'A'),
            fn () => $this->store->create('okay_key', 'x', '', [], 1, ' '),
            fn () => $this->store->create('okay_key', 'x', "pw: password=abc", [], 1, 'A'),
            fn () => $this->store->create('okay_key', 'x', '', [new Requirement('backup_offsite', null, 1), new Requirement('backup_offsite', null, 1)], 1, 'A'),
        ] as $op) {
            try {
                $op();
                self::fail('accepted');
            } catch (ProfileValidationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertCount(1, $this->db->fetchAll('SELECT 1 FROM compliance_profiles'), 'nothing half-created');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM compliance_profile_versions')['c']);
    }

    public function testEditingCreatesVersionsAndAnIdenticalSaveDoesNot(): void
    {
        $this->contractX();
        $same = $this->store->update('contract_x', 'Contract X', '7-year retention, daily encrypted off-site backups, quarterly restore test', self::contractXRequirements(), 7, 'Alex Admin');
        self::assertSame(1, $same->version, 'no change, no new version');
        $v2 = $this->store->update('contract_x', 'Contract X', 'Updated', [new Requirement('retention_min', 'audit_events', 3650), new Requirement('backup_offsite', null, 1)], 8, 'Sam', 'Customer asked for 10 years');
        self::assertSame(2, $v2->version);
        self::assertSame(3650, $v2->retentionMin('audit_events'));
        self::assertCount(2, $v2->requirements);
        $old = $this->store->get('contract_x', 1);
        self::assertSame(2555, $old?->retentionMin('audit_events'), 'the old version is still readable, unchanged');
        self::assertCount(7, $old->requirements);
        $h = $this->store->history('contract_x');
        self::assertSame([2, 1], array_column($h, 'version'));
        self::assertSame([true, false], array_column($h, 'current'));
        self::assertSame('Sam', $h[0]['changed_by_name']);
        self::assertSame('Customer asked for 10 years', $h[0]['change_note']);
        self::assertSame('Created', $h[1]['change_note']);
        self::assertNull($this->store->get('contract_x', 3));
        // each version has its own requirement rows
        self::assertSame(9, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM compliance_profile_requirements')['c']);
    }

    public function testDiffAndRevertKeepHistoryAppendOnly(): void
    {
        $this->contractX();
        $this->store->update('contract_x', 'Contract X (2027)', '', [new Requirement('retention_min', 'audit_events', 2600), new Requirement('backup_offsite', null, 1), new Requirement('backup_rpo_max', null, 12)], 1, 'Sam');
        $d = $this->store->diff('contract_x', 1, 2);
        self::assertNotNull($d);
        self::assertTrue($d['label_changed']);
        self::assertTrue($d['description_changed']);
        self::assertSame(['retention_min|audit_events', 'backup_rpo_max|'], array_map(static fn (array $c) => $c['after']->key(), $d['changed']));
        self::assertSame([2555, 2600], [$d['changed'][0]['before']->value, $d['changed'][0]['after']->value]);
        self::assertSame(4, count($d['removed']));
        self::assertSame(0, count($d['added']));
        self::assertNull($this->store->diff('contract_x', 1, 9));
        $back = $this->store->revertTo('contract_x', 1, 1, 'Sam');
        self::assertSame(3, $back->version, 'a revert is a new version');
        self::assertSame('Contract X', $back->label);
        self::assertCount(7, $back->requirements);
        self::assertSame([3, 2, 1], array_column($this->store->history('contract_x'), 'version'));
        self::assertSame('Restored the content of version 1', $this->store->history('contract_x')[0]['change_note']);
    }

    public function testAStaleEditIsRefusedAndWritesNothing(): void
    {
        $this->contractX();
        $this->store->update('contract_x', 'Contract X', 'by Sam', self::contractXRequirements(), 1, 'Sam', null, 1);
        try {
            $this->store->update('contract_x', 'Contract X', 'by Alex', self::contractXRequirements(), 1, 'Alex', null, 1);
            self::fail('stale edit accepted');
        } catch (ProfileValidationException $e) {
            self::assertStringContainsString('changed by someone else', $e->getMessage());
        }
        self::assertSame('by Sam', $this->store->get('contract_x')?->description);
        self::assertCount(2, $this->store->history('contract_x'));
    }

    public function testCopyFromBuiltInAndFromCustom(): void
    {
        $c = $this->store->copy('hipaa', 'hipaa_plus', 'HIPAA plus backups', 3, 'Sam');
        self::assertSame('hipaa', $c->basedOn);
        self::assertSame(2190, $c->retentionMin('audit_events'));
        self::assertFalse($c->builtin);
        $this->store->update('hipaa_plus', 'HIPAA plus backups', 'x', [...$c->requirements, new Requirement('backup_encrypted', null, 1)], 3, 'Sam');
        $cc = $this->store->copy('hipaa_plus', 'hipaa_plus_two', 'Second', 3, 'Sam');
        self::assertSame('hipaa_plus', $cc->basedOn);
        self::assertCount(2, $cc->requirements);
        self::assertSame(1, $cc->version);
        $this->expectException(ProfileValidationException::class);
        $this->store->copy('nope', 'whatever', 'x', 1, 'A');
    }

    public function testSelectionRulesAndLog(): void
    {
        $this->contractX();
        self::assertNull($this->store->selectedProfile());
        self::assertSame([], $this->store->retentionFloors());
        $this->store->select('hipaa', 1, 'Alex');
        self::assertSame('hipaa', $this->store->selectedKey());
        self::assertSame([ 'audit_events' => 2190], $this->store->retentionFloors(), 'a built-in asks only for its audit floor');
        $this->store->select('contract_x', 1, 'Alex');
        self::assertSame(['audit_events' => 2555, 'check_history' => 2555], $this->store->retentionFloors());
        self::assertSame('contract_x', $this->store->selectedProfile()?->key);
        // the selected profile follows its current version
        $this->store->update('contract_x', 'Contract X', 'v2', [new Requirement('retention_min', 'rmm_logs', 100)], 1, 'Alex');
        self::assertSame(['rmm_logs' => 100], $this->store->retentionFloors());
        // selected: cannot archive or delete
        foreach ([fn () => $this->store->archive('contract_x', 1, 'A'), fn () => $this->store->delete('contract_x', 1, 'A')] as $op) {
            try {
                $op();
                self::fail('changed the selected profile');
            } catch (ProfileValidationException) {
                self::addToAssertionCount(1);
            }
        }
        $this->store->select(null, 1, 'Alex');
        self::assertNull($this->store->selectedKey());
        self::assertSame([], $this->store->retentionFloors());
        $log = $this->store->selectionLog();
        self::assertSame(['clear', 'select', 'select'], array_column($log, 'action'));
        self::assertSame([null, 'contract_x', 'hipaa'], array_column($log, 'profile_key'));
        self::assertSame([null, 1, 1], array_column($log, 'profile_version'), 'the version in force when it was selected');
        // used before: delete refused, archive allowed
        try {
            $this->store->delete('contract_x', 1, 'A');
            self::fail('deleted a profile that was used');
        } catch (ProfileValidationException $e) {
            self::assertStringContainsString('Archive it instead', $e->getMessage());
        }
        $this->store->archive('contract_x', 1, 'A');
        self::assertSame('archived', $this->store->get('contract_x')?->status);
        self::assertNotContains('contract_x', array_map(static fn ($p) => $p->key, $this->store->list()));
        self::assertContains('contract_x', array_map(static fn ($p) => $p->key, $this->store->list(true)));
        foreach ([fn () => $this->store->select('contract_x', 1, 'A'), fn () => $this->store->update('contract_x', 'x', '', [], 1, 'A'), fn () => $this->store->select('nope', 1, 'A')] as $op) {
            try {
                $op();
                self::fail('allowed');
            } catch (ProfileValidationException) {
                self::addToAssertionCount(1);
            }
        }
        $this->store->unarchive('contract_x', 1, 'A');
        $this->store->select('contract_x', 1, 'A');
    }

    public function testAnUnusedProfileCanBeDeletedWithAllItsRows(): void
    {
        $this->contractX();
        $this->store->update('contract_x', 'Contract X', 'v2', [new Requirement('backup_offsite', null, 1)], 1, 'A');
        $this->store->delete('contract_x', 1, 'Alex');
        self::assertNull($this->store->get('contract_x'));
        foreach (['compliance_profiles', 'compliance_profile_versions', 'compliance_profile_requirements'] as $t) {
            self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) AS c FROM $t")['c'], $t);
        }
        $this->contractX();   // the key is free again
        $this->expectException(ProfileValidationException::class);
        $this->store->delete('missing_one', 1, 'A');
    }

    public function testExportImportRoundTripThroughTheStore(): void
    {
        $this->contractX();
        $json = $this->store->export('contract_x');
        $copy = $this->store->import($json, 2, 'Sam', 'contract_x_copy');
        self::assertSame(1, $copy->version);
        self::assertSame($this->store->get('contract_x')?->contentHash(), $copy->contentHash());
        // same key again is a collision unless the caller asks for a new version
        try {
            $this->store->import($json, 2, 'Sam');
            self::fail('overwrote');
        } catch (ProfileValidationException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }
        $edited = str_replace('"value": 24', '"value": 12', $json);
        $v2 = $this->store->import($edited, 2, 'Sam', null, true);
        self::assertSame(2, $v2->version);
        self::assertSame(12, $v2->requirement('backup_rpo_max')?->value);
        self::assertSame('Imported from a file', $this->store->history('contract_x')[0]['change_note']);
        // a built-in can be exported too
        self::assertStringContainsString('"key": "hipaa"', $this->store->export('hipaa'));
        $this->expectException(ProfileValidationException::class);
        $this->store->import($json, 2, 'Sam', 'brand_new', true);
    }

    public function testMaliciousImportsChangeNothing(): void
    {
        $good = json_decode($this->contractX() && true ? $this->store->export('contract_x') : '', true);
        $before = [(int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM compliance_profiles')['c'], (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM compliance_profile_requirements')['c']];
        $bad = [];
        $x = $good; $x['profile']['key'] = 'evil'; $x['profile']['requirements'][] = ['type' => 'x; DROP TABLE compliance_profiles;--']; $bad[] = $x;
        $x = $good; $x['profile']['key'] = "evil'); DROP TABLE compliance_profiles;--"; $bad[] = $x;
        $x = $good; $x['profile']['key'] = 'evil2'; $x['profile']['label'] = '<script>alert(1)</script>' . str_repeat('x', 200); $bad[] = $x;
        $x = $good; $x['profile']['key'] = 'evil3'; $x['profile']['description'] = 'token=abcdef0123456789'; $bad[] = $x;
        $x = $good; $x['profile']['key'] = 'evil4'; $x['profile']['requirements'] = array_fill(0, 100, ['type' => 'backup_offsite']); $bad[] = $x;
        foreach ($bad as $doc) {
            try {
                $this->store->import((string) json_encode($doc), 1, 'Sam');
                self::fail('accepted a malicious file');
            } catch (ProfileValidationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame($before, [(int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM compliance_profiles')['c'], (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM compliance_profile_requirements')['c']]);
        // a stored label with markup is just text (renderers escape it); a valid-but-odd label is stored verbatim
        $p = $this->store->create('markup_ok', 'Tier <3 & "quotes"', '', [], 1, 'A');
        self::assertSame('Tier <3 & "quotes"', $p->label);
    }

    public function testExportNeverContainsIdentitiesOrSecrets(): void
    {
        $this->contractX();
        $this->store->select('contract_x', 99, 'Alex Admin');
        $json = $this->store->export('contract_x');
        self::assertStringNotContainsString('Alex', $json);
        self::assertStringNotContainsString('created', $json);
        self::assertDoesNotMatchRegularExpression('/"(id|profile_id|user|by|at)"/', $json);
        foreach (array_keys(BuiltInProfiles::all()) as $k) {
            self::assertStringNotContainsString('@', $this->store->export($k));
        }
    }

    public function testTheAuditCallbackSeesEveryChangeAndItsFailureDoesNotUndoThem(): void
    {
        $failing = new FrameworkProfileStore($this->db, $this->clock, static function (): void {
            throw new \RuntimeException('audit down');
        });
        $failing->create('audit_probe', 'Probe', '', [], 1, 'A');
        self::assertNotNull($failing->get('audit_probe'));
        $this->store->create('audit_two', 'Two', '', [], 1, 'A');
        $this->store->update('audit_two', 'Two', 'd', [], 1, 'A');
        $this->store->select('audit_two', 1, 'A');
        $this->store->select(null, 1, 'A');
        $this->store->archive('audit_two', 1, 'A');
        $this->store->unarchive('audit_two', 1, 'A');
        self::assertSame(['profile.create', 'profile.update', 'profile.select', 'profile.clear', 'profile.archive', 'profile.unarchive'], array_column($this->audited, 0));
        self::assertStringNotContainsString('password', (string) json_encode($this->audited));
    }
}
