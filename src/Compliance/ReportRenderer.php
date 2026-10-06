<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

/**
 * Turns an Assessment into an auditor-friendly CSV or a self-contained printable HTML page (no scripts, no external assets).
 * Every value is escaped; CSV cells that could be read as spreadsheet formulas are neutralised.
 *
 * @api
 */
final class ReportRenderer
{
    public const DISCLAIMER = 'This report is a point-in-time self-assessment generated from this system\'s own settings and records. It is not a certification, attestation or audit opinion, and control references are indicative; confirm them against the current text of each standard. Items marked manual rely on a person\'s recorded review.';

    private const MANUAL_LABELS = ['current' => 'Current', 'due_soon' => 'Due soon', 'overdue' => 'Overdue', 'never' => 'Never reviewed'];

    /** @return list<list<string>> a "Responsible" column is added only when at least one item has a responsible party */
    public function rows(Assessment $a, ?string $framework = null): array
    {
        $withParty = self::anyResponsible($a);
        $head = ['Type', 'Category', 'Item', 'Status', 'Summary / last review', 'Reviewer', 'Next due', 'Controls'];
        $rows = [$withParty ? array_merge($head, ['Responsible']) : $head];
        foreach ($a->automatic as $r) {
            if (!self::inFramework($r['controls'], $framework)) {
                continue;
            }
            $row = ['Automatic', (string) $r['category'], (string) $r['title'], (string) $r['status_label'], trim($r['summary'] . ' ' . ($r['detail'] ?? '')), '', '', self::controlsText($r['controls'], $framework)];
            $rows[] = $withParty ? array_merge($row, [(string) ($r['responsible'] ?? 'Internal')]) : $row;
        }
        foreach ($a->manual as $r) {
            if (!self::inFramework($r['controls'], $framework)) {
                continue;
            }
            $row = ['Manual', (string) $r['category'], (string) $r['title'], self::MANUAL_LABELS[$r['state']] ?? (string) $r['state'], $r['reviewed_on'] ? 'Reviewed ' . $r['reviewed_on'] . ($r['note'] ? ': ' . $r['note'] : '') : 'No review recorded', (string) ($r['reviewer_name'] ?? ''), (string) ($r['next_due_on'] ?? ''), self::controlsText($r['controls'], $framework)];
            $rows[] = $withParty ? array_merge($row, [(string) ($r['responsible'] ?? 'Internal')]) : $row;
        }

        return $rows;
    }

