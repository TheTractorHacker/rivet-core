<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Change\ChangeProtocol;
use RivetCore\Rmm\Change\ChangeService;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Testing\InMemoryRmmEvents;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RemoteTestCase;
use RivetCore\Tests\Support\RmmHarness;

/**
 * Change monitoring: off by default, a SIGNED configuration offered only to an agent that asks, bounded ingest of records a rogue device cannot forge, retention and caps,
 * search with client scope, the "who changed what" timeline, rules and alerts, events, and the Security log presets of the event-log collector.
 */
final class ChangeMonitorTest extends RemoteTestCase
{
    private InMemoryRmmEvents $bus;
    private int $b = 0;
    private string $bToken = '';
    private \RivetCore\Rmm\Authz\RmmPrincipal $manager;

    protected function makeHarness(): RmmHarness
    {
        $this->bus = new InMemoryRmmEvents();
        $this->transferDir = sys_get_temp_dir() . '/rmm_xfer_' . bin2hex(random_bytes(4));
        $view = [RmmAbility::DEVICE_VIEW, RmmAbility::CHANGE_VIEW];

        return new RmmHarness(null, null, ['allow_linux' => true, 'transfer_dir' => $this->transferDir], false, null,
            new AllowUsersPolicy([1 => true, 10 => $view, 11 => [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED], 12 => [RmmAbility::DEVICE_VIEW], 13 => [...$view, RmmAbility::CHANGE_MANAGE], 14 => [...$view, RmmAbility::SOFTWARE_VIEW], 16 => true]), null, $this->bus);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new \RivetCore\Rmm\Authz\RmmPrincipal(13, 'Manager');
        [$this->dev, $this->devToken] = $this->linked('W1', null, 'windows', self::caps());
    }

    /** @return list<string> */
    private static function caps(string $os = 'windows'): array
    {
        $c = [...self::allCaps($os), 'change:monitor'];
        sort($c);

        return $c;
    }

    private function enable(array $win = ['win.startup', 'win.defender', 'win.local_admins', 'win.hosts'], array $lnx = ['lnx.sudo', 'lnx.ssh'], array $limits = []): void
    {
        $this->switchOn(['change_monitor']);
        $this->assertSame([], $this->h->module->changeSettings()->update(['presets' => ['windows' => $win, 'linux' => $lnx]] + ($limits === [] ? [] : ['limits' => $limits]), 1));
    }

    private static function ch(array $over = []): array
    {
        return $over + ['ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 20), 'kind' => 'registry', 'watch' => 'win.startup', 'change' => 'added', 'target' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run',
            'item' => 'Updater', 'old' => '', 'new' => 'C:\\ProgramData\\x\\up.exe /silent', 'summary' => '', 'old_hash' => '', 'new_hash' => str_repeat('a', 64), 'actor' => '', 'process' => ''];
    }

    /**
     * @param list<mixed> $changes
     * @return array{0:int,1:array<string,mixed>|null,2:\RivetCore\Rmm\Http\RmmResponse}
     */
    private function ship(array $changes, ?string $token = null, int $dropped = 0, array $extra = []): array
    {
        $r = $this->h->api->handle($this->h->request('POST', 'agent_changes', (string) json_encode(['changes' => $changes, 'dropped' => $dropped, 'config_version' => 'a1b2c3d4e5f60718'] + $extra), $token ?? $this->devToken));

        return [$r->status, $r->body === null ? null : json_decode($r->body, true), $r];
    }

    // ------------------------------------------------------------------ off by default, offered signed, on request

    public function testOffByDefault(): void
    {
        [$c, $j] = $this->ship([self::ch()]);
        $this->assertSame([503, 'feature_disabled'], [$c, $j['code']]);
        $this->assertSame('3600', $this->h->call('POST', 'agent_changes', '{}', $this->devToken)[3]->headers['Retry-After']);
        $this->assertArrayNotHasKey('features', $this->announce($this->devToken, self::caps()));
        $this->assertFalse($this->h->module->settings()->featureOn('change_monitor'));
        $this->assertContains('change_monitor', \RivetCore\Rmm\Settings\RmmSettings::FEATURES);
        $this->assertNotContains('change_monitor', \RivetCore\Rmm\Settings\RmmSettings::LEGACY_FEATURES);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_change_records'));
    }

