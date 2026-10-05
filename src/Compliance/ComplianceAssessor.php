<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

use RivetCore\Contracts\ClockInterface;

/**
 * Runs the automatic checks, works out each manual item's state from its latest recorded review, and scores every framework.
 *
 * Scoring: an item counts toward a framework when it is tagged to it. Pass is 1, "needs attention" is 0.5, fail or could-not-check
 * is 0; "not applicable" is left out. A manual item is 1 when current, 0.5 when its review is coming due, 0 when overdue or never
 * recorded. The score is the average as a percentage. It measures how many of the listed controls are evidenced, nothing more.
 */
final class ComplianceAssessor
{
    /**
     * @param list<CheckInterface> $checks
     * @param list<ManualItem> $manualItems
     */
    public function __construct(
        private array $checks,
        private array $manualItems,
        private AttestationProviderInterface $attestations,
        private ClockInterface $clock,
        /** @var array<string,string> responsibility assignments, key => party name (see ResponsibilityStore::names()) */
        private array $responsible = [],
    ) {
    }

    public function assess(): Assessment
    {
        $now = $this->clock->now();
        $automatic = [];
        foreach ($this->checks as $check) {
            try {
                $result = $check->run();
            } catch (\Throwable) {
                // Never leak an exception message into a report; the check just could not run.
                $result = CheckResult::error('This check could not run.');
            }
            $automatic[] = [
                'id' => $check->id(),
                'title' => $check->title(),
                'category' => $check->category(),
                'why' => $check->why(),
                'controls' => self::cleanControls(Nist171Map::apply($check->id(), $check->controls())),
                'status' => $result->status->value,
                'status_label' => $result->status->label(),
                'summary' => $result->summary,
                'detail' => $result->detail,
                'fix_path' => $result->fixPath,
                'metrics' => $result->metrics,
                'responsible' => ResponsibilityStore::resolve($this->responsible, $check->id(), $check->category()),
            ];
        }

        $latest = $this->attestations->latestPerItem();
        $manual = [];
        foreach ($this->manualItems as $item) {
            $manual[] = $this->manualState($item, $latest[$item->id] ?? null, $now);
        }

        return new Assessment($now, $automatic, $manual, self::summarize($automatic, $manual));
    }

    /** @param array<string,mixed>|null $last */
    private function manualState(ManualItem $item, ?array $last, \DateTimeImmutable $now): array
    {
        $state = 'never';
        $reviewedOn = $nextDue = null;
        if ($last !== null) {
            $reviewedOn = (string) $last['reviewed_on'];
            $due = $last['next_due_on'] ?? null;
            $nextDue = $due !== null && $due !== ''
                ? (string) $due
                : (new \DateTimeImmutable($reviewedOn))->modify('+' . $item->intervalDays . ' days')->format('Y-m-d');
            $today = $now->setTime(0, 0);
            $dueAt = new \DateTimeImmutable($nextDue);
            $window = min(30, intdiv($item->intervalDays, 4));
            $state = $today > $dueAt ? 'overdue' : ($today >= $dueAt->modify("-{$window} days") ? 'due_soon' : 'current');
        }

        return [
            'id' => $item->id,
            'title' => $item->title,
            'category' => $item->category,
            'why' => $item->why,
            'controls' => self::cleanControls(Nist171Map::apply($item->id, $item->controls)),
            'interval_days' => $item->intervalDays,
            'state' => $state,
            'reviewed_on' => $reviewedOn,
            'next_due_on' => $nextDue,
            'reviewer_name' => $last['reviewer_name'] ?? null,
            'note' => $last['note'] ?? null,
            'responsible' => ResponsibilityStore::resolve($this->responsible, $item->id, $item->category),
        ];
    }

    /**
     * @param array<string, list<string>> $controls
     * @return array<string, list<string>>
     */
    private static function cleanControls(array $controls): array
    {
        $out = [];
        foreach ($controls as $framework => $refs) {
            if (Framework::isValid((string) $framework) && $refs !== []) {
                $out[$framework] = array_values(array_map('strval', $refs));
            }
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $automatic
     * @param list<array<string,mixed>> $manual
     * @return array<string, array<string,mixed>>
     */
    public static function summarize(array $automatic, array $manual): array
    {
        $manualWeight = ['current' => 1.0, 'due_soon' => 0.5, 'overdue' => 0.0, 'never' => 0.0];
        $buckets = ['all' => null] + array_fill_keys(Framework::all(), null);
        $out = [];
        foreach (array_keys($buckets) as $key) {
            $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'na' => 0, 'error' => 0, 'manual_current' => 0, 'manual_due_soon' => 0, 'manual_overdue' => 0, 'manual_never' => 0];
            $sum = 0.0;
            $scored = 0;
            $items = 0;
            foreach ($automatic as $row) {
                if ($key !== 'all' && empty($row['controls'][$key])) {
                    continue;
                }
                $items++;
                $status = Status::from((string) $row['status']);
                $counts[$status->value]++;
                if (($w = $status->weight()) !== null) {
                    $sum += $w;
                    $scored++;
                }
            }
            foreach ($manual as $row) {
                if ($key !== 'all' && empty($row['controls'][$key])) {
                    continue;
                }
                $items++;
                $counts['manual_' . $row['state']]++;
                $sum += $manualWeight[$row['state']] ?? 0.0;
                $scored++;
            }
            $out[$key] = ['label' => $key === 'all' ? 'All frameworks' : Framework::LABELS[$key], 'items' => $items, 'score' => $scored > 0 ? round(100 * $sum / $scored, 1) : null] + $counts;
        }

        return $out;
    }
}
