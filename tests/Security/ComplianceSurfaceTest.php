<?php

declare(strict_types=1);

namespace RivetCore\Tests\Security;

use RivetCore\Compliance\Assessment;
use RivetCore\Compliance\ReportRenderer;
use RivetCore\Compliance\SharedReport;

/** Compliance shared report and renderer: guard tests only (no finding). */
final class ComplianceSurfaceTest extends SecurityTestCase
{
    private function assessment(): Assessment
    {
        return new Assessment(
            new \DateTimeImmutable('2026-10-06 12:00:00'),
            [['id' => 'a1', 'title' => '=cmd|calc', 'category' => 'Access', 'why' => '<script>why()</script>', 'status' => 'fail" onmouseover="x', 'status_label' => 'Fail', 'summary' => '@SUM(1)', 'detail' => '3 admin accounts: root, alice, bob', 'controls' => ['pci' => ['1.1']], 'responsible' => 'Internal']],
            [['title' => 'Backup test', 'category' => 'Resilience', 'state' => 'current', 'reviewed_on' => '2026-09-01', 'note' => 'PRIVATE-NOTE', 'reviewer_name' => 'Alice Reviewer', 'next_due_on' => '2027-09-01', 'controls' => ['pci' => ['9.9']], 'responsible' => 'Acme MSP']],
            ['all' => ['label' => 'All', 'score' => 50.0, 'pass' => 0, 'warn' => 0, 'fail' => 1, 'error' => 0, 'manual_current' => 1, 'manual_overdue' => 0, 'manual_never' => 0]]
        );
    }

    /** Guard: the portal view carries titles, states and scores only (no details, notes, reviewer names); `responsible` is exposed by design. */
    public function testGuardSharedReportViewIsReduced(): void
    {
        $json = (string) json_encode(SharedReport::view($this->assessment()));
        foreach (['PRIVATE-NOTE', 'Alice Reviewer', 'root, alice, bob', '3 admin accounts', '@SUM(1)', 'next_due'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, $leak);
        }
        $this->assertStringContainsString('Acme MSP', $json, 'the responsible party label is shown to portal users');
    }

    /** Guard: HTML output is escaped everywhere and the CSV neutralises formula starters, including values after a leading tab. */
    public function testGuardRendererEscapesHtmlAndFormulas(): void
    {
        $r = new ReportRenderer();
        $html = $r->html($this->assessment(), 'Org <b>Name</b>');
        $this->assertStringNotContainsString('<script>why()', $html);
        $this->assertStringNotContainsString('<b>Name</b>', $html);
        $this->assertStringNotContainsString('" onmouseover="', $html);
        $csv = $r->csv($this->assessment());
        $this->assertStringContainsString('"\'=cmd|calc"', $csv);
        $this->assertStringContainsString('"\'@SUM(1)', $csv);
        $this->assertSame('"\'+1"', ReportRenderer::csvCell('+1'));
        $this->assertSame('"\'-1"', ReportRenderer::csvCell('-1'));
        $this->assertSame('"\'' . "\t" . 'x"', ReportRenderer::csvCell("\tx"));
        $this->assertSame('"a b"', ReportRenderer::csvCell("a\r\nb"));
    }
}
