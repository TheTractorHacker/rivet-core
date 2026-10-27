<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Retention;

use PHPUnit\Framework\TestCase;
use RivetCore\Retention\RetentionService;
use RivetCore\Security\SecurityBaseline;
use RivetCore\Tests\Support\FakeDatabase;

/** ERR-03 (formerly GAP-10): RetentionService itself keeps the audit trail for at least the baseline floor. */
final class RetentionFloorTest extends TestCase
{
    private function cutoff(FakeDatabase $db, string $table): ?string
    {
        foreach ($db->calls as $c) {
            if (str_starts_with($c['sql'], 'DELETE FROM ' . $table)) {
                return $c['params'][0];
            }
        }

        return null;
    }

    private function expected(int $days): string
    {
        return (new \DateTimeImmutable('2030-06-01 00:00:00'))->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    private function pruned(int $days, ?int $audit): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->rows = [['n' => '2030-06-01 00:00:00']];
        (new RetentionService($db))->prune($days, $audit);

        return $db;
    }

    public function testStoredSevenDaysStillKeepsNinety(): void
    {
        $db = $this->pruned(7, 7);
        $this->assertSame($this->expected(SecurityBaseline::MIN_AUDIT_RETENTION_DAYS), $this->cutoff($db, 'audit_events'));
        $this->assertSame($this->expected(7), $this->cutoff($db, 'webhook_deliveries'), 'other tables have no floor');
        $this->assertSame($this->expected(7), $this->cutoff($db, 'integration_jobs'));
    }

    public function testDefaultAuditHorizonFollowsTheGeneralOneAndIsClamped(): void
    {
        $this->assertSame($this->expected(90), $this->cutoff($this->pruned(1, null), 'audit_events'));
        $this->assertSame($this->expected(90), $this->cutoff($this->pruned(89, null), 'audit_events'));
    }

    public function testValuesAtOrAboveTheFloorAreUntouched(): void
    {
        $this->assertSame($this->expected(90), $this->cutoff($this->pruned(30, 90), 'audit_events'));
        $this->assertSame($this->expected(365), $this->cutoff($this->pruned(30, 365), 'audit_events'));
    }

    public function testBelowOneStillMeansKeepEverythingAndPlanAgrees(): void
    {
        $this->assertNull($this->cutoff($this->pruned(30, 0), 'audit_events'));
        $this->assertNull($this->cutoff($this->pruned(30, -1), 'audit_events'));
        $db = new FakeDatabase();
        $db->rows = [['n' => '2030-06-01 00:00:00', 'c' => '0']];
        (new RetentionService($db))->plan(7, 7);
        $params = array_values(array_filter($db->calls, static fn (array $c): bool => str_contains($c['sql'], 'FROM audit_events')))[0]['params'];
        $this->assertSame([$this->expected(90)], $params, 'the dry run reports the clamped horizon');
    }
}