    /** rc.31 review F11 (migration 0038): seq is a stored counter, not the settings row's updated_at, so a restore or a clock step backwards cannot make agents refuse new configurations. */
    public function testTheConfigSeqIsAMonotonicCounterThatSurvivesARestoreAndAClockStepBack(): void
    {
        $this->switchOn(['change_monitor']);
        $this->enable();
        $first = $this->announce($this->devToken, self::caps())['config']['change'];
        $this->assertSame($first['seq'], $this->announce($this->devToken, self::caps())['config']['change']['seq'], 'an unchanged configuration keeps its seq');
        // agents accepted a seq from a clock that was an hour ahead, then the clock came back and the settings row looks old (a restored database): the next change must still go UP
        $ahead = $first['seq'] + 3600;
        $this->h->q("UPDATE endpoint_agent_config_seq SET seq = $ahead WHERE name = 'change:windows'");
        $this->h->q("UPDATE endpoint_agent_change_settings SET updated_at = '2001-01-01 00:00:00'");
        $this->h->module->changeSettings()->refresh();
        $this->assertSame($ahead, $this->announce($this->devToken, self::caps())['config']['change']['seq'], 'the stored counter is what is offered, whatever updated_at says');
        $this->h->module->changeSettings()->update(['limits' => ['change_rate_per_min' => 77]], 1);
        $second = $this->announce($this->devToken, self::caps())['config']['change'];
        $this->assertGreaterThan($ahead, $second['seq'], 'a change after a clock step back still raises seq above everything offered before');
        // and the same body again (a change reverted) is a change of body: it raises it again, it never reuses an old number
        $this->h->module->changeSettings()->update(['limits' => ['change_rate_per_min' => 120]], 1);
        $this->assertGreaterThan($second['seq'], $this->announce($this->devToken, self::caps())['config']['change']['seq']);
        $this->assertGreaterThanOrEqual(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_config_seq WHERE name LIKE 'change:%'"), 'a counter per platform');
    }

    public function testTheConfigSeqFallsBackToTheOldBehaviourBeforeMigration0038(): void
    {
        $this->switchOn(['change_monitor']);
        $this->enable();
        $this->h->q('DROP TABLE endpoint_agent_config_seq');
        \RivetCore\Rmm\Support\ConfigSequence::forget();
        $cfg = $this->announce($this->devToken, self::caps())['config']['change'];
        $this->assertSame(\RivetCore\Rmm\Support\Sql::ts((string) $this->h->one('SELECT updated_at FROM endpoint_agent_change_settings WHERE id=1')), $cfg['seq']);
        $this->h->q(\RivetCore\Rmm\Migration\Migration0038ConfigSeq::table());
        \RivetCore\Rmm\Support\ConfigSequence::forget();
    }

    public function testTheSignedConfigIsOfferedOnlyToAnAgentThatAskedWhileTheSwitchIsOnAndAWatchExists(): void
    {
        $this->switchOn(['change_monitor']);
        $this->assertArrayNotHasKey('features', $this->announce($this->devToken, self::caps()), 'no preset on: nothing to offer');
        $this->enable();
        $r = $this->announce($this->devToken, self::caps());
        $this->assertSame(['change:monitor'], $r['features']);
        $cfg = $r['config']['change'];
        $this->assertSame(['v', 'kind', 'platform', 'interval_s', 'batch_max', 'rate_per_min', 'max_value_bytes', 'watches', 'seq', 'version', 'signature'], array_keys($cfg));
        $this->assertSame([1, 'change_config', 'windows', 300, 100, 120, 256], [$cfg['v'], $cfg['kind'], $cfg['platform'], $cfg['interval_s'], $cfg['batch_max'], $cfg['rate_per_min'], $cfg['max_value_bytes']]);
        $this->assertGreaterThan(0, $cfg['seq']);
        $this->h->clock->advance(10);
        $this->h->module->changeSettings()->update(['limits' => ['change_rate_per_min' => 121]], 1);
        $this->assertGreaterThan($cfg['seq'], $this->announce($this->devToken, self::caps())['config']['change']['seq'], 'a settings change raises seq: an older signed configuration is a replay');
        $this->h->module->changeSettings()->update(['limits' => ['change_rate_per_min' => 120]], 1);
        $cfg = $this->announce($this->devToken, self::caps())['config']['change'];
        $this->assertSame(['win.startup', 'win.defender', 'win.local_admins', 'win.hosts'], array_column($cfg['watches'], 'id'));
        $this->assertSame(['registry', 'registry', 'group', 'file'], array_column($cfg['watches'], 'kind'));
        // the signature: Ed25519 over the domain line and the canonical object, by the module's key
        [, $pub] = $this->h->module->settings()->signingKey();
        $this->assertTrue(Signer::verify(Signer::changeConfigMessage($cfg), $cfg['signature'], $pub));
        $this->assertStringStartsWith("rivetit-change-config-v1\n{", Signer::changeConfigMessage($cfg));
        $tampered = $cfg;
        $tampered['watches'][0]['paths'][0]['key'] = 'HKLM\\SOFTWARE\\Other';
        $this->assertFalse(Signer::verify(Signer::changeConfigMessage($tampered), $cfg['signature'], $pub), 'one changed character breaks it');
        $retargeted = $cfg;
        $retargeted['platform'] = 'linux';
        $this->assertFalse(Signer::verify(Signer::changeConfigMessage($retargeted), $cfg['signature'], $pub), 'the platform is signed');
        // a job or check signature is never a configuration signature (domain separation)
        $plain = $cfg;
        unset($plain['signature']);
        $this->assertFalse(Signer::verify(Signer::jobMessage($plain), $cfg['signature'], $pub));
        $this->assertFalse(Signer::verify(Signer::checkMessage($plain), $cfg['signature'], $pub));
        $this->assertSame($cfg['version'], $this->announce($this->devToken, self::caps())['config']['change']['version'], 'the version only changes when the configuration does');
        // an agent that did not announce change:monitor is answered exactly as before
        $old = $this->announce($this->devToken, self::allCaps());
        $this->assertArrayNotHasKey('features', $old);
        $this->assertSame(['checks', 'collect_interval_s'], array_keys($old['config']));
        // the Linux device gets the Linux watches only
        [, $lt] = $this->linked('L1', null, 'linux', self::caps('linux'));
        $lr = $this->announce($lt, self::caps('linux'), 'linux');
        $this->assertSame(['lnx.ssh', 'lnx.sudo'], array_column($lr['config']['change']['watches'], 'id'));
        $this->assertSame('linux', $lr['config']['change']['platform']);
        $this->assertNotSame($cfg['version'], $lr['config']['change']['version']);
        // under load shedding level 2 the offer is withdrawn (and the endpoint refuses)
        $this->h->module->settings()->set(['shed_level' => 2]);
        $this->assertArrayNotHasKey('features', $this->announce($this->devToken, self::caps()));
        [$c, $j] = $this->ship([self::ch()]);
        $this->assertSame([503, 'unavailable'], [$c, $j['code']]);
        $this->h->module->settings()->set(['shed_level' => 1]);
        $this->assertSame(['change:monitor'], $this->announce($this->devToken, self::caps())['features'], 'level 1 still offers it: change records are not the first to go');
        $this->h->module->settings()->set(['shed_level' => 0]);
        // switched off again: nothing is offered
        $this->switchOn([]);
        $this->assertArrayNotHasKey('features', $this->announce($this->devToken, self::caps()));
    }

    public function testNoUsableSigningKeyMeansNothingIsOffered(): void
    {
        $this->enable();
        $this->assertSame(['change:monitor'], $this->announce($this->devToken, self::caps())['features']);
        $this->assertNotNull($this->h->module->changeSettings()->agentConfig('windows'));
        $this->h->q("UPDATE endpoint_agent_settings SET signing_private_key_enc = '' WHERE id = 1");
        $this->h->module->settings()->refresh();
        $this->assertNull($this->h->module->changeSettings()->agentConfig('windows'), 'an unsigned configuration is never offered');
        $this->assertNull($this->h->module->changeSettings()->agentConfig('linux'));
    }

    // ------------------------------------------------------------------ ingest

    public function testABatchIsStoredCleanedRedactedAndNeverDuplicated(): void
    {
        $this->enable();
        $a = self::ch();
        $b = self::ch(['item' => 'Backup', 'new' => "mysql --password=hunter2 \x07 --host a\nb", 'actor' => "CORP\\bob\x00", 'process' => 'C:\\Windows\\regedit.exe', 'change' => 'modified', 'old' => 'old.exe', 'old_hash' => str_repeat('b', 64)]);
        $c = self::ch(['item' => 'DbPassword', 'old' => 'topsecret1', 'new' => 'topsecret2', 'change' => 'modified']);
        $d = self::ch(['kind' => 'group', 'watch' => 'win.local_admins', 'target' => 'group:Administrators', 'item' => 'CORP\\mallory', 'old' => '', 'new' => '']);
        $e = self::ch(['kind' => 'file', 'watch' => 'win.hosts', 'target' => 'C:\\Windows\\System32\\drivers\\etc\\hosts', 'item' => '', 'change' => 'modified', 'summary' => '+1 -0 lines', 'new' => '10.0.0.5 bank.example.com']);
        [$code, $j] = $this->ship([$a, $b, $c, $d, $e]);
        $this->assertSame([200, true, 5, 0, 0], [$code, $j['ok'], $j['accepted'], $j['dropped'], $j['duplicates']]);
        $rows = $this->h->rows('SELECT * FROM endpoint_agent_change_records ORDER BY change_id');
        $this->assertCount(5, $rows);
        $this->assertSame(['added', 'modified', 'modified', 'added', 'modified'], array_map(static fn ($r) => ChangeProtocol::changeName((int) $r['change_type']), $rows));
        $this->assertStringNotContainsString('hunter2', implode('|', array_column($rows, 'new_value')));
        $this->assertStringNotContainsString("\n", implode('|', array_column($rows, 'new_value')));
        $this->assertSame('CORP\\bob', $rows[1]['actor'], 'control characters are removed');
        $this->assertSame('C:\\Windows\\regedit.exe', $rows[1]['process']);
        $this->assertSame([str_repeat('a', 32), ''], [$rows[0]['new_hash'], $rows[0]['old_hash']]);
        // a value whose NAME looks like a secret is never stored, whatever the device sends
        $this->assertSame(['[REDACTED]', '[REDACTED]', ChangeProtocol::FLAG_REDACTED], [$rows[2]['old_value'], $rows[2]['new_value'], (int) $rows[2]['flags']]);
        $this->assertStringNotContainsString('topsecret', json_encode($rows) ?: '');
        $this->assertSame(5, (int) $this->h->one("SELECT row_count FROM endpoint_agent_change_state WHERE device_id=$this->dev"));
        $this->assertSame('a1b2c3d4e5f60718', $this->h->one("SELECT config_version FROM endpoint_agent_change_state WHERE device_id=$this->dev"));
        // the agent never got the answer and sends the same batch again: stored once
        [$code, $j] = $this->ship([$a, $b, $c, $d, $e]);
        $this->assertSame([200, 0, 5], [$code, $j['accepted'], $j['duplicates']]);
        $this->assertSame(5, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_change_records'));
        // records it cannot understand are dropped and counted, never an error for the batch
        $bad = [['ts' => 'nope'] + self::ch(), self::ch(['change' => 'exploded']), self::ch(['kind' => 'nope']), 'junk', 5, self::ch(['ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 400 * 86400)]), self::ch(['item' => 'Fine', 'new' => 'x.exe'])];
        [$code, $j] = $this->ship($bad, null, 7);
        $this->assertSame([200, 1, 6], [$code, $j['accepted'], $j['dropped']]);
        $this->assertSame(13, (int) $this->h->one("SELECT dropped_total FROM endpoint_agent_change_state WHERE device_id=$this->dev"), 'the agent\'s own drops are counted too');
        // a malformed body
        foreach ([['changes' => 'x'], ['changes' => ['a' => 1]], ['changes' => array_fill(0, 101, self::ch())], ['changes' => [], 'dropped' => -1], ['changes' => [], 'dropped' => 'x']] as $badBody) {
            $this->assertSame(422, $this->h->api->handle($this->h->request('POST', 'agent_changes', (string) json_encode($badBody), $this->devToken))->status, json_encode($badBody));
        }
        $this->assertSame(413, $this->h->api->handle($this->h->request('POST', 'agent_changes', str_repeat(' ', 262145), $this->devToken))->status);
        $this->assertSame(401, $this->h->api->handle($this->h->request('POST', 'agent_changes', '{"changes":[]}', null))->status);
        $this->assertSame(405, $this->h->api->handle($this->h->request('GET', 'agent_changes', null, $this->devToken))->status);
        $this->assertSame(200, $this->h->api->handle($this->h->request('POST', 'agent_changes', '{}', $this->devToken))->status, 'an empty body is an empty batch (the status heartbeat)');
    }

    public function testSudoersTagsAreNotTakenForSecretsButRealSecretsStillAre(): void
    {
        $this->enable(['win.hosts'], ['lnx.sudo', 'lnx.ssh']);
        [, $lt] = $this->linked('L2', null, 'linux', self::caps('linux'));
        $post = fn (array $c): array => json_decode((string) $this->h->api->handle($this->h->request('POST', 'agent_changes', (string) json_encode(['changes' => [$c]]), $lt))->body, true);
        $base = ['ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 20), 'kind' => 'file', 'watch' => 'lnx.sudo', 'change' => 'added', 'target' => '/etc/sudoers.d/90-x', 'item' => '', 'old' => '',
            'new' => 'mallory ALL=(ALL) NOPASSWD: ALL | Defaults password=hunter2', 'summary' => 'file added (2 lines)'];
        $this->assertSame(1, $post($base)['accepted']);
        $row = $this->h->rows("SELECT new_value, flags FROM endpoint_agent_change_records WHERE target = '/etc/sudoers.d/90-x'")[0];
        $this->assertSame('mallory ALL=(ALL) NOPASSWD: ALL | Defaults password=[REDACTED]', $row['new_value']);
        $this->assertSame(ChangeProtocol::FLAG_REDACTED, (int) $row['flags']);
        $this->assertSame(1, $post(['target' => '/etc/ssh/sshd_config', 'watch' => 'lnx.ssh', 'new' => 'NOPASSWD: ALL token: abc'] + $base)['accepted']);
        $this->assertSame('NOPASSWD: [REDACTED] token: [REDACTED]', $this->h->one("SELECT new_value FROM endpoint_agent_change_records WHERE target = '/etc/ssh/sshd_config'"), 'the exception is for sudoers files only');
    }

    public function testCommandLineSecretsAreMaskedOnTheServerToo(): void
    {
        foreach (['curl -u bob:s3cr3t http://x' => 's3cr3t', 'mysql -h db -u root -pS3CRET1 -e x' => 'S3CRET1', 'schtasks /create /RP topsecret9 /TN x' => 'topsecret9',
            'app /p:pw1234 /q' => 'pw1234', 'git clone https://bob:tok3n123@host/r.git' => 'tok3n123', 'net use Z: \\\\s\\x /user:bob hunter9' => 'hunter9'] as $in => $leak) {
            $this->assertStringNotContainsString($leak, ChangeProtocol::redact($in), $in);
        }
        $this->assertSame('x PASSWD: /bin/ls', ChangeProtocol::redact('x PASSWD: /bin/ls', true));
        $this->assertStringContainsString('[REDACTED]', ChangeProtocol::redact('xPASSWD: abc', true), 'a word boundary, not a substring');
    }

    public function testTheAgentsSkippedCountIsStoredAndShown(): void
    {
        $this->enable();
        $this->ship([], null, 0, ['status' => ['watches' => [['id' => 'win.startup', 'state' => 'partial', 'items' => 3, 'error' => 'a key was skipped by this agent\'s policy', 'skipped' => 4]], 'resumed_after_s' => 7200]]);
        $s = $this->api('GET', [(string) $this->dev, 'changes', 'status'], $this->tech)[1];
        $this->assertSame([4, 'partial'], [$s['device']['monitor']['watches'][0]['skipped'], $s['device']['monitor']['watches'][0]['state']]);
        $this->assertSame(7200, $s['device']['monitor']['resumed_after_s'], 'the operator sees that the offer was gone for a while');
    }

    public function testAResentBatchStillCostsTheRateBudget(): void
    {
        $this->enable(limits: ['change_rate_per_min' => 10]);
        $batch = array_map(static fn (int $i): array => self::ch(['item' => "d$i"]), range(1, 6));
        $this->assertSame(6, $this->ship($batch)[1]['accepted']);
        $this->ship($batch);   // a resend: all duplicates, but 6 more against this minute's budget of 10
        [, $j] = $this->ship(array_map(static fn (int $i): array => self::ch(['item' => "n$i"]), range(1, 6)));
        $this->assertSame([0, 6], [$j['accepted'], $j['dropped']], 'duplicates are not free');
    }

    public function testLongValuesAreCutAndFlagged(): void
    {
        $this->enable(limits: ['change_max_value_bytes' => 64]);
        $this->ship([self::ch(['new' => str_repeat('pq ', 200), 'old' => 'short'])]);
        $row = $this->h->rows('SELECT * FROM endpoint_agent_change_records')[0];
        $this->assertSame(64, mb_strlen($row['new_value']));
        $this->assertSame(ChangeProtocol::FLAG_TRUNCATED, (int) $row['flags']);
        $this->ship([self::ch(['item' => 'Other', 'new' => str_repeat('é', 600)])]);
        $this->assertSame(64, mb_strlen((string) $this->h->one("SELECT new_value FROM endpoint_agent_change_records WHERE item='Other'")));
    }

    public function testARogueDeviceCannotRecordWhatItWasNotToldToWatch(): void
    {
        $this->enable();
        $run = 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run';
        $forged = [
            'a watch it was never given' => self::ch(['watch' => 'win.firewall']),
            'an invented watch' => self::ch(['watch' => 'custom.evil']),
            'a watch id of the wrong shape' => self::ch(['watch' => "win.startup' OR 1=1"]),
            'the wrong kind for the watch' => self::ch(['kind' => 'file']),
            'a Linux watch on a Windows device' => self::ch(['watch' => 'lnx.sudo', 'kind' => 'file', 'target' => '/etc/sudoers']),
            'a key outside the watched keys' => self::ch(['target' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\x']),
            'a sibling that only shares the prefix' => self::ch(['target' => $run . 'Services']),
            'the SAM hive' => self::ch(['target' => 'HKLM\\SAM\\SAM\\Domains\\Account']),
            'the SAM hive under a watched name' => self::ch(['target' => $run . '\\..\\..\\..\\..\\SAM']),
            'a control character in the target' => self::ch(['target' => $run . "\x00\\x"]),
            'a group the device does not watch' => self::ch(['kind' => 'group', 'watch' => 'win.local_admins', 'target' => 'group:Remote Desktop Users']),
            'a file outside the hosts path' => self::ch(['kind' => 'file', 'watch' => 'win.hosts', 'target' => 'C:\\Windows\\System32\\config\\SAM']),
            'a hosts lookalike' => self::ch(['kind' => 'file', 'watch' => 'win.hosts', 'target' => 'C:\\Windows\\System32\\drivers\\etc\\hosts.evil\\..\\..\\..\\config\\SAM']),
            'no target' => self::ch(['target' => '']),
            'an array target' => self::ch(['target' => ['x']]),
        ];
        [, $j] = $this->ship(array_values($forged));
        $this->assertSame([0, count($forged)], [$j['accepted'], $j['dropped']], implode(', ', array_keys($forged)));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_change_records'));
        // a key BELOW a watched key is fine, and so is the key itself
        [, $j] = $this->ship([self::ch(['target' => $run, 'item' => 'a']), self::ch(['target' => $run . '\\Sub', 'item' => 'b'])]);
        $this->assertSame(2, $j['accepted']);
        // user hives: HKU\<sid> matches HKU\*
        [, $j] = $this->ship([self::ch(['target' => 'HKU\\S-1-5-21-1-2-3-1001\\Software\\Microsoft\\Windows\\CurrentVersion\\Run', 'item' => 'u'])]);
        $this->assertSame(1, $j['accepted']);
        // one device cannot write for another: the device id is the credential's, nothing in the body names one
        [$d2, $t2] = $this->linked('W2', null, 'windows', self::caps());
        $this->ship([self::ch(['item' => 'mine', 'device_id' => $d2, 'client_id' => 999])], $this->devToken);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_change_records WHERE device_id=$d2"));
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_change_records WHERE device_id=$this->dev AND item='mine'"));
        // the policy is asked again for every record: tighten it and an already-saved watch can no longer record
        $this->assertNotSame('', $t2);
    }

    public function testASecretSavedInAnEarlierWatchStillCannotBeRecordedOnceThePolicyDeniesIt(): void
    {
        $this->enable();
        $this->assertSame([], $this->h->module->changeSettings()->update(['custom' => [['id' => 'tmp', 'platform' => 'windows', 'kind' => 'registry', 'paths' => [['key' => 'HKLM\\SOFTWARE\\Acme\\Agent', 'depth' => 1]]]]], 1));
        $this->assertContains('custom.tmp', array_column($this->announce($this->devToken, self::caps())['config']['change']['watches'], 'id'));
        $deny = new class () implements \RivetCore\Rmm\Contracts\ChangeWatchPolicyInterface {
            public function allows(string $kind, string $target, int $clientId): bool
            {
                return !str_contains($target, 'Acme');
            }
        };
        $this->h->module->setChangeWatchPolicy($deny);   // an edition sets its policy before the module is first used; the services built after it use it
        $this->assertNotContains('custom.tmp', array_column($this->h->module->changeSettings()->agentConfig('windows')['watches'], 'id'), 'the configuration is rebuilt through the policy');
        $dev = $this->h->module->devices()->find($this->dev);
        $out = $this->h->module->changeService()->ingest((array) $dev, ['changes' => [self::ch(['watch' => 'custom.tmp', 'target' => 'HKLM\\SOFTWARE\\Acme\\Agent'])]]);
        $this->assertSame(0, $out['accepted']);
    }

    // ------------------------------------------------------------------ volume control

    public function testTheDeviceHasARateBudgetAndRowCapAndTheFleetACap(): void
    {
        $this->enable(limits: ['change_rate_per_min' => 10, 'change_device_max_rows' => 100, 'change_fleet_max_rows' => 1000]);
        $many = static fn (int $n, string $tag): array => array_map(static fn (int $i): array => self::ch(['item' => "$tag $i", 'ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 100 - $i)]), range(1, $n));
        [, $j] = $this->ship($many(12, 'a'));
        $this->assertSame([10, 2], [$j['accepted'], $j['dropped']], 'ten per minute, the rest is counted and dropped');
        $this->h->clock->advance(61);
        [, $j] = $this->ship($many(5, 'b'));
        $this->assertSame(5, $j['accepted'], 'the budget is per minute');
        $this->enable(limits: ['change_rate_per_min' => 2000, 'change_device_max_rows' => 100, 'change_fleet_max_rows' => 1000]);
        $this->h->clock->advance(61);
        $this->ship($many(100, 'c'));
        $this->h->clock->advance(61);
        $this->ship($many(100, 'd'));
        $this->assertSame(120, (int) $this->h->one("SELECT row_count FROM endpoint_agent_change_state WHERE device_id=$this->dev"), 'a little above the cap is tolerated');
        $this->h->clock->advance(61);
        [, $j] = $this->ship($many(10, 'e'));
        $this->assertSame([0, 10], [$j['accepted'], $j['dropped']]);
        $out = $this->h->module->changeService()->prune();
        $this->assertSame(20, $out['device_cap']);
        $this->assertSame(100, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_change_records'));
        // the rate bucket of the endpoint
        $this->h->rateCalls = [];
        $this->h->rateOverride['agent_changes:' . $this->dev] = 2;
        $codes = array_map(fn (): int => $this->ship([])[0], range(1, 4));
        $this->assertSame([200, 200, 429, 429], $codes);
        $this->assertSame([30, 60], $this->h->rateArgs['agent_changes:' . $this->dev]);
        // the fleet ceiling is a cap for everyone
        [, $t2] = $this->linked('W2', null, 'windows', self::caps());
        $this->h->q("UPDATE endpoint_agent_change_state SET row_count = 1090 WHERE device_id=$this->dev");
        $this->h->clock->advance(61);
        [, $j] = $this->ship($many(50, 'z'), $t2);
        $this->assertSame([10, 40], [$j['accepted'], $j['dropped']]);
    }

    public function testRetentionIsPrunedInBatchesAndCountersStayExact(): void
    {
        $this->enable(limits: ['change_retention_days' => 1]);
        [$d2] = $this->linked('W2', null, 'windows', self::caps());
        $old = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
        $new = gmdate('Y-m-d H:i:s', time() - 60);
        $rows = [];
        for ($i = 0; $i < 4500; ++$i) {
            $dev = $i % 3 === 0 ? $d2 : $this->dev;
            $rows[] = "($dev, '$old', '$old', 'registry', 'win.startup', 1, 'HKLM\\\\x', 'i$i', UNHEX('" . substr(hash('sha256', "o$i"), 0, 32) . "'))";
        }
        for ($i = 0; $i < 50; ++$i) {
            $rows[] = "($this->dev, '$new', '$new', 'registry', 'win.startup', 1, 'HKLM\\\\x', 'n$i', UNHEX('" . substr(hash('sha256', "n$i"), 0, 32) . "'))";
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            $this->h->q('INSERT INTO endpoint_agent_change_records (device_id, occurred_at, received_at, kind, watch, change_type, target, item, dedupe) VALUES ' . implode(',', $chunk));
        }
        $this->h->q("INSERT INTO endpoint_agent_change_state (device_id, row_count) VALUES ($this->dev, 3050) ON DUPLICATE KEY UPDATE row_count = 3050");
        $this->h->q("INSERT INTO endpoint_agent_change_state (device_id, row_count) VALUES ($d2, 1500) ON DUPLICATE KEY UPDATE row_count = 1500");
        $pauses = 0;
        $out = $this->h->module->changeService()->prune(function (int $us) use (&$pauses): void {
            ++$pauses;
        });
        $this->assertSame(4500, $out['retention']);
        $this->assertSame(3, $pauses, 'deleted in batches of ' . ChangeService::PRUNE_BATCH . ' with a pause between');
        $this->assertSame(50, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_change_records'));
        $this->assertSame(50, (int) $this->h->one("SELECT row_count FROM endpoint_agent_change_state WHERE device_id=$this->dev"));
        $this->assertSame(0, (int) $this->h->one("SELECT row_count FROM endpoint_agent_change_state WHERE device_id=$d2"));
        $this->assertArrayHasKey('pruned_changes_retention', $this->h->module->housekeeping()->run(), 'housekeeping runs it');
    }

    // ------------------------------------------------------------------ search, scope, timeline

    private function seed(): void
    {
        $this->enable();
        [$this->b, $this->bToken] = $this->linked('B1', $this->h->clientB, 'windows', self::caps());
        $run = 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run';
        $this->ship([
            self::ch(['item' => 'Updater', 'ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 100), 'actor' => 'CORP\\bob', 'process' => 'C:\\Windows\\reg.exe']),
            self::ch(['item' => 'Cleaner', 'change' => 'removed', 'old' => 'c.exe', 'new' => '', 'ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 200)]),
            self::ch(['kind' => 'group', 'watch' => 'win.local_admins', 'target' => 'group:Administrators', 'item' => 'CORP\\mallory', 'new' => '', 'ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 50)]),
            self::ch(['kind' => 'registry', 'watch' => 'win.defender', 'target' => 'HKLM\\SOFTWARE\\Microsoft\\Windows Defender\\Exclusions\\Paths', 'item' => 'C:\\Tools', 'new' => '0', 'ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 3000)]),
        ]);
        $this->ship([self::ch(['item' => 'BOnly', 'target' => $run])], $this->bToken);
    }

    public function testSearchByDeviceTimeKindWatchChangeTextAndActor(): void
    {
        $this->seed();
        $items = fn (array $f, ?string $dev = null): array => array_column($this->api('GET', [$dev ?? (string) $this->dev, 'changes'], $this->tech, null, $f)[1]['items'], 'item');
        [$c, $j] = $this->api('GET', [(string) $this->dev, 'changes'], $this->tech, null, ['from' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
        $this->assertSame(200, $c);
        $this->assertSame(['CORP\\mallory', 'Updater', 'Cleaner', 'C:\\Tools'], array_column($j['items'], 'item'), 'newest first');
        $this->assertSame(4, $j['total']);
        $first = $j['items'][1];
        $this->assertSame(['registry', 'win.startup', 'added', 'CORP\\bob', 'C:\\Windows\\reg.exe', $this->dev, 'W1'], [$first['kind'], $first['watch'], $first['change'], $first['actor'], $first['process'], $first['device_id'], $first['hostname']]);
        $this->assertSame(['CORP\\mallory'], $items(['kind' => 'group']));
        $this->assertSame(['C:\\Tools'], $items(['watch' => 'win.defender']));
        $this->assertSame(['Cleaner'], $items(['change' => 'removed']));
        $this->assertSame(['C:\\Tools'], $items(['target' => 'exclusions']));
        $this->assertSame(['Updater'], $items(['actor' => 'bob']));
        $this->assertSame(['Cleaner'], $items(['q' => 'c.exe']));
        $this->assertSame([], $items(['q' => 'zzz%']), 'a percent sign is text, not a wildcard');
        $this->assertSame([], $items(['q' => "x' OR '1'='1"]));
        $this->assertCount(4, $items(['watch' => "win.startup' OR 1=1"]), 'a malformed watch filter is ignored (everything is listed), never injected');
        $this->assertSame(['Updater'], $items(['from' => gmdate('Y-m-d\TH:i:s\Z', time() - 150), 'to' => gmdate('Y-m-d\TH:i:s\Z', time() - 75)]));
        $this->assertSame(['Updater'], array_column($this->api('GET', [(string) $this->dev, 'changes'], $this->tech, null, ['limit' => '1', 'offset' => '1'])[1]['items'], 'item'));
        $this->assertSame(200, $this->api('GET', [(string) $this->dev, 'changes'], $this->tech, null, ['from' => 'garbage', 'to' => '9999-99-99'])[0]);
        $s = $this->api('GET', [(string) $this->dev, 'changes', 'status'], $this->tech)[1];
        $this->assertSame([4, 5000, 90, true], [$s['device']['rows'], $s['device_max_rows'], $s['retention_days'], $s['enabled']]);
        $this->assertSame(['win.startup', 'win.defender', 'win.local_admins', 'win.hosts'], $s['watches']);
    }

    public function testFleetSearchAndEveryRouteAreScopedAndGrantChecked(): void
    {
        $this->seed();
        $fleet = fn (\RivetCore\Rmm\Authz\RmmPrincipal $who, array $q = []): array => $this->api('GET', ['changes'], $who, null, $q);
        [$c, $j] = $fleet($this->tech);
        $this->assertSame(200, $c);
        $this->assertCount(5, $j['items']);
        [, $j] = $fleet($this->scoped);
        $this->assertSame(['BOnly'], array_column($j['items'], 'item'), 'a technician limited to client B sees only client B\'s changes');
        $this->assertSame(404, $fleet($this->scoped, ['device_id' => (string) $this->dev])[0]);
        $this->assertSame(404, $this->api('GET', [(string) $this->dev, 'changes'], $this->scoped)[0]);
        $this->assertSame(404, $this->api('GET', [(string) $this->dev, 'changes', 'timeline'], $this->scoped)[0]);
        $this->assertSame(404, $this->api('GET', [(string) $this->dev, 'changes', 'status'], $this->scoped)[0]);
        $this->assertSame(404, $fleet($this->scoped, ['client_id' => (string) $this->h->clientA])[0]);
        $this->assertSame(200, $fleet($this->scoped, ['device_id' => (string) $this->b])[0]);
        $this->assertSame(404, $this->api('GET', ['9999', 'changes'], $this->admin)[0]);
        // the grants: change.view is its own ability
        foreach ([$this->runner, $this->viewer, $this->h->principal(99, 'Nobody')] as $who) {
            foreach ([[(string) $this->dev, 'changes'], [(string) $this->dev, 'changes', 'timeline'], [(string) $this->dev, 'changes', 'status'], ['changes'], ['changes', 'stats'], ['changes', 'settings'], ['changes', 'rules']] as $path) {
                $this->assertSame(403, $this->api('GET', $path, $who)[0], $who->userName . ' ' . implode('/', $path));
            }
        }
        $this->assertSame(1, $this->api('GET', ['changes', 'stats'], $this->scoped)[1]['fleet_rows'], 'the fleet figure of a restricted caller is theirs only');
        $this->assertSame(['win.startup' => 1], array_column($this->api('GET', ['changes', 'stats'], $this->scoped)[1]['last_24h_by_watch'], 'count', 'watch'));
        // read only: nothing else is accepted on a read route, and unknown paths are 404
        $this->assertSame(405, $this->api('POST', [(string) $this->dev, 'changes'], $this->admin, [])[0]);
        $this->assertSame(405, $this->api('DELETE', ['changes'], $this->admin)[0]);
        $this->assertSame(404, $this->api('GET', [(string) $this->dev, 'changes', 'nope'], $this->admin)[0]);
        $this->assertSame(404, $this->api('GET', [(string) $this->dev, 'changes', 'timeline', 'x'], $this->admin)[0]);
        $this->assertSame(404, $this->api('GET', ['changes', 'nope'], $this->admin)[0]);
        // the read model for an edition page
        $this->assertSame(5, $this->h->module->readModel()->changeSearch([], [$this->h->clientA, $this->h->clientB])['total']);
        $this->assertSame(1, $this->h->module->readModel()->changeSearch([], [$this->h->clientB])['total']);
        $this->assertSame(0, $this->h->module->readModel()->changeSearch([], [])['total']);
        $this->assertSame(5, $this->h->module->readModel()->changeStats()['fleet_rows']);
        // moving a device to another client moves its changes with it: the client is stored with each record (phase 2 of #89), so the move goes through DeviceService::transfer()
        $this->assertTrue($this->h->module->deviceService()->transfer($this->dev, $this->h->clientB, 0, 1));
        $this->assertCount(5, $fleet($this->scoped)[1]['items']);
    }

    public function testASearchWindowIsBounded(): void
    {
        $this->seed();
        $r = $this->h->module->changeService()->search(['from' => '2000-01-01T00:00:00Z'], null);
        $this->assertSame(30 * 86400, strtotime($r['to']) - strtotime($r['from']), 'a fleet search spans at most 30 days');
        $r = $this->h->module->changeService()->search(['device_id' => $this->dev, 'from' => '2000-01-01T00:00:00Z'], null);
        $this->assertSame(365 * 86400, strtotime($r['to']) - strtotime($r['from']));
        $this->assertLessThanOrEqual(500, count($this->h->module->changeService()->search([], null, 99999)['items']));
    }

    public function testTheTimelineIsWhoChangedWhatAndMergesTheSoftwareInventory(): void
    {
        $this->seed();
        $now = gmdate('Y-m-d H:i:s', time() - 120);
        $this->h->q("INSERT INTO rmm_software_history (device_id, software_key, name, source, change_type, old_version, new_version, publisher, occurred_at) VALUES
            ($this->dev, 'k1', 'AcmeCAD', 'registry', 'added', NULL, '1.2', 'Acme', '$now'), ($this->dev, 'k2', 'Putty', 'registry', 'removed', '0.80', NULL, '', '$now')");
        // rc.31 review F6: a caller who may see changes but not software does not get the software inventory history through the timeline
        [$c, $plain] = $this->api('GET', [(string) $this->dev, 'changes', 'timeline'], $this->tech);
        $this->assertSame(200, $c);
        $this->assertSame(['agent'], array_values(array_unique(array_column($plain['items'], 'source'))));
        $this->assertNotContains('AcmeCAD', array_column($plain['items'], 'target'));
        [$c, $j] = $this->api('GET', [(string) $this->dev, 'changes', 'timeline'], new \RivetCore\Rmm\Authz\RmmPrincipal(14, 'ChangesAndSoftware'));
        $this->assertSame(200, $c);
        $sources = array_unique(array_column($j['items'], 'source'));
        sort($sources);
        $this->assertSame(['agent', 'inventory'], $sources);
        $names = array_column($j['items'], 'target');
        $this->assertContains('AcmeCAD', $names);
        $this->assertContains('HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run', $names);
        $sorted = array_column($j['items'], 'occurred_at');
        $desc = $sorted;
        rsort($desc);
        $this->assertSame($desc, $sorted, 'newest first across both sources');
        $soft = array_values(array_filter($j['items'], static fn ($i) => $i['source'] === 'inventory' && $i['target'] === 'AcmeCAD'))[0];
        $this->assertSame(['software', 'added', '', '1.2'], [$soft['kind'], $soft['change'], $soft['old_value'], $soft['new_value']]);
        $actors = array_column($j['actors'], 'count', 'actor');
        ksort($actors);
        $this->assertSame(['' => 3, 'CORP\\bob' => 1], $actors, 'who: the people Windows recorded, and the changes with no recorded actor');
        $this->assertCount(1, $j['days']);
        $this->assertSame(4, $j['days'][0]['count']);
        $this->assertSame('W1', $j['hostname']);
    }

    // ------------------------------------------------------------------ rules, alerts, events

    /** @return array<string,mixed> */
    private function rule(array $over = [], ?\RivetCore\Rmm\Authz\RmmPrincipal $who = null): array
    {
        [$c, $j] = $this->api('POST', ['changes', 'rules'], $who ?? $this->admin, $over + ['name' => 'Run keys', 'watch' => 'win.startup', 'severity' => 'fail']);
        $this->assertSame(201, $c, json_encode($j));

        return $j;
    }

    public function testRuleManagementAndItsScope(): void
    {
        $this->enable();
        $r = $this->rule();
        $this->assertSame(['Run keys', 'win.startup', 'any', 'change.rule_' . $r['rule_id'], true, 0], [$r['name'], $r['watch'], $r['change'], $r['check_key'], $r['enabled'], $r['client_id']]);
        foreach ([['name' => ''], ['name' => 'x'], ['name' => 'x', 'watch' => 'win.startup', 'regex' => '.*'], ['name' => 'x', 'watch' => 'nope'], ['name' => 'x', 'watch' => 'win.startup', 'threshold' => 0],
            ['name' => 'x', 'watch' => 'win.startup', 'change' => 'exploded'], ['name' => 'x', 'kind' => 'bogus'], ['name' => 'x', 'watch' => 'win.startup', 'severity' => 'boom'], ['name' => str_repeat('a', 101), 'watch' => 'win.startup']] as $bad) {
            $this->assertSame(422, $this->api('POST', ['changes', 'rules'], $this->admin, $bad)[0], json_encode($bad));
        }
        $this->assertCount(1, $this->api('GET', ['changes', 'rules'], $this->tech)[1]['data']);
        $this->assertSame(403, $this->api('POST', ['changes', 'rules'], $this->tech, ['name' => 'x', 'watch' => 'win.startup'])[0], 'rules need change.manage');
        [$c, $j] = $this->api('PATCH', ['changes', 'rules', (string) $r['rule_id']], $this->manager, ['threshold' => 3, 'window_min' => 10, 'change' => 'added']);
        $this->assertSame([200, 3, 10, 'added', 'Run keys'], [$c, $j['threshold'], $j['window_min'], $j['change'], $j['name']]);
        $this->assertSame(404, $this->api('GET', ['changes', 'rules', '9999'], $this->admin)[0]);
        $this->assertSame(404, $this->api('DELETE', ['changes', 'rules', 'x'], $this->admin)[0]);
        $this->assertSame(403, $this->api('POST', ['changes', 'rules'], $this->scoped, ['name' => 'g', 'watch' => 'win.startup'])[0], 'an all-clients rule needs all-client access');
        $this->assertSame(404, $this->api('POST', ['changes', 'rules'], $this->scoped, ['name' => 'a', 'watch' => 'win.startup', 'client_id' => $this->h->clientA])[0]);
        $mine = $this->api('POST', ['changes', 'rules'], $this->scoped, ['name' => 'b', 'watch' => 'win.startup', 'client_id' => $this->h->clientB]);
        $this->assertSame(201, $mine[0]);
        $this->assertSame([$mine[1]['rule_id']], array_column($this->api('GET', ['changes', 'rules'], $this->scoped)[1]['data'], 'rule_id'));
        $this->assertSame(404, $this->api('GET', ['changes', 'rules', (string) $r['rule_id']], $this->scoped)[0]);
        $this->assertSame(404, $this->api('PATCH', ['changes', 'rules', (string) $r['rule_id']], $this->scoped, ['name' => 'hijack'])[0]);
        $this->assertSame(404, $this->api('DELETE', ['changes', 'rules', (string) $r['rule_id']], $this->scoped)[0]);
        $this->assertSame(200, $this->api('DELETE', ['changes', 'rules', (string) $mine[1]['rule_id']], $this->scoped)[0]);
        $audit = json_encode($this->h->audit->records()) ?: '';
        $this->assertStringContainsString('Change Rule Created', $audit);
        $this->assertStringContainsString('Change Rule Changed', $audit);
        $this->assertStringContainsString('Change Rule Deleted', $audit);
    }

    public function testTheRecommendedRulesAreCreatedOnceAndAnyChangeToARunKeyAlerts(): void
    {
        $this->enable();
        [$c, $j] = $this->api('POST', ['changes', 'rules', 'recommended'], $this->manager, []);
        $this->assertSame(201, $c);
        $created = count($j['created']);
        $this->assertGreaterThanOrEqual(5, $created);
        $watches = array_column($j['data'], 'watch');
        foreach (['win.startup', 'win.defender', 'win.local_admins', 'lnx.ssh', 'lnx.sudo'] as $w) {
            $this->assertContains($w, $watches);
        }
        $again = $this->api('POST', ['changes', 'rules', 'recommended'], $this->manager, [])[1];
        $this->assertSame([], $again['created'], 'created once, never duplicated');
        $this->assertSame(403, $this->api('POST', ['changes', 'rules', 'recommended'], $this->tech, [])[0]);
        $this->assertSame(422, $this->api('POST', ['changes', 'rules', 'recommended'], $this->admin, ['x' => 1])[0]);
        $startup = array_values(array_filter($j['data'], static fn ($r) => $r['watch'] === 'win.startup'))[0];
        $this->ship([self::ch(['item' => 'Evil', 'new' => 'evil.exe'])]);
        $alerts = $this->h->bridge->alertKeys();
        $this->assertCount(1, $alerts, 'any change to a Run key opens an alert at once, past the failure debounce');
        $this->assertStringContainsString($startup['check_key'], $alerts[0]);
        $this->assertStringContainsString('added HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run', (string) $this->h->one("SELECT detail FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key='{$startup['check_key']}'"));
        // a Defender exclusion raises its own
        $this->ship([self::ch(['watch' => 'win.defender', 'target' => 'HKLM\\SOFTWARE\\Microsoft\\Windows Defender\\Exclusions\\Paths', 'item' => 'C:\\Evil', 'new' => '0'])]);
        $this->assertCount(2, $this->h->bridge->alertKeys());
        // an unrelated watch does not
        $this->ship([self::ch(['kind' => 'file', 'watch' => 'win.hosts', 'target' => 'C:\\Windows\\System32\\drivers\\etc\\hosts', 'item' => '', 'change' => 'modified'])]);
        $this->assertCount(2, $this->h->bridge->alertKeys());
        $ev = $this->bus->of(RmmEvent::CHANGE_RULE_MATCHED);
        $this->assertCount(2, $ev);
        $this->assertSame([$startup['rule_id'], 'win.startup', 'added', $startup['check_key']], [$ev[0]['rule_id'], $ev[0]['watch'], $ev[0]['change'], $ev[0]['alert_check_key']]);
        $this->assertStringContainsString('Evil', $ev[0]['sample']);
        // quietness resolves it
        $this->h->clock->advance(61 * 60);
        $this->assertSame(2, $this->h->module->changeRules()->sweep());
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key='{$startup['check_key']}'"));
        $this->assertCount(2, $this->bus->of(RmmEvent::CHECK_RECOVERED));
    }

    public function testACountThresholdAClientScopedRuleAndDisablingClosesTheAlert(): void
    {
        $this->enable();
        $r = $this->rule(['name' => 'Many', 'threshold' => 3, 'window_min' => 5, 'severity' => 'warn']);
        $key = $r['check_key'];
        $this->ship([self::ch(['item' => 'a']), self::ch(['item' => 'b'])]);
        $this->assertSame([], $this->h->bridge->alertKeys(), 'two of three');
        $this->ship([self::ch(['item' => 'c'])]);
        $this->assertCount(1, $this->h->bridge->alertKeys(), 'the window counts what earlier batches stored');
        $this->assertSame('warn', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key='$key'"));
        // a rule for one client never fires on another client's device
        [$b, $bt] = $this->linked('B2', $this->h->clientB, 'windows', self::caps());
        $this->api('PATCH', ['changes', 'rules', (string) $r['rule_id']], $this->admin, ['threshold' => 1, 'client_id' => $this->h->clientA]);
        $this->ship([self::ch(['item' => 'z'])], $bt);
        $this->assertNull($this->h->one("SELECT status FROM endpoint_agent_checks WHERE device_id=$b AND check_key='$key'"));
        // disabling closes the alert at the next sweep, however recent the match
        $this->api('PATCH', ['changes', 'rules', (string) $r['rule_id']], $this->admin, ['enabled' => false]);
        $this->assertSame(1, $this->h->module->changeRules()->sweep());
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key='$key'"));
        $this->assertArrayHasKey('change_rule_alerts_recovered', $this->h->module->housekeeping()->run());
    }

    /** rc.31 review F4: device-supplied change text never carries markup into an alert detail or a webhook payload; the stored record keeps what the device sent. */
    public function testDeviceTextIsMadeSafeInAlertsAndEventsButStoredAsSent(): void
    {
        $this->enable();
        $this->api('POST', ['changes', 'rules', 'recommended'], $this->manager, []);
        $evil = '<img src=x onerror=alert(1)> `id` "q"';
        $this->ship([self::ch(['item' => 'Evil', 'new' => $evil, 'summary' => '<b>bold</b>', 'actor' => 'CORP\\<bob>'])]);
        $this->assertStringContainsString('<img', (string) $this->h->one('SELECT new_value FROM endpoint_agent_change_records LIMIT 1'), 'stored as sent (editions escape on output)');
        $detail = (string) $this->h->one("SELECT detail FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key LIKE 'change.%' LIMIT 1");
        $this->assertStringContainsString('img src=x onerror=alert(1)', $detail);
        foreach (['<', '>', '`', '"'] as $bad) {
            $this->assertStringNotContainsString($bad, $detail);
            foreach ([RmmEvent::CHANGE_DETECTED, RmmEvent::CHANGE_RULE_MATCHED] as $name) {
                foreach ($this->bus->of($name) as $e) {
                    foreach (array_intersect_key($e, array_flip(['target', 'item', 'old_value', 'new_value', 'summary', 'actor', 'process', 'sample'])) as $field => $value) {
                        $this->assertStringNotContainsString($bad, (string) $value, "$name.$field has $bad");
                    }
                }
            }
        }
        $this->assertNotEmpty($this->bus->of(RmmEvent::CHANGE_DETECTED));
    }

    public function testEventsAreCappedPerBatchAndCarryTheFields(): void
    {
        $this->enable();
        $many = array_map(static fn (int $i): array => self::ch(['item' => "k$i", 'actor' => 'CORP\\bob']), range(1, 30));
        $this->ship($many);
        $ev = $this->bus->of(RmmEvent::CHANGE_DETECTED);
        $this->assertCount(ChangeService::EVENT_CAP, $ev, 'a mass change is a burst of at most 20 events, not 30');
        $this->assertSame(30, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_change_records'), 'every change is still recorded');
        $this->assertSame(['registry', 'win.startup', 'added', 'k1', 'CORP\\bob', $this->dev], [$ev[0]['kind'], $ev[0]['watch'], $ev[0]['change'], $ev[0]['item'], $ev[0]['actor'], $ev[0]['device_id']]);
        $this->ship($many);
        $this->assertCount(ChangeService::EVENT_CAP, $this->bus->of(RmmEvent::CHANGE_DETECTED), 'a resent batch raises nothing twice');
        $this->assertContains(RmmEvent::CHANGE_DETECTED, RmmEvent::all());
        $this->assertContains('rmm.change.rule_matched', RmmEvent::all());
    }

    // ------------------------------------------------------------------ settings

    public function testAPartlyResentBatchRaisesEventsOnlyForTheNewRecords(): void
    {
        $this->enable();
        $a = self::ch(['item' => 'A']);
        $this->ship([$a]);
        $this->h->clock->advance(5);   // the first batch was received in an earlier second
        $before = count($this->bus->of(RmmEvent::CHANGE_DETECTED));
        $this->ship([$a, self::ch(['item' => 'B'])]);
        $new = array_slice($this->bus->of(RmmEvent::CHANGE_DETECTED), $before);
        $this->assertSame(['B'], array_column($new, 'item'));
    }

    public function testSettingsNeedTheRightGrantAndTheStorageAcknowledgement(): void
    {
        $path = ['changes', 'settings'];
        $this->assertSame(403, $this->api('PUT', $path, $this->tech, ['presets' => ['windows' => ['win.startup']]])[0], 'view is not manage');
        $this->assertSame(403, $this->api('PUT', $path, $this->manager, ['features' => ['change_monitor' => true], 'accept_change_storage' => true])[0], 'the switch is rmm.admin');
        $this->assertSame(403, $this->api('PUT', $path, $this->manager, ['limits' => ['change_retention_days' => 5]])[0], 'the caps are rmm.admin');
        [$c, $j] = $this->api('PUT', $path, $this->admin, ['features' => ['change_monitor' => true]]);
        $this->assertSame([422, 'confirmation_required'], [$c, $j['code']]);
        $this->assertStringContainsString('database', $j['error']);
        $this->assertFalse($this->h->module->settings()->featureOn('change_monitor'));
        [$c, $j] = $this->api('PUT', $path, $this->admin, ['features' => ['change_monitor' => true], 'accept_change_storage' => true, 'presets' => ['windows' => ['win.startup', 'win.services'], 'linux' => ['lnx.sudo']]]);
        $this->assertSame(200, $c, json_encode($j));
        $this->assertTrue($j['enabled']);
        $on = array_column(array_filter($j['presets'], static fn ($p) => $p['enabled'] && $p['source'] === 'agent'), 'id');
        $this->assertSame(['win.startup', 'win.services', 'lnx.sudo'], $on);
        $this->assertTrue($this->h->module->settings()->featureOn('change_monitor'));
        $this->assertSame(['typical' => 470, 'budget' => 900, 'worst' => 3000], $j['bytes_per_record']);
        $this->assertSame([5000 * 900, 500000 * 900, 5000 * 3000, 500000 * 3000], [$j['device_cap_bytes'], $j['fleet_cap_bytes'], $j['worst_case_bytes_per_device'], $j['fleet_worst_case_bytes']]);
        // refusals leave everything as it was
        foreach ([['presets' => ['windows' => ['lnx.sudo']]], ['presets' => ['windows' => ['nope']]], ['presets' => 'x'], ['presets' => ['mac' => []]], ['limits' => ['change_retention_days' => 0]], ['limits' => ['nope' => 1]], ['limits' => ['change_interval_s' => 59]],
            ['bogus' => 1], ['features' => ['monitoring' => false]], ['features' => ['change_monitor' => 'yes']]] as $bad) {
            $this->assertSame(422, $this->api('PUT', $path, $this->admin, $bad)[0], json_encode($bad));
        }
        $this->assertSame(['win.startup', 'win.services'], $this->h->module->changeSettings()->enabledPresets('windows'));
        $got = $this->api('GET', $path, $this->tech)[1];
        $this->assertSame(ChangePresetsCount::ALL, count($got['presets']));
        $this->assertContains('change_monitor', array_keys($this->h->module->settings()->features()));
        $a = json_encode($this->h->audit->records()) ?: '';
        $this->assertStringContainsString('Change Monitor Settings Changed', $a);
        $this->assertStringContainsString('Change Monitor Settings Denied', $a);
        // switching it off again needs no acknowledgement
        $this->assertSame(200, $this->api('PUT', $path, $this->admin, ['features' => ['change_monitor' => false]])[0]);
        $this->assertFalse($this->h->module->settings()->featureOn('change_monitor'));
    }

    public function testCustomWatchesPassThePolicyAndNeverReachCredentials(): void
    {
        $this->enable();
        $ok = ['id' => 'acme', 'platform' => 'windows', 'kind' => 'registry', 'title' => 'Acme agent', 'paths' => [['key' => 'HKLM\\SOFTWARE\\Acme\\Agent', 'depth' => 2, 'values' => ['Server', 'Mode']]]];
        $this->assertSame(200, $this->api('PUT', ['changes', 'settings'], $this->manager, ['custom' => [$ok]])[0]);
        $cfg = $this->announce($this->devToken, self::caps())['config']['change'];
        $this->assertSame('custom.acme', end($cfg['watches'])['id']);
        $this->assertSame(['key' => 'HKLM\\SOFTWARE\\Acme\\Agent', 'depth' => 2, 'values' => ['Server', 'Mode']], end($cfg['watches'])['paths'][0]);
        $bad = [
            'SAM' => ['key' => 'HKLM\\SAM\\SAM\\Domains'],
            'SECURITY' => ['key' => 'HKEY_LOCAL_MACHINE\\security\\Policy\\Secrets'],
            'a whole hive' => ['key' => 'HKLM'],
            'the first level' => ['key' => 'HKLM\\SOFTWARE'],
            'the LSA boot key part' => ['key' => 'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Lsa\\JD'],
            'the same under ControlSet001' => ['key' => 'HKLM\\SYSTEM\\ControlSet001\\Control\\Lsa\\Skew1'],
            'HKCU (the service profile)' => ['key' => 'HKCU\\Software\\Acme'],
            'a traversal' => ['key' => 'HKLM\\SOFTWARE\\Acme\\..\\..\\SAM'],
            'a slash' => ['key' => 'HKLM/SOFTWARE/Acme'],
            'too deep' => ['key' => 'HKLM\\SOFTWARE\\Acme\\Agent', 'depth' => 9],
            'an unknown member' => ['key' => 'HKLM\\SOFTWARE\\Acme\\Agent', 'script' => 'x'],
            'a control character' => ['key' => "HKLM\\SOFTWARE\\Acme\\Ag\nent"],
        ];
        foreach ($bad as $why => $path) {
            [$c] = $this->api('PUT', ['changes', 'settings'], $this->manager, ['custom' => [['paths' => [$path]] + $ok]]);
            $this->assertSame(422, $c, $why);
        }
        $files = [
            'a Linux file on a Windows device' => ['path' => '/etc/hosts'],
            'a Windows path outside the allowed folders' => ['path' => 'C:\\Users\\bob\\Documents\\secrets.docx'],
            'the SAM hive file' => ['path' => 'C:\\Windows\\System32\\config\\SAM'],
        ];
        foreach ($files as $why => $path) {
            [$c] = $this->api('PUT', ['changes', 'settings'], $this->manager, ['custom' => [['kind' => 'file', 'paths' => [$path]] + $ok]]);
            $this->assertSame(422, $c, $why);
        }
        $linux = ['id' => 'web', 'platform' => 'linux', 'kind' => 'file', 'paths' => [['path' => '/etc/nginx/nginx.conf'], ['path' => '/etc/nginx/conf.d', 'depth' => 1, 'match' => '*.conf']]];
        $this->assertSame(200, $this->api('PUT', ['changes', 'settings'], $this->manager, ['custom' => [$ok, $linux]])[0]);
        foreach (['/etc/shadow', '/etc/gshadow-', '/etc/ssh/ssh_host_ed25519_key', '/etc/ssl/private/site.key', '/root/.ssh/id_ed25519', '/etc/letsencrypt/live/x/privkey.pem', '/etc', '/home/bob/.bash_history', '/etc/../root/.ssh/id_rsa',
            '/etc/sudoers.d/', "/etc/ho\x00sts", 'etc/hosts', '/proc/1/environ', '/etc/wireguard/wg0.conf', '/etc/NetworkManager/system-connections/home.nmconnection'] as $p) {
            [$c] = $this->api('PUT', ['changes', 'settings'], $this->manager, ['custom' => [['id' => 'x', 'platform' => 'linux', 'kind' => 'file', 'paths' => [['path' => $p]]]]]);
            $this->assertSame(422, $c, $p);
        }
        $this->assertSame(['custom.acme', 'custom.web'], array_column($this->h->module->changeSettings()->customWatches(), 'id'), 'the refused saves changed nothing');
        [$c] = $this->api('PUT', ['changes', 'settings'], $this->manager, ['custom' => array_map(static fn (int $i): array => ['id' => "w$i"] + $ok, range(1, 31))]);
        $this->assertSame(422, $c, 'at most 30 custom watches');
        $this->assertSame(403, $this->api('PUT', ['changes', 'settings'], $this->scoped, ['custom' => [$ok]])[0], 'watches apply to every client, so a client-limited technician cannot change them');
    }

    // ------------------------------------------------------------------ Security log presets

    public function testTheSecurityLogPresetsExpandIntoOrdinarySourcesAndAggregationNeedsTheCapability(): void
    {
        $this->switchOn(['logs']);
        $this->assertSame([], $this->h->module->remoteSettings()->update(['logs_collect' => ['windows' => [['channel' => 'System', 'levels' => ['error'], 'event_ids' => [7036]]], 'linux' => []]], 1));
        $base = $this->announce($this->devToken, self::caps())['config']['logs'];
        $this->assertSame(['System'], array_column($base['sources'], 'channel'));
        $all = \RivetCore\Rmm\Logs\SecurityLogPresets::ids();
        $this->assertCount(8, $all);
        $this->assertSame([], $this->h->module->remoteSettings()->update(['logs_collect' => ['presets' => $all]], 1));
        $this->assertSame([['channel' => 'System', 'event_ids' => [7036]]], array_map(static fn ($s) => ['channel' => $s['channel'], 'event_ids' => $s['event_ids']], $this->h->module->remoteSettings()->logSources('windows')), 'saving only presets keeps the sources');
        // an agent that cannot aggregate: the ids merged per channel, no 4625
        $r = $this->announce($this->devToken, self::caps())['config']['logs'];
        $by = [];
        foreach ($r['sources'] as $s) {
            $by[$s['channel']][] = $s;
        }
        $this->assertCount(2, $by, 'System (the admin\'s and the presets\') and Security');
        $sec = $by['Security'][0];
        $this->assertSame([1102, 4697, 4698, 4702, 4719, 4720, 4722, 4724, 4725, 4726, 4728, 4729, 4732, 4733, 4756, 4757, 6416], $sec['event_ids']);
        $this->assertSame([], $sec['levels'], 'audit events carry level 0: every level is read');
        $this->assertArrayNotHasKey('aggregate', $sec);
        $this->assertNotContains(4625, $sec['event_ids']);
        $this->assertCount(2, $by['System']);
        $this->assertSame([104, 7045], $by['System'][1]['event_ids']);
        foreach ($r['sources'] as $s) {
            $this->assertArrayNotHasKey('aggregate', $s);
        }
        // an agent that announced logs:presets also gets the aggregated failed-logon source
        $caps = [...self::caps(), 'logs:presets'];
        sort($caps);
        $r2 = $this->announce($this->devToken, $caps)['config']['logs'];
        $agg = array_values(array_filter($r2['sources'], static fn ($s) => isset($s['aggregate'])));
        $this->assertCount(1, $agg);
        $this->assertSame([4625], $agg[0]['event_ids']);
        $this->assertSame(['window_s' => 300, 'min' => 5, 'by' => ['TargetUserName', 'IpAddress']], $agg[0]['aggregate']);
        $this->assertNotSame($r['version'], $r2['version']);
        // Linux devices never get Windows presets; unknown ids are refused; nothing changes on error
        [, $lt] = $this->linked('L1', null, 'linux');
        $this->assertArrayNotHasKey('logs', $this->announce($lt, self::allCaps('linux'), 'linux')['config'], 'a Linux device with no Linux source gets no log config from Windows presets');
        $this->assertNotSame([], $this->h->module->remoteSettings()->update(['logs_collect' => ['presets' => ['win.sec.nope']]], 1));
        $this->assertNotSame([], $this->h->module->remoteSettings()->update(['logs_collect' => ['presets' => 'x']], 1));
        $this->assertCount(8, $this->h->module->remoteSettings()->logPresets());
        // without any preset or source the old behaviour is untouched
        $this->assertSame([], $this->h->module->remoteSettings()->update(['logs_collect' => ['presets' => [], 'windows' => []]], 1));
        $this->assertArrayNotHasKey('features', $this->announce($this->devToken, self::caps()));
    }

    // ------------------------------------------------------------------ cost

    public function testCostOfACheckinAndOfABatch(): void
    {
        $caps = self::caps();
        $cost = function (callable $f): int {
            $before = $this->h->counting->statements;
            $f();

            return $this->h->counting->statements - $before;
        };
        $this->switchOn([]);
        $this->announce($this->devToken, $caps);
        $this->announce($this->devToken, $caps);
        $off = $cost(fn () => $this->announce($this->devToken, $caps));
        $this->enable();
        $this->announce($this->devToken, $caps);
        $on = $cost(fn () => $this->announce($this->devToken, $caps));
        $this->assertLessThanOrEqual($off + 3, $on, 'offering the signed config costs at most three more statements (the settings row, read once, and the capability read)');
        $this->switchOn([]);
        $this->announce($this->devToken, $caps);
        $this->assertSame($off, $cost(fn () => $this->announce($this->devToken, $caps)), 'with the switch off a check-in costs what it did before');
        // one batch of 100 records with no rule
        $this->enable();
        $batch = array_map(static fn (int $i): array => self::ch(['item' => "entry $i", 'ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 100 - $i)]), range(1, 100));
        $this->h->module->settings()->refresh();
        $n = $cost(fn () => $this->ship($batch));
        $this->assertLessThanOrEqual(11, $n, "a 100-record batch costs $n statements");
        $this->assertSame(100, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_change_records'));
        for ($i = 0; $i < 10; ++$i) {
            $this->rule(['name' => "r$i", 'watch' => 'win.defender']);
        }
        $this->h->clock->advance(61);
        $batch2 = array_map(static fn (int $i): array => self::ch(['item' => "other $i", 'ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 100 - $i)]), range(1, 100));
        $m = $cost(fn () => $this->ship($batch2));
        $this->assertLessThanOrEqual($n + 2, $m, 'ten rules cost one read of the rule list, not a statement per record');
    }
}

/** How many presets the catalog holds (20: eleven Windows, nine Linux, including the two that come from the software inventory). */
final class ChangePresetsCount
{
    public const ALL = 20;
}
