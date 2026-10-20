<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Assets\DeviceTypeClassifier as C;

final class DeviceTypeClassifierTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function table(): iterable
    {
        yield 'laptop with battery' => [['os' => 'windows', 'os_version' => 'Windows 11 Pro', 'chassis_type' => 'laptop', 'has_battery' => true, 'is_virtual' => false, 'virtualization' => 'none'], 'Laptop'];
        yield 'battery only' => [['os' => 'windows', 'os_version' => 'Windows 11 Pro', 'has_battery' => true], 'Laptop'];
        yield 'desktop with a UPS reported as battery' => [['os' => 'windows', 'os_version' => 'Windows 11 Pro', 'chassis_type' => 'desktop', 'has_battery' => true], 'Desktop'];
        yield 'tower' => [['os' => 'windows', 'os_version' => 'Windows 10', 'chassis_type' => 'tower', 'has_battery' => false], 'Desktop'];
        yield 'all in one' => [['os' => 'windows', 'os_version' => 'Windows 11', 'chassis_type' => 'all_in_one', 'has_battery' => true], 'Desktop'];
        yield 'rack server' => [['os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'chassis_type' => 'rack_server', 'has_battery' => false], 'Server'];
        yield 'blade' => [['os' => 'windows', 'os_version' => 'Windows 11', 'chassis_type' => 'blade'], 'Server'];
        yield 'windows server' => [['os' => 'windows', 'os_version' => 'Windows Server 2022 Standard', 'chassis_type' => 'rack_server'], 'Server'];
        yield 'windows server in capitals' => [['os_version' => 'WINDOWS SERVER 2019'], 'Server'];
        yield 'vmware guest' => [['os' => 'windows', 'os_version' => 'Windows 11', 'chassis_type' => 'other', 'is_virtual' => true, 'virtualization' => 'vmware'], 'Virtual Machine'];
        yield 'proxmox kvm guest' => [['os' => 'linux', 'os_version' => 'Debian 12', 'is_virtual' => true, 'virtualization' => 'kvm', 'has_battery' => false], 'Virtual Machine'];
        yield 'hyper-v guest running Windows Server' => [['os' => 'windows', 'os_version' => 'Windows Server 2022', 'is_virtual' => true, 'virtualization' => 'hyperv'], 'Virtual Machine'];
        yield 'lxc container' => [['os' => 'linux', 'os_version' => 'Debian 12', 'virtualization' => 'lxc'], 'Virtual Machine'];
        yield 'virtualization without is_virtual' => [['os' => 'linux', 'virtualization' => 'xen'], 'Virtual Machine'];
        yield 'proxmox host' => [['os' => 'linux', 'os_version' => 'Debian 12', 'chassis_type' => 'rack_server', 'is_virtual' => false, 'role_hints' => ['hypervisor_host']], 'Hypervisor'];
        yield 'hyper-v host' => [['os' => 'windows', 'os_version' => 'Windows Server 2022', 'role_hints' => ['hypervisor_host', 'domain_controller']], 'Hypervisor'];
        yield 'nested hypervisor' => [['os' => 'linux', 'is_virtual' => true, 'virtualization' => 'kvm', 'role_hints' => ['hypervisor_host']], 'Hypervisor'];
        yield 'domain controller' => [['os' => 'windows', 'os_version' => 'Windows 11', 'role_hints' => ['domain_controller']], 'Server'];
        yield 'tablet' => [['os' => 'windows', 'os_version' => 'Windows 11', 'chassis_type' => 'tablet', 'has_battery' => true], 'Tablet'];
        yield 'linux without battery or chassis' => [['os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'has_battery' => false], 'Server'];
        yield 'linux desktop chassis' => [['os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'chassis_type' => 'desktop', 'has_battery' => false], 'Desktop'];
        yield 'linux laptop' => [['os' => 'linux', 'os_version' => 'Fedora 40', 'chassis_type' => 'laptop', 'has_battery' => true], 'Laptop'];
        yield 'windows no battery, no chassis' => [['os' => 'windows', 'os_version' => 'Windows 11', 'has_battery' => false], 'Desktop'];
        yield 'explicitly not virtual' => [['os' => 'windows', 'is_virtual' => false, 'virtualization' => 'vmware', 'chassis_type' => 'desktop'], 'Desktop'];
        yield 'old agent: nothing' => [['os_version' => 'Windows 11 Pro'], 'Laptop'];
        yield 'old agent: server OS' => [['os_version' => 'Windows Server 2016'], 'Server'];
        yield 'empty' => [[], 'Laptop'];
        yield 'chassis other and no battery fact' => [['os' => 'windows', 'chassis_type' => 'other'], 'Laptop'];
    }

    /** @param array<string,mixed> $in */
    #[DataProvider('table')]
    public function testTable(array $in, string $want): void
    {
        $this->assertSame($want, C::classify($in));
        $this->assertContains($want, C::LABELS);
        $this->assertNotSame('', C::explain($in)['reason']);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function hostile(): iterable
    {
        yield 'script strings' => [['os' => '<script>', 'os_version' => "<script>alert(1)</script>\x00", 'chassis_type' => '<img>', 'virtualization' => "vmware'; DROP TABLE x;--", 'role_hints' => ['<b>', 'root']]];
        yield 'wrong types' => [['os_version' => ['server'], 'chassis_type' => 7, 'has_battery' => 'yes', 'is_virtual' => 1, 'virtualization' => ['kvm'], 'role_hints' => 'hypervisor_host']];
        yield 'huge' => [['os_version' => str_repeat('A', 1000000), 'role_hints' => array_fill(0, 100000, 'x')]];
        yield 'nested' => [['role_hints' => [['hypervisor_host']], 'chassis_type' => null, 'has_battery' => null]];
    }

    /** @param array<string,mixed> $in */
    #[DataProvider('hostile')]
    public function testHostileInputNeverEscapesTheVocabulary(array $in): void
    {
        $this->assertSame('Laptop', C::classify($in));
    }

    public function testServerOnlyInTheFirstTwoHundredCharacters(): void
    {
        $this->assertSame('Laptop', C::classify(['os_version' => str_repeat('a', 300) . 'server']));
    }

    public function testChooseAndResolve(): void
    {
        $this->assertSame('Server', C::choose('Server', 'Laptop'));
        $this->assertSame('Desktop', C::choose('auto', 'Desktop'));
        $this->assertSame('Desktop', C::choose(null, 'Desktop'));
        $this->assertSame('Laptop', C::choose('Toaster', 'nonsense'));
        $this->assertSame('Hypervisor', C::resolve('Hypervisor', ['Laptop', 'Server', 'Hypervisor']));
        $this->assertSame('Server', C::resolve('Hypervisor', ['Laptop', 'Server']));
        $this->assertSame('Server', C::resolve('Virtual Machine', ['Laptop', 'Server']));
        $this->assertSame('Laptop', C::resolve('Tablet', ['Laptop', 'Server']));
        $this->assertSame('Laptop', C::resolve('Hypervisor', ['Laptop']));
        $this->assertSame('Laptop', C::resolve('Mystery', ['Laptop']));
    }
}
