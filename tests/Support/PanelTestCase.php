<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use PHPUnit\Framework\TestCase;

/** Base class of the RivetPanel integration tests: skipped without a scratch database, otherwise a fresh {@see PanelHarness} per test. */
abstract class PanelTestCase extends TestCase
{
    protected PanelHarness $p;
    private string $previousLog = '';

    protected function setUp(): void
    {
        if (!getenv('RIVETCORE_TEST_DB_NAME')) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
            $this->markTestSkipped('The test database name must contain "scratch".');
        }
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');
        $this->p = new PanelHarness();
    }

    protected function tearDown(): void
    {
        RmmHarness::closeAll();
        ini_set('error_log', $this->previousLog);
    }

    /** A base64 PNG (1x1) usable as a valid attachment body. */
    protected static function png(): string
    {
        return base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 32));
    }

    /** @return array{filename:string,content_type:string,data_base64:string} */
    protected static function pngAttachment(string $name = 'screen.png'): array
    {
        return ['filename' => $name, 'content_type' => 'image/png', 'data_base64' => self::png()];
    }
}
