<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\SettingsInterface;
use RivetCore\Support\ArraySettings;

/** Reference run: the in-memory settings adapter shipped in src/Support passes the kit. */
final class InMemorySettingsConformanceTest extends SettingsConformanceTestCase
{
    protected function settingsWith(array $values): SettingsInterface
    {
        return new ArraySettings($values);
    }
}
