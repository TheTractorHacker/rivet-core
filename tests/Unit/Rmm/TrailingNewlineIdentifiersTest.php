<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\RmmProtocol;

/** CORE-10 (SR-02 class): a `$` anchor lets a trailing newline through; agent-controlled identifiers go through RmmProtocol::matches(). */
final class TrailingNewlineIdentifiersTest extends TestCase
{
    /** @return iterable<string,array{0:string,1:string}> */
    public static function identifiers(): iterable
    {
        yield 'check key' => [RmmProtocol::CHECK_KEY_RE, 'disk_c'];
        yield 'mac' => [RmmProtocol::MAC_RE, 'aa:bb:cc:dd:ee:ff'];
        yield 'install id' => [RmmProtocol::INSTALL_ID_RE, '123e4567-e89b-42d3-a456-426614174000'];
        yield 'machine guid' => [RmmProtocol::MACHINE_GUID_RE, '{ABC-123}'];
        yield 'agent version' => [RmmProtocol::AGENT_VERSION_RE, '1.2.3'];
    }

    #[DataProvider('identifiers')]
    public function testTheConstantAloneLetsATrailingNewlineThroughAndMatchesDoesNot(string $re, string $ok): void
    {
        $this->assertSame(1, preg_match($re, $ok . "\n"), 'documents the flaw of the bare anchor (the constants are public and frozen)');
        $this->assertTrue(RmmProtocol::matches($re, $ok));
        $this->assertFalse(RmmProtocol::matches($re, $ok . "\n"));
        $this->assertFalse(RmmProtocol::matches($re, $ok . "\n\n"));
        $this->assertFalse(RmmProtocol::matches($re, "\n" . $ok));
        $this->assertFalse(RmmProtocol::matches($re, ''));
    }

    public function testDeviceValidationRefusesNewlineSuffixedIdentifiers(): void
    {
        $good = \RivetCore\Tests\Support\RmmHarness::device([]);
        $this->assertIsArray(\RivetCore\Rmm\Enrollment\DeviceValidator::validateDevice($good, true), 'the control device is valid');
        foreach (['install_id', 'machine_guid', 'agent_version'] as $field) {
            if (!is_string($good[$field] ?? null)) {
                continue;
            }
            try {
                \RivetCore\Rmm\Enrollment\DeviceValidator::validateDevice([$field => $good[$field] . "\n"] + $good, true);
                $this->fail("$field with a trailing newline was accepted");
            } catch (\RivetCore\Rmm\Http\ApiError $e) {
                $this->assertSame(422, $e->httpStatus ?? 422);
            }
        }
    }
}
