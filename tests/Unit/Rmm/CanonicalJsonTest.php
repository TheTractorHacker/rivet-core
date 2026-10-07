<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Crypto\CanonicalJson;

final class CanonicalJsonTest extends TestCase
{
    public function testCanonicalOnlyVectors(): void
    {
        $vec = RmmVectors::load('agent_job_signing_vectors.json');
        $this->assertCount(4, $vec['canonical_only']);
        foreach ($vec['canonical_only'] as $c) {
            $this->assertSame($c['canonical'], CanonicalJson::encode(json_decode($c['input'])), $c['name']);
        }
    }

    public function testRules(): void
    {
        $this->assertSame('{"B":3,"a":2,"aa":5,"b":1,"é":4}', CanonicalJson::encode(json_decode('{"b":1,"a":2,"B":3,"é":4,"aa":5}')));
        $this->assertSame('{"a":[],"o":{}}', CanonicalJson::encode(json_decode('{"o":{},"a":[]}')));
        $this->assertSame('"\\u0000\\u001f' . "\x7f" . "\u{2028}" . '/<>&é"', CanonicalJson::encode("\0\x1f\x7f\u{2028}/<>&é"));
        $this->assertSame('"\\b\\f\\n\\r\\t\\"\\\\"', CanonicalJson::encode("\x08\x0c\n\r\t\"\\"));
        $this->assertSame('[0,-5,9007199254740991,true,false,null]', CanonicalJson::encode(json_decode('[0,-5,9007199254740991,true,false,null]')));
        $this->assertSame('{"1":"x","a":null}', CanonicalJson::encode(['a' => null, 1 => 'x']));
        $this->assertSame('[]', CanonicalJson::encode([]));
        $this->assertSame('{}', CanonicalJson::encode(new \stdClass()));
    }

    /** Regression: a null member must not borrow the value of a numeric key (the RivetIT lookup fell back to $v[(int)$k]). */
    public function testNullMemberDoesNotBorrowNumericKeyValue(): void
    {
        $this->assertSame('{"0":5,"a":null}', CanonicalJson::encode(json_decode('{"a":null,"0":5}')));
    }

    public function testRefusals(): void
    {
        foreach ([1.5, 0.0, ['a' => 1.0], "bad\xff", new \DateTimeImmutable('2020-01-01'), [new \stdClass(), fopen('php://memory', 'r')]] as $bad) {
            try {
                CanonicalJson::encode($bad);
                $this->fail('expected refusal');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testToObjectKeepsListsAndObjects(): void
    {
        $o = CanonicalJson::toObject(['a' => [], 'b' => ['x' => 1], 'c' => [1, ['y' => 2]]]);
        $this->assertSame('{"a":[],"b":{"x":1},"c":[1,{"y":2}]}', CanonicalJson::encode($o));
        $this->assertInstanceOf(\stdClass::class, $o);
        $this->assertSame('{"p":{}}', CanonicalJson::encode(CanonicalJson::toObject(['p' => new \stdClass()])));
    }

    /** Property: canonical output equals an independent reference writer, is stable under decode/re-encode, and round-trips. */
    public function testRandomStructuresMatchReferenceAndRoundTrip(): void
    {
        mt_srand(20261006);
        for ($i = 0; $i < 400; $i++) {
            $value = $this->randomValue(4);
            $canonical = CanonicalJson::encode($value);
            $this->assertSame($this->reference($value), $canonical, "case $i");
            $decoded = json_decode($canonical);
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), "case $i is valid JSON");
            $this->assertSame($canonical, CanonicalJson::encode($decoded), "case $i re-encodes identically");
            $this->assertEquals($this->normalise($value), $decoded, "case $i round-trips");
            // Shuffling the member order of every object never changes the output.
            $this->assertSame($canonical, CanonicalJson::encode($this->shuffled($value)), "case $i order independent");
            $this->assertStringNotContainsString("\n", $canonical);
        }
    }

    private function randomString(): string
    {
        $pool = ['a', 'b', 'Z', '0', ' ', '"', '\\', '/', '<', '&', "\n", "\t", "\x00", "\x1f", "\x7f", "\x08", "\x0c", "\r", 'é', 'ü', '日', '😀', "\u{2028}", "\u{2029}", "\u{FFFD}"];
        $s = '';
        for ($n = mt_rand(0, 8); $n > 0; $n--) {
            $s .= $pool[mt_rand(0, count($pool) - 1)];
        }

        return $s;
    }

    private function randomValue(int $depth): mixed
    {
        $kind = mt_rand(0, $depth > 0 ? 7 : 4);
        switch ($kind) {
            case 0:
                return null;
            case 1:
                return (bool) mt_rand(0, 1);
            case 2:
                return mt_rand(-1000000, 1000000);
            case 3:
                return mt_rand(0, 1) ? PHP_INT_MAX - mt_rand(0, 5) : -mt_rand(0, 5);
            case 4:
                return $this->randomString();
            case 5:
            case 6:
                $list = [];
                for ($n = mt_rand(0, 4); $n > 0; $n--) {
                    $list[] = $this->randomValue($depth - 1);
                }

                return $list;
            default:
                $o = new \stdClass();
                for ($n = mt_rand(0, 5); $n > 0; $n--) {
                    $k = $this->randomString();
                    $o->{str_starts_with($k, "\0") ? 'k' . $k : $k} = $this->randomValue($depth - 1);
                }

                return $o;
        }
    }

    /** Independent reference: json_encode flags are NOT used for strings; every character is classified by hand. */
    private function reference(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_string($v)) {
            $out = '"';
            foreach (str_split($v) as $ch) {
                $o = ord($ch);
                $out .= match (true) {
                    $ch === '"' => '\\"',
                    $ch === '\\' => '\\\\',
                    $o === 8 => '\\b',
                    $o === 12 => '\\f',
                    $o === 10 => '\\n',
                    $o === 13 => '\\r',
                    $o === 9 => '\\t',
                    $o < 0x20 => '\\u00' . str_pad(dechex($o), 2, '0', STR_PAD_LEFT),
                    default => $ch,
                };
            }

            return $out . '"';
        }
        if (is_array($v)) {
            return '[' . implode(',', array_map(fn (mixed $x): string => $this->reference($x), $v)) . ']';
        }
        $members = [];
        foreach ((array) $v as $k => $x) {
            $members[] = [(string) $k, $x];
        }
        usort($members, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return '{' . implode(',', array_map(fn (array $m): string => $this->reference($m[0]) . ':' . $this->reference($m[1]), $members)) . '}';
    }

    private function normalise(mixed $v): mixed
    {
        return json_decode((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function shuffled(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map(fn (mixed $x): mixed => $this->shuffled($x), $v);
        }
        if ($v instanceof \stdClass) {
            $pairs = (array) $v;
            $keys = array_keys($pairs);
            shuffle($keys);
            $o = new \stdClass();
            foreach ($keys as $k) {
                $o->{(string) $k} = $this->shuffled($pairs[$k]);
            }

            return $o;
        }

        return $v;
    }
}
