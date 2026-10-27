<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every ```php block in docs/modules/crypto.md is run in its own PHP process with assertions on, so the examples cannot rot. */
final class CryptoDocsTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function blocks(): array
    {
        $md = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/modules/crypto.md');
        preg_match_all('/```php\n(.*?)```/s', $md, $m);
        $out = [];
        foreach ($m[1] as $i => $code) {
            $out['example ' . ($i + 1)] = [$code];
        }

        return $out;
    }

    public function testThereAreExamples(): void
    {
        $this->assertGreaterThanOrEqual(7, count(self::blocks()));
    }

    #[DataProvider('blocks')]
    public function testExampleRuns(string $code): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cryptodoc');
        $this->assertNotFalse($file);
        file_put_contents($file, "<?php\nrequire " . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ";\n" . $code);
        $cmd = escapeshellarg(PHP_BINARY) . ' -d zend.assertions=1 -d assert.exception=1 ' . escapeshellarg($file) . ' 2>&1';
        exec($cmd, $lines, $status);
        @unlink($file);
        $this->assertSame(0, $status, "the example failed:\n" . implode("\n", $lines));
    }
}
