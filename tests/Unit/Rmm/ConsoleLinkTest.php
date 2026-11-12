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
    private const NODE = 'node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$_-AbCdEfGhIjKlMnOpQrSt';

    public function testTheLinkHasTheDocumentedShapeAndRoundTripsThroughAUrlParser(): void
    {
        foreach (ConsoleLink::KINDS as $kind) {
            $link = ConsoleLink::build($kind, self::NODE);
            $this->assertNotNull($link);
            $this->assertStringStartsWith('rivetconsole://connect?kind=' . $kind . '&node=', $link);
            $u = parse_url($link);
            $this->assertSame(['scheme' => 'rivetconsole', 'host' => 'connect'], ['scheme' => $u['scheme'], 'host' => $u['host']]);
            parse_str($u['query'], $q);
            $this->assertSame(['kind' => $kind, 'node' => self::NODE], $q, 'what the console reads back (URLSearchParams decodes the same way)');
            $this->assertSame(['kind', 'node'], array_keys($q), 'nothing but the kind and the node');
        }
    }

    public function testTheLinkCarriesNoCredentialOrTokenField(): void
    {
        $link = (string) ConsoleLink::build('desktop', self::NODE);
        $this->assertMatchesRegularExpression('/^rivetconsole:\/\/connect\?kind=[a-z-]+&node=[A-Za-z0-9%._~-]+$/', $link, 'only a kind and an encoded node id, no userinfo, no extra parameter');
        foreach (['login', 'token', 'password', 'cookie', 'auth'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $link);
        }
        $this->assertNull(parse_url($link, PHP_URL_USER));
        $this->assertNull(parse_url($link, PHP_URL_PASS));
    }

    /** @return array<string,array{0:string}> */
    public static function badKinds(): array
    {
        return ['empty' => [''], 'unknown' => ['vnc'], 'case' => ['Desktop'], 'injection' => ['desktop&node=x'], 'space' => ['desktop '], 'path' => ['../desktop'], 'ps typo' => ['terminal_ps']];
    }

    #[DataProvider('badKinds')]
    public function testAKindOutsideTheAllowListBuildsNothing(string $kind): void
    {
        $this->assertFalse(ConsoleLink::validKind($kind));
        $this->assertNull(ConsoleLink::build($kind, self::NODE));
    }

    /** @return array<string,array{0:string}> */
    public static function badNodes(): array
    {
        return ['empty' => [''], 'one slash' => ['node/AAAAAAAAAAAAAAAAAAAAAAAA'], 'domain' => ['node/dom/AAAAAAAAAAAAAAAAAAAAAAAA'], 'no prefix' => [str_repeat('A', 64)],
            'too short' => ['node//' . str_repeat('A', 19)], 'too long' => ['node//' . str_repeat('A', 101)], 'bad char' => ['node//' . str_repeat('A', 20) . '+'],
            'ampersand' => ['node//' . str_repeat('A', 20) . '&kind=files'], 'newline' => ['node//' . str_repeat('A', 20) . "\n"], 'space' => ['node//' . str_repeat('A', 20) . ' x'],
            'url' => ['https://mesh.example/?login=abc']];
    }

    #[DataProvider('badNodes')]
    public function testANodeIdOutsideThePatternBuildsNothing(string $node): void
    {
        $this->assertFalse(ConsoleLink::validNode($node));
        $this->assertNull(ConsoleLink::build('desktop', $node));
        $this->assertNull(ConsoleLink::descriptor('desktop', $node, 'https://mesh.example/'));
    }

    public function testTheNodePatternBoundariesAre20And100Characters(): void
    {
        $this->assertTrue(ConsoleLink::validNode('node//' . str_repeat('a', 20)));
        $this->assertTrue(ConsoleLink::validNode('node//' . str_repeat('a', 100)));
        $this->assertTrue(ConsoleLink::validNode('node//' . str_repeat('@$_-', 5)));
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
        $this->assertNull(ConsoleLink::kindFor('console'));
        $this->assertSame('desktop', ConsoleLink::kindFor('desktop', ''));
    }

    public function testTheDescriptorKeepsTheStockUrlAsFallbackAndTheLinkCredentialFree(): void
    {
        $stock = 'https://mesh.example/?login=SECRETCOOKIE&gotonode=X&viewmode=11';
        $d = ConsoleLink::descriptor('desktop', self::NODE, $stock);
        $this->assertNotNull($d);
        $this->assertSame(['kind', 'url', 'fallback_url'], array_keys($d));
        $this->assertSame($stock, $d['fallback_url']);
        $this->assertStringNotContainsString('SECRETCOOKIE', $d['url']);
        $this->assertStringNotContainsString('login', $d['url']);
        $this->assertNull(ConsoleLink::descriptor('desktop', null, $stock));
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
