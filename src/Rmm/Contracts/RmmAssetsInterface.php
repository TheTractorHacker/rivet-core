<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * The asset side of device identity matching and linking. The identity policy (junk serials, scope, ambiguity, ownership) is
 * Core logic; this contract only reaches the edition's assets and interfaces tables, which Core never touches (ADR-002).
 *
 * @api
 */
interface RmmAssetsInterface
{
    /**
     * Non-archived assets whose serial equals $serial exactly (case as stored). Core has already refused junk serials.
     *
     * @return list<array{asset_id:int, asset_name:string, client_id:int, serial:?string}>
     */
    public function findBySerial(string $serial, int $limit = 10): array;

    /**
     * Non-archived assets with an interface whose MAC equals one of $macs, ignoring case and treating '-' and ':' alike.
     * $macs are already normalised by Core to lower-case colon form (aa:bb:cc:dd:ee:ff). Each asset is returned once.
     *
     * @param list<string> $macs
     * @return list<array{asset_id:int, asset_name:string, client_id:int, serial:?string}>
     */
    public function findByMacs(array $macs, int $limit = 20): array;

    /**
     * Non-archived assets whose name equals $hostname case-insensitively. A hostname match is only ever a hint (Core never links on it).
     *
     * @return list<array{asset_id:int, asset_name:string, client_id:int, serial:?string}>
     */
    public function findByHostname(string $hostname, int $limit = 10): array;

    /** @return array{asset_id:int, client_id:int, archived:bool}|null null when the asset does not exist */
    public function find(int $assetId): ?array;

    /**
     * Create an asset for an unmatched device (policy auto_create or an administrator's "create asset"). Returns the new id.
     * Status 'Active'; the OS text is "Windows <os_version>".
     *
     * TYPE (since 1.0.0-rc.29). Core passes two additive keys: `asset_type_hint`, present only when the technician chose a type for the installer
     * (one of Laptop, Desktop, Server, Virtual Machine, Hypervisor, Tablet, Other; absent for "detect automatically"), and `asset_type_detected`, always
     * present, the {@see \RivetCore\Rmm\Assets\DeviceTypeClassifier} verdict from the agent's hardware facts (same label set). Use the hint when it is
     * there, otherwise the detected label, and map the label to the edition's own asset type name; a label the edition has no asset type for goes through
     * {@see \RivetCore\Rmm\Assets\DeviceTypeClassifier::resolve()} (Hypervisor and Virtual Machine -> Server, Tablet, Desktop and Other -> Laptop).
     * An adapter written before rc.29 ignores both keys and keeps the old rule (Server when os_version contains "server", else Laptop): still valid, just less accurate.
     *
     * @param array{hostname:string, os_version:string, manufacturer:?string, model:?string, serial:?string, asset_type_hint?:string, asset_type_detected?:string} $device
     */
    public function createForDevice(array $device, int $clientId, int $locationId): int;

    /**
     * Fill blanks only, never overwrite what a human typed: serial and model when empty, make when '', OS when empty, and (since rc.29, optional key
     * `type`, a DeviceTypeClassifier label) the asset type when it is empty. A device matched to an EXISTING asset therefore never changes that asset's type.
     *
     * @param array{serial:?string, model:?string, manufacturer:?string, os:string, type?:string} $facts
     */
    public function fillBlanks(int $assetId, array $facts): void;

    /** A device moved to another client (administrator action): the asset follows it. */
    public function moveToClient(int $assetId, int $clientId, int $locationId): void;
}
