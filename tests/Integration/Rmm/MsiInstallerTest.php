<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Http\RmmRequest;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\Http\SapiEmitter;
use RivetCore\Rmm\Installer\InstallerFormat;
use RivetCore\Rmm\Installer\InstallerService;
use RivetCore\Rmm\Installer\InstallerStamp;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\OleFile;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * 1.0.0-rc.16: the MSI installer format. A published MSI template (its own table, never an agent update), the `format` option of the administrator's download and of
 * the token-gated download, the trailer appended to a COPY of the template and read back by the same reader the exe uses, the refusals, and client scoping.
 */
final class MsiInstallerTest extends RmmTestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/rmm/msi/template-x64.msi';
    private RmmPrincipal $admin;
    private RmmPrincipal $tech;
    private RmmPrincipal $scoped;
    private string $tmp = '';

    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(policy: new AllowUsersPolicy([1 => true, 10 => [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED], 16 => true]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->tech = new RmmPrincipal(10, 'Tech');
        $this->scoped = new RmmPrincipal(16, 'Scoped to B');
        $this->h->tenancy->restrictUser(16, [$this->h->clientB]);
        $this->tmp = sys_get_temp_dir() . '/rmm_msi_it_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700);
        $this->h->enable();
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.com']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function put(string $name, string $bytes): string
    {
        file_put_contents($this->tmp . '/' . $name, $bytes);

        return $this->tmp . '/' . $name;
    }

    private function template(): string
    {
        return (string) file_get_contents(self::FIXTURE);
    }

    private function publish(string $version = '1.0.0', string $arch = 'amd64', ?string $bytes = null, bool $current = true): \RivetCore\Rmm\Technician\ActionResult
    {
        return $this->h->module->admin()->uploadMsiTemplate($this->admin, $this->put('t.msi', $bytes ?? $this->template()), $version, $arch, ['activate' => $current]);
    }

    private function emit(RmmResponse $resp): string
    {
        $out = '';
        (new SapiEmitter(static function (int $c): void {
        }, static function (string $h): void {
        }, static function (string $b) use (&$out): void {
            $out .= $b;
        }))->emit($resp);

        return $out;
    }

    private function rows(string $table): int
    {
        return (int) $this->h->one("SELECT COUNT(*) FROM $table");
    }

    // ------------------------------------------------------------------ publishing

    public function testPublishingStoresARowAndTheFileAndLeavesTheAgentTablesAlone(): void
    {
        $r = $this->publish();
        $this->assertTrue($r->ok, $r->message);
        $t = $r->data['template'];
        $this->assertSame(['1.0.0', 'amd64', 'summary', 1, 1], [$t['version'], $t['arch'], $t['arch_source'], (int) $t['active'], (int) $t['is_current']]);
        $this->assertSame(hash_file('sha256', self::FIXTURE), $t['sha256']);
        $this->assertSame(filesize(self::FIXTURE), (int) $t['size_bytes']);
        $this->assertFileExists($this->h->binaryDir . '/' . $t['storage_name']);
        $this->assertMatchesRegularExpression('/^bin_[0-9a-f]{32}\.bin$/', $t['storage_name']);
        $this->assertSame([0, 0], [$this->rows('endpoint_agent_binaries'), $this->rows('endpoint_agent_releases')], 'a template is never an agent update');
        $this->assertStringContainsString('MSI Template Uploaded', json_encode($this->h->audit->records()));
        $list = $this->h->module->readModel()->msiTemplates();
        $this->assertSame([['1.0.0', 'amd64', true, true, 'summary']], array_map(static fn (array $x): array => [$x['version'], $x['arch'], $x['active'], $x['is_current'], $x['arch_source']], $list));
    }

    public function testRepublishingTheSameFileIsANoOpAndADifferentFileForTheSameVersionIsRefused(): void
    {
        $this->assertTrue($this->publish()->ok);
        $again = $this->publish();
        $this->assertTrue($again->ok);
        $this->assertFalse($again->data['created']);
        $this->assertSame(1, $this->rows('endpoint_agent_msi_templates'));
        $changed = $this->template();
        $changed[9000] = chr(ord($changed[9000]) ^ 1);
        $r = $this->publish('1.0.0', 'amd64', $changed);
        $this->assertFalse($r->ok);
        $this->assertStringContainsString('already published with different contents', $r->message);
        $this->assertSame(1, $this->rows('endpoint_agent_msi_templates'));
        $this->assertTrue($this->publish('1.0.1', 'amd64', $changed)->ok, 'a new version number is fine');
    }

    public function testEveryRefusalStoresNothing(): void
    {
        $cases = [
            'an exe' => [RmmHarness::fakePe(0x8664, 9000, 'a'), 'amd64', 'not an MSI'],
            'not OLE' => [str_repeat('x', 9000), 'amd64', 'not a Windows Installer package'],
            'too small' => [substr($this->template(), 0, 4000), 'amd64', 'too small'],
            'wrong architecture' => [$this->template(), 'arm64', 'says it is for amd64'],
            'unknown architecture' => [$this->template(), 'x86', 'amd64 or arm64'],
            'a 32-bit package' => [OleFile::build([7 => 'Intel;1033']), 'amd64', 'platform "intel"'],
            'already stamped' => [$this->template() . InstallerStamp::trailer('{"a":1}'), 'amd64', 'already carries an installer footer'],
        ];
        foreach ($cases as $name => [$bytes, $arch, $needle]) {
            $r = $this->publish('2.0.0', $arch, $bytes);
            $this->assertFalse($r->ok, $name);
            $this->assertStringContainsString($needle, $r->message, $name);
        }
        $this->assertFalse($this->publish('not-a-version')->ok);
        $this->assertSame(0, $this->rows('endpoint_agent_msi_templates'));
        $this->assertSame([], glob($this->h->binaryDir . '/bin_*') ?: []);
    }

    public function testASizeCapAppliesAndAnUnreadableSummaryNeedsTheDeclaredArchitecture(): void
    {
        $store = $this->h->module->binaryStore();
        $this->assertIsString($store->inspectMsi($this->put('b.msi', OleFile::build([7 => 'x64;1033'], 9001)), 'amd64', 9000));
        $this->assertIsArray($store->inspectMsi($this->put('c.msi', OleFile::build([7 => 'x64;1033'], 9000)), 'amd64', 9000), 'exactly at the cap');
        // no usable summary: accepted on the administrator's word, and the row says so
        $r = $this->publish('3.0.0', 'amd64', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 9000));
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame('declared', $r->data['template']['arch_source']);
    }

    public function testCurrentTemplateSwitchingAndDeactivation(): void
    {
        $a = $this->publish('1.0.0');
        $other = $this->template();
        $other[9100] = chr(ord($other[9100]) ^ 1);
        $b = $this->publish('1.1.0', 'amd64', $other, false);
        $this->assertSame([1, 0], [(int) $this->h->one('SELECT is_current FROM endpoint_agent_msi_templates WHERE version=\'1.0.0\''), (int) $this->h->one('SELECT is_current FROM endpoint_agent_msi_templates WHERE version=\'1.1.0\'')]);
        $adm = $this->h->module->admin();
        $this->assertTrue($adm->msiTemplateAction($this->admin, (int) $b->data['template']['template_id'], 'make_current')->ok);
        $this->assertSame('1.1.0', $this->h->module->binaryStore()->currentMsi('amd64')['version']);
        $this->assertTrue($adm->msiTemplateAction($this->admin, (int) $b->data['template']['template_id'], 'deactivate')->ok);
        $this->assertNull($this->h->module->binaryStore()->currentMsi('amd64'), 'a deactivated current template is not used');
        $this->assertTrue($adm->msiTemplateAction($this->admin, (int) $a->data['template']['template_id'], 'make_current')->ok);
        $this->assertFalse($adm->msiTemplateAction($this->admin, 9999, 'make_current')->ok);
        $this->assertSame(422, $adm->msiTemplateAction($this->admin, (int) $a->data['template']['template_id'], 'delete everything')->http);
    }

    public function testOnlyThePublishAbilityMayPublishOrChange(): void
    {
        $adm = $this->h->module->admin();
        $this->assertSame(403, $adm->uploadMsiTemplate($this->tech, $this->put('t.msi', $this->template()), '1.0.0', 'amd64')->http);
        $this->assertSame(0, $this->rows('endpoint_agent_msi_templates'));
        $this->publish();
        $this->assertSame(403, $adm->msiTemplateAction($this->tech, 1, 'deactivate')->http);
    }

    // ------------------------------------------------------------------ downloading

    public function testTheMsiInstallerIsACopyOfTheTemplateWithTheSamePayloadTrailer(): void
    {
        $this->publish();
        $stored = $this->h->rows('SELECT * FROM endpoint_agent_msi_templates')[0];
        $path = $this->h->binaryDir . '/' . $stored['storage_name'];
        $before = hash_file('sha256', $path);
        $r = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, 'msi test', 'amd64', false, InstallerFormat::MSI);
        $this->assertTrue($r->ok, $r->message);
        /** @var RmmResponse $resp */
        $resp = $r->data['download'];
        $this->assertSame('attachment; filename="RivetIT-Agent-dept-a-x64.msi"', $resp->headers['Content-Disposition']);
        $body = $this->emit($resp);
        $this->assertSame(strlen($body), $resp->file?->totalLength());
        $stamp = InstallerStamp::read($body);
        $this->assertNotNull($stamp, 'the SAME trailer reader parses the stamped MSI');
        $this->assertSame($this->template(), substr($body, 0, $stamp['exe_length']), 'the template bytes are untouched and the trailer follows its last byte');
        $this->assertSame("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", substr($body, 0, 8));
        $d = $stamp['data'];
        $this->assertSame([1, 'Dept A', 'https://rmm.example.com'], [$d['version'], $d['department'], $d['server_url']]);
        $this->assertMatchesRegularExpression('/^rvte1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $d['enrollment_token']);
        $this->assertSame($before, hash_file('sha256', $path), 'the STORED template is never modified');
        $this->assertArrayNotHasKey('install_mesh_agent', $d);
        // the token row, its audit line and the format in it
        $tokens = $this->h->rows('SELECT * FROM endpoint_agent_enrollment_tokens');
        $this->assertCount(1, $tokens);
        $this->assertNull($tokens[0]['revoked_at']);
        $this->assertStringContainsString('(amd64, msi)', json_encode($this->h->audit->records()));
        // a second download is a second copy with its own token and payload, from the same unmodified template
        $r2 = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64', false, InstallerFormat::MSI);
        $stamp2 = InstallerStamp::read($this->emit($r2->data['download']));
        $this->assertNotSame($d['enrollment_token'], $stamp2['data']['enrollment_token']);
        $this->assertNotSame($d['installer_id'], $stamp2['data']['installer_id']);
        $this->assertSame($before, hash_file('sha256', $path));
    }

    public function testTheMsiCarriesNothingOfMeshLikeTheExe(): void
    {
        $this->publish();
        $this->h->module->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.com']);
        $this->assertSame([], $this->h->module->meshAgentSettings()->update(['mesh_group_id' => str_repeat('ab', 48)], 1));
        $r = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64', true, InstallerFormat::MSI);
        $this->assertTrue($r->ok, $r->message);
        $d = InstallerStamp::read($this->emit($r->data['download']))['data'];
        $this->assertArrayNotHasKey('install_mesh_agent', $d, 'rc.27: the Mesh agent installs after approval, the MSI payload carries nothing of Mesh');
        $this->assertArrayNotHasKey('mesh', $d);
        $this->assertStringNotContainsString('mesh.example.com', json_encode($d));
    }

    public function testTheDefaultFormatIsStillTheExeAndAnMsiNeedsNoAgentBinary(): void
    {
        // only a template is published: the MSI works, the default (exe) says it has no binary
        $this->publish();
        $exe = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64');
        $this->assertFalse($exe->ok);
        $this->assertStringContainsString('No agent binary is published for amd64', $exe->message);
        $this->assertSame(0, $this->rows('endpoint_agent_enrollment_tokens'));
        $this->assertTrue($this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64', false, 'msi')->ok);
        // and with both published, nothing about the exe changes
        $this->h->publishBinary('1.0.0', 'amd64', 8192, true);
        $r = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64');
        $this->assertTrue($r->ok);
        $this->assertSame('attachment; filename="RivetIT-Agent-Setup-dept-a-x64.exe"', $r->data['download']->headers['Content-Disposition']);
    }

    public function testRefusalsCreateNoToken(): void
    {
        $adm = $this->h->module->admin();
        $dl = static fn (RmmPrincipal $who, int $client, string $arch, string $format) => $adm->downloadInstaller($who, $client, 0, 'stable', 5, 7, '', $arch, false, $format);
        $r = $dl($this->admin, $this->h->clientA, 'amd64', 'msi');
        $this->assertFalse($r->ok);
        $this->assertStringContainsString('No MSI template is published for amd64', $r->message);
        $r = $dl($this->admin, $this->h->clientA, 'arm64', 'msi');
        $this->assertStringContainsString('No MSI template is published for arm64', $r->message);
        $this->assertStringContainsString('amd64 template only', $r->message);
        $this->publish();
        $this->assertStringContainsString('Choose the installer format', $dl($this->admin, $this->h->clientA, 'amd64', 'zip')->message);
        $this->assertStringContainsString('Choose the client', $dl($this->admin, 9999, 'amd64', 'msi')->message);
        $this->h->module->settings()->set(['service_url' => '']);
        $this->assertStringContainsString('https', $dl($this->admin, $this->h->clientA, 'amd64', 'msi')->message);
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.com']);
        $this->assertSame(0, $this->rows('endpoint_agent_enrollment_tokens'));
    }

    public function testATemplateThatCannotBeServedRevokesItsToken(): void
    {
        $this->publish();
        $row = $this->h->rows('SELECT * FROM endpoint_agent_msi_templates')[0];
        $path = $this->h->binaryDir . '/' . $row['storage_name'];
        file_put_contents($path, str_repeat('x', (int) $row['size_bytes']));   // same size, different bytes
        $r = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64', false, 'msi');
        $this->assertFalse($r->ok);
        $this->assertStringContainsString('integrity check', $r->message);
        $this->assertStringContainsString('MSI template', $r->message);
        $this->assertNotNull($this->h->rows('SELECT * FROM endpoint_agent_enrollment_tokens')[0]['revoked_at'], 'nothing was served: no live token is left behind');
        unlink($path);
        $this->assertStringContainsString('missing or damaged', $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64', false, 'msi')->message);
    }

    // ------------------------------------------------------------------ the token-gated download

    /** @return resource */
    private function stream(string $s)
    {
        $f = fopen('php://memory', 'w+b');
        fwrite($f, $s);
        rewind($f);

        return $f;
    }

    private function gated(string $token, array $body): RmmResponse
    {
        return $this->h->api->handle(new RmmRequest('POST', 'agent_installer', [], [], ['content-type' => 'application/json'], '127.0.0.1', 'x', true, null, $this->stream((string) json_encode($body + ['token' => $token]))));
    }

    public function testTheTokenGatedDownloadServesTheMsiOnRequest(): void
    {
        $this->publish();
        $this->h->publishBinary('1.0.0', 'amd64', 8192, true);
        $tok = $this->h->token(null, 24, 5);
        $msi = $this->gated($tok, ['arch' => 'amd64', 'format' => 'msi']);
        $this->assertSame(200, $msi->status);
        $this->assertSame('attachment; filename="RivetIT-Agent-dept-a-x64.msi"', $msi->headers['Content-Disposition']);
        $body = $this->emit($msi);
        $stamp = InstallerStamp::read($body);
        $this->assertNotNull($stamp);
        $this->assertSame($this->template(), substr($body, 0, $stamp['exe_length']));
        $this->assertSame($tok, $stamp['data']['enrollment_token']);
        $this->assertStringContainsString('(amd64, msi)', json_encode($this->h->audit->records()));
        // no format, or exe: the answer is what it always was
        foreach ([[], ['format' => 'exe'], ['format' => '']] as $extra) {
            $exe = $this->gated($tok, ['arch' => 'amd64'] + $extra);
            $this->assertSame(200, $exe->status, json_encode($extra));
            $this->assertSame('attachment; filename="RivetIT-Agent-Setup-dept-a-x64.exe"', $exe->headers['Content-Disposition']);
        }
        $this->assertSame(422, $this->gated($tok, ['arch' => 'amd64', 'format' => 'zip'])->status);
        $this->assertSame(422, $this->gated($tok, ['arch' => 'amd64', 'format' => ['msi']])->status);
        // an unknown token is the same generic 404 for the msi
        $bad = $this->gated('rvte1.aaaaaaaaaaaa.' . str_repeat('b', 43), ['arch' => 'amd64', 'format' => 'msi']);
        $this->assertSame(404, $bad->status);
    }

    public function testTheTokenGatedMsiIsRefusedClearlyWithoutATemplate(): void
    {
        $this->h->publishBinary('1.0.0', 'amd64', 8192, true);
        $r = $this->gated($this->h->token(null, 24, 5), ['arch' => 'amd64', 'format' => 'msi']);
        $this->assertSame(409, $r->status);
        $this->assertStringContainsString('No MSI template is published for amd64', (string) $r->body);
    }

    // ------------------------------------------------------------------ the deployment commands

    public function testDeploymentCommandsAddAnMsiBlockOnlyForTheMsiFormat(): void
    {
        $this->publish();
        $this->h->publishBinary('1.0.0', 'amd64', 8192, true);
        $adm = $this->h->module->admin();
        $exe = $adm->deploymentCommands($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64');
        $this->assertArrayNotHasKey('msi', $exe->data['commands'], 'the exe block is byte for byte what it was');
        $r = $adm->deploymentCommands($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64', false, InstallerFormat::MSI);
        $this->assertTrue($r->ok, $r->message);
        $m = $r->data['commands']['msi'];
        $tok = $r->data['token_plain'];
        $this->assertSame('RivetIT-Agent-dept-a-x64.msi', $m['filename']);
        $this->assertSame('msiexec /i "RivetIT-Agent-dept-a-x64.msi" /qn /norestart /l*v "%TEMP%\\rivetit-agent-msi.log"', $m['msiexec']);
        $this->assertStringContainsString('SERVER="https://rmm.example.com"', $m['properties']);
        $this->assertStringContainsString('ENROLL_TOKEN="' . $tok . '"', $m['properties']);
        $this->assertStringContainsString("format = 'msi'", $m['powershell']);
        $this->assertStringContainsString("'" . $tok . "'", $m['powershell']);
        $this->assertStringContainsString('msiexec.exe', $m['powershell']);
        $this->assertStringContainsString("DisplayName -eq 'RivetIT Agent'", $m['uninstall']);
        $this->assertSame(['product_name' => 'RivetIT Agent', 'upgrade_code' => InstallerService::MSI_UPGRADE_CODE_AMD64], $m['detection']);
        // hostile characters never reach a command line
        $this->assertStringNotContainsString('"; ', InstallerService::msiexecCommand('a"; calc.exe; ".msi'));
    }

    // ------------------------------------------------------------------ client scoping

    public function testClientScopingOfTheMsiDownload(): void
    {
        $this->publish();
        $adm = $this->h->module->admin();
        $before = $this->rows('endpoint_agent_enrollment_tokens');
        // a technician restricted to client B cannot mint an MSI installer for client A, and nothing is created
        $r = $adm->downloadInstaller($this->scoped, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64', false, 'msi');
        $this->assertFalse($r->ok);
        $this->assertContains($r->http, [403, 404]);
        $this->assertSame($before, $this->rows('endpoint_agent_enrollment_tokens'));
        $r = $adm->deploymentCommands($this->scoped, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64', false, 'msi');
        $this->assertFalse($r->ok);
        $this->assertSame($before, $this->rows('endpoint_agent_enrollment_tokens'));
        // for its own client it works, and the payload names that client
        $ok = $adm->downloadInstaller($this->scoped, $this->h->clientB, 0, 'stable', 5, 7, '', 'amd64', false, 'msi');
        $this->assertTrue($ok->ok, $ok->message);
        $this->assertSame('Dept B', InstallerStamp::read($this->emit($ok->data['download']))['data']['department']);
        // a token for client B never produces client A's payload through the gated route either
        $tok = $this->h->token($this->h->clientB, 24, 5);
        $stamp = InstallerStamp::read($this->emit($this->gated($tok, ['arch' => 'amd64', 'format' => 'msi'])));
        $this->assertSame('Dept B', $stamp['data']['department']);
    }
}
