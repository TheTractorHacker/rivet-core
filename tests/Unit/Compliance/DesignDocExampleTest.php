<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\Profile\ProfileCodec;

/** The Contract X example in the design note must be a file this release actually accepts, and must say what the note says it says. */
final class DesignDocExampleTest extends TestCase
{
    public function testTheContractXExampleImports(): void
    {
        $doc = (string) file_get_contents(__DIR__ . '/../../../docs/design/compliance-frameworks.md');
        self::assertSame(1, preg_match('/<!-- example:contract-x -->\s*```json\n(.*?)\n```/s', $doc, $m), 'the example block is missing');
        $p = ProfileCodec::parse($m[1]);
        self::assertSame('contract_x', $p->key);
        self::assertSame('7-year retention, daily encrypted off-site backups, quarterly restore test', $p->description);
        foreach (['audit_events', 'rmm_logs', 'change_records', 'check_history', 'patch_history', 'software_history', 'backups'] as $class) {
            self::assertSame(7 * 365, $p->retentionMin($class), "$class: seven years");
        }
        self::assertSame(24, $p->requirement('backup_rpo_max')?->value, 'daily');
        self::assertNotNull($p->requirement('backup_encrypted'));
        self::assertNotNull($p->requirement('backup_offsite'));
        self::assertNotNull($p->requirement('backup_covers_all_stores'));
        self::assertLessThanOrEqual(92, $p->requirement('restore_test_max_age')?->value, 'quarterly');
        self::assertSame(ProfileCodec::export($p), ProfileCodec::export(ProfileCodec::parse(ProfileCodec::export($p))), 'stable round trip');
    }
}
