<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\Backup\ArtifactCheckedBackupFactsProvider;
use RivetCore\Compliance\Backup\BackupEncryptionInspector as I;
use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Compliance\Backup\BackupStore;
use RivetCore\Compliance\Check\BackupEncryptedCheck;
use RivetCore\Compliance\Profile\FixedRequirementsProvider;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Compliance\Status;
use RivetCore\Tests\Support\FixedClock;

/** DATA-03 / HARD-05 / GAP-11: detect an unencrypted backup artifact from its content and feed that into the compliance facts. */
final class BackupEncryptionInspectorTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private function file(string $content, string $suffix = '.bin'): string
    {
        $p = sys_get_temp_dir() . '/rc-bk-' . bin2hex(random_bytes(6)) . $suffix;
        file_put_contents($p, $content);

        return $this->files[] = $p;
    }

    public function testRecognisesEncryptedFormats(): void
    {
        $i = new I();
        foreach ([
            ['age', "age-encryption.org/v1\n-> X25519 abc\n" . random_bytes(80)],
            ['age', "-----BEGIN AGE ENCRYPTED FILE-----\nYWdl\n-----END AGE ENCRYPTED FILE-----\n"],
            ['gpg', "-----BEGIN PGP MESSAGE-----\n\nhQEM\n-----END PGP MESSAGE-----\n"],
            ['gpg', "\x85\x01\x0c\x03" . random_bytes(60)],      // old-format PKESK packet
            ['gpg', "\xc3\x0d\x04" . random_bytes(60)],           // new-format SKESK packet
            ['gpg', "\xd2\x20\x01" . random_bytes(60)],           // new-format SEIPD packet
            ['openssl', 'Salted__' . random_bytes(40)],
            ['rivetcore-v3', 'v3:main:' . base64_encode(random_bytes(40))],
        ] as [$method, $content]) {
            $r = $i->inspectHeader($content);
            $this->assertSame(I::ENCRYPTED, $r->state, $method . ' ' . bin2hex(substr($content, 0, 6)));
            $this->assertSame($method, $r->method);
            $this->assertTrue($r->isEncrypted());
        }
    }

    public function testRecognisesPlaintextAndUnknown(): void
    {
        $i = new I();
        foreach (["\x1f\x8b\x08\x00" . random_bytes(30), "PK\x03\x04" . random_bytes(30), 'PGDMP' . random_bytes(30), "-- PostgreSQL database dump\n-- Dumped from", "SELECT 1;\nINSERT INTO t VALUES (1);\n"] as $content) {
            $this->assertSame(I::PLAINTEXT, $i->inspectHeader($content)->state, bin2hex(substr($content, 0, 6)));
        }
        $tar = str_pad('file.txt', 257) . "ustar\0" . str_repeat("\0", 300);
        $this->assertSame(I::PLAINTEXT, $i->inspectHeader($tar)->state);
        $this->assertSame(I::UNKNOWN, $i->inspectHeader('')->state);
        $this->assertSame(I::UNKNOWN, $i->inspectHeader("\x01\x02\x03\x04\x05")->state, 'binary without a known header is unknown, never encrypted');
        $this->assertFalse((new I())->inspectHeader("\x01\x02\x03")->isEncrypted());
    }

    public function testASuffixAloneIsNeverProof(): void
    {
        $i = new I();
        $liar = $this->file("-- PostgreSQL database dump\nCREATE TABLE t();\n", '.sql.age');
        $r = $i->inspectFile($liar);
        $this->assertSame(I::PLAINTEXT, $r->state);
        $this->assertSame('age', $r->suffixHint);
        $this->assertTrue($r->suffixLies());
        $real = $i->inspectFile($this->file("age-encryption.org/v1\n" . random_bytes(100), '.sql.age'));
        $this->assertTrue($real->isEncrypted());
        $this->assertFalse($real->suffixLies());
        $this->assertSame(I::UNKNOWN, $i->inspectFile('/nonexistent/backup.age')->state);
        $this->assertSame(I::UNKNOWN, $i->inspectFile($this->file(''))->state);
    }

    public function testAllEncryptedRollUp(): void
    {
        $i = new I();
        $enc1 = $this->file("age-encryption.org/v1\n" . random_bytes(50));
        $enc2 = $this->file("-----BEGIN PGP MESSAGE-----\nabc");
        $plain = $this->file("PGDMP" . random_bytes(20));
        $unk = $this->file("\x01\x02\x03");
        $this->assertTrue($i->allEncrypted([$enc1, $enc2]));
        $this->assertFalse($i->allEncrypted([$enc1, $plain]));
        $this->assertFalse($i->allEncrypted([$unk, $plain]));
        $this->assertNull($i->allEncrypted([$enc1, $unk]));
        $this->assertNull($i->allEncrypted([]));
    }

    private function facts(?bool $encrypted, ?string $method = null): BackupFacts
    {
        return new BackupFacts(true, new \DateTimeImmutable('-1 hour'), [BackupStore::CONTROL_DB], [BackupFacts::DEST_LOCAL], $encrypted, 30, null, $method);
    }

    private function provider(BackupFacts $f, array $paths): ArtifactCheckedBackupFactsProvider
    {
        $inner = new class($f) implements BackupFactsProviderInterface {
            public function __construct(private BackupFacts $f)
            {
            }

            public function backupFacts(): BackupFacts
            {
                return $this->f;
            }
        };

        return new ArtifactCheckedBackupFactsProvider($inner, static fn (): array => $paths);
    }

    public function testAPlaintextFileOverridesAnEditionThatClaimsEncryption(): void
    {
        $plain = $this->file("-- PostgreSQL database dump\n");
        $f = $this->provider($this->facts(true, 'age'), [$plain])->backupFacts();
        $this->assertFalse($f->encrypted);
        $this->assertNull($f->encryptionMethod);
        $this->assertStringContainsString('not encrypted', $f->reason);
        $p = new FixedRequirementsProvider(new FrameworkProfile('contract_x', 'Contract X', '', 3, false, [new Requirement(RequirementType::BACKUP_ENCRYPTED, null, 1)]));
        $check = new BackupEncryptedCheck($p, $this->provider($this->facts(true, 'age'), [$plain]), new FixedClock());
        $this->assertSame(Status::Fail, $check->run()->status, 'the compliance check fails on the file evidence although the edition said encrypted');
    }

    public function testEncryptedFilesFillAnUnknownAndNeverOverruleAFalse(): void
    {
        $enc = $this->file("age-encryption.org/v1\n" . random_bytes(50));
        $f = $this->provider($this->facts(null), [$enc])->backupFacts();
        $this->assertTrue($f->encrypted);
        $this->assertSame('age', $f->encryptionMethod);
        $this->assertFalse($this->provider($this->facts(false), [$enc])->backupFacts()->encrypted, 'an explicit false from the edition is kept');
    }

    public function testUnknownFilesAndMissingInputsChangeNothing(): void
    {
        $unk = $this->file("\x01\x02\x03");
        $this->assertTrue($this->provider($this->facts(true, 'gpg'), [$unk])->backupFacts()->encrypted, 'unreadable evidence never downgrades the edition word');
        $this->assertNull($this->provider($this->facts(null), [$unk])->backupFacts()->encrypted, 'and never upgrades it');
        $this->assertNull($this->provider($this->facts(null), [])->backupFacts()->encrypted);
        $this->assertFalse($this->provider(BackupFacts::unavailable(), [$unk])->backupFacts()->available);
        $throws = new ArtifactCheckedBackupFactsProvider($this->provider($this->facts(true), []), static fn (): array => throw new \RuntimeException('x'));
        $this->assertFalse($throws->backupFacts()->available, 'never throws');
    }
}
