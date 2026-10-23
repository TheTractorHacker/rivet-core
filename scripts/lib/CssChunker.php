<?php

declare(strict_types=1);

/**
 * Splits a stylesheet into top-level "chunks" without changing a byte.
 *
 * A chunk is everything from the end of the previous chunk (so: the blank lines and comments that lead into a rule) up to and
 * including the closing brace of one top-level rule or at-rule, or the semicolon of a statement at-rule (@import, @charset).
 * Whatever trails the last rule (usually a comment) is returned as a final chunk with no selector. implode('', chunks) is always
 * the input, which is what makes a split provable.
 *
 * It understands comments, quoted strings, url(...) and nested braces. It is not a full CSS parser; it only needs to find
 * top-level boundaries, and the callers verify the round trip.
 */
final class CssChunker
{
    /**
     * @return list<array{text:string, prelude:string, selector:string, body:string, start_line:int, end_line:int}>
     */
    public static function chunks(string $css): array
    {
        $len = strlen($css);
        $chunks = [];
        $chunkStart = 0;
        $depth = 0;
        $ruleStart = 0;      // offset where the current rule's own text (after leading comments) begins
        $sawText = false;    // a non-comment, non-space character since the chunk began
        $bodyOpen = -1;
        $i = 0;
        while ($i < $len) {
            $c = $css[$i];
            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $i++;
                while ($i < $len && $css[$i] !== $c) {
                    $i += $css[$i] === '\\' ? 2 : 1;
                }
                $i++;
                if ($depth === 0) {
                    $sawText = true;
                }
                continue;
            }
            if ($depth === 0 && !$sawText && !ctype_space($c)) {
                $sawText = true;
                $ruleStart = $i;
            }
            if ($c === '{') {
                if ($depth === 0) {
                    $bodyOpen = $i;
                }
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth < 0) {
                    throw new RuntimeException('Unbalanced "}" at byte ' . $i);
                }
                if ($depth === 0) {
                    $chunks[] = self::make($css, $chunkStart, $i + 1, $ruleStart, $bodyOpen);
                    $chunkStart = $i + 1;
                    $sawText = false;
                }
            } elseif ($c === ';' && $depth === 0 && $sawText) {
                $chunks[] = self::make($css, $chunkStart, $i + 1, $ruleStart, -1);
                $chunkStart = $i + 1;
                $sawText = false;
            }
            $i++;
        }
        if ($depth !== 0) {
            throw new RuntimeException('Unbalanced "{" at end of file');
        }
        if ($chunkStart < $len) {
            $chunks[] = self::make($css, $chunkStart, $len, $len, -1);
        }
        return $chunks;
    }

    /** @return array{text:string, prelude:string, selector:string, body:string, start_line:int, end_line:int} */
    private static function make(string $css, int $from, int $to, int $ruleStart, int $bodyOpen): array
    {
        $text = substr($css, $from, $to - $from);
        $selector = $bodyOpen >= 0 ? trim(substr($css, $ruleStart, $bodyOpen - $ruleStart)) : trim(substr($css, $ruleStart, $to - $ruleStart));
        $body = $bodyOpen >= 0 ? substr($css, $bodyOpen + 1, $to - $bodyOpen - 2) : '';
        return [
            'text' => $text,
            'prelude' => substr($css, $from, max(0, $ruleStart - $from)),
            'selector' => $selector,
            'body' => $body,
            'start_line' => substr_count($css, "\n", 0, $from) + 1,
            'end_line' => substr_count($css, "\n", 0, $to) + 1,
        ];
    }
}
