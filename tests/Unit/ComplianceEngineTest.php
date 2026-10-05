<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\AttestationProviderInterface;
use RivetCore\Compliance\CheckInterface;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\ComplianceAssessor;
use RivetCore\Compliance\Framework;
use RivetCore\Compliance\ManualItem;
use RivetCore\Compliance\ReportRenderer;
use RivetCore\Contracts\ClockInterface;

final class ComplianceEngineTest extends TestCase
{
    private function clock(string $at = '2026-10-05 12:00:00'): ClockInterface
    {
        return new class($at) implements ClockInterface {
            public function __construct(private string $at)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->at);
            }
        };
    }

    private function check(string $id, callable $run, array $controls): CheckInterface
    {
        return new class($id, $run, $controls) implements CheckInterface {
            public function __construct(private string $id, private $run, private array $controls)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function title(): string
            {
                return 'T ' . $this->id;
            }

            public function category(): string
            {
                return 'Cat';
            }

            public function why(): string
            {
                return 'because';
            }

            public function controls(): array
            {
                return $this->controls;
            }

            public function run(): CheckResult
            {
                return ($this->run)();
            }
        };
    }

    private function attest(array $latest): AttestationProviderInterface
    {
        return new class($latest) implements AttestationProviderInterface {
            public function __construct(private array $l)
            {
            }

            public function latestPerItem(): array
            {
                return $this->l;
            }
        };
    }

    public function testScoringStatesAndIsolation(): void
    {
        $checks = [
            $this->check('a', fn () => CheckResult::pass('ok'), [Framework::SOC2 => ['CC6.1'], Framework::PCI => ['8.4.2']]),
            $this->check('b', fn () => CheckResult::warn('meh'), [Framework::SOC2 => ['CC6.2']]),
            $this->check('c', fn () => CheckResult::fail('bad'), [Framework::SOC2 => ['CC6.3']]),
            $this->check('d', fn () => CheckResult::notApplicable('n/a'), [Framework::SOC2 => ['CC6.4']]),
            $this->check('boom', function () { throw new \RuntimeException('secret db password'); }, [Framework::PCI => ['1.1']]),
        ];
        $items = [
            new ManualItem('current_one', 'Cur', 'M', 'w', [Framework::SOC2 => ['CC1.1']], 365),
            new ManualItem('late_one', 'Late', 'M', 'w', [Framework::SOC2 => ['CC1.2']], 365),
            new ManualItem('never_one', 'Never', 'M', 'w', [Framework::SOC2 => ['CC1.3']], 365),
            new ManualItem('soon_one', 'Soon', 'M', 'w', [Framework::SOC2 => ['CC1.4']], 365),
        ];
        $att = $this->attest([
            'current_one' => ['reviewed_on' => '2026-09-01', 'next_due_on' => null, 'reviewer_name' => 'A', 'note' => null],
            'late_one' => ['reviewed_on' => '2025-01-01', 'next_due_on' => '2026-01-01', 'reviewer_name' => 'A', 'note' => null],
            'soon_one' => ['reviewed_on' => '2025-10-20', 'next_due_on' => null, 'reviewer_name' => 'B', 'note' => 'x'],
        ]);
        $a = (new ComplianceAssessor($checks, $items, $att, $this->clock()))->assess();

        $byId = array_column($a->automatic, null, 'id');
        self::assertSame('error', $byId['boom']['status']);
        self::assertStringNotContainsString('secret', json_encode($a->toArray()));
        $manual = array_column($a->manual, null, 'id');
        self::assertSame('current', $manual['current_one']['state']);
        self::assertSame('overdue', $manual['late_one']['state']);
        self::assertSame('never', $manual['never_one']['state']);
        self::assertSame('due_soon', $manual['soon_one']['state']);

        // SOC 2 items: pass 1, warn .5, fail 0, NA skipped, manual 1/0/0/.5 => 3.0 over 7 scored
        self::assertSame(round(100 * 3.0 / 7, 1), $a->summaries[Framework::SOC2]['score']);
        self::assertSame(1, $a->summaries[Framework::SOC2]['na']);
        // PCI: pass + error => 50%
        self::assertSame(50.0, $a->summaries[Framework::PCI]['score']);
        self::assertNull($a->summaries[Framework::HIPAA]['score']);
    }

    public function testRoundTrip(): void
    {
        $a = (new ComplianceAssessor([$this->check('a', fn () => CheckResult::pass('ok'), [Framework::SOC2 => ['x']])], [], $this->attest([]), $this->clock()))->assess();
        $b = \RivetCore\Compliance\Assessment::fromArray(json_decode(json_encode($a->toArray()), true));
        self::assertEquals($a->toArray(), $b->toArray());
    }

    public function testRendererEscapesAndNeutralisesFormulas(): void
    {
        $evil = '<script>alert(1)</script>';
        $checks = [$this->check('a', fn () => CheckResult::fail('=HYPERLINK("x")', $evil), [Framework::ISO27001 => ['A.1']])];
        $a = (new ComplianceAssessor($checks, [], $this->attest([]), $this->clock()))->assess();
        $r = new ReportRenderer();
        $html = $r->html($a, 'Org <b>', Framework::ISO27001);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('Org &lt;b&gt;', $html);
        self::assertStringContainsString('not a certification', $html);
        $csv = $r->csv($a);
        self::assertStringContainsString("\"'=HYPERLINK", $csv);
        self::assertSame("\"'=1\"", ReportRenderer::csvCell('=1'));
        self::assertSame('"a ""q"" b"', ReportRenderer::csvCell('a "q" b'));
        // framework filter drops items not tagged to it
        self::assertCount(1, $r->rows($a, Framework::SOC2));
    }

    public function testEveryFrameworkHasARetentionPreset(): void
    {
        foreach (Framework::all() as $key) {
            self::assertTrue(\RivetCore\Compliance\RetentionPolicy::isValidProfile($key), "$key has no retention preset");
        }
    }

    public function testResponsiblePartyFlowsToTheReportOnlyWhenAssigned(): void
    {
        $items = [new ManualItem('backup_restore_test', 'Restore test', 'Resilience', 'w', [Framework::SOC2 => ['A1.3']], 365)];
        $plain = (new ComplianceAssessor([], $items, $this->attest([]), $this->clock()))->assess();
        $r = new ReportRenderer();
        self::assertNull($plain->manual[0]['responsible']);
        self::assertStringNotContainsString('Responsible', $r->csv($plain), 'no column when nobody outsourced anything');
        $with = (new ComplianceAssessor([], $items, $this->attest([]), $this->clock(), ['section:Resilience' => 'Acme <MSP>']))->assess();
        self::assertSame('Acme <MSP>', $with->manual[0]['responsible']);
        self::assertStringContainsString('Responsible', $r->csv($with));
        $html = $r->html($with, 'Org');
        self::assertStringContainsString('Acme &lt;MSP&gt;', $html);
        self::assertStringNotContainsString('Acme <MSP>', $html);
        $view = \RivetCore\Compliance\SharedReport::view($with);
        self::assertSame('Acme <MSP>', $view['manual'][0]['responsible']);
    }
}
