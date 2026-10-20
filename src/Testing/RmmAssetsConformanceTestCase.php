<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\RmmAssetsInterface;

/**
 * Conformance kit for {@see RmmAssetsInterface}. The edition tells the case how to create an asset and how to read one back
 * from its own tables.
 *
 * Checks: lookups return only non-archived assets with the documented row shape; serial is an exact match; MACs match ignoring
 * case and treating '-' and ':' alike, and an asset with several matching MACs comes back once; hostname matches
 * case-insensitively; limits are honoured; find() reports archived and null for unknown ids; createForDevice() makes an Active
 * 'Server' or 'Laptop' (and, since rc.29, the installer's `asset_type_hint` first, else `asset_type_detected`, mapped onto the edition's own types; fillBlanks() fills a blank `type` and never changes one) with "Windows <os_version>" in the client and location; fillBlanks() fills only blanks and never
 * overwrites; moveToClient() moves the asset and nothing else; nothing throws for unknown ids.
 *
 * @api
 */
abstract class RmmAssetsConformanceTestCase extends TestCase
{
    use UntypedValues;

    abstract protected function assets(): RmmAssetsInterface;

    /**
     * Create an asset in the edition's store and return its id.
     *
     * @param array{name:string, client_id:int, serial:?string, macs:list<string>, archived:bool, model:string, make:string, os:string} $asset
     *        macs exactly as a person may have typed them: any case, ':' or '-' separators
     */
    abstract protected function createAsset(array $asset): int;

    /**
     * The asset type names the edition has (exact spelling). The default, Laptop and Server, is what every edition has had since before rc.29;
     * return more (Desktop, Virtual Machine, Hypervisor, Tablet, Other) and the kit checks those labels map one to one; a label you do not list must
     * fall back as {@see \RivetCore\Rmm\Assets\DeviceTypeClassifier::resolve()} says.
     *
     * @return list<string>
     */
    protected function supportedTypes(): array
    {
        return ['Laptop', 'Server'];
    }

    /**
     * Create an asset whose type is $type ('' = blank). An edition that can store a blank type overrides this; the default skips the two type-fill tests.
     *
     * @param array{name:string, client_id:int, serial:?string, macs:list<string>, archived:bool, model:string, make:string, os:string} $asset
     */
    protected function createAssetWithType(array $asset, string $type): int
    {
        $this->markTestSkipped('This edition does not offer createAssetWithType().');
    }

    /**
     * Read an asset back from the edition's store, not through the adapter.
     *
     * @return array{name:string, client_id:int, location_id:int, serial:?string, model:string, make:string, os:string, type:string, status:string}|null
     */
    abstract protected function readAsset(int $assetId): ?array;

    /**
     * @param array<string,mixed> $over
     * @return array{name:string, client_id:int, serial:?string, macs:list<string>, archived:bool, model:string, make:string, os:string}
     */
    private function spec(array $over = []): array
    {
        /** @var array{name:string, client_id:int, serial:?string, macs:list<string>, archived:bool, model:string, make:string, os:string} */
        return $over + ['name' => 'host-' . bin2hex(random_bytes(4)), 'client_id' => 0, 'serial' => null, 'macs' => [], 'archived' => false, 'model' => '', 'make' => '', 'os' => ''];
    }

    private static function token(): string
    {
        return strtoupper(bin2hex(random_bytes(6)));
    }

    private static function mac(): string
    {
        return implode(':', str_split(bin2hex(random_bytes(6)), 2));
    }

