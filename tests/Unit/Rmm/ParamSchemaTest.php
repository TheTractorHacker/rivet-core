<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Fields\CustomFieldService;
use RivetCore\Rmm\Fields\FieldPlaceholders;
use RivetCore\Rmm\Scripts\ParamSchema;

final class ParamSchemaTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private static function schema(): array
    {
        [$s, $err] = ParamSchema::normalizeSchema([
            ['name' => 'target', 'type' => 'string', 'required' => true, 'max_length' => 20],
            ['name' => 'count', 'type' => 'int', 'min' => 1, 'max' => 10, 'default' => 3],
            ['name' => 'force', 'type' => 'bool', 'default' => false],
            ['name' => 'mode', 'type' => 'choice', 'choices' => ['fast', 'slow']],
            ['name' => 'token', 'type' => 'secret'],
        ]);
        self::assertNull($err);

        return $s ?? [];
    }

    public function testNamesThatCouldShadowTheInterpreterAreRefused(): void
    {
        foreach (['path', 'Path', 'home', 'error', 'input', 'if', 'class', 'pid', 'ifs', 'rivet_x', '1abc', 'a-b', 'UPPER', str_repeat('a', 33), ''] as $n) {
            [$s, $err] = ParamSchema::normalizeSchema([['name' => $n, 'type' => 'string']]);
            $this->assertNull($s, $n);
            $this->assertNotNull($err, $n);
        }
        $this->assertTrue(ParamSchema::validName('server_name'));
    }

    public function testSchemaRules(): void
    {
        $this->assertSame([[], null], ParamSchema::normalizeSchema(null));
        $this->assertSame([[], null], ParamSchema::normalizeSchema([]));
        $this->assertNotNull(ParamSchema::normalizeSchema([['name' => 'a'], ['name' => 'a']])[1], 'duplicate');
        $this->assertNotNull(ParamSchema::normalizeSchema([['name' => 'a', 'type' => 'float']])[1]);
        $this->assertNotNull(ParamSchema::normalizeSchema([['name' => 'a', 'type' => 'choice']])[1], 'choices required');
        $this->assertNotNull(ParamSchema::normalizeSchema([['name' => 'a', 'type' => 'secret', 'default' => 'x']])[1], 'a secret has no default');
        $this->assertNotNull(ParamSchema::normalizeSchema([['name' => 'a', 'type' => 'int', 'min' => 5, 'max' => 1]])[1]);
        $this->assertNotNull(ParamSchema::normalizeSchema([['name' => 'a', 'type' => 'string', 'max_length' => 5000]])[1]);
        $this->assertNotNull(ParamSchema::normalizeSchema([['name' => 'a', 'type' => 'int', 'default' => 'abc']])[1]);
        $many = [];
        for ($i = 0; $i <= ParamSchema::MAX_PARAMS; ++$i) {
            $many[] = ['name' => "p$i"];
        }
        $this->assertNotNull(ParamSchema::normalizeSchema($many)[1]);
        $this->assertNotNull(ParamSchema::normalizeSchema('not json')[1]);
    }

    public function testValidationAppliesDefaultsAndTypes(): void
    {
        [$v, $secrets, $err] = ParamSchema::validate(self::schema(), ['target' => 'web01', 'mode' => 'fast', 'token' => 's3cr3t']);
        $this->assertNull($err);
        $this->assertSame(['target' => 'web01', 'count' => 3, 'force' => false, 'mode' => 'fast', 'token' => 's3cr3t'], $v);
        $this->assertSame(['token'], $secrets);
        [$v, , $err] = ParamSchema::validate(self::schema(), ['target' => 'a', 'count' => '7', 'force' => 'yes']);
        $this->assertNull($err);
        $this->assertSame([7, true], [$v['count'], $v['force']]);
    }

    public function testValidationErrors(): void
    {
        $s = self::schema();
        foreach ([
            [[], 'target is required'],
            [['target' => 'a', 'bogus' => 1], 'Unknown parameter bogus'],
            [['target' => str_repeat('x', 21)], 'longer than 20'],
            [['target' => 'a', 'count' => 11], 'outside'],
            [['target' => 'a', 'count' => 'many'], 'whole number'],
            [['target' => 'a', 'force' => 'maybe'], 'true or false'],
            [['target' => 'a', 'mode' => 'medium'], 'allowed choices'],
            [['target' => "a\0b"], 'must be text'],
        ] as [$in, $needle]) {
            [$v, , $err] = ParamSchema::validate($s, $in);
            $this->assertNull($v, json_encode($in) ?: '');
            $this->assertStringContainsString($needle, (string) $err);
        }
        // an unknown name is reported without echoing arbitrary input
        [, , $err] = ParamSchema::validate($s, ['target' => 'a', "x\ny;rm -rf" => 1]);
        $this->assertSame('Unknown parameter (invalid name).', $err);
    }

    public function testPlaceholders(): void
    {
        $this->assertSame(['a', 'b_2'], FieldPlaceholders::names('x {{field.a}} y {{ field.b_2 }} {{field.a}}'));
        $this->assertSame([], FieldPlaceholders::names('plain {{ nope }} {{field.}}'));
        $this->assertSame('mail', FieldPlaceholders::whole(' {{field.mail}} '));
        $this->assertNull(FieldPlaceholders::whole('x {{field.mail}}'));
        $this->assertSame('to bob at ', FieldPlaceholders::fill('to {{field.who}} at {{field.gone}}', ['who' => 'bob']));
        $this->assertTrue(FieldPlaceholders::validName('smtp_host'));
        $this->assertFalse(FieldPlaceholders::validName('Smtp'));
    }

    public function testCustomFieldValueTypes(): void
    {
        $this->assertSame('12.5', CustomFieldService::cleanValue('number', '12.50'));
        $this->assertSame('100', CustomFieldService::cleanValue('number', 100));
        $this->assertSame('-3', CustomFieldService::cleanValue('number', ' -3 '));
        $this->assertSame('true', CustomFieldService::cleanValue('bool', 'Yes'));
        $this->assertSame('false', CustomFieldService::cleanValue('bool', false));
        $this->assertSame('2026-02-28', CustomFieldService::cleanValue('date', '2026-02-28'));
        $this->assertSame('b', CustomFieldService::cleanValue('list', 'b', ['a', 'b']));
        $this->assertSame('free text', CustomFieldService::cleanValue('text', 'free text'));
        foreach ([['number', 'abc'], ['number', '1e5'], ['bool', 'maybe'], ['date', '2026-02-30'], ['date', '28/02/2026'], ['list', 'z'], ['text', "a\0b"], ['text', str_repeat('x', 2001)]] as [$t, $v]) {
            try {
                CustomFieldService::cleanValue($t, $v, ['a', 'b']);
                $this->fail("$t accepted " . var_export($v, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
