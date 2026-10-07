<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\RmmModuleStateInterface;

/**
 * Conformance kit for {@see RmmModuleStateInterface}.
 *
 * Checks: editionAllows() returns a bool, is repeatable and never throws; stateDirectory() is null or the path of an existing
 * writable directory (an absolute path, no NUL byte), and is stable between calls.
 *
 * @api
 */
abstract class RmmModuleStateConformanceTestCase extends TestCase
{
    abstract protected function state(): RmmModuleStateInterface;

    public function testEditionAllowsIsARepeatableBool(): void
    {
        $state = $this->state();
        $first = $state->editionAllows();
        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame($first, $state->editionAllows());
        }
    }

    public function testStateDirectoryIsNullOrAnExistingWritableDirectory(): void
    {
        $state = $this->state();
        $dir = $state->stateDirectory();
        if ($dir === null) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertStringStartsWith('/', $dir, 'absolute path');
        $this->assertStringNotContainsString("\0", $dir);
        $this->assertDirectoryExists($dir);
        $this->assertDirectoryIsWritable($dir);
        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame($dir, $state->stateDirectory());
        }
    }
}