    /**
     * @param list<array{asset_id:int, asset_name:string, client_id:int, serial:?string}> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $this->assertSame(['asset_id', 'asset_name', 'client_id', 'serial'], array_keys($r), 'documented row shape');
            $this->assertIsInt(self::untyped($r['asset_id']));
            $this->assertIsString(self::untyped($r['asset_name']));
            $this->assertIsInt(self::untyped($r['client_id']));
            $ids[] = $r['asset_id'];
        }
        sort($ids);

        return $ids;
    }

    public function testFindBySerialIsExactAndSkipsArchived(): void
    {
        $serial = 'SN' . self::token();
        $live = $this->createAsset($this->spec(['serial' => $serial, 'client_id' => 7]));
        $this->createAsset($this->spec(['serial' => $serial, 'archived' => true]));
        $this->createAsset($this->spec(['serial' => $serial . 'X']));
        $rows = $this->assets()->findBySerial($serial);
        $this->assertSame([$live], $this->ids($rows));
        $this->assertSame($serial, $rows[0]['serial']);
        $this->assertSame(7, $rows[0]['client_id']);
        $this->assertSame([], $this->assets()->findBySerial('NOPE' . self::token()));
    }

    public function testFindBySerialHonoursTheLimit(): void
    {
        $serial = 'SN' . self::token();
        for ($i = 0; $i < 3; ++$i) {
            $this->createAsset($this->spec(['serial' => $serial]));
        }
        $this->assertCount(2, $this->assets()->findBySerial($serial, 2));
    }

    public function testFindByMacsIgnoresCaseAndSeparatorsAndSkipsArchived(): void
    {
        $m1 = self::mac();
        $m2 = self::mac();
        $a = $this->createAsset($this->spec(['macs' => [strtoupper(str_replace(':', '-', $m1))]]));
        $b = $this->createAsset($this->spec(['macs' => [$m2, self::mac()]]));
        $this->createAsset($this->spec(['macs' => [$m1], 'archived' => true]));
        $this->assertSame([$a], $this->ids($this->assets()->findByMacs([$m1])));
        $this->assertSame([$b], $this->ids($this->assets()->findByMacs([$m2])));
        $this->assertSame([min($a, $b), max($a, $b)], $this->ids($this->assets()->findByMacs([$m1, $m2])));
        $this->assertSame([], $this->assets()->findByMacs([self::mac()]));
        $this->assertSame([], $this->assets()->findByMacs([]));
    }

    public function testAnAssetWithSeveralMatchingMacsIsReturnedOnce(): void
    {
        $m1 = self::mac();
        $m2 = self::mac();
        $a = $this->createAsset($this->spec(['macs' => [$m1, strtoupper($m2)]]));
        $this->assertSame([$a], $this->ids($this->assets()->findByMacs([$m1, $m2])));
    }

    public function testFindByHostnameIsCaseInsensitiveAndSkipsArchived(): void
    {
        $name = 'Host-' . self::token();
        $a = $this->createAsset($this->spec(['name' => $name]));
        $this->createAsset($this->spec(['name' => $name, 'archived' => true]));
        $this->assertSame([$a], $this->ids($this->assets()->findByHostname(strtolower($name))));
        $this->assertSame([$a], $this->ids($this->assets()->findByHostname(strtoupper($name))));
        $this->assertSame([], $this->assets()->findByHostname($name . '-x'));
    }

    public function testFindReportsClientAndArchivedAndNullForUnknown(): void
    {
        $live = $this->createAsset($this->spec(['client_id' => 5]));
        $gone = $this->createAsset($this->spec(['client_id' => 6, 'archived' => true]));
        $this->assertSame(['asset_id' => $live, 'client_id' => 5, 'archived' => false], $this->assets()->find($live));
        $this->assertSame(['asset_id' => $gone, 'client_id' => 6, 'archived' => true], $this->assets()->find($gone));
        $this->assertNull($this->assets()->find(2_000_000_000));
    }

    public function testCreateForDeviceMakesALaptopOrAServer(): void
    {
        $id = $this->assets()->createForDevice(
            ['hostname' => 'WS-' . self::token(), 'os_version' => '10.0.22631 Pro', 'manufacturer' => 'Dell Inc.', 'model' => 'Latitude 7440', 'serial' => 'ABC123'],
            11,
            0
        );
        $row = $this->readAsset($id);
        $this->assertNotNull($row);
        $this->assertSame('Laptop', $row['type']);
        $this->assertSame('Active', $row['status']);
        $this->assertSame('Windows 10.0.22631 Pro', $row['os']);
        $this->assertSame(11, $row['client_id']);
        $this->assertSame('ABC123', $row['serial']);
        $this->assertSame('Latitude 7440', $row['model']);
        $this->assertSame('Dell Inc.', $row['make']);

        $srv = $this->assets()->createForDevice(
            ['hostname' => 'SRV-' . self::token(), 'os_version' => '10.0.20348 WINDOWS SERVER 2022', 'manufacturer' => null, 'model' => null, 'serial' => null],
            11,
            0
        );
        $row = $this->readAsset($srv);
        $this->assertNotNull($row);
        $this->assertSame('Server', $row['type']);
        $this->assertNull($row['serial'] === '' ? null : $row['serial']);
    }

    /**
     * @param array<string,string> $extra
     * @return array<string,mixed>
     */
    private function device(string $osVersion, array $extra = []): array
    {
        return ['hostname' => 'T-' . self::token(), 'os_version' => $osVersion, 'manufacturer' => null, 'model' => null, 'serial' => null] + $extra;
    }

    /** @param array<string,mixed> $device */
    private function typeOf(array $device): string
    {
        $row = $this->readAsset($this->assets()->createForDevice($device, 11, 0));
        $this->assertNotNull($row);

        return $row['type'];
    }

