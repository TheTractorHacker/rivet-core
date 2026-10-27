<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\SettingsInterface;
use RivetCore\Support\ArraySettings;
use RivetCore\Testing\SettingsConformanceTestCase;

/** Core's own ArraySettings, with falsy and typed values. */
final class ArraySettingsKitTest extends SettingsConformanceTestCase
{
    private const VALUES = ['a.zero' => 0, 'a.false' => false, 'a.empty' => '', 'a.str' => 'x', 'a.list' => [1, 2], 'a.string_zero' => '0'];

    protected function settings(): SettingsInterface
    {
        return new ArraySettings(self::VALUES);
    }

    protected function seededSettings(): array
    {
        return self::VALUES;
    }
}
