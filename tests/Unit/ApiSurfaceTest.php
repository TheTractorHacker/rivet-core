<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** docs/api-surface.md is generated; a change to the public API must show up as a diff of it in review. */
final class ApiSurfaceTest extends TestCase
{
    public function testApiSurfaceDocIsNotStale(): void
    {
        $root = dirname(__DIR__, 2);
        $generated = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/api-surface.php') . ' 2>&1');
        self::assertSame(
            $generated,
            (string) file_get_contents($root . '/docs/api-surface.md'),
            'docs/api-surface.md is stale: run `php scripts/api-surface.php > docs/api-surface.md` and review the diff'
        );
    }
}
