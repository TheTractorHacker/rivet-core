<?php

declare(strict_types=1);

/**
 * Just enough CSS cascade knowledge to answer one question about moving a rule later in the cascade:
 * can the move change which declaration wins?
 *
 * Source order only decides between two declarations of the same property, on the same element, with equal specificity and equal
 * importance. So a moved rule M is reported against a rule S that used to come after it only when S and M have a selector pair with
 * equal specificity, a plausible common subject element, and a common property (shorthand and longhand count as the same family).
 * Everything else is provably unaffected by the move. The check errs towards reporting (media conditions are ignored, selector
 * ancestors are ignored), never towards silence.
 */
final class CssCascade
{
    /**
     * Flattens a chunk (a rule, or an @media / @supports block of rules) into [selector, properties, important] entries.
     *
     * @param array{selector:string, body:string} $chunk
     * @return list<array{selector:string, props:list<string>, important:bool}>
     */
    public static function rules(array $chunk): array
    {
        $sel = $chunk['selector'];
        if ($sel === '' || $sel[0] !== '@') {
            return [['selector' => $sel, 'props' => self::props($chunk['body']), 'important' => str_contains($chunk['body'], '!important')]];
        }
        if (!preg_match('/^@(media|supports|layer)\b/i', $sel)) {
            return [];   // @font-face, @keyframes, @charset: not selector rules (the caller compares names separately)
        }
        $out = [];
        foreach (CssChunker::chunks($chunk['body']) as $inner) {
            foreach (self::rules($inner) as $r) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** @return list<string> property families declared in a declaration block (custom properties keep their full name) */
    public static function props(string $body): array
    {
        $body = (string) preg_replace('~/\*.*?\*/~s', '', $body);
        $names = [];
        // Declarations end at ';' outside parentheses/strings; a coarse split on ';' is enough because only the name before ':' is read.
        foreach (explode(';', $body) as $decl) {
            if (preg_match('/^\s*(--[\w-]+|-?[a-z][\w-]*)\s*:/i', $decl, $m)) {
                $names[self::family($m[1])] = true;
            }
        }
        return array_keys($names);
    }

    private static function family(string $prop): string
    {
        if (str_starts_with($prop, '--')) {
            return $prop;
        }
        $prop = (string) preg_replace('/^-(webkit|moz|ms|o)-/', '', strtolower($prop));
        return explode('-', $prop)[0];
    }

    /** @return list<string> the comma-separated members of a selector list, ignoring commas inside (), [] */
    public static function members(string $list): array
    {
        $out = [];
        $depth = 0;
        $cur = '';
        for ($i = 0, $n = strlen($list); $i < $n; $i++) {
            $c = $list[$i];
            if ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
            }
            if ($c === ',' && $depth === 0) {
                $out[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $out[] = trim($cur);
        return array_values(array_filter($out, static fn(string $s): bool => $s !== ''));
    }

    /** @return array{0:int,1:int,2:int} (ids, classes/attributes/pseudo-classes, types/pseudo-elements) */
    public static function specificity(string $sel): array
    {
        $sel = (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '""', $sel);
        $a = $b = $c = 0;
        // :where() contributes nothing; :is/:not/:has/:matches take their most specific argument.
        $sel = (string) preg_replace('/:where\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/', '', $sel);
        while (preg_match('/:(?:is|not|has|matches)\(((?:[^()]|\((?:[^()]|\([^()]*\))*\))*)\)/', $sel, $m, PREG_OFFSET_CAPTURE)) {
            $best = [0, 0, 0];
            foreach (self::members($m[1][0]) as $arg) {
                $s = self::specificity($arg);
                if ($s > $best) {
                    $best = $s;
                }
            }
            $a += $best[0];
            $b += $best[1];
            $c += $best[2];
            $sel = substr($sel, 0, $m[0][1]) . substr($sel, $m[0][1] + strlen($m[0][0]));
        }
        $sel = (string) preg_replace('/:(?:nth-[a-z-]+|lang|dir)\([^)]*\)/', ':x', $sel, -1, $nth);
        $b += $nth;
        $a += preg_match_all('/#[\w-]+/', $sel);
        $b += preg_match_all('/\.[\w-]+|\[[^\]]*\]|(?<!:):(?!:)[\w-]+/', $sel);
        $c += preg_match_all('/::[\w-]+/', $sel);
        $bare = (string) preg_replace('/#[\w-]+|\.[\w-]+|\[[^\]]*\]|::?[\w-]+(?:\([^)]*\))?/', ' ', $sel);
        $c += preg_match_all('/(?:^|[\s>+~])\s*[a-zA-Z][\w-]*/', $bare);
        return [$a, $b, $c];
    }

    /** @return array{tag:string, classes:list<string>, ids:list<string>} the subject (last compound) of one selector */
    public static function subject(string $sel): array
    {
        $parts = preg_split('/\s*[>+~]\s*|\s+(?![^(\[]*[)\]])/', trim($sel)) ?: [];
        $last = (string) end($parts);
        preg_match_all('/\.([\w-]+)/', $last, $cm);
        preg_match_all('/#([\w-]+)/', $last, $im);
        $tag = preg_match('/^([a-zA-Z][\w-]*)/', $last, $tm) ? strtolower($tm[1]) : '';
        return ['tag' => $tag, 'classes' => $cm[1], 'ids' => $im[1]];
    }

    /** Could the two selectors match one element? (Subject only; true unless clearly impossible.) */
    public static function mayCoincide(string $x, string $y): bool
    {
        // A pseudo-element (::before) is never the element itself.
        $pa = preg_match('/::[\w-]+/', $x, $ma) ? $ma[0] : '';
        $pb = preg_match('/::[\w-]+/', $y, $mb) ? $mb[0] : '';
        if ($pa !== $pb) {
            return false;
        }
        $a = self::subject($x);
        $b = self::subject($y);
        if ($a['tag'] !== '' && $b['tag'] !== '' && $a['tag'] !== $b['tag']) {
            return false;
        }
        if ($a['ids'] !== [] && $b['ids'] !== [] && array_diff($a['ids'], $b['ids']) !== [] && array_diff($b['ids'], $a['ids']) !== []) {
            return false;
        }
        // Equal specificity and different required classes still coincide on an element carrying both; that only happens through
        // markup, so it is flagged only when one side's classes are contained in the other's (one rule is the general form of the other).
        return array_diff($a['classes'], $b['classes']) === [] || array_diff($b['classes'], $a['classes']) === [];
    }

    /**
     * Pairs of (moved rule, later rule) whose relative order could matter.
     *
     * @param list<array{selector:string, props:list<string>, important:bool}> $moved
     * @param list<array{selector:string, props:list<string>, important:bool}> $later
     * @return list<string> human-readable findings
     */
    public static function conflicts(array $moved, array $later): array
    {
        $found = [];
        foreach ($moved as $m) {
            foreach ($later as $l) {
                $shared = array_values(array_intersect($m['props'], $l['props']));
                if ($shared === [] || $m['important'] !== $l['important']) {
                    continue;
                }
                foreach (self::members($m['selector']) as $ms) {
                    foreach (self::members($l['selector']) as $ls) {
                        if (self::specificity($ms) === self::specificity($ls) && self::mayCoincide($ms, $ls)) {
                            $found[] = sprintf('%s  vs later  %s  [%s]', $ms, $ls, implode(',', $shared));
                        }
                    }
                }
            }
        }
        return array_values(array_unique($found));
    }
}
