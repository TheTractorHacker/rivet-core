<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\SettingsInterface;

/**
 * Conformance kit for {@see SettingsInterface}: an unknown key reads as the caller's default (unchanged, no coercion),
 * keys that make no sense never throw, and a value that IS stored is returned even when it is falsy ('0', '', 0).
 *
 * @api
 */
abstract class SettingsConformanceTestCase extends TestCase
{
    /** The adapter under test, backed by whatever store the edition uses. */
    abstract protected function settings(): SettingsInterface;

    /**
     * Keys the test fixture has stored, with the value they must read back. Include falsy values ('0', '') if the
     * store can hold them. Values compare as strings when scalar (a SQL-backed store returns strings). Default: none.
     *
     * @return array<string,mixed>
     */
    protected function seededSettings(): array
    {
        return [];
    }

    /** @return array<string,array{mixed}> */
    public static function defaultValues(): array
    {
        return [
            'null' => [null],
            'string' => ['fallback'],
            'zero' => [0],
            'int' => [42],
            'float' => [1.5],
            'false' => [false],
            'true' => [true],
            'empty string' => [''],
            'empty array' => [[]],
            'array' => [['a' => 1]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('defaultValues')]
    public function testUnknownKeyReturnsTheDefaultUnchanged(mixed $default): void
    {
        $this->assertSame($default, $this->settings()->get('conformance.unknown.' . bin2hex(random_bytes(4)), $default));
    }

    public function testDefaultDefaultsToNull(): void
    {
        $this->assertNull($this->settings()->get('conformance.unknown.' . bin2hex(random_bytes(4))));
    }

    /** @return array<string,array{string}> */
    public static function oddKeys(): array
    {
        return [
            'empty' => [''],
            'space' => ['a b'],
            'trailing space' => ['core.audit.enabled '],
            'sql' => ["x'; DROP TABLE settings; --"],
            'backtick' => ['`config_name`'],
            'unicode' => ["clé.été.\u{1F600}"],
            'long' => [str_repeat('k', 5000)],
            'dots' => ['...'],
            'null byte' => ["a\0b"],
            'upper' => ['CORE.AUDIT.ENABLED'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('oddKeys')]
    public function testOddKeysNeverThrowAndReadAsDefault(string $key): void
    {
        $this->assertSame('dflt', $this->settings()->get($key, 'dflt'), 'a key that is not stored must read as the default');
    }

    public function testReadsAreRepeatable(): void
    {
        $s = $this->settings();
        $this->assertSame($s->get('conformance.unknown.x', 'd'), $s->get('conformance.unknown.x', 'd'));
        foreach ($this->seededSettings() as $key => $_) {
            $this->assertSame($s->get($key, 'd'), $s->get($key, 'd'));
        }
    }

    public function testStoredValuesAreReturnedEvenWhenFalsy(): void
    {
        $seeded = $this->seededSettings();
        if ($seeded === []) {
            $this->markTestSkipped('seededSettings() is empty: stored-value behaviour not exercised.');
        }
        $sentinel = new \stdClass();
        foreach ($seeded as $key => $expected) {
            $actual = $this->settings()->get($key, $sentinel);
            $this->assertNotSame($sentinel, $actual, "stored key '$key' read as the default");
            if (is_scalar($expected) || $expected === null) {
                $this->assertSame((string) $expected, is_scalar($actual) ? (string) $actual : '', "value of '$key'");
            } else {
                $this->assertEquals($expected, $actual, "value of '$key'");
            }
        }
    }

    public function testStoredValueDoesNotDependOnTheDefaultPassedIn(): void
    {
        foreach ($this->seededSettings() as $key => $_) {
            $this->assertSame($this->settings()->get($key, 'one'), $this->settings()->get($key, 'two'), "'$key' changed with the default");
        }
        $this->addToAssertionCount(1);
    }
}
