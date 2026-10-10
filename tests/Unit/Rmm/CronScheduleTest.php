<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Scripts\CronSchedule;

final class CronScheduleTest extends TestCase
{
    private static function next(string $expr, string $after): ?string
    {
        return CronSchedule::parse($expr)->next(new \DateTimeImmutable($after . ' UTC'))?->format('Y-m-d H:i');
    }

    /** @return iterable<string,array{0:string,1:string,2:string}> */
    public static function cases(): iterable
    {
        yield 'every minute' => ['* * * * *', '2026-10-10 12:00:30', '2026-10-10 12:01'];
        yield 'strictly after, never the same minute' => ['0 12 * * *', '2026-10-10 12:00:00', '2026-10-11 12:00'];
        yield 'later today' => ['30 14 * * *', '2026-10-10 12:00:00', '2026-10-10 14:30'];
        yield 'steps' => ['*/15 * * * *', '2026-10-10 12:07:00', '2026-10-10 12:15'];
        yield 'range with a step' => ['0 8-18/5 * * *', '2026-10-10 09:00:00', '2026-10-10 13:00'];
        yield 'a list' => ['5,35 * * * *', '2026-10-10 12:06:00', '2026-10-10 12:35'];
        yield 'start with a step' => ['10/20 * * * *', '2026-10-10 12:11:00', '2026-10-10 12:30'];
        yield 'day of week (Monday)' => ['0 3 * * 1', '2026-10-10 12:00:00', '2026-10-12 03:00'];
        yield 'sunday as 7' => ['0 3 * * 7', '2026-10-10 12:00:00', '2026-10-11 03:00'];
        yield 'sunday as 0' => ['0 3 * * 0', '2026-10-10 12:00:00', '2026-10-11 03:00'];
        yield 'day of month' => ['0 0 1 * *', '2026-10-10 12:00:00', '2026-11-01 00:00'];
        yield 'both day fields restricted: either matches (the 13th or a Friday)' => ['0 0 13 * 5', '2026-10-10 12:00:00', '2026-10-13 00:00'];
        yield 'month' => ['0 0 1 3 *', '2026-10-10 12:00:00', '2027-03-01 00:00'];
        yield 'leap day' => ['0 0 29 2 *', '2026-10-10 12:00:00', '2028-02-29 00:00'];
        yield 'year end' => ['59 23 31 12 *', '2026-12-31 23:59:00', '2027-12-31 23:59'];
    }

    #[DataProvider('cases')]
    public function testNext(string $expr, string $after, string $expected): void
    {
        $this->assertSame($expected, self::next($expr, $after));
    }

    /** @return iterable<string,array{0:string}> */
    public static function bad(): iterable
    {
        foreach (['', '* * * *', '* * * * * *', '60 * * * *', '* 24 * * *', '* * 0 * *', '* * 32 * *', '* * * 13 *', '* * * * 8', '5-1 * * * *', '*/0 * * * *', 'a * * * *', '@daily', '1,,2 * * * *', '*/x * * * *', 'MON * * * *'] as $b) {
            yield $b === '' ? 'empty' : $b => [$b];
        }
    }

    #[DataProvider('bad')]
    public function testRejects(string $expr): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CronSchedule::parse($expr);
    }

    public function testNormalisesWhitespace(): void
    {
        $this->assertSame('0 3 * * 1', CronSchedule::parse("  0   3 *\t* 1 ")->expression);
    }

    public function testNeverMatchingExpressionGivesNull(): void
    {
        $this->assertNull(self::next('0 0 31 2 *', '2026-10-10 12:00:00'), 'February 31st never comes');
    }
}
