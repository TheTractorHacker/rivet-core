<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Webhooks\UrlPolicy;

/**
 * CORE-API-6 (red team 2026-11-21, core-api/poc_urlpolicy_battery.php): UrlPolicy treated the Azure Wire Server 168.63.129.16 (the platform endpoint every Azure VM
 * reaches: health probes, DHCP, the guest agent) as a public address, so a webhook destination resolving to it was allowed. It is now never allowed: not public, and not
 * reachable through allowedNetworks either, in any IPv4-mapped, -compatible or NAT64 spelling. Its neighbours stay ordinary public addresses.
 */
final class UrlPolicyAzureWireServerTest extends TestCase
{
    /** @param list<string> $nets */
    private static function policy(array $nets = []): UrlPolicy
    {
        $map = ['azure.test' => ['168.63.129.16'], 'mixed.test' => ['93.184.216.34', '168.63.129.16'], 'neighbour.test' => ['168.63.129.17'], 'mapped.test' => ['::ffff:168.63.129.16'],
            'nat64.test' => ['64:ff9b::a83f:8110']];

        return new UrlPolicy(false, static fn (string $h): array => $map[$h] ?? [], allowedNetworks: $nets);
    }

    public function testTheWireServerIsNeverAllowed(): void
    {
        foreach (['http://168.63.129.16/', 'http://azure.test/', 'http://mixed.test/', 'http://mapped.test/', 'http://nat64.test/', 'http://[::ffff:168.63.129.16]/'] as $url) {
            $this->assertNull(self::policy()->vet($url), $url);
            $this->assertNull(self::policy(['168.63.0.0/16', '168.63.129.16/32', '::ffff:0:0/96'])->vet($url), "$url with the address listed in allowedNetworks");
        }
        $this->assertFalse(UrlPolicy::isPublicIp('168.63.129.16'));
        $this->assertFalse(UrlPolicy::isPublicIp('::ffff:168.63.129.16'));
    }

    public function testItsNeighboursAreOrdinaryPublicAddresses(): void
    {
        $this->assertNotNull(self::policy()->vet('http://neighbour.test/'));
        $this->assertNotNull(self::policy()->vet('http://168.63.129.15/'));
        $this->assertTrue(UrlPolicy::isPublicIp('168.63.129.17'));
        // the existing metadata block is unchanged
        $this->assertNull(self::policy()->vet('http://169.254.169.254/'));
    }
}
