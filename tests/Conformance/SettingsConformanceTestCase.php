<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\SettingsInterface;

/**
 * Behaviour every SettingsInterface adapter must have. Extend it and implement settingsWith().
 */
abstract class SettingsConformanceTestCase extends TestCase
{
    /** A fresh adapter whose store holds exactly $values (key => value). */
    abstract protected function settingsWith(array $values): SettingsInterface;

    public function testMissingKeyReturnsNullOrTheGivenDefault(): void
    {
        $s = $this->settingsWith([]);
        $this->assertNull($s->get('rivetcore_conformance_missing'));
        $this->assertSame('fallback', $s->get('rivetcore_conformance_missing', 'fallback'));
        $this->assertSame(0, $s->get('rivetcore_conformance_missing', 0), 'a falsy default must come back unchanged');
        $this->assertFalse($s->get('rivetcore_conformance_missing', false));
    }

    public function testStoredValueIsReturnedInsteadOfTheDefault(): void
    {
        $s = $this->settingsWith(['rivetcore_conformance_key' => 'value']);
        $this->assertSame('value', $s->get('rivetcore_conformance_key', 'fallback'));
    }

    public function testAnEmptyStringIsAValueNotAMissingKey(): void
    {
        $s = $this->settingsWith(['rivetcore_conformance_empty' => '']);
        $this->assertSame('', $s->get('rivetcore_conformance_empty', 'fallback'));
    }

    public function testLookupDoesNotThrowOnOddKeys(): void
    {
        $s = $this->settingsWith([]);
        foreach (['', ' ', "k'; DROP TABLE settings; --", str_repeat('x', 300), "ключ"] as $key) {
            $this->assertSame('d', $s->get($key, 'd'), 'unexpected result for key ' . var_export($key, true));
        }
    }

    public function testRepeatedReadsAreStable(): void
    {
        $s = $this->settingsWith(['rivetcore_conformance_key' => 'v']);
        $this->assertSame($s->get('rivetcore_conformance_key'), $s->get('rivetcore_conformance_key'));
    }
}
