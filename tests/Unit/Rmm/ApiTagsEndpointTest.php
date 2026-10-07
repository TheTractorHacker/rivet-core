<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;

/**
 * Every RMM type declares its API status: the contracts, value objects, HTTP objects, protocol constants and conformance cases
 * are @api and listed in docs/api-surface.md; migrations, the schema helper and the in-memory reference adapters are @internal
 * and must not be listed.
 */
final class ApiTagsEndpointTest extends TestCase
{
    private const INTERNAL_PREFIXES = ['RivetCore\\Rmm\\Migration\\', 'RivetCore\\Testing\\InMemory'];

    /** @return list<class-string> */
    private function rmmTypes(): array
    {
        $src = (string) realpath(dirname(__DIR__, 3) . '/src');
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $fqcn = 'RivetCore\\' . str_replace(['/', '.php'], ['\\', ''], substr((string) $file->getRealPath(), strlen($src) + 1));
            $isRmm = str_starts_with($fqcn, 'RivetCore\\Rmm\\')
                || (str_starts_with($fqcn, 'RivetCore\\Testing\\') && preg_match('/\\\\(InMemoryRmm|Rmm\w+ConformanceTestCase|SecretBox\w*|InMemorySecretBox)/', $fqcn) === 1);
            if ($isRmm) {
                $out[] = $fqcn;
            }
        }
        sort($out);
        /** @var list<class-string> $out */
        return $out;
    }

    private function isInternalByDesign(string $fqcn): bool
    {
        foreach (self::INTERNAL_PREFIXES as $p) {
            if (str_starts_with($fqcn, $p)) {
                return true;
            }
        }

        return $fqcn === 'RivetCore\\Testing\\InMemorySecretBox';
    }

    public function testEveryRmmTypeHasExactlyTheExpectedTag(): void
    {
        $types = $this->rmmTypes();
        $this->assertGreaterThan(25, count($types));
        $problems = [];
        foreach ($types as $fqcn) {
            $doc = (string) (new \ReflectionClass($fqcn))->getDocComment();
            $api = preg_match('/@api\b/', $doc) === 1;
            $internal = preg_match('/@internal\b/', $doc) === 1;
            $want = $this->isInternalByDesign($fqcn) ? 'internal' : 'api';
            if ($api === $internal || ($want === 'api') !== $api) {
                $problems[] = "$fqcn should be @$want";
            }
        }
        $this->assertSame([], $problems);
    }

    public function testTheApiSurfaceDocListsTheApiTypesAndNoInternalOne(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/api-surface.md');
        foreach ($this->rmmTypes() as $fqcn) {
            $listed = str_contains($doc, "## `$fqcn`");
            $this->assertSame(!$this->isInternalByDesign($fqcn), $listed, $fqcn . ($listed ? ' must not be' : ' must be') . ' in docs/api-surface.md');
        }
    }
}
