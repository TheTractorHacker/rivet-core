<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * Runs a conformance test case INSIDE another test and reports which of its tests passed, failed or were skipped, so the
 * kit can be tested against deliberately broken adapters ("mutants"): a rule is only proven to be checked when an adapter
 * that violates it makes the right test fail.
 *
 * It calls setUp(), the test method and tearDown() directly and catches what PHPUnit would have recorded as a failure.
 * (TestCase::runBare() is not usable here: it reports the inner failure to the running PHPUnit session and fails the outer
 * test too.) The cases only use assertions, skips and data providers, which work on a TestCase that PHPUnit did not start.
 */
final class Harness
{
    /**
     * @param class-string<TestCase> $testClass a concrete case with `public static ?string $flaw` (see Flaw)
     * @return array{passed:list<string>,failed:array<string,string>,skipped:list<string>} failed maps "method" or "method#dataset" to the message
     */
    public static function run(string $testClass, ?string $flaw): array
    {
        $result = ['passed' => [], 'failed' => [], 'skipped' => []];
        $testClass::$flaw = $flaw; // @phpstan-ignore-line static property declared by the Flaw trait
        try {
            $ref = new \ReflectionClass($testClass);
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->isStatic() || !str_starts_with($m->getName(), 'test')) {
                    continue;
                }
                foreach (self::runs($testClass, $m) as $label => [$data]) {
                    $case = new $testClass($m->getName());
                    try {
                        self::lifecycle($case, $m, $data);
                        $result['passed'][] = $label;
                    } catch (SkippedWithMessageException) {
                        $result['skipped'][] = $label;
                    } catch (\Throwable $e) {
                        $result['failed'][$label] = $e->getMessage();
                    }
                }
            }
        } finally {
            $testClass::$flaw = null; // @phpstan-ignore-line
        }

        return $result;
    }

    /** @param array<mixed> $args */
    private static function lifecycle(TestCase $case, \ReflectionMethod $test, array $args): void
    {
        $setUp = new \ReflectionMethod($case, 'setUp');
        $tearDown = new \ReflectionMethod($case, 'tearDown');
        $setUp->invoke($case);
        try {
            $test->invokeArgs($case, $args);
        } finally {
            $tearDown->invoke($case);
        }
    }

    /**
     * @param class-string<TestCase> $testClass
     * @return array<string,array{0:array<mixed>,1:int|string|null}>
     */
    private static function runs(string $testClass, \ReflectionMethod $m): array
    {
        $attrs = $m->getAttributes(DataProvider::class);
        if ($attrs === []) {
            return [$m->getName() => [[], null]];
        }
        $provider = $attrs[0]->newInstance()->methodName();
        $out = [];
        foreach ($testClass::$provider() as $name => $args) {
            $out[$m->getName() . '#' . $name] = [$args, $name];
        }

        return $out;
    }
}
