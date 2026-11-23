<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every database connection the PHPUnit suite opens (directly, or through the doc samples and examples DocSamplesTest runs) honours RIVETCORE_TEST_DB_PORT.
 *
 * The doc-sample prelude, docs/examples/minimal-edition.php and docs/examples/ExampleAdapterConformanceTest.php passed host, user, password and name but no port,
 * so with a scratch database on another port (anything but CI's 3306) they connected to whatever listened on 3306 of that host, with the scratch credentials.
 * CI's database is on 3306, so only this scan catches a connection that forgets the port again.
 */
final class TestDatabasePortTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return list<string> the files the suite runs that may open a database connection */
    private static function files(): array
    {
        $files = [...(glob(self::ROOT . '/docs/examples/*.php') ?: []), self::ROOT . '/scripts/check-doc-samples.php'];
        foreach (['Unit', 'Integration', 'Conformance', 'Support', 'Fixtures'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/tests/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f instanceof \SplFileInfo && str_ends_with($f->getFilename(), '.php') && $f->getRealPath() !== __FILE__) {   // not this test's own fixtures
                    $files[] = $f->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> "file:line" of every mysqli connection built from the test DB_HOST setting whose statement never mentions the port */
    private static function offenders(string $source, string $label): array
    {
        $found = [];
        if (preg_match_all('/new\s+\\\\?mysqli\s*\(|->real_connect\s*\(/', $source, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as [, $offset]) {
                $end = strpos($source, ';', $offset);
                $statement = substr($source, $offset, ($end === false ? strlen($source) : $end) - $offset);
                if (str_contains($statement, 'DB_HOST') && !str_contains($statement, 'DB_PORT')) {
                    $found[] = $label . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
                }
            }
        }

        return $found;
    }

    public function testEveryTestDatabaseConnectionPassesThePort(): void
    {
        $offenders = [];
        foreach (self::files() as $file) {
            $offenders = [...$offenders, ...self::offenders((string) file_get_contents($file), substr((string) realpath($file), strlen((string) realpath(self::ROOT)) + 1))];
        }
        $this->assertSame([], $offenders, 'These connections ignore RIVETCORE_TEST_DB_PORT (add `(int) (getenv(\'RIVETCORE_TEST_DB_PORT\') ?: 0)`):');
    }

    public function testTheScanSeesAConnectionWithoutAPort(): void
    {
        $this->assertSame(['x:2'], self::offenders("<?php\n\$m = new \\mysqli(getenv('RIVETCORE_TEST_DB_HOST'), 'u', 'p', 'n');", 'x'));
        $this->assertSame(['x:1'], self::offenders("\$m->real_connect(\$env('DB_HOST'), 'u', 'p', 'n');", 'x'));
        $this->assertSame([], self::offenders("\$m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST'), 'u', 'p', 'n', (int) getenv('RIVETCORE_TEST_DB_PORT'));", 'x'));
    }
}
