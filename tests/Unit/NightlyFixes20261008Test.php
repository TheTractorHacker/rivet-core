<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Automation\AutomationExecutor;
use RivetCore\Mcp\Migration\Migration0017McpIdentityBinaryCollation;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Rmm\Enrollment\EnrollmentService;
use RivetCore\Tests\Support\FakeDatabase;

/** Database-free checks for the 2026-10-08 nightly findings (CORE-1, F8, F10). */
final class NightlyFixes20261008Test extends TestCase
{
    /** @param array<string,mixed> $dev @param array<string,mixed> $token */
    private static function same(array $dev, array $token): bool
    {
        $m = new \ReflectionMethod(EnrollmentService::class, 'sameClient');

        return (bool) $m->invoke(null, $dev, $token);
    }

    public function testEnrollmentOnlyReusesADeviceOfTheTokensClient(): void
    {
        $this->assertTrue(self::same(['client_id' => '7'], ['client_id' => 7]));
        $this->assertFalse(self::same(['client_id' => 8], ['client_id' => 7]));
        $this->assertFalse(self::same([], ['client_id' => 7]));
        $this->assertFalse(self::same(['client_id' => 0], []), 'a token without a client matches nothing');
    }

    public function testReinstallLookupsAreScopedByClientInSql(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Rmm/Enrollment/EnrollmentService.php');
        $this->assertStringContainsString('WHERE machine_guid = ? AND client_id = ?', $src);
        $this->assertStringContainsString('WHERE serial = ? AND client_id = ?', $src);
        $this->assertStringNotContainsString('WHERE machine_guid = ? ORDER', $src);
        $this->assertStringNotContainsString('WHERE serial = ? ORDER', $src);
    }

    public function testMigration0017IsRegisteredLastAndIdempotent(): void
    {
        $all = CoreMigrations::all();
        $this->assertInstanceOf(Migration0017McpIdentityBinaryCollation::class, $all[count($all) - 1]);
        $this->assertSame('0017_mcp_identity_binary_collation', $all[count($all) - 1]->id());

        $db = new FakeDatabase();
        $db->rows = [['COLUMN_NAME' => 'issuer', 'COLLATION_NAME' => 'utf8mb4_general_ci'], ['COLUMN_NAME' => 'subject', 'COLLATION_NAME' => 'utf8mb4_general_ci']];
        (new Migration0017McpIdentityBinaryCollation())->up($db);
        $alter = $db->calls[1]['sql'] ?? '';
        $this->assertStringContainsString('MODIFY COLUMN `issuer`', $alter);
        $this->assertStringContainsString('MODIFY COLUMN `subject`', $alter);
        $this->assertStringContainsString('utf8mb4_bin', $alter);

        $done = new FakeDatabase();
        $done->rows = [['COLUMN_NAME' => 'issuer', 'COLLATION_NAME' => 'utf8mb4_bin'], ['COLUMN_NAME' => 'subject', 'COLLATION_NAME' => 'utf8mb4_bin']];
        (new Migration0017McpIdentityBinaryCollation())->up($done);
        $this->assertCount(1, $done->calls, 'already binary: only the lookup ran');

        $missing = new FakeDatabase();
        (new Migration0017McpIdentityBinaryCollation())->up($missing);
        $this->assertCount(1, $missing->calls, 'no table: no-op');
    }

    public function testInterpolationIsAnAllowlistOfFreeTextKeys(): void
    {
        $ctx = ['ticket.subject' => 'victim@example.com', 'ticket.id' => '7'];
        $out = AutomationExecutor::interpolate([
            'subject' => 'Re {ticket.id}',
            'email' => '{ticket.subject}',
            'to' => '{ticket.subject}',
            'assigned_to' => '{ticket.id}',
            'asset_id' => '{ticket.id}',
            'headers' => '{ticket.subject}',
            'url' => 'https://{ticket.id}.example/',
            'priority' => '{ticket.id}',
            'details' => 'About {ticket.subject}',
        ], $ctx);
        $this->assertSame('Re 7', $out['subject']);
        $this->assertSame('About victim@example.com', $out['details']);
        foreach (['email', 'to', 'assigned_to', 'asset_id', 'headers', 'url', 'priority'] as $k) {
            $this->assertStringContainsString('{', (string) $out[$k], "$k must stay verbatim");
        }
    }
}
