<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

/**
 * A per-subject, per-UTC-day budget kept in `rmm_daily_budget` ({@see \RivetCore\Rmm\Migration\BudgetSchema}). {@see charge()} is one conditional UPDATE, so two
 * parallel requests can never both spend the last of a budget; the budget is spent BEFORE the work it pays for and handed back with {@see refund()} if the work fails.
 *
 * @internal
 */
final class DailyBudget
{
    private const KEEP_DAYS = 7;

    public function __construct(private readonly Sql $sql)
    {
    }

    /**
     * Spend $count units and $bytes bytes of the day's budget. False (nothing spent) when either limit would be passed.
     */
    public function charge(string $scope, int $subjectId, int $count, int $bytes, int $maxCount, int $maxBytes): bool
    {
        $day = $this->day();
        $this->ensure($scope, $subjectId, $day);

        return $this->sql->run('UPDATE rmm_daily_budget SET used_count = used_count + ?, used_bytes = used_bytes + ?
            WHERE scope = ? AND subject_id = ? AND day = ? AND used_count + ? <= ? AND used_bytes + ? <= ?',
            [$count, $bytes, $scope, $subjectId, $day, $count, $maxCount, $bytes, $maxBytes]) === 1;
    }

    /** Hand back what {@see charge()} spent (the work it paid for did not happen). */
    public function refund(string $scope, int $subjectId, int $count, int $bytes): void
    {
        $this->sql->run('UPDATE rmm_daily_budget SET used_count = GREATEST(0, used_count - ?), used_bytes = GREATEST(0, used_bytes - ?) WHERE scope = ? AND subject_id = ? AND day = ?',
            [$count, $bytes, $scope, $subjectId, $this->day()]);
    }

    /** True exactly once per subject and day: the caller that gets true writes the single audit row about the refusals. */
    public function firstRefusalToday(string $scope, int $subjectId): bool
    {
        $day = $this->day();
        $this->ensure($scope, $subjectId, $day);

        return $this->sql->run('UPDATE rmm_daily_budget SET flagged = 1 WHERE scope = ? AND subject_id = ? AND day = ? AND flagged = 0', [$scope, $subjectId, $day]) === 1;
    }

    /** Seconds until the budget resets (the next UTC midnight), at least 1: the Retry-After of a refusal. */
    public function secondsUntilReset(): int
    {
        $t = $this->sql->time();

        return max(1, 86400 - ($t % 86400));
    }

    /** @return array{count:int,bytes:int} */
    public function used(string $scope, int $subjectId): array
    {
        $r = $this->sql->one('SELECT used_count, used_bytes FROM rmm_daily_budget WHERE scope = ? AND subject_id = ? AND day = ?', [$scope, $subjectId, $this->day()]);

        return ['count' => (int) ($r['used_count'] ?? 0), 'bytes' => (int) ($r['used_bytes'] ?? 0)];
    }

    private function day(): string
    {
        return gmdate('Y-m-d', $this->sql->time());
    }

    private function ensure(string $scope, int $subjectId, string $day): void
    {
        if ($this->sql->run('INSERT IGNORE INTO rmm_daily_budget (scope, subject_id, day) VALUES (?, ?, ?)', [$scope, $subjectId, $day]) === 1) {
            // the first row of a day: forget the old days
            $this->sql->run('DELETE FROM rmm_daily_budget WHERE day < ?', [gmdate('Y-m-d', $this->sql->time() - self::KEEP_DAYS * 86400)]);
        }
    }
}