    public function testAnInstallerTypeHintWins(): void
    {
        // The hint beats both the detected type and the OS text, in both directions.
        $this->assertSame('Server', $this->typeOf($this->device('10.0.22631 Pro', ['asset_type_hint' => 'Server', 'asset_type_detected' => 'Laptop'])));
        $this->assertSame('Laptop', $this->typeOf($this->device('10.0.20348 Windows Server 2022', ['asset_type_hint' => 'Laptop', 'asset_type_detected' => 'Server'])));
        foreach (['Desktop', 'Virtual Machine', 'Hypervisor', 'Tablet', 'Other'] as $label) {
            $this->assertSame(
                \RivetCore\Rmm\Assets\DeviceTypeClassifier::resolve($label, $this->supportedTypes()),
                $this->typeOf($this->device('10.0.22631 Pro', ['asset_type_hint' => $label, 'asset_type_detected' => 'Laptop'])),
                "hint $label"
            );
        }
    }

    public function testTheDetectedTypeIsUsedWithoutAHint(): void
    {
        $this->assertSame('Server', $this->typeOf($this->device('10.0.22631 Pro', ['asset_type_detected' => 'Server'])));
        $this->assertSame('Laptop', $this->typeOf($this->device('10.0.20348 Windows Server 2022', ['asset_type_detected' => 'Laptop'])));
        $this->assertSame(
            \RivetCore\Rmm\Assets\DeviceTypeClassifier::resolve('Virtual Machine', $this->supportedTypes()),
            $this->typeOf($this->device('10.0.22631 Pro', ['asset_type_detected' => 'Virtual Machine']))
        );
    }

    public function testWithoutTheTypeKeysTheOldRuleStillApplies(): void
    {
        $this->assertSame('Server', $this->typeOf($this->device('Windows SERVER 2019')));
        $this->assertSame('Laptop', $this->typeOf($this->device('10.0.22631 Pro')));
    }

    public function testFillBlanksFillsABlankType(): void
    {
        $id = $this->createAssetWithType($this->spec([]), '');
        $this->assets()->fillBlanks($id, ['serial' => null, 'model' => null, 'manufacturer' => null, 'os' => '', 'type' => 'Server']);
        $row = $this->readAsset($id);
        $this->assertNotNull($row);
        $this->assertSame('Server', $row['type']);
    }

    public function testFillBlanksNeverOverwritesAnAssetsType(): void
    {
        $id = $this->createAssetWithType($this->spec([]), 'Laptop');
        $this->assets()->fillBlanks($id, ['serial' => null, 'model' => null, 'manufacturer' => null, 'os' => '', 'type' => 'Server']);
        $row = $this->readAsset($id);
        $this->assertNotNull($row);
        $this->assertSame('Laptop', $row['type']);
    }

    public function testFillBlanksFillsOnlyBlanks(): void
    {
        $id = $this->createAsset($this->spec(['serial' => null, 'model' => '', 'make' => '', 'os' => '']));
        $this->assets()->fillBlanks($id, ['serial' => 'S1', 'model' => 'M1', 'manufacturer' => 'Acme', 'os' => 'Windows 11']);
        $row = $this->readAsset($id);
        $this->assertNotNull($row);
        $this->assertSame(['S1', 'M1', 'Acme', 'Windows 11'], [$row['serial'], $row['model'], $row['make'], $row['os']]);
    }

    public function testFillBlanksNeverOverwritesWhatIsThere(): void
    {
        $id = $this->createAsset($this->spec(['serial' => 'KEEP', 'model' => 'KeepModel', 'make' => 'KeepMake', 'os' => 'KeepOS']));
        $this->assets()->fillBlanks($id, ['serial' => 'S2', 'model' => 'M2', 'manufacturer' => 'Other', 'os' => 'Windows 11']);
        $row = $this->readAsset($id);
        $this->assertNotNull($row);
        $this->assertSame(['KEEP', 'KeepModel', 'KeepMake', 'KeepOS'], [$row['serial'], $row['model'], $row['make'], $row['os']]);
    }

    public function testMoveToClientMovesOnlyThatAsset(): void
    {
        $a = $this->createAsset($this->spec(['client_id' => 1]));
        $b = $this->createAsset($this->spec(['client_id' => 1]));
        $this->assets()->moveToClient($a, 9, 0);
        $ra = $this->readAsset($a);
        $rb = $this->readAsset($b);
        $this->assertNotNull($ra);
        $this->assertNotNull($rb);
        $this->assertSame(9, $ra['client_id']);
        $this->assertSame(1, $rb['client_id']);
    }

    public function testUnknownAssetsNeverThrowAndCreateNothing(): void
    {
        $this->assets()->fillBlanks(2_000_000_000, ['serial' => 'S', 'model' => 'M', 'manufacturer' => 'A', 'os' => 'W']);
        $this->assets()->moveToClient(2_000_000_000, 3, 0);
        $this->assertNull($this->readAsset(2_000_000_000));
        $this->assertNull($this->assets()->find(2_000_000_000));
    }
}