    private static function anyResponsible(Assessment $a): bool
    {
        foreach ([$a->automatic, $a->manual] as $list) {
            foreach ($list as $r) {
                if (!empty($r['responsible'])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function csv(Assessment $a, ?string $framework = null): string
    {
        $out = "\xEF\xBB\xBF";
        $meta = [['Compliance status report'], ['Generated', $a->generatedAt->format('Y-m-d H:i:s')], ['Framework', $framework !== null && Framework::isValid($framework) ? Framework::LABELS[$framework] : 'All frameworks'], ['Note', self::DISCLAIMER], []];
        foreach (array_merge($meta, $this->rows($a, $framework)) as $row) {
            $out .= implode(',', array_map([self::class, 'csvCell'], $row)) . "\r\n";
        }

        return $out;
    }

    public static function csvCell(string $v): string
    {
        $v = str_replace(["\r\n", "\r", "\n"], ' ', $v);
        if ($v !== '' && strpbrk($v[0], "=+-@\t") !== false) {
            $v = "'" . $v;
        }

        return '"' . str_replace('"', '""', $v) . '"';
    }

    public function html(Assessment $a, string $orgName, ?string $framework = null, ?string $appVersion = null): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fw = $framework !== null && Framework::isValid($framework) ? $framework : null;
        $sum = $a->summaries[$fw ?? 'all'] ?? [];
        $score = isset($sum['score']) ? $sum['score'] . '%' : 'n/a';
        $h = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>Compliance status report</title><style>body{font:14px/1.45 system-ui,sans-serif;color:#111;margin:24px auto;max-width:980px;padding:0 16px}h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:24px 0 8px;border-bottom:1px solid #ccc;padding-bottom:4px}'
            . 'table{border-collapse:collapse;width:100%;font-size:13px}th,td{border:1px solid #ccc;padding:5px 7px;text-align:left;vertical-align:top}th{background:#f2f2f2}.s-pass,.s-current{color:#116329}.s-warn,.s-due_soon{color:#8a5a00}.s-fail,.s-overdue,.s-never,.s-error{color:#a40e26}.s-na{color:#555}'
            . '.note{background:#f7f7f7;border:1px solid #ddd;padding:8px 10px;font-size:12px}.score{font-size:28px;font-weight:600}@media print{body{margin:0}tr{break-inside:avoid}}</style></head><body>';
        $h .= '<h1>Compliance status report</h1><div>' . $e($orgName) . ' &middot; ' . $e($fw !== null ? Framework::LABELS[$fw] : 'All frameworks') . ' &middot; generated ' . $e($a->generatedAt->format('Y-m-d H:i')) . ($appVersion ? ' &middot; version ' . $e($appVersion) : '') . '</div>';
        $h .= '<p class="note">' . $e(self::DISCLAIMER) . '</p>';
        $h .= '<h2>Summary</h2><div class="score">' . $e($score) . '</div><div>Share of listed controls with evidence (pass = 1, needs attention = 0.5).</div>';
        $h .= '<table><tr><th>Framework</th><th>Score</th><th>Pass</th><th>Attention</th><th>Fail / error</th><th>Manual current</th><th>Manual overdue / never</th></tr>';
        foreach ($a->summaries as $key => $s) {
            if ($fw !== null && $key !== $fw) {
                continue;
            }
            $h .= '<tr><td>' . $e((string) $s['label']) . '</td><td>' . $e(isset($s['score']) ? $s['score'] . '%' : 'n/a') . '</td><td>' . (int) $s['pass'] . '</td><td>' . (int) $s['warn'] . '</td><td>' . ((int) $s['fail'] + (int) $s['error']) . '</td><td>' . (int) $s['manual_current'] . '</td><td>' . ((int) $s['manual_overdue'] + (int) $s['manual_never']) . '</td></tr>';
        }
        $wp = self::anyResponsible($a);
        $h .= '</table><h2>Automatic checks</h2><table><tr><th>Category</th><th>Check</th><th>Status</th><th>Finding</th><th>Controls</th>' . ($wp ? '<th>Responsible</th>' : '') . '</tr>';
        foreach ($a->automatic as $r) {
            if (!self::inFramework($r['controls'], $fw)) {
                continue;
            }
            $h .= '<tr><td>' . $e((string) $r['category']) . '</td><td>' . $e((string) $r['title']) . '<br><small>' . $e((string) $r['why']) . '</small></td><td class="s-' . $e((string) $r['status']) . '">' . $e((string) $r['status_label']) . '</td><td>' . $e((string) $r['summary']) . ($r['detail'] ? '<br><small>' . $e((string) $r['detail']) . '</small>' : '') . '</td><td>' . $e(self::controlsText($r['controls'], $fw)) . '</td>' . ($wp ? '<td>' . $e((string) ($r['responsible'] ?? 'Internal')) . '</td>' : '') . '</tr>';
        }
        $h .= '</table><h2>Manual checklist</h2><table><tr><th>Category</th><th>Item</th><th>State</th><th>Last review</th><th>Reviewer</th><th>Next due</th><th>Controls</th>' . ($wp ? '<th>Responsible</th>' : '') . '</tr>';
        foreach ($a->manual as $r) {
            if (!self::inFramework($r['controls'], $fw)) {
                continue;
            }
            $h .= '<tr><td>' . $e((string) $r['category']) . '</td><td>' . $e((string) $r['title']) . '</td><td class="s-' . $e((string) $r['state']) . '">' . $e(self::MANUAL_LABELS[$r['state']] ?? (string) $r['state']) . '</td><td>' . $e((string) ($r['reviewed_on'] ?? 'Never')) . ($r['note'] ? '<br><small>' . $e((string) $r['note']) . '</small>' : '') . '</td><td>' . $e((string) ($r['reviewer_name'] ?? '')) . '</td><td>' . $e((string) ($r['next_due_on'] ?? '')) . '</td><td>' . $e(self::controlsText($r['controls'], $fw)) . '</td>' . ($wp ? '<td>' . $e((string) ($r['responsible'] ?? 'Internal')) . '</td>' : '') . '</tr>';
        }

        return $h . '</table></body></html>';
    }

    /** @param array<string,list<string>> $controls */
    private static function inFramework(array $controls, ?string $framework): bool
    {
        return $framework === null || !Framework::isValid($framework) || !empty($controls[$framework]);
    }

    /** @param array<string,list<string>> $controls */
    private static function controlsText(array $controls, ?string $framework): string
    {
        $parts = [];
        foreach ($controls as $fw => $refs) {
            if ($framework !== null && Framework::isValid($framework) && $fw !== $framework) {
                continue;
            }
            $parts[] = (Framework::LABELS[$fw] ?? $fw) . ': ' . implode(', ', $refs);
        }

        return implode('; ', $parts);
    }
}
