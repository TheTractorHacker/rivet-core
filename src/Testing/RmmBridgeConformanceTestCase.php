<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;

/**
 * Conformance kit for {@see RmmBridgeInterface}. The edition tells the case how to create an asset and a saved script and how
 * to read links, alerts and the session log back from its own tables. (Run the adapter on the same database connection Core
 * uses, so its writes take part in Core's transactions; this case cannot prove that.)
 *
 * Checks: ensureIntegration() is idempotent per type; integrationExists() needs the right type; upsertLink() creates one
 * 'unknown' link per asset and integration, updates it in place, and moves an agent key from another asset; removeLink()
 * deletes only that key; applyHealth() is false without a link, sets 'online' and moves rmm_status_changed_at only when the
 * status changed; markOffline() flips only online links of the given keys and counts them; openAlert() is idempotent per
 * (integration, key) and starts 'new'; resolveAlert() resolves once and is harmless afterwards; reassignAlerts() moves only
 * open alerts of that asset; savedPowerShellScript() returns only enabled PowerShell scripts; recordRemoteSession() stores the
 * fields as given.
 *
 * @api
 */
abstract class RmmBridgeConformanceTestCase extends TestCase
{
    private const FACTS = ['hostname' => 'host', 'os_name' => 'Windows', 'os_version' => '10.0.1', 'manufacturer' => 'Acme', 'model' => 'M1'];
    private const HEALTH = [
        'hostname' => 'host', 'os_version' => '10.0.1', 'manufacturer' => 'Acme', 'model' => 'M1', 'cpu' => 'cpu', 'ram_gb' => '8',
        'logged_in_user' => 'u', 'cpu_pct' => 10, 'ram_pct' => 20, 'disk_pct' => 30, 'needs_reboot' => false, 'last_boot' => '2026-01-01 00:00:00',
    ];

    abstract protected function bridge(): RmmBridgeInterface;

    /** Create an asset in the edition's store (any client) and return its id. */
    abstract protected function createAsset(): int;

    /**
     * The link row of this agent key, read from the edition's store; null when there is none.
     *
     * @return array{asset_id:int, status:string, status_changed_at:?string}|null  status is rmm_status ('unknown', 'online', 'offline')
     */
    abstract protected function readLink(int $integrationId, string $agentKey): ?array;

    /** Make the link's rmm_status_changed_at a long time ago ('2000-01-01 00:00:00'), so a later change is visible. */
    abstract protected function backdateStatusChange(int $integrationId, string $agentKey): void;

    /** @return array{status:string, client_id:int, asset_id:?int, severity:string}|null  status 'new' for an open alert, 'resolved' once resolved */
    abstract protected function readAlert(int $alertId): ?array;

    /** Number of alert rows with this integration and key. */
    abstract protected function countAlerts(int $integrationId, string $alertKey): int;

    /** Add a script to the saved library and return its id. */
    abstract protected function createScript(string $body, bool $powershell, bool $enabled): int;

    /** @return list<array{client_id:int, user_id:int, connection_type:string, reference:string, ip_address:?string, user_agent:?string}> */
    abstract protected function readRemoteSessions(int $assetId): array;

    private function type(): string
    {
        return 'rmm_conf_' . bin2hex(random_bytes(4));
    }

    private function integration(): int
    {
        return $this->bridge()->ensureIntegration($this->type(), 'Conformance');
    }

    private function key(): string
    {
        return 'conf:' . bin2hex(random_bytes(6));
    }

