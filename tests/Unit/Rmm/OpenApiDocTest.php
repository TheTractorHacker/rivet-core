<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\RmmProtocol;

/** Structural checks of docs/rmm/openapi-device.yaml without a YAML parser: its paths, its references and its error codes. */
final class OpenApiDocTest extends TestCase
{
    private string $yaml = '';

    protected function setUp(): void
    {
        $this->yaml = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/rmm/openapi-device.yaml');
    }

    public function testItIsOpenApi31WithTheDeviceAndTechnicianPaths(): void
    {
        $this->assertStringStartsWith("openapi: 3.1.0\n", $this->yaml);
        foreach (['/agent_enroll', '/agent_checkin', '/agent_jobs', '/agent_update', '/agent_installer', '/endpoint_devices', '/endpoint_devices/{id}',
            '/endpoint_devices/{id}/jobs', '/endpoint_devices/{id}/jobs/{job_id}/cancel', '/endpoint_devices/{id}/remote'] as $path) {
            $this->assertMatchesRegularExpression('#^  ' . preg_quote($path, '#') . ':$#m', $this->yaml, $path);
        }
        $this->assertStringNotContainsString('nullable: true', $this->yaml, '3.1 spells nullable as a type list');
    }

    public function testEveryReferenceResolves(): void
    {
        preg_match_all('~\#/components/(\w+)/(\w+)~', $this->yaml, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as [, $kind, $name]) {
            $this->assertMatchesRegularExpression('/^    ' . preg_quote($name, '/') . ':/m', $this->yaml, "#/components/$kind/$name is not defined");
        }
    }

    public function testTheErrorCodeEnumCoversTheProtocolCodes(): void
    {
        $this->assertSame(1, preg_match('/code:\n\s+type: string\n.*?enum: \[(.*?)\]/s', $this->yaml, $m));
        $enum = array_map('trim', explode(',', $m[1]));
        // 'queued' and 'cancelled' are success codes of the action layer, never an error body
        foreach (array_merge(array_diff(RmmProtocol::ERROR_CODES, ['queued', 'cancelled']), ['device_limit', 'module_disabled', 'feature_disabled']) as $code) {
            $this->assertContains($code, $enum, $code);
        }
    }

    public function testTheCapsInTheSpecAreTheProtocolValues(): void
    {
        $this->assertSame([40, 60], RmmProtocol::RATE_CHECKIN);
        $this->assertSame([120, 60], RmmProtocol::RATE_JOBS);
        $this->assertSame([60, 60], RmmProtocol::RATE_UPDATE);
        $flat = (string) preg_replace('/\s+/', ' ', $this->yaml);
        $this->assertStringContainsString('At most 40 per 60 s per device', $flat);
        $this->assertStringContainsString('At most 120 per 60 s per device', $flat);
        $this->assertStringContainsString('At most 60 per 60 s', $flat);
        $this->assertStringContainsString('limited to 1 MiB', $flat);
    }
}
