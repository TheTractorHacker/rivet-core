<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Checks\CheckCatalog;

/**
 * CORE-AGENT-2 / Chain G (server half): an http, port or cert check whose target is a LITERAL link-local or cloud metadata address is refused when it
 * is defined. The agent refuses it anyway on the resolved address of every connection; loopback and private addresses stay valid targets.
 */
final class CheckCatalogDestinationTest extends TestCase
{
    /** @return iterable<string,array{string,array<string,mixed>,bool}> */
    public static function targets(): iterable
    {
        yield 'aws imds url' => ['http', ['url' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/role', 'contains' => 'AKIA'], false];
        yield 'link-local url' => ['http', ['url' => 'http://169.254.10.10:8080/'], false];
        yield 'aws imds v6 url' => ['http', ['url' => 'http://[fd00:ec2::254]/latest/meta-data/'], false];
        yield 'mapped url' => ['http', ['url' => 'http://[::ffff:169.254.169.254]/'], false];
        yield 'azure wireserver url' => ['http', ['url' => 'http://168.63.129.16/machine?comp=goalstate'], false];
        yield 'alibaba url' => ['http', ['url' => 'http://100.100.100.200/latest/meta-data/'], false];
        yield 'imds port' => ['port', ['host' => '169.254.169.254', 'port' => 80], false];
        yield 'link-local v6 port' => ['port', ['host' => 'fe80::1', 'port' => 22], false];
        yield 'imds cert host' => ['cert', ['host' => '169.254.169.254', 'port' => 443], false];
        yield 'loopback url stays valid' => ['http', ['url' => 'http://127.0.0.1:8080/health'], true];
        yield 'private url stays valid' => ['http', ['url' => 'https://10.0.0.5/status'], true];
        yield 'name stays valid (the agent decides on the resolved address)' => ['http', ['url' => 'https://intranet.example.test/'], true];
        yield 'private port stays valid' => ['port', ['host' => '192.168.1.10', 'port' => 443], true];
        yield 'public port' => ['port', ['host' => '8.8.8.8', 'port' => 53], true];
        yield 'ntp to amazon time sync stays valid' => ['ntp', ['server' => '169.254.169.123'], true];
    }

    /** @param array<string,mixed> $params */
    #[DataProvider('targets')]
    public function testMetadataTargetsAreRefused(string $type, array $params, bool $valid): void
    {
        [$out, $err] = CheckCatalog::validateParams($type, $params);
        if ($valid) {
            $this->assertNull($err);
            $this->assertNotNull($out);

            return;
        }
        $this->assertNull($out);
        $this->assertStringContainsString('cloud metadata', (string) $err);
    }
}
