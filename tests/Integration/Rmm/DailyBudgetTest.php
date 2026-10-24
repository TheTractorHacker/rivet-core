<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Migration\BudgetSchema;
use RivetCore\Rmm\Migration\Migration0041DailyBudget;
use RivetCore\Rmm\Support\DailyBudget;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\RmmTestCase;

/** Migration 0041 and the per-day budget ledger behind the panel chat cap (RC36 M1) and the Mesh event cap (RC36 L3). */
final class DailyBudgetTest extends RmmTestCase
{
    private function budget(): DailyBudget
    {
        return new DailyBudget($this->h->module->sql());
    }

    public function testTheMigrationIsIdempotentAndAdditive(): void
    {
        $db = new MysqliDatabase($this->h->mysqli);
        $m = new Migration0041DailyBudget();
        $this->assertSame('0041_daily_budget', $m->id());
        $m->up($db);
        $m->up($db);
        foreach (array_keys(BudgetSchema::tables()) as $t) {
            $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'"));
        }
        $this->assertSame(['scope', 'subject_id', 'day', 'used_count', 'used_bytes', 'flagged'],
            array_column($this->h->rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rmm_daily_budget' ORDER BY ORDINAL_POSITION"), 'COLUMN_NAME'));
    }

    public function testChargeStopsExactlyAtTheLimitsInCountAndInBytes(): void
    {
        $b = $this->budget();
        $this->assertTrue($b->charge('t', 1, 1, 400, 3, 1000));
        $this->assertTrue($b->charge('t', 1, 1, 400, 3, 1000));
        $this->assertFalse($b->charge('t', 1, 1, 400, 3, 1000), 'bytes: 1200 > 1000');
        $this->assertSame(['count' => 2, 'bytes' => 800], $b->used('t', 1), 'a refused charge spends nothing');
        $this->assertTrue($b->charge('t', 1, 1, 200, 3, 1000), 'exactly 1000');
        $this->assertFalse($b->charge('t', 1, 1, 0, 3, 1000), 'count: a fourth');
        $this->assertTrue($b->charge('t', 2, 1, 0, 3, 1000), 'another subject has its own budget');
        $this->assertTrue($b->charge('u', 1, 1, 0, 3, 1000), 'and another scope');
    }

    public function testRefundHandsTheBudgetBackAndNeverGoesNegative(): void
    {
        $b = $this->budget();
        $b->charge('t', 1, 1, 100, 5, 1000);
        $b->refund('t', 1, 1, 100);
        $this->assertSame(['count' => 0, 'bytes' => 0], $b->used('t', 1));
        $b->refund('t', 1, 5, 500);
        $this->assertSame(['count' => 0, 'bytes' => 0], $b->used('t', 1));
    }

    public function testTheFirstRefusalIsReportedOncePerDayAndTheDayRollsOver(): void
    {
        $b = $this->budget();
        $this->assertTrue($b->firstRefusalToday('t', 1));
        $this->assertFalse($b->firstRefusalToday('t', 1));
        $this->assertTrue($b->firstRefusalToday('t', 2));
        $this->h->clock->advance(86400);
        $this->assertTrue($b->firstRefusalToday('t', 1), 'a new UTC day');
        $this->assertTrue($b->charge('t', 1, 1, 0, 1, 10), 'and a fresh budget');
    }

    public function testRowsOlderThanAWeekAreDeletedWhenADaysFirstRowIsWritten(): void
    {
        $b = $this->budget();
        $b->charge('t', 1, 1, 0, 5, 10);
        $this->h->clock->advance(8 * 86400);
        $b->charge('t', 1, 1, 0, 5, 10);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_daily_budget'));
    }

    public function testRetryAfterIsTheTimeToTheNextUtcMidnight(): void
    {
        $s = $this->budget()->secondsUntilReset();
        $now = $this->h->clock->now()->getTimestamp();
        $this->assertSame(86400 - ($now % 86400), $s);
        $this->assertGreaterThanOrEqual(1, $s);
    }
}
