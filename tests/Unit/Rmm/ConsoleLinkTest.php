<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Remote\ConsoleLink;
use RivetCore\Rmm\Remote\RemoteProtocol;
use RivetCore\Rmm\Settings\RmmSettings;

/** The optional Rivet Console: the credential-free deep link, its allow-lists, and the remote_console switch's defaults and validation. */
final class ConsoleLinkTest extends TestCase
{
    private const CODE = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789-_AbCdE';
    private const ORIGIN = 'https://rmm.example.com';

    public function testTheLinkHasTheV1ShapeAndOnlyThreeParameters(): void
    {
        $link = ConsoleLink::build(self::ORIGIN, self::CODE);
        $this->assertSame('rivetconsole://connect?v=1&origin=https%3A%2F%2Frmm.example.com&code=' . self::CODE, $link);
        $u = parse_url((string) $link);
        $this->assertSame(['rivetconsole', 'connect'], [$u['scheme'], $u['host']]);
        parse_str($u['query'], $q);
        $this->assertSame(['v' => '1', 'origin' => self::ORIGIN, 'code' => self::CODE], $q, 'what URLSearchParams reads back');
        $this->assertSame(['v', 'origin', 'code'], array_keys($q));
        $this->assertNull(parse_url((string) $link, PHP_URL_USER));
    }

