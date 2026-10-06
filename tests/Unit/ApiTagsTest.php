<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Every type under src/ declares whether it is public API (@api) or not (@internal), exactly one of them. */
final class ApiTagsTest extends TestCase
{
    public function testEveryTypeIsTaggedApiOrInternalExactlyOnce(): void
    {
        $src = (string) realpath(dirname(__DIR__, 2) . '/src');
        $seen = 0;
        $problems = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $fqcn = 'RivetCore\\' . str_replace(['/', '.php'], ['\\', ''], substr((string) $file->getRealPath(), strlen($src) + 1));
            if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn) && !enum_exists($fqcn)) {
                $problems[] = "$fqcn: no type with this name in {$file->getFilename()} (PSR-4 mismatch)";
                continue;
            }
            ++$seen;
            preg_match_all('/@(api|internal)\b/', (string) (new \ReflectionClass($fqcn))->getDocComment(), $m);
            $tags = array_values(array_unique($m[1]));
            if (count($tags) !== 1) {
                $problems[] = $fqcn . ': ' . ($tags === [] ? 'neither @api nor @internal' : 'both @api and @internal');
            }
        }
        self::assertGreaterThan(80, $seen, 'the scan found suspiciously few types');
        self::assertSame([], $problems, "Tag the class docblock with @api or @internal:\n" . implode("\n", $problems));
    }
}
