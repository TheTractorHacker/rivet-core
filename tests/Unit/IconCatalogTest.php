<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Ui\IconCatalog;

final class IconCatalogTest extends TestCase
{
    private const FA_CSS = '/var/www/mw-itflow.foleyit.com/plugins/fontawesome-free/css/all.min.css';

    public function testCatalogShape(): void
    {
        $all = IconCatalog::all();
        self::assertGreaterThanOrEqual(250, count($all));
        $cats = IconCatalog::categories();
        self::assertSame(IconCatalog::all(), $all, 'stable between calls');
        foreach ($all as $e) {
            self::assertMatchesRegularExpression('/^fa-[a-z0-9]+(-[a-z0-9]+)*$/', $e['class']);
            self::assertLessThanOrEqual(50, strlen($e['class']));
            self::assertArrayHasKey($e['category'], $cats, $e['class']);
            self::assertNotSame('', $e['label']);
            self::assertTrue(IconCatalog::has($e['class']));
        }
    }

    public function testNoDuplicatesInSource(): void
    {
        $data = (new \ReflectionClassConstant(IconCatalog::class, 'DATA'))->getValue();
        $names = [];
        foreach ($data as $rows) {
            foreach ($rows as $row) {
                $names[] = explode('|', $row)[0];
            }
        }
        $dups = array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1));
        self::assertSame([], $dups, 'duplicate icons across/within categories: ' . implode(', ', $dups));
        $classes = array_column(IconCatalog::all(), 'class');
        self::assertSame($classes, array_values(array_unique($classes)));
    }

    public function testEveryCategoryPopulatedAndGroupingMatches(): void
    {
        $grouped = IconCatalog::byCategory();
        self::assertSame(array_keys(IconCatalog::categories()), array_keys($grouped));
        $n = 0;
        foreach ($grouped as $key => $rows) {
            self::assertNotEmpty($rows, $key);
            $n += count($rows);
        }
        self::assertSame(count(IconCatalog::all()), $n);
    }

    public function testClassesExistInFontAwesomeCss(): void
    {
        if (!is_file(self::FA_CSS)) {
            self::markTestSkipped('Font Awesome css not present');
        }
        $css = (string) file_get_contents(self::FA_CSS);
        $missing = [];
        foreach (IconCatalog::all() as $e) {
            if (!str_contains($css, '.' . $e['class'] . ':before')) {
                $missing[] = $e['class'];
            }
        }
        self::assertSame([], $missing);
        $svg = dirname(self::FA_CSS, 2) . '/webfonts/fa-solid-900.svg';
        if (is_file($svg)) {
            $glyphs = (string) file_get_contents($svg);
            foreach (IconCatalog::all() as $e) {
                self::assertStringContainsString('glyph-name="' . substr($e['class'], 3) . '"', $glyphs, $e['class'] . ' is not in the solid set');
            }
        }
    }

    /** @return array<string,array{0:?string,1:string}> */
    public static function normalizeCases(): array
    {
        return [
            'canonical' => ['fa-fire', 'fa-fire'],
            'fas' => ['fas fa-star', 'fa-star'],
            'fa' => ['fa fa-fire', 'fa-fire'],
            'fa-solid' => ['fa-solid fa-star', 'fa-star'],
            'far' => ['far fa-star', 'fa-star'],
            'bare' => ['fire', 'fa-fire'],
            'bare hyphen' => ['arrow-up', 'fa-arrow-up'],
            'trim' => [' fa-star ', 'fa-star'],
            'upper' => ['FA-FIRE', 'fa-fire'],
            'mixed spaces' => ["  fas   fa-star\t", 'fa-star'],
            'uncatalogued but valid' => ['fa-some-future-icon-2', 'fa-some-future-icon-2'],
            'single letter' => ['x', 'fa-x'],
            'null' => [null, 'fa-filter'],
            'empty' => ['', 'fa-filter'],
            'blank' => ['   ', 'fa-filter'],
            'bare prefix' => ['fa-', 'fa-filter'],
            'just fa' => ['fa', 'fa-filter'],
            'just fas' => ['fas', 'fa-filter'],
            'just fa-solid' => ['fa-solid', 'fa-filter'],
            'hostile' => ['"><script>', 'fa-filter'],
            'hostile with class' => ['fa-fire"><script>alert(1)</script>', 'fa-filter'],
            'quote' => ['fa-fire"', 'fa-filter'],
            'extra word' => ['fa-fire evil', 'fa-filter'],
            'two icons' => ['fa-fire fa-star', 'fa-filter'],
            'double hyphen' => ['fa--fire', 'fa-filter'],
            'trailing hyphen' => ['fa-fire-', 'fa-filter'],
            'underscore' => ['fa-fire_x', 'fa-filter'],
            'unicode' => ['fa-fïre', 'fa-filter'],
            'newline injection' => ["fa-fire\nfoo", 'fa-filter'],
            'trailing newline' => ["fa-fire\n", 'fa-fire'],
            'too long' => ['fa-' . str_repeat('a', 48), 'fa-filter'],
            'max length' => ['fa-' . str_repeat('a', 47), 'fa-' . str_repeat('a', 47)],
            'very long' => [str_repeat('fa-fire ', 500), 'fa-filter'],
        ];
    }

    #[DataProvider('normalizeCases')]
    public function testNormalize(?string $in, string $expected): void
    {
        self::assertSame($expected, IconCatalog::normalize($in));
    }

    public function testNormalizeCustomDefaultAndHasDistinction(): void
    {
        self::assertSame('fa-link', IconCatalog::normalize('<b>', 'fa-link'));
        self::assertTrue(IconCatalog::has('fa-fire'));
        self::assertFalse(IconCatalog::has('fa-some-future-icon-2'));
        self::assertSame('fa-some-future-icon-2', IconCatalog::normalize('fa-some-future-icon-2'));
        self::assertFalse(IconCatalog::has('fire'), 'has() expects a canonical class');
    }

    public function testNormalizeOutputIsAlwaysSafe(): void
    {
        foreach (IconCatalog::all() as $e) {
            self::assertSame($e['class'], IconCatalog::normalize($e['class']));
            self::assertSame($e['class'], IconCatalog::normalize('fas ' . $e['class']));
        }
    }

    public function testSearchRankingAndKeywords(): void
    {
        $classes = static fn (array $rows): array => array_column($rows, 'class');
        $r = $classes(IconCatalog::search('fire'));
        self::assertSame('fa-fire', $r[0]);
        self::assertContains('fa-fire-alt', $r);
        self::assertContains('fa-fire-extinguisher', $r);
        self::assertContains('fa-fire', $classes(IconCatalog::search('urgent')), 'keyword match');
        self::assertSame('fa-fire', $classes(IconCatalog::search('FA-FIRE'))[0], 'case-insensitive, fa- prefix');
        self::assertSame('fa-star', $classes(IconCatalog::search('  Star '))[0]);
        // Prefix beats keyword-only matches.
        $r = $classes(IconCatalog::search('lock'));
        self::assertSame('fa-lock', $r[0]);
        // Deterministic.
        self::assertSame(IconCatalog::search('user'), IconCatalog::search('user'));
        self::assertSame([], IconCatalog::search('zzzzqqqq'));
    }

    public function testSearchEmptyQueryCategoryAndLimit(): void
    {
        self::assertCount(60, IconCatalog::search(''));
        self::assertCount(5, IconCatalog::search('', null, 5));
        self::assertSame([], IconCatalog::search('', null, 0));
        self::assertSame(IconCatalog::all()[0], IconCatalog::search('')[0]);
        $sec = IconCatalog::search('', 'security', 500);
        self::assertCount(count(IconCatalog::byCategory()['security']), $sec);
        foreach ($sec as $e) {
            self::assertSame('security', $e['category']);
        }
        foreach (IconCatalog::search('user', 'money', 500) as $e) {
            self::assertSame('money', $e['category']);
        }
        self::assertSame([], IconCatalog::search('', 'no-such-category'));
        self::assertSame(IconCatalog::search('', 'security', 500), IconCatalog::search('', 'security', 500));
    }

    public function testToJsonRoundTrip(): void
    {
        $json = IconCatalog::toJson();
        self::assertMatchesRegularExpression('/^[\x20-\x7e]*$/', $json, 'ASCII only');
        self::assertStringNotContainsString('\/', $json);
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(IconCatalog::VERSION, $d['version']);
        self::assertSame(IconCatalog::categories(), $d['categories']);
        self::assertCount(count(IconCatalog::all()), $d['icons']);
        $first = IconCatalog::all()[0];
        self::assertSame(['c' => $first['class'], 'l' => $first['label'], 'g' => $first['category'], 'k' => $first['keywords']], $d['icons'][0]);
        self::assertStringNotContainsString('<', $json);
    }
}
