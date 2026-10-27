<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\SettingsInterface;
use RivetCore\Testing\SettingsConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedSettings;

/** The kit against a correct SQL-like settings store (and the harness target for the settings mutants). */
final class SettingsKitTest extends SettingsConformanceTestCase
{
    use Flaw;

    protected function settings(): SettingsInterface
    {
        return new FlawedSettings(self::$flaw);
    }

    protected function seededSettings(): array
    {
        return FlawedSettings::SEEDED;
    }
}
