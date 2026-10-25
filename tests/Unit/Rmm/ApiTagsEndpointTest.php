<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;

/**
 * Every RMM type declares its API status: the contracts, value objects, HTTP objects, protocol constants and conformance cases
 * are @api and listed in docs/api-surface.md; migrations, the schema helper, the in-memory reference adapters and the store implementations
 * (`Store\\MariaDb\\*`, `Store\\Postgres\\Postgres*Store` and the guards, the lazy delegates) are @internal and must not be listed.
 */
final class ApiTagsEndpointTest extends TestCase
{
    private const INTERNAL_PREFIXES = ['RivetCore\\Rmm\\Migration\\', 'RivetCore\\Testing\\InMemory', 'RivetCore\\Rmm\\Store\\MariaDb\\', 'RivetCore\\Rmm\\Store\\Lazy', 'RivetCore\\Rmm\\Store\\Postgres\\Guard\\Guarded'];
    /** Internal by design, one by one: the PostgreSQL store implementations and helpers (the port, the config, the adapter, the breaker, the migrator, the exceptions and the fault record are @api). */
    private const INTERNAL_CLASSES = [
        'RivetCore\\Rmm\\Store\\DeviceNames', 'RivetCore\\Rmm\\Support\\MetricMath', 'RivetCore\\Rmm\\Store\\Postgres\\Guard\\HotStoreGuard', 'RivetCore\\Rmm\\Store\\Postgres\\Pdo\\PdoErrorMapper',
        'RivetCore\\Rmm\\Store\\Postgres\\PostgresMetricStore', 'RivetCore\\Rmm\\Store\\Postgres\\PostgresCheckHistoryStore', 'RivetCore\\Rmm\\Store\\Postgres\\PostgresLogStore',
        'RivetCore\\Rmm\\Store\\Postgres\\PostgresChangeEventStore', 'RivetCore\\Rmm\\Store\\Postgres\\PostgresSoftwareHistoryStore', 'RivetCore\\Rmm\\Store\\Postgres\\PostgresPatchHistoryStore',
        'RivetCore\\Rmm\\Store\\Postgres\\PostgresDeploymentHistoryStore', 'RivetCore\\Rmm\\Store\\Postgres\\PostgresHypervisorHistoryStore', 'RivetCore\\Rmm\\Store\\Postgres\\PostgresStoreMaintenance',
        'RivetCore\\Rmm\\Store\\Postgres\\PostgresSchema', 'RivetCore\\Rmm\\Store\\Postgres\\PostgresSql', 'RivetCore\\Rmm\\Store\\Postgres\\PostgresRollupState',
        'RivetCore\\Rmm\\Store\\Postgres\\UnconfiguredPostgresConnection',
    ];

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

        return $fqcn === 'RivetCore\\Testing\\InMemorySecretBox' || in_array($fqcn, self::INTERNAL_CLASSES, true);
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