    public function testEnsureIntegrationIsIdempotentPerType(): void
    {
        $t = $this->type();
        $id = $this->bridge()->ensureIntegration($t, 'One');
        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, $this->bridge()->ensureIntegration($t, 'Renamed'));
        $this->assertNotSame($id, $this->bridge()->ensureIntegration($this->type(), 'Other'));
    }

    public function testIntegrationExistsNeedsTheRightType(): void
    {
        $t = $this->type();
        $id = $this->bridge()->ensureIntegration($t, 'One');
        $this->assertTrue($this->bridge()->integrationExists($id, $t));
        $this->assertFalse($this->bridge()->integrationExists($id, $t . 'x'));
        $this->assertFalse($this->bridge()->integrationExists(2_000_000_000, $t));
    }

    public function testUpsertLinkCreatesOneUnknownLinkAndUpdatesInPlace(): void
    {
        $i = $this->integration();
        $asset = $this->createAsset();
        $key = $this->key();
        $this->bridge()->upsertLink($i, $asset, $key, self::FACTS);
        $link = $this->readLink($i, $key);
        $this->assertNotNull($link);
        $this->assertSame($asset, $link['asset_id']);
        $this->assertSame('unknown', $link['status']);
        $this->bridge()->upsertLink($i, $asset, $key, ['hostname' => 'renamed'] + self::FACTS);
        $this->assertSame($asset, $this->readLink($i, $key)['asset_id'] ?? null);
    }

    public function testUpsertLinkMovesAnAgentKeyFromAnotherAsset(): void
    {
        $i = $this->integration();
        $a = $this->createAsset();
        $b = $this->createAsset();
        $key = $this->key();
        $this->bridge()->upsertLink($i, $a, $key, self::FACTS);
        $this->bridge()->upsertLink($i, $b, $key, self::FACTS);
        $link = $this->readLink($i, $key);
        $this->assertNotNull($link);
        $this->assertSame($b, $link['asset_id']);
    }

    public function testRemoveLinkDeletesOnlyThatKey(): void
    {
        $i = $this->integration();
        $a = $this->createAsset();
        $b = $this->createAsset();
        $ka = $this->key();
        $kb = $this->key();
        $this->bridge()->upsertLink($i, $a, $ka, self::FACTS);
        $this->bridge()->upsertLink($i, $b, $kb, self::FACTS);
        $this->bridge()->removeLink($i, $ka);
        $this->assertNull($this->readLink($i, $ka));
        $this->assertNotNull($this->readLink($i, $kb));
        $this->bridge()->removeLink($i, $ka);
        $this->addToAssertionCount(1);
    }

    public function testApplyHealthIsFalseWithoutALinkAndCreatesNone(): void
    {
        $i = $this->integration();
        $asset = $this->createAsset();
        $this->assertFalse($this->bridge()->applyHealth($i, $asset, self::HEALTH));
        $this->assertNull($this->readLink($i, 'none'));
    }

    public function testApplyHealthSetsOnlineAndMovesTheChangeTimeOnlyOnAChange(): void
    {
        $i = $this->integration();
        $asset = $this->createAsset();
        $key = $this->key();
        $this->bridge()->upsertLink($i, $asset, $key, self::FACTS);
        $this->assertTrue($this->bridge()->applyHealth($i, $asset, self::HEALTH));
        $link = $this->readLink($i, $key);
        $this->assertNotNull($link);
        $this->assertSame('online', $link['status']);
        $this->assertNotNull($link['status_changed_at'], 'unknown -> online is a change');
        $this->backdateStatusChange($i, $key);
        $this->assertTrue($this->bridge()->applyHealth($i, $asset, self::HEALTH));
        $this->assertSame('2000-01-01 00:00:00', $this->readLink($i, $key)['status_changed_at'] ?? null, 'online -> online is not a change');
    }

    public function testMarkOfflineFlipsOnlyOnlineLinksOfTheGivenKeys(): void
    {
        $i = $this->integration();
        $other = $this->integration();
        $a = $this->createAsset();
        $b = $this->createAsset();
        $c = $this->createAsset();
        $d = $this->createAsset();
        [$ka, $kb, $kc, $kd] = [$this->key(), $this->key(), $this->key(), $this->key()];
        $this->bridge()->upsertLink($i, $a, $ka, self::FACTS);
        $this->bridge()->upsertLink($i, $b, $kb, self::FACTS);
        $this->bridge()->upsertLink($i, $c, $kc, self::FACTS); // stays 'unknown'
        $this->bridge()->upsertLink($other, $d, $kd, self::FACTS);
        $this->bridge()->applyHealth($i, $a, self::HEALTH);
        $this->bridge()->applyHealth($i, $b, self::HEALTH);
        $this->bridge()->applyHealth($other, $d, self::HEALTH);
        $this->backdateStatusChange($i, $ka);

        $n = $this->bridge()->markOffline($i, [$ka, $kc, $kd]);
        $this->assertSame(1, $n, 'only the online link of this integration among the given keys');
        $la = $this->readLink($i, $ka);
        $this->assertNotNull($la);
        $this->assertSame('offline', $la['status']);
        $this->assertNotSame('2000-01-01 00:00:00', $la['status_changed_at']);
        $this->assertSame('online', $this->readLink($i, $kb)['status'] ?? null);
        $this->assertSame('unknown', $this->readLink($i, $kc)['status'] ?? null);
        $this->assertSame('online', $this->readLink($other, $kd)['status'] ?? null);
        $this->assertSame(0, $this->bridge()->markOffline($i, []));
    }

    public function testOpenAlertIsIdempotentPerIntegrationAndKey(): void
    {
        $i = $this->integration();
        $asset = $this->createAsset();
        $key = 'agent:1:cpu:' . random_int(1, 1_000_000);
        $id = $this->bridge()->openAlert($i, $key, $asset, 4, 'warning', 'CPU high', ['check' => 'cpu']);
        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, $this->bridge()->openAlert($i, $key, $asset, 4, 'error', 'again', []));
        $this->assertSame(1, $this->countAlerts($i, $key));
        $alert = $this->readAlert($id);
        $this->assertNotNull($alert);
        $this->assertSame('new', $alert['status']);
        $this->assertSame(4, $alert['client_id']);
        $this->assertSame($asset, $alert['asset_id']);
        $this->assertSame('warning', $alert['severity']);
        $this->assertNotSame($id, $this->bridge()->openAlert($i, $key . 'b', $asset, 4, 'warning', 'x', []));
    }

    public function testSameAlertKeyInAnotherIntegrationIsAnotherAlert(): void
    {
        $key = 'agent:2:disk:1';
        $a = $this->bridge()->openAlert($this->integration(), $key, null, 0, 'warning', 'x', []);
        $b = $this->bridge()->openAlert($this->integration(), $key, null, 0, 'warning', 'x', []);
        $this->assertNotSame($a, $b);
    }

    public function testResolveAlertResolvesOnceAndIsHarmlessAfterwards(): void
    {
        $i = $this->integration();
        $id = $this->bridge()->openAlert($i, 'agent:3:svc:1', null, 2, 'error', 'down', []);
        $this->bridge()->resolveAlert($i, $id);
        $alert = $this->readAlert($id);
        $this->assertNotNull($alert);
        $this->assertSame('resolved', $alert['status']);
        $this->bridge()->resolveAlert($i, $id);
        $this->assertSame('resolved', $this->readAlert($id)['status'] ?? null);
        $this->bridge()->resolveAlert($i, 2_000_000_000);
    }

    public function testReassignAlertsMovesOnlyOpenAlertsOfThatAssetAndIntegration(): void
    {
        $i = $this->integration();
        $a = $this->createAsset();
        $b = $this->createAsset();
        $open = $this->bridge()->openAlert($i, 'agent:4:a:1', $a, 1, 'warning', 'x', []);
        $done = $this->bridge()->openAlert($i, 'agent:4:b:1', $a, 1, 'warning', 'x', []);
        $this->bridge()->resolveAlert($i, $done);
        $elsewhere = $this->bridge()->openAlert($i, 'agent:4:c:1', $b, 1, 'warning', 'x', []);
        $this->bridge()->reassignAlerts($i, $a, 8);
        $this->assertSame(8, $this->readAlert($open)['client_id'] ?? null);
        $this->assertSame(1, $this->readAlert($done)['client_id'] ?? null);
        $this->assertSame(1, $this->readAlert($elsewhere)['client_id'] ?? null);
    }

    public function testSavedPowerShellScriptReturnsOnlyEnabledPowerShell(): void
    {
        $ok = $this->createScript('Get-Date', true, true);
        $off = $this->createScript('Get-Date', true, false);
        $sh = $this->createScript('echo hi', false, true);
        $this->assertSame('Get-Date', $this->bridge()->savedPowerShellScript($ok));
        $this->assertNull($this->bridge()->savedPowerShellScript($off));
        $this->assertNull($this->bridge()->savedPowerShellScript($sh));
        $this->assertNull($this->bridge()->savedPowerShellScript(2_000_000_000));
    }

    public function testRecordRemoteSessionStoresTheFieldsAsGiven(): void
    {
        $asset = $this->createAsset();
        $this->bridge()->recordRemoteSession($asset, 6, 77, 'meshcentral', 'meshcentral:session:abc', '203.0.113.9', 'UA/1.0');
        $this->bridge()->recordRemoteSession($asset, 6, 78, 'meshcentral', 'meshcentral:session:def', null, null);
        $rows = $this->readRemoteSessions($asset);
        $this->assertCount(2, $rows);
        $first = $rows[0]['user_id'] === 77 ? $rows[0] : $rows[1];
        $second = $rows[0]['user_id'] === 77 ? $rows[1] : $rows[0];
        $this->assertSame([6, 77, 'meshcentral', 'meshcentral:session:abc', '203.0.113.9', 'UA/1.0'], [$first['client_id'], $first['user_id'], $first['connection_type'], $first['reference'], $first['ip_address'], $first['user_agent']]);
        $this->assertSame([78, 'meshcentral:session:def'], [$second['user_id'], $second['reference']]);
    }
}
