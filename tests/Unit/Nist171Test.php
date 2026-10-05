<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\ClientChecklist;
use RivetCore\Compliance\Framework;
use RivetCore\Compliance\Nist171Map;

final class Nist171Test extends TestCase
{
    public function testFrameworkIsRegistered(): void
    {
        self::assertTrue(Framework::isValid('nist171'));
        self::assertStringContainsString('CMMC', Framework::LABELS[Framework::NIST171]);
        self::assertLessThanOrEqual(100, strlen(implode(',', Framework::all())), 'the stored csv column is varchar(100)');
    }

    public function testEveryReferenceIsAWellFormedRequirementNumber(): void
    {
        foreach (Nist171Map::MAP as $id => $refs) {
            self::assertNotEmpty($refs, $id);
            foreach ($refs as $ref) {
                self::assertMatchesRegularExpression('/^3\.(1|2|3|4|5|6|7|8|9|10|11|12|13|14)\.\d{1,2}$/', $ref, "$id: $ref");
            }
        }
    }

    public function testApplyOnlyAddsWhenMissing(): void
    {
        self::assertSame(['3.5.3'], Nist171Map::apply('mfa_coverage', [])['nist171']);
        self::assertSame(['9.9.9'], Nist171Map::apply('mfa_coverage', ['nist171' => ['9.9.9']])['nist171']);
        self::assertSame([], Nist171Map::apply('unmapped_item', []));
    }

    public function testCustomerChecklistForNist(): void
    {
        $ids = array_map(fn ($i) => $i->id, ClientChecklist::forFrameworks([Framework::NIST171]));
        foreach (['cui_scope', 'ssp_poam', 'cmmc_self_assessment', 'mfa_enforced', 'access_review', 'incident_response_test'] as $want) {
            self::assertContains($want, $ids);
        }
        self::assertNotContains('baa_in_place', $ids);
        self::assertNotContains('pci_scope_validation', $ids);
        foreach (ClientChecklist::forFrameworks([Framework::NIST171]) as $i) {
            self::assertSame([Framework::NIST171], array_keys($i->controls));
        }
        $all = ClientChecklist::ids();
        self::assertSame($all, array_values(array_unique($all)));
    }
}