    public function testACodeIs43Base64UrlCharactersFromRandomBytesAndNeverRepeats(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; ++$i) {
            $c = ConsoleLink::newCode();
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $c);
            $this->assertTrue(ConsoleLink::validCode($c));
            $seen[$c] = true;
        }
        $this->assertCount(50, $seen);
        $this->assertSame(64, strlen(ConsoleLink::hashCode(self::CODE)));
        $this->assertSame(hash('sha256', self::CODE), ConsoleLink::hashCode(self::CODE));
    }

    /** @return array<string,array{0:string}> */
    public static function badCodes(): array
    {
        return ['empty' => [''], 'short' => [str_repeat('A', 42)], 'long' => [str_repeat('A', 44)], 'padding' => [str_repeat('A', 42) . '='], 'plus' => [str_repeat('A', 42) . '+'],
            'newline' => [str_repeat('A', 43) . "\n"], 'space' => [str_repeat('A', 42) . ' '], 'ampersand' => [str_repeat('A', 42) . '&']];
    }

    #[DataProvider('badCodes')]
    public function testAMalformedCodeBuildsNothing(string $code): void
    {
        $this->assertFalse(ConsoleLink::validCode($code));
        $this->assertNull(ConsoleLink::build(self::ORIGIN, $code));
        $this->assertNull(ConsoleLink::descriptor(self::ORIGIN, $code, 'https://mesh.example/'));
    }

    /** @return array<string,array{0:string,1:?string}> */
    public static function origins(): array
    {
        return ['plain' => ['https://rmm.example.com', 'https://rmm.example.com'], 'port' => ['https://rmm.example.com:8443', 'https://rmm.example.com:8443'], 'trailing slash' => ['https://rmm.example.com/', 'https://rmm.example.com'],
            'upper host' => ['https://RMM.Example.COM', 'https://rmm.example.com'], 'ipv4' => ['https://10.1.2.3', 'https://10.1.2.3'],
            'http' => ['http://rmm.example.com', null], 'path' => ['https://rmm.example.com/app', null], 'query' => ['https://rmm.example.com?x=1', null], 'fragment' => ['https://rmm.example.com#x', null],
            'userinfo' => ['https://u:p@rmm.example.com', null], 'user only' => ['https://u@rmm.example.com', null], 'no scheme' => ['rmm.example.com', null], 'empty' => ['', null],
            'javascript' => ['javascript:alert(1)', null], 'space' => ['https://rmm .example.com', null], 'percent' => ['https://rmm%2eexample.com', null], 'bad port' => ['https://rmm.example.com:99999', null],
            'double dot' => ['https://rmm..example.com', null], 'long' => ['https://' . str_repeat('a', 250) . '.com', null]];
    }

    #[DataProvider('origins')]
    public function testTheOriginIsABareHttpsOrigin(string $in, ?string $out): void
    {
        $this->assertSame($out, ConsoleLink::normalizeOrigin($in));
    }

    public function testHttpIsOnlyAcceptedWhenTheEditionAllowsInsecureHttp(): void
    {
        $this->assertSame('http://127.0.0.1:8080', ConsoleLink::normalizeOrigin('http://127.0.0.1:8080', true));
        $this->assertNull(ConsoleLink::normalizeOrigin('http://127.0.0.1:8080'));
        $this->assertNull(ConsoleLink::build('http://127.0.0.1:8080', self::CODE));
    }

    public function testBuildRefusesAnOriginThatIsNotAlreadyNormalised(): void
    {
        $this->assertNull(ConsoleLink::build('https://rmm.example.com/', self::CODE));
        $this->assertNull(ConsoleLink::build('https://RMM.example.com', self::CODE));
        $this->assertNull(ConsoleLink::build('https://rmm.example.com/?code=x', self::CODE));
    }

    /** @return array<string,array{0:string}> */
    public static function badKinds(): array
    {
        return ['empty' => [''], 'unknown' => ['vnc'], 'case' => ['Desktop'], 'injection' => ['desktop&node=x'], 'space' => ['desktop '], 'path' => ['../desktop'], 'ps typo' => ['terminal_ps'],
            'background' => ['background'], 'chat' => ['chat'], 'console' => ['console']];
    }

    #[DataProvider('badKinds')]
    public function testAKindOutsideTheAllowListIsNotIssued(string $kind): void
    {
        $this->assertFalse(ConsoleLink::validKind($kind));
        $this->assertSame(['desktop', 'terminal', 'terminal-ps', 'files'], ConsoleLink::KINDS);
    }

    /** @return array<string,array{0:string}> */
    public static function badNodes(): array
    {
        return ['empty' => [''], 'one slash' => ['node/AAAAAAAAAAAAAAAAAAAAAAAA'], 'no prefix' => [str_repeat('A', 64)], 'too short' => ['node//' . str_repeat('A', 19)], 'too long' => ['node//' . str_repeat('A', 101)],
            'bad char' => ['node//' . str_repeat('A', 20) . '+'], 'ampersand' => ['node//' . str_repeat('A', 20) . '&kind=files'], 'newline' => ['node//' . str_repeat('A', 20) . "\n"],
            'space' => ['node//' . str_repeat('A', 20) . ' x'], 'url' => ['https://mesh.example/?login=abc'], 'long domain' => ['node/' . str_repeat('d', 33) . '/' . str_repeat('A', 20)],
            'bad domain' => ['node/do.main/' . str_repeat('A', 20)]];
    }

    #[DataProvider('badNodes')]
    public function testANodeIdOutsideThePatternIsRefused(string $node): void
    {
        $this->assertFalse(ConsoleLink::validNode($node));
    }

    public function testTheNodePatternAcceptsADomainAndBoundariesAre20And100Characters(): void
    {
        $this->assertTrue(ConsoleLink::validNode('node//' . str_repeat('a', 20)));
        $this->assertTrue(ConsoleLink::validNode('node//' . str_repeat('a', 100)));
        $this->assertTrue(ConsoleLink::validNode('node//' . str_repeat('@$_-', 5)));
        $this->assertTrue(ConsoleLink::validNode('node/domain1/' . str_repeat('a', 64)));
        $this->assertTrue(ConsoleLink::validNode('node/' . str_repeat('d', 32) . '/' . str_repeat('a', 20)));
    }

    public function testKindFollowsTheViewAndTerminalPsOnlyFitsATerminal(): void
    {
        $this->assertSame('desktop', ConsoleLink::kindFor('desktop'));
        $this->assertSame('terminal', ConsoleLink::kindFor('terminal'));
        $this->assertSame('files', ConsoleLink::kindFor('files'));
        $this->assertSame('terminal-ps', ConsoleLink::kindFor('terminal', 'terminal-ps'));
        $this->assertSame('files', ConsoleLink::kindFor('files', 'files'));
        $this->assertNull(ConsoleLink::kindFor('desktop', 'terminal-ps'));
        $this->assertNull(ConsoleLink::kindFor('desktop', 'files'));
        $this->assertNull(ConsoleLink::kindFor('terminal', 'rm -rf'));
        $this->assertNull(ConsoleLink::kindFor('console'), 'the Background view never yields a console kind');
        $this->assertNull(ConsoleLink::kindFor('chat'));
        $this->assertSame('desktop', ConsoleLink::kindFor('desktop', ''));
    }

    public function testTheDescriptorHasUrlAndFallbackOnlyAndNoKindOrNode(): void
    {
        $stock = 'https://mesh.example/?login=SECRETCOOKIE&gotonode=X&viewmode=11';
        $d = ConsoleLink::descriptor(self::ORIGIN, self::CODE, $stock);
        $this->assertNotNull($d);
        $this->assertSame(['url', 'fallback_url'], array_keys($d));
        $this->assertSame($stock, $d['fallback_url']);
        $this->assertStringNotContainsString('SECRETCOOKIE', $d['url']);
        $this->assertStringNotContainsString('login', $d['url']);
        $this->assertStringNotContainsString('kind', $d['url']);
        $this->assertStringNotContainsString('node', $d['url']);
    }

    // ------------------------------------------------------------------ the switch

    public function testTheSwitchIsKnownAndNeverOnByDefault(): void
    {
        $this->assertSame('remote_console', RemoteProtocol::FEATURE_REMOTE_CONSOLE);
        $this->assertContains('remote_console', RmmSettings::FEATURES);
        $this->assertNotContains('remote_console', RmmSettings::LEGACY_FEATURES, 'a NULL features_json never turns it on');
    }

    public function testTheSwitchValidatesAsABooleanAndPersistsAsItsOwnKey(): void
    {
        [$json, $err] = RmmSettings::validateFeatures(['remote_console' => true]);
        $this->assertSame('', (string) $err);
        $this->assertSame('{"remote_console":true}', $json);
        [, $err] = RmmSettings::validateFeatures(['remote_console' => 'yes']);
        $this->assertSame('Feature remote_console must be true or false.', $err);
        [, $err] = RmmSettings::validateFeatures(['remote_consoles' => true]);
        $this->assertNotSame('', (string) $err);
    }

    public function testTheConsoleSwitchAndBackgroundModeAreIndependentKeys(): void
    {
        [$json] = RmmSettings::validateFeatures(['remote_console' => true]);
        $this->assertStringNotContainsString('background_mode', (string) $json, 'turning the console on does not enable Background Mode');
        [$json] = RmmSettings::validateFeatures(['background_mode' => true, 'remote_console' => false]);
        $this->assertSame(['background_mode' => true, 'remote_console' => false], json_decode((string) $json, true), 'and turning it off does not touch Background Mode');
    }
}
