<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Software\SoftwareHash;
use RivetCore\Testing\InMemoryRmmEvents;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** The software inventory end to end through agent_checkin: offer, baseline, delta, resync, history, events, load shedding, queued ingest. */
final class SoftwareTest extends RmmTestCase
{
    private string $T = '';
    private int $dev = 0;
    private int $assetId = 0;

    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(null, null, ['allow_linux' => true], false, null, null, null, new InMemoryRmmEvents());
    }

    private function on(bool $feature = true): void
    {
        $this->h->enable();
        $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'inventory_software' => $feature]]);
    }

    private function linked(string $serial = 'SW-1'): void
    {
        $this->on();
        $this->assetId = $this->h->asset(['name' => 'Linux box', 'serial' => $serial]);
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 20), $this->h::device(['serial' => $serial, 'hostname' => 'LNX-1', 'os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'arch' => 'amd64']));
        $this->dev = (int) $j['device_id'];
        $this->T = $j['device_token'];
    }

    /**
     * @param list<array{0:string,1:string,2:string,3?:string}> $items [source, name, version, publisher]
     * @return array<string,mixed>
     */
    private static function item(array $i): array
    {
        return ['name' => $i[1], 'version' => $i[2], 'publisher' => $i[3] ?? '', 'source' => $i[0]];
    }

    /** @param list<array{0:string,1:string,2:string,3?:string}> $list */
    private static function hashOf(array $list): string
    {
        return SoftwareHash::of(array_map(static fn (array $i): array => ['source' => $i[0], 'name' => $i[1], 'version' => $i[2], 'publisher' => $i[3] ?? ''], $list));
    }

    /**
     * @param list<array{0:string,1:string,2:string,3?:string}> $list
     * @return array<string,mixed>
     */
    private static function full(array $list): array
    {
        return ['mode' => 'full', 'hash' => self::hashOf($list), 'count' => count($list), 'truncated' => false, 'items' => array_map(self::item(...), $list)];
    }

    /** @param array<string,mixed> $software @return array{0:int,1:array<string,string>,2:mixed,3:\RivetCore\Rmm\Http\RmmResponse} */
    private function send(array $software, array $caps = ['check:disk', 'job:shell', 'software_inventory']): array
    {
        return $this->h->checkin($this->T, ['platform' => 'linux', 'arch' => 'amd64', 'capabilities' => $caps, 'software' => $software]);
    }

    private function current(): array
    {
        return array_map(static fn (array $r): string => $r['name'] . '@' . $r['version'], $this->h->rows("SELECT name, version FROM rmm_device_software WHERE device_id = {$this->dev} AND removed_at IS NULL ORDER BY name"));
    }

    private function history(): array
    {
        return array_map(static fn (array $r): string => $r['change_type'] . ':' . $r['name'] . ':' . ($r['old_version'] ?? '-') . '>' . ($r['new_version'] ?? '-'),
            $this->h->rows("SELECT * FROM rmm_software_history WHERE device_id = {$this->dev} ORDER BY history_id"));
    }

    public function testTheOfferIsMadeOnlyToADeviceThatAnnouncedItAndOnlyWhileTheFeatureIsOn(): void
    {
        $this->linked();
        [$c, , $r] = $this->h->checkin($this->T);
        $this->assertSame(200, $c);
        $this->assertArrayNotHasKey('features', $r, 'an agent that announced nothing gets the old response');
        [, , $r] = $this->h->checkin($this->T, ['capabilities' => ['job:shell']]);
        $this->assertArrayNotHasKey('features', $r);
        [, , $r] = $this->h->checkin($this->T, ['capabilities' => ['job:shell', 'software_inventory']]);
        $this->assertSame(['software_inventory'], $r['features']);
        $this->assertArrayNotHasKey('resync', $r);
        $this->assertSame(['ok', 'status', 'matched_asset_id', 'next_check_in_s', 'jobs_pending', 'config', 'update', 'server_time', 'signing_key_id', 'features'], array_keys($r));
        $this->on(false);
        [, , $r] = $this->h->checkin($this->T, ['capabilities' => ['software_inventory']]);
        $this->assertArrayNotHasKey('features', $r, 'sub-switch off: not offered');
        $this->assertSame(['software_inventory'], json_decode((string) $this->h->one("SELECT capabilities_json FROM rmm_device_state WHERE device_id = {$this->dev}"), true), 'the latest announcement replaces the stored one');
    }

    public function testTheFirstFullReportIsABaselineWithoutHistoryOrEvents(): void
    {
        $this->linked();
        $list = [['dpkg', 'bash', '5.2', 'Ubuntu'], ['dpkg', 'curl', '8.5', 'Ubuntu'], ['snap', 'firefox', '131.0', 'mozilla']];
        [$c, , $r] = $this->send(self::full($list));
        $this->assertSame(200, $c);
        $this->assertSame(['bash@5.2', 'curl@8.5', 'firefox@131.0'], $this->current());
        $this->assertSame([], $this->history());
        $this->assertSame([], $this->h->events?->of(RmmEvent::SOFTWARE_INSTALLED));
        $st = $this->h->rows("SELECT * FROM rmm_device_state WHERE device_id = {$this->dev}")[0];
        $this->assertSame([self::hashOf($list), 3, 0], [$st['software_hash'], (int) $st['software_count'], (int) $st['software_resync']]);
        $this->assertNotNull($st['software_full_at']);
        $this->assertSame(['software_inventory'], $r['features']);
        $this->assertArrayNotHasKey('resync', $r);
        $this->assertSame(3, (int) $this->h->one("SELECT COUNT(*) FROM rmm_device_software WHERE device_id = {$this->dev} AND first_seen_at = last_seen_at AND removed_at IS NULL"));
    }

    public function testADeltaRecordsInstallUpgradeDowngradeRemovalAndPublishesEvents(): void
    {
        $this->linked();
        $base = [['dpkg', 'bash', '5.2', 'Ubuntu'], ['dpkg', 'curl', '8.5', 'Ubuntu'], ['dpkg', 'nano', '7.2', 'Ubuntu'], ['dpkg', 'vim', '9.1', 'Ubuntu']];
        $this->send(self::full($base));
        $after = [['dpkg', 'bash', '5.3', 'Ubuntu'], ['dpkg', 'curl', '8.5', 'Ubuntu'], ['dpkg', 'vim', '9.0', 'Ubuntu'], ['dpkg', 'git', '2.43', 'Ubuntu']];
        $delta = ['mode' => 'delta', 'base_hash' => self::hashOf($base), 'hash' => self::hashOf($after), 'count' => 4, 'truncated' => false,
            'items' => [self::item($after[0]), self::item($after[2]), self::item($after[3])], 'removed' => [['source' => 'dpkg', 'name' => 'nano']]];
        [$c, , $r] = $this->send($delta);
        $this->assertSame(200, $c);
        $this->assertArrayNotHasKey('resync', $r);
        $this->assertSame(['bash@5.3', 'curl@8.5', 'git@2.43', 'vim@9.0'], $this->current());
        $h = $this->history();
        sort($h);
        $this->assertSame(['downgraded:vim:9.1>9.0', 'installed:git:->2.43', 'removed:nano:7.2>-', 'upgraded:bash:5.2>5.3'], $h);
        $this->assertSame(['git'], array_column($this->h->events?->of(RmmEvent::SOFTWARE_INSTALLED) ?? [], 'name'));
        $this->assertSame(['nano'], array_column($this->h->events?->of(RmmEvent::SOFTWARE_REMOVED) ?? [], 'name'));
        $ev = $this->h->events?->of(RmmEvent::SOFTWARE_INSTALLED)[0] ?? [];
        $this->assertSame([$this->dev, $this->assetId, $this->h->clientA, 'LNX-1', '2.43', 'dpkg'], [$ev['device_id'], $ev['asset_id'], $ev['client_id'], $ev['hostname'], $ev['version'], $ev['source']]);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_device_software WHERE device_id = {$this->dev} AND name = 'nano' AND removed_at IS NOT NULL"), 'a removed item stays with removed_at');
        // reinstall
        $third = array_merge($after, [['dpkg', 'nano', '7.3', 'Ubuntu']]);
        $this->send(['mode' => 'delta', 'base_hash' => self::hashOf($after), 'hash' => self::hashOf($third), 'count' => 5, 'truncated' => false, 'items' => [self::item($third[4])], 'removed' => []]);
        $this->assertContains('nano@7.3', $this->current());
        $this->assertContains('installed:nano:->7.3', $this->history());
    }

    public function testADeltaOnAStaleBaseIsRefusedAndTheDeviceIsToldToSendEverything(): void
    {
        $this->linked();
        $base = [['dpkg', 'bash', '5.2']];
        $this->send(self::full($base));
        $bogus = ['mode' => 'delta', 'base_hash' => str_repeat('a', 64), 'hash' => str_repeat('b', 64), 'count' => 2, 'truncated' => false, 'items' => [self::item(['dpkg', 'evil', '1'])], 'removed' => []];
        [$c, , $r] = $this->send($bogus);
        $this->assertSame(200, $c, 'never an error');
        $this->assertSame(['software'], $r['resync']);
        $this->assertSame(['bash@5.2'], $this->current(), 'nothing from the refused delta was applied');
        // the full list that follows clears the request
        $list = [['dpkg', 'bash', '5.2'], ['dpkg', 'evil', '1']];
        [, , $r] = $this->send(self::full($list));
        $this->assertArrayNotHasKey('resync', $r);
        $this->assertSame(['bash@5.2', 'evil@1'], $this->current());
        $this->assertSame(['installed:evil:->1'], $this->history());
    }

    public function testADeltaThatDoesNotHashToWhatTheAgentSaidCostsOneFullList(): void
    {
        $this->linked();
        $base = [['dpkg', 'bash', '5.2']];
        $this->send(self::full($base));
        $delta = ['mode' => 'delta', 'base_hash' => self::hashOf($base), 'hash' => str_repeat('c', 64), 'count' => 2, 'truncated' => false, 'items' => [self::item(['dpkg', 'git', '2.43'])], 'removed' => []];
        [, , $r] = $this->send($delta);
        $this->assertSame(['software'], $r['resync']);
        $this->assertContains('git@2.43', $this->current(), 'the delta was applied, then flagged');
        [, , $r] = $this->send(self::full([['dpkg', 'bash', '5.2'], ['dpkg', 'git', '2.43']]));
        $this->assertArrayNotHasKey('resync', $r);
    }

    public function testATruncatedFullReportNeverRemovesWhatItDoesNotList(): void
    {
        $this->linked();
        $this->send(self::full([['dpkg', 'a', '1'], ['dpkg', 'b', '1'], ['dpkg', 'c', '1']]));
        $cut = self::full([['dpkg', 'a', '1']]);
        $cut['truncated'] = true;
        $this->send($cut);
        $this->assertSame(['a@1', 'b@1', 'c@1'], $this->current());
        $this->send(self::full([['dpkg', 'a', '1']]));
        $this->assertSame(['a@1'], $this->current(), 'a complete report does');
        $this->assertSame(['removed:b:1>-', 'removed:c:1>-'], array_slice($this->history(), 0, 2));
    }

    public function testAnUnchangedFullListOnlyRefreshesLastSeen(): void
    {
        $this->linked();
        $list = [['dpkg', 'a', '1'], ['dpkg', 'b', '2']];
        $this->send(self::full($list));
        $this->h->q("UPDATE rmm_device_software SET last_seen_at = '2020-01-01 00:00:00'");
        $this->send(self::full($list));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM rmm_device_software WHERE last_seen_at < '2021-01-01'"));
        $this->assertSame([], $this->history());
    }

    public function testHostileAndOversizedReportsAreIgnoredNotRejected(): void
    {
        $this->linked();
        foreach ([[], ['mode' => 'weird', 'hash' => 'x'], ['mode' => 'full', 'hash' => 'zz', 'items' => []], ['mode' => 'full', 'hash' => str_repeat('a', 64), 'items' => 'nope'],
            ['mode' => 'delta', 'hash' => str_repeat('a', 64), 'items' => []]] as $bad) {
            [$c] = $this->send($bad);
            $this->assertSame(200, $c);
        }
        $this->assertSame([], $this->current());
        $huge = self::full([]);
        $huge['items'] = array_fill(0, 5001, self::item(['dpkg', 'x', '1']));
        [$c] = $this->send($huge);
        $this->assertSame(200, $c);
        $this->assertSame([], $this->current(), 'more than 5000 items: the block is ignored');
        // junk inside a valid block: bad items are skipped, long strings cut, control characters become spaces
        $list = ['mode' => 'full', 'hash' => str_repeat('e', 64), 'count' => 4, 'truncated' => false, 'items' => [
            ['name' => str_repeat('n', 300), 'version' => "1.0\x00\x01evil", 'publisher' => '', 'source' => 'dpkg'],
            ['name' => 'no-source', 'version' => '1', 'source' => 'floppy'],
            ['name' => '', 'version' => '1', 'source' => 'dpkg'],
            'not an object',
            ['name' => 'dated', 'version' => '1', 'source' => 'rpm', 'installed' => '2026-02-30'],
        ]];
        $this->send($list);
        $rows = $this->h->rows("SELECT name, version, installed_on FROM rmm_device_software WHERE device_id = {$this->dev} ORDER BY name");
        $this->assertCount(2, $rows);
        $this->assertSame(['dated', null], [$rows[0]['name'], $rows[0]['installed_on']], 'an impossible date is dropped');
        $this->assertSame([200, '1.0 evil'], [mb_strlen($rows[1]['name']), $rows[1]['version']]);
    }

    public function testNothingIsAppliedWhileTheFeatureIsOffOrTheDeviceDidNotAnnounceIt(): void
    {
        $this->linked();
        $this->send(self::full([['dpkg', 'a', '1']]), ['job:shell']);
        $this->assertSame([], $this->current(), 'not announced');
        $this->on(false);
        $this->send(self::full([['dpkg', 'a', '1']]));
        $this->assertSame([], $this->current(), 'feature off');
    }

    public function testLoadSheddingAcknowledgesButDoesNotIngestAndAsksAgainLater(): void
    {
        $this->linked();
        $this->h->module->settings()->set(['shed_level' => 1]);
        [$c, , $r] = $this->send(self::full([['dpkg', 'a', '1']]));
        $this->assertSame(200, $c);
        $this->assertSame([], $this->current());
        $this->assertSame(['software'], $r['resync'] ?? [], 'the next response asks again');
        $this->h->module->settings()->set(['shed_level' => 0]);
        [, , $r] = $this->send(self::full([['dpkg', 'a', '1']]));
        $this->assertSame(['a@1'], $this->current());
        $this->assertArrayNotHasKey('resync', $r);
    }

    public function testQueuedIngestAppliesTheReportInTheWorker(): void
    {
        $this->linked();
        $this->h->module->settings()->set(['ingest_mode' => 'queued']);
        [$c] = $this->send(self::full([['dpkg', 'a', '1'], ['dpkg', 'b', '2']]));
        $this->assertSame(200, $c);
        $this->assertSame([], $this->current(), 'queued: not yet');
        $out = $this->h->module->ingestQueue()->drain();
        $this->assertSame(1, $out['completed']);
        $this->assertSame(['a@1', 'b@2'], $this->current());
        // a delta processed out of order is flagged, not applied
        $base = [['dpkg', 'a', '1'], ['dpkg', 'b', '2']];
        $second = array_merge($base, [['dpkg', 'c', '3']]);
        $third = array_merge($second, [['dpkg', 'd', '4']]);
        $this->send(['mode' => 'delta', 'base_hash' => self::hashOf($second), 'hash' => self::hashOf($third), 'count' => 4, 'truncated' => false, 'items' => [self::item(['dpkg', 'd', '4'])], 'removed' => []]);
        $this->h->module->ingestQueue()->drain();
        $this->assertSame(['a@1', 'b@2'], $this->current());
        [, , $r] = $this->h->checkin($this->T, ['capabilities' => ['software_inventory']]);
        $this->assertSame(['software'], $r['resync']);
    }

    public function testATechnicianRefreshAsksForAFullListAtTheNextCheckin(): void
    {
        $this->linked();
        $this->send(self::full([['dpkg', 'a', '1']]));
        $r = $this->h->module->inventory()->refreshSoftware($this->h->principal(), $this->dev);
        $this->assertTrue($r->ok);
        $this->assertSame(202, $r->http);
        [, , $resp] = $this->h->checkin($this->T, ['capabilities' => ['software_inventory']]);
        $this->assertSame(['software'], $resp['resync']);
    }

    public function testASecondDeviceHasItsOwnList(): void
    {
        $this->linked();
        $this->send(self::full([['dpkg', 'a', '1']]));
        $first = $this->dev;
        $this->h->asset(['name' => 'Other', 'serial' => 'SW-2']);
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 20), $this->h::device(['serial' => 'SW-2', 'hostname' => 'LNX-2', 'os' => 'linux']));
        $this->T = $j['device_token'];
        $this->dev = (int) $j['device_id'];
        $this->send(self::full([['dpkg', 'z', '9']]));
        $this->assertSame(['z@9'], $this->current());
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_device_software WHERE device_id = $first"));
    }
}
